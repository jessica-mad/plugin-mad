<?php if (!defined('ABSPATH')) exit;

/**
 * Módulo: Incentivos de carrito (CPT)
 * Requisitos: WooCommerce
 * Estructura:
 *   modules/incentives/Module.php
 *   modules/incentives/assets/front.js
 *   modules/incentives/views/settings.php
 */

return new class($core) implements MAD_Suite_Module {
  private $core; 
  private $opt_key;
  const CPT = 'hic_incentive';

  public function __construct($core){
    $this->core = $core;
    $this->opt_key = MAD_Suite_Core::option_key($this->slug());
  }

  /* ===== Identidad del módulo ===== */
  public function slug(){ return 'incentives'; }
  public function title(){ return __('Incentivos de carrito','mad-suite'); }
  public function menu_label(){ return __('Incentivos','mad-suite'); }
  public function menu_slug(){ return 'mad-'.$this->slug(); }

  /* ===== Ciclo de vida ===== */
  public function init(){
    if ( ! class_exists('WooCommerce') ) return;

    // CPT + Metabox
    add_action('init',               [$this,'register_cpt']);
    add_action('add_meta_boxes',     [$this,'register_metaboxes']);
    add_action('save_post',          [$this,'save_metaboxes']);

    // Front
    add_action('wp_enqueue_scripts', [$this,'enqueue_front']);
    add_action('wp_head',            [$this,'inline_styles']);
    add_action('wp_ajax_madin_get_incentive',        [$this,'ajax_incentive']);
    add_action('wp_ajax_nopriv_madin_get_incentive', [$this,'ajax_incentive']);

    // Sincronía de regalos
    add_action('woocommerce_cart_loaded_from_session', [$this,'sync_rewards']);
    add_action('woocommerce_before_calculate_totals',  [$this,'sync_rewards'], 11);
  }

  public function admin_init(){
    // Ajustes generales
    register_setting($this->menu_slug(), $this->opt_key);

    add_settings_section('sec_inc', __('Configuración del mini-carrito','mad-suite'), function(){
      echo '<p>'.esc_html__('Define dónde se mostrarán los incentivos en tu tienda.','mad-suite').'</p>';
    }, $this->menu_slug());

    add_settings_field('wrap', __('Contenedor mini-carrito','mad-suite'), function(){
      $s=$this->get(); 
      $v= esc_attr($s['wrap_selector']);
      printf('<input type="text" name="%1$s[wrap_selector]" value="%2$s" class="regular-text">', esc_attr($this->opt_key), $v);
      echo '<p class="description">'.esc_html__('Selector CSS donde inyectar el incentivo (ej. .nm-cart-panel-summary)','mad-suite').'</p>';
    }, $this->menu_slug(), 'sec_inc');
  }

  public function render_settings_page(){
    include plugin_dir_path(__FILE__).'views/settings.php';
  }

  /* ===== Defaults & opciones ===== */
  private function defaults(){
    return [
      'wrap_selector' => '.nm-cart-panel-summary',
    ];
  }
  
  private function get(){ 
    return wp_parse_args( get_option($this->opt_key, []), $this->defaults() ); 
  }

  /* ===== CPT & Metabox ===== */
  public function register_cpt(){
    register_post_type(self::CPT, [
      'labels'=>[
        'name'=>__('Incentivos','mad-suite'), 
        'singular_name'=>__('Incentivo','mad-suite'),
        'add_new'=>__('Añadir nuevo','mad-suite'), 
        'add_new_item'=>__('Añadir incentivo','mad-suite'),
        'edit_item'=>__('Editar incentivo','mad-suite'), 
        'all_items'=>__('Todos los incentivos','mad-suite')
      ],
      'public'=>false,
      'show_ui'=>true,
      'show_in_menu'=>false,
      'supports'=>['title','page-attributes'],
      'map_meta_cap'=>true
    ]);
  }

  public function register_metaboxes(){
    add_meta_box('madin_inc', __('Configuración del incentivo','mad-suite'), [$this,'mb_render'], self::CPT,'normal','high');
  }

  public function mb_render($post){
    wp_nonce_field('madin_inc','madin_inc_nonce');
    
    // Obtener valores guardados con manejo correcto de tipos
    $active_val = get_post_meta($post->ID, '_active', true);
    $active = ($active_val === '1' || $active_val === 1 || $active_val === true);
    
    $min_val = get_post_meta($post->ID, '_min', true);
    $min = ($min_val !== '' && $min_val !== false) ? $min_val : '';
    
    $msg_val = get_post_meta($post->ID, '_msg', true);
    $msg = $msg_val ? $msg_val : '¡Te faltan {{missing}} para conseguirlo!';
    
    $usep_val = get_post_meta($post->ID, '_usep', true);
    $usep = ($usep_val === '1' || $usep_val === 1 || $usep_val === true);
    
    $pid = (int)get_post_meta($post->ID, '_pid', true);
    $img = (int)get_post_meta($post->ID, '_img', true);
    
    $qty_val = get_post_meta($post->ID, '_rqty', true);
    $qty = $qty_val ? max(1, (int)$qty_val) : 1;
    
    $free_val = get_post_meta($post->ID, '_rfree', true);
    $free = ($free_val === '1' || $free_val === 1 || $free_val === true);
    
    $imgurl = $img ? wp_get_attachment_image_url($img, 'thumbnail') : '';
    ?>
    <table class="form-table">
      <tr>
        <th><?php _e('Activo','mad-suite'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="_active" value="1" <?php checked($active,true); ?>> 
            <?php _e('Mostrar incentivo','mad-suite'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th><?php _e('Mínimo (€)','mad-suite'); ?></th>
        <td>
          <input type="number" step="0.01" min="0" name="_min" value="<?php echo esc_attr($min); ?>" style="width:140px">
          <p class="description"><?php _e('Importe mínimo del carrito para alcanzar este incentivo','mad-suite'); ?></p>
        </td>
      </tr>
      <tr>
        <th><?php _e('Mensaje','mad-suite'); ?></th>
        <td>
          <input type="text" name="_msg" value="<?php echo esc_attr($msg ?: '¡Te faltan {{missing}} para conseguirlo!'); ?>" style="width:100%">
          <p class="description"><?php _e('Usa','mad-suite'); ?> <code>{{missing}}</code> <?php _e('para mostrar el importe restante','mad-suite'); ?></p>
        </td>
      </tr>
      <tr>
        <th><?php _e('Imagen / Producto','mad-suite'); ?></th>
        <td>
          <label style="display:block;margin-bottom:12px;">
            <input type="checkbox" name="_usep" value="1" <?php checked($usep,true); ?>> 
            <?php _e('Usar imagen destacada de un producto','mad-suite'); ?>
          </label>

          <div id="madin_prod" style="<?php echo $usep?'':'display:none'; ?>">
            <label>
              <?php _e('ID producto','mad-suite'); ?> 
              <input type="number" min="0" name="_pid" value="<?php echo esc_attr($pid); ?>" style="width:120px">
            </label>
            <p style="margin:12px 0 0;">
              <label style="margin-right:12px;">
                <?php _e('Cantidad auto-añadida','mad-suite'); ?> 
                <input type="number" min="1" name="_rqty" value="<?php echo esc_attr($qty); ?>" style="width:80px;margin-left:6px">
              </label>
              <label>
                <input type="checkbox" name="_rfree" value="1" <?php checked($free,true); ?>> 
                <?php _e('Añadir como regalo (0 €)','mad-suite'); ?>
              </label>
            </p>
          </div>

          <div id="madin_img" style="<?php echo $usep?'display:none':''; ?>">
            <input type="hidden" id="madin_img_id" name="_img" value="<?php echo esc_attr($img); ?>">
            <button type="button" class="button" id="madin_img_btn"><?php _e('Seleccionar imagen','mad-suite'); ?></button>
            <span id="madin_img_prev" style="margin-left:10px;vertical-align:middle;">
              <?php if($imgurl): ?>
                <img src="<?php echo esc_url($imgurl); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">
              <?php endif; ?>
            </span>
            <button type="button" class="button-link-delete" id="madin_img_remove" style="margin-left:10px;<?php echo $img? '':'display:none'; ?>">
              <?php _e('Quitar','mad-suite'); ?>
            </button>
          </div>
        </td>
      </tr>
      <tr>
        <th><?php _e('Prioridad','mad-suite'); ?></th>
        <td>
          <p class="description"><?php _e('Usa el campo "Orden" en la columna derecha (menor número = mayor prioridad).','mad-suite'); ?></p>
        </td>
      </tr>
    </table>
    <script>
    (function(){
      var c=document.querySelector('input[name="_usep"]'),
          p=document.getElementById('madin_prod'),
          i=document.getElementById('madin_img');
      
      if(c){ 
        c.addEventListener('change',function(){ 
          if(this.checked){
            p.style.display='';
            i.style.display='none';
          } else {
            p.style.display='none';
            i.style.display='';
          } 
        }); 
      }
      
      var b=document.getElementById('madin_img_btn'),
          r=document.getElementById('madin_img_remove'),
          h=document.getElementById('madin_img_id'),
          pr=document.getElementById('madin_img_prev');
      
      if(b&&wp&&wp.media){ 
        b.addEventListener('click',function(e){ 
          e.preventDefault();
          var f=wp.media({
            title:'<?php _e('Seleccionar imagen','mad-suite'); ?>',
            button:{text:'<?php _e('Usar esta imagen','mad-suite'); ?>'},
            multiple:false
          });
          f.on('select',function(){ 
            var a=f.state().get('selection').first().toJSON();
            h.value=a.id; 
            pr.innerHTML='<img src="'+a.sizes.thumbnail.url+'" style="width:60px;height:60px;object-fit:cover;border:1px solid #ddd;border-radius:8px">'; 
            r.style.display='';
          }); 
          f.open();
        }); 
      }
      
      if(r){ 
        r.addEventListener('click',function(e){ 
          e.preventDefault(); 
          h.value=''; 
          pr.innerHTML=''; 
          r.style.display='none'; 
        }); 
      }
    })();
    </script>
    <?php
  }

  public function save_metaboxes($post_id){
    if (get_post_type($post_id) !== self::CPT) return;
    if (!isset($_POST['madin_inc_nonce']) || !wp_verify_nonce($_POST['madin_inc_nonce'], 'madin_inc')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Guardar checkboxes como '1' o '0' (string) para consistencia
    update_post_meta($post_id, '_active', isset($_POST['_active']) && $_POST['_active'] ? '1' : '0');
    update_post_meta($post_id, '_usep', isset($_POST['_usep']) && $_POST['_usep'] ? '1' : '0');
    update_post_meta($post_id, '_rfree', isset($_POST['_rfree']) && $_POST['_rfree'] ? '1' : '0');
    
    // Guardar valores numéricos
    update_post_meta($post_id, '_min', isset($_POST['_min']) ? sanitize_text_field($_POST['_min']) : '0');
    update_post_meta($post_id, '_pid', isset($_POST['_pid']) ? intval($_POST['_pid']) : 0);
    update_post_meta($post_id, '_img', isset($_POST['_img']) ? intval($_POST['_img']) : 0);
    update_post_meta($post_id, '_rqty', isset($_POST['_rqty']) ? max(1, absint($_POST['_rqty'])) : 1);
    
    // Guardar texto
    update_post_meta($post_id, '_msg', isset($_POST['_msg']) ? sanitize_text_field($_POST['_msg']) : '');
  }

  /* ===== Front assets & UI ===== */
  public function enqueue_front(){
    // Solo cargar en el frontend, no en admin
    if(is_admin()) return;
    
    $script_url = plugins_url('assets/front.js', __FILE__);
    $script_path = plugin_dir_path(__FILE__) . 'assets/front.js';
    
    // Verificar que el archivo existe
    if(!file_exists($script_path)){
      error_log('MAD Incentives: front.js no encontrado en ' . $script_path);
      return;
    }
    
    wp_enqueue_script(
      'mad-incentives-front', 
      $script_url, 
      ['jquery'], 
      filemtime($script_path), // Usar timestamp del archivo como versión
      true
    );
    
    $s = $this->get();
    wp_localize_script('mad-incentives-front', 'MAD_Incentives', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('mad_incentives'),
      'wrapSelector' => $s['wrap_selector'],
    ]);
  }

  public function inline_styles(){ ?>
    <style>
      .mad-inc-box{
        display:flex;
        gap:12px;
        align-items:center;
        justify-content:space-between;
        border:1px solid black;
        padding:12px;
        margin:10px 0 0 0;
        background:transparent;
        color:white;
      }
      .mad-inc-body{
        flex:1 1 auto;
        display:flex;
        flex-direction:column;
        gap:8px;
      }
      .mad-inc-text{
        font-weight:600;
        text-align:left;
      }
      .mad-inc-bar{
        position:relative;
        height:8px;
        background:#e0e0e0;
        border-radius:99px;
        overflow:hidden;
      }
      .mad-inc-bar-fill{
        position:absolute;
        left:0;
        top:0;
        bottom:0;
        width:0;
        background:#000;
        transition:width .3s ease;
      }
      .mad-inc-meta{
        display:flex;
        justify-content:space-between;
        font-size:12px;
        color:#bbb;
      }
      .mad-inc-img{
        width:60px;
        height:60px;
        object-fit:cover;
        border-radius:8px;
      }
    </style>
  <?php }

  public function ajax_incentive(){
    check_ajax_referer('mad_incentives','nonce');
    if(function_exists('WC') && WC()->cart){ 
      WC()->cart->calculate_totals(); 
    }
    wp_send_json_success(['html'=>$this->incentive_html()]);
  }

  /* ===== Lógica incentivos ===== */
  private function cart_subtotal(){
    return (function_exists('WC') && WC()->cart) ? (float) WC()->cart->get_displayed_subtotal() : 0.0;
  }

  private function cart_base_total_ex_rewards(){
    if(!function_exists('WC') || !WC()->cart) return 0.0;
    $mk='_mad_reward_inc_id'; 
    $t=0.0;
    foreach(WC()->cart->get_cart() as $key=>$it){
      $rid=$it[$mk]??($it['mad_meta'][$mk]??null);
      if($rid) continue;
      if(isset($it['line_subtotal'])){ 
        $t+=(float)$it['line_subtotal']; 
        continue; 
      }
      if(isset($it['data'])&&is_object($it['data'])){ 
        $t += (float)$it['data']->get_price() * (int)($it['quantity']??1); 
      }
    }
    return $t;
  }

  private function q_active(){
    return new WP_Query([
      'post_type'=>self::CPT,
      'post_status'=>'publish',
      'posts_per_page'=>-1,
      'orderby'=>['menu_order'=>'ASC','date'=>'ASC'],
      'no_found_rows'=>true,
      'meta_query'=>[['key'=>'_active','value'=>'1']]
    ]);
  }

  private function pick_current(&$missing=0.0){
    $sub=$this->cart_subtotal(); 
    $q=$this->q_active(); 
    if(!$q->have_posts()) return null;
    
    foreach($q->posts as $p){
      $min=(float)get_post_meta($p->ID,'_min',true);
      if($sub < $min){ 
        $missing=max(0.0,$min-$sub); 
        return $p; 
      }
    }
    return null; // todos alcanzados
  }

  private function incentive_html(){
    $post=$this->pick_current($missing); 
    if(!$post) return '';
    
    $sub=$this->cart_subtotal(); 
    $min=(float)get_post_meta($post->ID,'_min',true);
    $msg=get_post_meta($post->ID,'_msg',true) ?: '¡Te faltan {{missing}} para conseguirlo!';
    $msg=str_replace('{{missing}}', wc_price($missing), $msg);
    $pct=max(0,min(100, $min>0? round(($sub/$min)*100) : 100));
    
    $img_html='';
    if( (bool)get_post_meta($post->ID,'_usep',true) ){
      $pid=(int)get_post_meta($post->ID,'_pid',true);
      if($pid) $img_html=get_the_post_thumbnail($pid,'thumbnail',['class'=>'mad-inc-img']);
    } else {
      $img=(int)get_post_meta($post->ID,'_img',true);
      if($img) $img_html=wp_get_attachment_image($img,'thumbnail',false,['class'=>'mad-inc-img']);
    }
    
    ob_start(); 
    ?>
    <div id="mad-minicart-incentive" class="mad-inc-box" role="status" aria-live="polite">
      <div class="mad-inc-body">
        <div class="mad-inc-text"><?php echo wp_kses_post($msg); ?></div>
        <div class="mad-inc-bar">
          <div class="mad-inc-bar-fill" style="width: <?php echo esc_attr($pct); ?>%"></div>
        </div>
        <div class="mad-inc-meta">
          <span><?php echo wc_price($sub); ?></span>
          <span><?php echo wc_price($min); ?></span>
        </div>
      </div>
      <?php if($img_html): ?>
        <div class="mad-inc-media"><?php echo $img_html; ?></div>
      <?php endif; ?>
    </div>
    <?php 
    return ob_get_clean();
  }

  public function sync_rewards(){
    if(is_admin() && !defined('DOING_AJAX')) return;
    if(!function_exists('WC') || !WC()->cart) return;
    
    static $sync=false; 
    if($sync) return; 
    $sync=true;

    $base=$this->cart_base_total_ex_rewards();
    $q=$this->q_active(); 
    if(!$q->have_posts()){ 
      $sync=false; 
      return; 
    }

    // incentivos alcanzados que usan producto
    $reached=[];
    foreach($q->posts as $p){
      if(!(bool)get_post_meta($p->ID,'_active',true)) continue;
      $min=(float)get_post_meta($p->ID,'_min',true);
      if($base+0.0001 < $min) continue;
      if(!(bool)get_post_meta($p->ID,'_usep',true)) continue;
      $pid=(int)get_post_meta($p->ID,'_pid',true);
      if($pid<=0) continue;
      $reached[]=$p;
    }

    $target=!empty($reached)? end($reached):null;
    $should=null;
    if($target){
      $inc=$target->ID; 
      $pid=(int)get_post_meta($inc,'_pid',true);
      $qty=max(1,(int)get_post_meta($inc,'_rqty',true));
      $free=(bool)get_post_meta($inc,'_rfree',true);
      $po=wc_get_product($pid);
      if($po && $po->is_purchasable()){
        $should=['inc_id'=>$inc,'product'=>$pid,'qty'=>$qty,'free'=>$free];
      }
    }

    $mk='_mad_reward_inc_id'; 
    $found=[];
    foreach(WC()->cart->get_cart() as $key=>$it){
      $rid=$it[$mk]??($it['mad_meta'][$mk]??null);
      if($rid){
        if(!isset($found[$rid])) $found[$rid]=['qty'=>0,'keys'=>[]];
        $found[$rid]['qty']+=(int)($it['quantity']??1);
        $found[$rid]['keys'][]=$key;
      }
    }

    foreach($found as $inc_id=>$data){
      $still=($should && (int)$should['inc_id']===(int)$inc_id);
      if(!$still){ 
        foreach($data['keys'] as $k){ 
          WC()->cart->remove_cart_item($k); 
        } 
      }
    }

    if($should){
      $have= isset($found[$should['inc_id']])? (int)$found[$should['inc_id']]['qty'] : 0;
      $need=max(0,$should['qty']-$have);
      if($need>0){
        WC()->cart->add_to_cart( 
          (int)$should['product'], 
          (int)$need, 
          0, 
          [], 
          [ $mk => (int)$should['inc_id'] ] 
        );
      }
    }

    if($should && $should['free']){
      foreach(WC()->cart->get_cart() as $key=>$it){
        $rid=$it[$mk]??($it['mad_meta'][$mk]??null);
        if($rid && (int)$rid===(int)$should['inc_id']){
          if(isset($it['data'])&&is_object($it['data'])){ 
            $it['data']->set_price(0); 
          }
        }
      }
    }

    $sync=false;
  }
};