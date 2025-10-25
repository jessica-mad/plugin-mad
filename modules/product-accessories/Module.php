<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Módulo: Producto accesorio
 * - CPT "mad_accessory" para definir perfiles de accesorios
 * - Opción B: Campo en PDP antes del botón de añadir al carrito
 * - Opción C: Controles en el carrito por cada línea principal
 * - Añade el accesorio como línea separada, vinculada al item padre
 */

return new class($core) implements MAD_Suite_Module {

  private $core;
  private $cpt = 'mad_accessory';
  private $adding_accessory = false; // FLAG para evitar bucles

  public function __construct($core){ $this->core = $core; }

  public function slug(){ return 'product-accessory'; }
  public function title(){ return __('Productos accesorios','mad-suite'); }
  public function menu_label(){ return __('Accesorios','mad-suite'); }
  public function menu_slug(){ return 'mad-'.$this->slug(); }

  public function init(){
    if ( ! class_exists('WooCommerce') ) return;

    // CPT + UI
    add_action('init', [$this,'register_cpt']);
    add_action('add_meta_boxes', [$this,'add_metaboxes']);
    add_action('save_post_'.$this->cpt, [$this,'save_metabox'], 10, 2);
    add_action('admin_enqueue_scripts', [$this,'admin_assets']);

    // Buscador AJAX de productos (admin)
    add_action('wp_ajax_mad_acc_search_products', [$this,'ajax_search_products']);

    // PDP (Opción B)
    add_action('woocommerce_before_add_to_cart_button', [$this,'render_pdp_field'], 22);
    add_filter('woocommerce_add_cart_item_data', [$this,'capture_pdp_request'], 10, 3);
    
    // CAMBIO: Usar prioridad alta y verificar flag
    add_action('woocommerce_add_to_cart', [$this,'maybe_add_accessory_on_addtocart'], 20, 6);

    // Carrito (Opción C)
    add_action('woocommerce_after_cart_item_name', [$this,'render_cart_accessory_controls'], 10, 2);
    add_action('wp', [$this,'handle_cart_accessory_updates']); // cuando se envía "Actualizar carrito"

    // Visual y metadatos
    add_filter('woocommerce_get_item_data', [$this,'show_accessory_link_meta'], 10, 2);
    add_filter('woocommerce_cart_item_name', [$this,'decorate_accessory_cart_name'], 10, 3);

    // Eliminación en cascada
    add_action('woocommerce_remove_cart_item', [$this,'cascade_remove_children'], 10, 2);

    // Transferir metadatos al pedido
    add_action('woocommerce_checkout_create_order_line_item', [$this,'transfer_meta_to_order'], 10, 4);
  }

  public function admin_init(){
    // Nada especial; el listado se gestiona con el CPT y la subpágina
  }

  public function render_settings_page(){
    // Redirige al listado del CPT dentro del panel
    wp_safe_redirect( admin_url('edit.php?post_type='.$this->cpt) );
    exit;
  }

  /* ============================================================
   * CPT + Metaboxes
   * ============================================================
   */

  public function register_cpt(){
    register_post_type($this->cpt, [
      'label' => __('Productos accesorios','mad-suite'),
      'labels' => [
        'name' => __('Productos accesorios','mad-suite'),
        'singular_name' => __('Producto accesorio','mad-suite'),
        'add_new' => __('Añadir nuevo','mad-suite'),
        'add_new_item' => __('Añadir accesorio','mad-suite'),
        'edit_item' => __('Editar accesorio','mad-suite'),
        'new_item' => __('Nuevo accesorio','mad-suite'),
        'view_item' => __('Ver accesorio','mad-suite'),
        'search_items' => __('Buscar accesorios','mad-suite'),
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => false, // colgado bajo MAD Suite
      'supports' => ['title'],
      'capability_type' => 'post',
    ]);

    // Colgar el listado bajo el menú raíz de MAD Suite
    add_submenu_page(
      MAD_Suite_Core::MENU_SLUG_ROOT,
      __('Accesorios','mad-suite'),
      __('Accesorios','mad-suite'),
      MAD_Suite_Core::CAPABILITY,
      'edit.php?post_type='.$this->cpt
    );
  }

  public function add_metaboxes(){
    add_meta_box('mad_acc_cfg', __('Configuración del accesorio','mad-suite'), [$this,'mb_cfg_html'], $this->cpt, 'normal', 'high');
  }

  public function admin_assets($hook){
    // Solo en pantallas del CPT de accesorios
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== $this->cpt ) return;

    // Select2 del core de WP (opcional) o uso nativo
    wp_enqueue_script('jquery');

    // Script inline muy ligero para el buscador
    wp_register_script('mad-acc-admin', '', [], false, true);
    wp_enqueue_script('mad-acc-admin');
    $ajax = [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce'    => wp_create_nonce('mad_acc_admin'),
    ];
    wp_add_inline_script('mad-acc-admin', 'window.MAD_ACC_ADMIN = '.wp_json_encode($ajax).';', 'before');

    // CSS mínimo para el árbol de términos
    $css = '
      .mad-acc-field { margin-bottom: 12px; }
      .mad-acc-tree ul { margin-left:16px; }
      .mad-acc-hint { color:#666; font-size:12px; }
      .mad-acc-pill { display:inline-block; padding:2px 6px; border:1px solid #ccc; border-radius:999px; margin:2px 4px 0 0; font-size:12px; }
    ';
    wp_register_style('mad-acc-admin-css', false);
    wp_enqueue_style('mad-acc-admin-css');
    wp_add_inline_style('mad-acc-admin-css', $css);
  }

  public function mb_cfg_html($post){
    require __DIR__.'/settings.php';
  }

  public function save_metabox($post_id, $post){
    if ( ! isset($_POST['mad_acc_nonce']) || ! wp_verify_nonce($_POST['mad_acc_nonce'], 'mad_acc_save') ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can(MAD_Suite_Core::CAPABILITY) ) return;

    // Sanear y guardar
    $product_id   = isset($_POST['_mad_acc_product_id']) ? (int) $_POST['_mad_acc_product_id'] : 0;
    $show_pdp     = !empty($_POST['_mad_acc_show_on_pdp']) ? 1 : 0;
    $show_cart    = !empty($_POST['_mad_acc_show_in_cart']) ? 1 : 0;
    $qty_min      = isset($_POST['_mad_acc_qty_min']) ? max(0, (int)$_POST['_mad_acc_qty_min']) : 0;
    $qty_max      = isset($_POST['_mad_acc_qty_max']) ? max(0, (int)$_POST['_mad_acc_qty_max']) : 0;
    $qty_def      = isset($_POST['_mad_acc_qty_default']) ? max(0, (int)$_POST['_mad_acc_qty_default']) : 0;
    $title_over   = isset($_POST['_mad_acc_title_override']) ? sanitize_text_field($_POST['_mad_acc_title_override']) : '';

    $scope_cats   = isset($_POST['_mad_acc_scope_cats']) ? array_map('intval', (array)$_POST['_mad_acc_scope_cats']) : [];
    $scope_tags   = isset($_POST['_mad_acc_scope_tags']) ? array_map('intval', (array)$_POST['_mad_acc_scope_tags']) : [];
    $scope_prods  = isset($_POST['_mad_acc_scope_products']) ? array_map('intval', (array)$_POST['_mad_acc_scope_products']) : [];

    if ($qty_max && $qty_min > $qty_max) $qty_min = $qty_max;
    if ($qty_def && $qty_max && $qty_def > $qty_max) $qty_def = $qty_max;

    update_post_meta($post_id, '_mad_acc_product_id', $product_id);
    update_post_meta($post_id, '_mad_acc_show_on_pdp', $show_pdp);
    update_post_meta($post_id, '_mad_acc_show_in_cart', $show_cart);
    update_post_meta($post_id, '_mad_acc_qty_min', $qty_min);
    update_post_meta($post_id, '_mad_acc_qty_max', $qty_max);
    update_post_meta($post_id, '_mad_acc_qty_default', $qty_def);
    update_post_meta($post_id, '_mad_acc_title_override', $title_over);

    update_post_meta($post_id, '_mad_acc_scope_cats', $scope_cats);
    update_post_meta($post_id, '_mad_acc_scope_tags', $scope_tags);
    update_post_meta($post_id, '_mad_acc_scope_products', $scope_prods);
  }

  public function ajax_search_products(){
    check_ajax_referer('mad_acc_admin', 'nonce');
    if ( ! current_user_can(MAD_Suite_Core::CAPABILITY) ) wp_send_json_error('perm');

    $s = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    $args = [
      'post_type' => ['product','product_variation'],
      'post_status' => ['publish','private'],
      'posts_per_page' => 20,
      's' => $s,
      'fields' => 'ids',
    ];
    $q = new WP_Query($args);
    $out = [];
    if ($q->have_posts()){
      foreach($q->posts as $pid){
        if ( 'product_variation' === get_post_type($pid) ) {
          $parent_id = wp_get_post_parent_id($pid);
          $name = get_the_title($parent_id).' – '.wc_get_formatted_variation( wc_get_product($pid), true, false, true );
        } else {
          $name = get_the_title($pid);
        }
        $out[] = ['id'=>$pid, 'text'=>sprintf('#%d – %s', $pid, $name)];
      }
    }
    wp_send_json(['results'=>$out]);
  }

  /* ============================================================
   * PDP (Opción B)
   * ============================================================
   */

  public function render_pdp_field(){
    if ( ! is_product() ) return;
    global $product; if ( ! $product ) return;
    $profiles = $this->matching_profiles_for_product($product->get_id(), ['place'=>'pdp']);
    if ( ! $profiles ) return;

    echo '<div class="mad-acc-pdp" style="margin:10px 0;">';
    wp_nonce_field('mad_acc_add','mad_acc_nonce');
    foreach($profiles as $p){
      $pid   = $p->ID;
      $min   = (int) get_post_meta($pid,'_mad_acc_qty_min',true) ?: 0;
      $max   = (int) get_post_meta($pid,'_mad_acc_qty_max',true) ?: 9999;
      $def   = (int) get_post_meta($pid,'_mad_acc_qty_default',true) ?: 0;
      $label = get_post_meta($pid,'_mad_acc_title_override',true) ?: __('Incluir bolsas al vacío','mad-suite');

      printf(
        '<div class="mad-acc-field"><label>%s: <input type="number" name="mad_acc_qty[%d]" min="%d" max="%d" value="%d" style="width:90px;"></label></div>',
        esc_html($label), $pid, $min, $max, $def
      );
    }
    echo '</div>';
  }

  public function capture_pdp_request($cart_item_data, $product_id, $variation_id){
    if ( isset($_POST['mad_acc_nonce']) && wp_verify_nonce($_POST['mad_acc_nonce'],'mad_acc_add') ){
      $req = isset($_POST['mad_acc_qty']) ? array_map('intval', (array)$_POST['mad_acc_qty']) : [];
      $req = array_filter($req, fn($q)=> $q>0);
      if ($req) $cart_item_data['mad_acc_requested'] = $req; // profile_id => qty
    }
    return $cart_item_data;
  }

  public function maybe_add_accessory_on_addtocart($cart_item_key, $product_id, $qty, $variation_id, $variation, $cart_item_data){
    // IMPORTANTE: Evitar bucle cuando añadimos el accesorio
    if ( $this->adding_accessory ) return;
    
    // Solo procesar si hay solicitud de accesorios
    if ( empty($cart_item_data['mad_acc_requested']) ) return;
    
    // Verificar que el item realmente se añadió al carrito
    $cart_item = WC()->cart->get_cart_item($cart_item_key);
    if ( ! $cart_item ) return;

    // Activar flag para evitar bucles
    $this->adding_accessory = true;

    try {
      foreach($cart_item_data['mad_acc_requested'] as $profile_id => $acc_qty){
        $this->add_child_accessory_item($cart_item_key, (int)$profile_id, (int)$acc_qty);
      }
    } catch (Exception $e) {
      // Log del error si es necesario
      error_log('MAD Accessory Error: ' . $e->getMessage());
    }

    // Desactivar flag
    $this->adding_accessory = false;
  }

  private function add_child_accessory_item($parent_key, $profile_id, $qty){
    $acc_product_id = (int) get_post_meta($profile_id,'_mad_acc_product_id',true);
    if ( ! $acc_product_id || $qty<=0 ) return;

    $parent = WC()->cart->get_cart_item($parent_key);
    if ( ! $parent ) return;

    // Verificar que el producto accesorio existe y está disponible
    $acc_product = wc_get_product($acc_product_id);
    if ( ! $acc_product || ! $acc_product->is_purchasable() ) return;

    $parent_name = $parent['data'] ? $parent['data']->get_name() : '';

    $cart_item_data = [
      'mad_acc' => [
        '_mad_acc_parent_key'  => $parent_key,
        '_mad_acc_profile_id'  => (int)$profile_id,
        '_mad_acc_parent_name' => $parent_name,
      ],
    ];

    // Asegurar que cada accesorio quede único por padre (al incluir mad_acc se diferencia el hash)
    WC()->cart->add_to_cart($acc_product_id, $qty, 0, [], $cart_item_data);
  }

  /* ============================================================
   * Carrito (Opción C)
   * ============================================================
   */

  public function render_cart_accessory_controls($cart_item, $cart_item_key){
    if ( ! is_cart() ) return;
    // Solo en items NO accesorios
    if ( ! empty($cart_item['mad_acc']) ) return;

    $product_id = $cart_item['product_id'];
    $profiles = $this->matching_profiles_for_product($product_id, ['place'=>'cart']);
    if ( ! $profiles ) return;

    foreach($profiles as $p){
      $pid = $p->ID;
      $min = (int) get_post_meta($pid,'_mad_acc_qty_min',true) ?: 0;
      $max = (int) get_post_meta($pid,'_mad_acc_qty_max',true) ?: 9999;
      $existing_child_key = $this->find_child_accessory_key($cart_item_key, $pid);
      $current_qty = $existing_child_key
        ? (int) WC()->cart->get_cart_item($existing_child_key)['quantity']
        : ((int) get_post_meta($pid,'_mad_acc_qty_default',true) ?: 0);

      $label = get_post_meta($pid,'_mad_acc_title_override',true) ?: __('Agregar bolsas para este producto','mad-suite');

      echo '<div class="mad-acc-cart" style="margin-top:6px">';
      echo '<label>'.esc_html($label).': ';
      printf('<input type="number" name="mad_acc_cart_qty[%s][%d]" min="%d" max="%d" value="%d" style="width:90px;">',
        esc_attr($cart_item_key), $pid, $min, $max, $current_qty
      );
      echo '</label></div>';
    }

    // Nonce una vez
    if ( empty($GLOBALS['__mad_acc_cart_nonce_printed']) ) {
      $GLOBALS['__mad_acc_cart_nonce_printed'] = true;
      wp_nonce_field('mad_acc_cart_update','mad_acc_cart_nonce');
    }
  }

  public function handle_cart_accessory_updates(){
    if ( !(is_cart() && isset($_POST['update_cart'])) ) return;
    if ( ! isset($_POST['mad_acc_cart_nonce']) || ! wp_verify_nonce($_POST['mad_acc_cart_nonce'],'mad_acc_cart_update') ) return;

    $this->adding_accessory = true; // Activar flag

    $map = isset($_POST['mad_acc_cart_qty']) ? (array)$_POST['mad_acc_cart_qty'] : [];
    foreach($map as $parent_key => $profiles){
      foreach((array)$profiles as $profile_id => $qty){
        $qty = max(0, (int)$qty);
        $child_key = $this->find_child_accessory_key($parent_key, (int)$profile_id);
        if ( $child_key && $qty>0 ){
          WC()->cart->set_quantity($child_key, $qty, true);
        } elseif ( $child_key && $qty==0 ){
          WC()->cart->remove_cart_item($child_key);
        } elseif ( ! $child_key && $qty>0 ){
          $this->add_child_accessory_item($parent_key, (int)$profile_id, $qty);
        }
      }
    }

    $this->adding_accessory = false; // Desactivar flag
  }

  private function find_child_accessory_key($parent_key, $profile_id){
    foreach(WC()->cart->get_cart() as $key => $item){
      if ( ! empty($item['mad_acc'])
        && $item['mad_acc']['_mad_acc_parent_key'] === $parent_key
        && (int)$item['mad_acc']['_mad_acc_profile_id'] === (int)$profile_id ){
        return $key;
      }
    }
    return null;
  }

  /* ============================================================
   * Visual y pedido
   * ============================================================
   */

  public function decorate_accessory_cart_name($name, $cart_item, $key){
    if ( empty($cart_item['mad_acc']) ) return $name;
    $parent_name = $cart_item['mad_acc']['_mad_acc_parent_name'] ?? '';
    if ( $parent_name ){
      $name .= sprintf(
        ' <small style="opacity:.7">(%s %s)</small>',
        esc_html__('para:','mad-suite'),
        esc_html($parent_name)
      );
    }
    return $name;
  }

  public function show_accessory_link_meta($item_data, $cart_item){
    if ( ! empty($cart_item['mad_acc']) && ! empty($cart_item['mad_acc']['_mad_acc_parent_name']) ){
      $item_data[] = [
        'key' => __('Accesorio de','mad-suite'),
        'display' => esc_html($cart_item['mad_acc']['_mad_acc_parent_name']),
      ];
    }
    return $item_data;
  }

  public function cascade_remove_children($cart_item_key, $cart){
    // Si se elimina un ítem principal, eliminar sus accesorios
    $removed = $cart->removed_cart_contents[$cart_item_key] ?? null;
    $is_accessory = $removed && ! empty($removed['mad_acc']);
    if ( $is_accessory ) return; // Si quitan el accesorio, no tocamos el padre

    foreach($cart->get_cart() as $key => $item){
      if ( ! empty($item['mad_acc']) && $item['mad_acc']['_mad_acc_parent_key'] === $cart_item_key ){
        $cart->remove_cart_item($key);
      }
    }
  }

  public function transfer_meta_to_order($item, $cart_item_key, $values, $order){
    if ( empty($values['mad_acc']) ) return;
    $item->add_meta_data('_mad_acc_parent_key',  $values['mad_acc']['_mad_acc_parent_key'] ?? '');
    $item->add_meta_data('_mad_acc_profile_id',  $values['mad_acc']['_mad_acc_profile_id'] ?? 0);
    $item->add_meta_data('_mad_acc_parent_name', $values['mad_acc']['_mad_acc_parent_name'] ?? '');
  }

  /* ============================================================
   * Matching y utilidades
   * ============================================================
   */

  private function matching_profiles_for_product($product_id, $args=[]){
    $place = $args['place'] ?? null; // 'pdp' | 'cart' | null

    static $cache; if ($cache===null) $cache=[];
    $key = 'all';
    if (!isset($cache[$key])){
      $q = new WP_Query([
        'post_type' => $this->cpt,
        'post_status' => 'publish',
        'posts_per_page' => 200,
        'no_found_rows' => true,
        'orderby' => 'date',
        'order' => 'DESC',
      ]);
      $cache[$key] = $q->have_posts() ? $q->posts : [];
      wp_reset_postdata();
    }
    $posts = $cache[$key];
    if ( ! $posts ) return [];

    $cats = wp_get_post_terms($product_id, 'product_cat', ['fields'=>'ids']);
    $tags = wp_get_post_terms($product_id, 'product_tag', ['fields'=>'ids']);

    $out = [];
    foreach($posts as $p){
      $pid = $p->ID;

      if ($place==='pdp' && ! get_post_meta($pid,'_mad_acc_show_on_pdp',true)) continue;
      if ($place==='cart' && ! get_post_meta($pid,'_mad_acc_show_in_cart',true)) continue;

      $scope_prods = (array) get_post_meta($pid,'_mad_acc_scope_products',true);
      $scope_cats  = (array) get_post_meta($pid,'_mad_acc_scope_cats',true);
      $scope_tags  = (array) get_post_meta($pid,'_mad_acc_scope_tags',true);

      $ok = false;
      if ($scope_prods && in_array((int)$product_id, array_map('intval',$scope_prods), true)) $ok = true;
      if ( !$ok && $scope_cats && array_intersect(array_map('intval',$cats), array_map('intval',$scope_cats)) ) $ok = true;
      if ( !$ok && $scope_tags && array_intersect(array_map('intval',$tags), array_map('intval',$scope_tags)) ) $ok = true;

      if ($ok) $out[] = $p;
    }
    return $out;
  }

};