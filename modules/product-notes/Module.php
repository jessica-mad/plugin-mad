<?php
/**
 * Módulo: Product Notes
 * Añade un campo de nota por producto y lo propaga a carrito, checkout y pedido.
 * Permite activar por defecto por categorías/etiquetas.
 * 
 * @package MAD_Suite
 * @subpackage Product_Notes
 */

if ( ! defined('ABSPATH') ) exit;

return new class($core) implements MAD_Suite_Module {

  private $core;
  private $slug = 'product-notes';

  public function __construct( $core ) {
    $this->core = $core;
  }

  /* ========== Implementación de MAD_Suite_Module ========== */

  public function slug() {
    return $this->slug;
  }

  public function title() {
    return __('Agregar notas a productos', 'mad-suite');
  }

  public function menu_label() {
    return __('Notas en productos', 'mad-suite');
  }

  public function menu_slug() {
    return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
  }

  public function description() {
    return __('Permite agregar notas personalizadas a productos específicos. Las notas se muestran en carrito, checkout y pedidos.', 'mad-suite');
  }

  public function required_plugins() {
    return [
      'WooCommerce' => 'woocommerce/woocommerce.php',
    ];
  }

  public function init() {
    // Solo ejecutar si WooCommerce está activo
    if ( ! class_exists('WooCommerce') ) {
      add_action('admin_notices', function(){
        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('El módulo "Notas en productos" requiere WooCommerce activo.', 'mad-suite');
        echo '</p></div>';
      });
      return;
    }

    // Front: render (autorender hook) — se puede desactivar en ajustes
    $settings = $this->get_settings();
    if ( ! empty($settings['autorender']) ) {
      add_action('woocommerce_before_add_to_cart_button', [$this, 'render_field_hook']);
    }

    // Validación + guardado + display + traspaso a pedido
    add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_before_add'], 10, 4);
    add_filter('woocommerce_add_cart_item_data', [$this, 'save_cart_item_data'], 10, 3);
    add_filter('woocommerce_get_item_data', [$this, 'display_item_data'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_meta_to_order_item'], 10, 4);

    // Shortcode
    add_shortcode('product_note_field', [$this, 'shortcode_field']);
  }

  public function admin_init() {
    $option_key = MAD_Suite_Core::option_key( $this->slug );
    register_setting( $this->menu_slug(), $option_key );

    add_settings_section(
      'madpn_main',
      __('Ajustes generales', 'mad-suite'),
      function(){
        echo '<p>' . esc_html__('Define en qué categorías o etiquetas se activará por defecto el campo de nota (puedes seguir ajustándolo por producto con ACF).', 'mad-suite') . '</p>';
      },
      $this->menu_slug()
    );

    // Categorías
    add_settings_field(
      'madpn_default_cats',
      __('Categorías por defecto', 'mad-suite'),
      [$this, 'field_cats_callback'],
      $this->menu_slug(),
      'madpn_main'
    );

    // Etiquetas
    add_settings_field(
      'madpn_default_tags',
      __('Etiquetas por defecto', 'mad-suite'),
      [$this, 'field_tags_callback'],
      $this->menu_slug(),
      'madpn_main'
    );

    // Autorender
    add_settings_field(
      'madpn_autorender',
      __('Mostrar campo automáticamente', 'mad-suite'),
      [$this, 'field_autorender_callback'],
      $this->menu_slug(),
      'madpn_main'
    );
  }

  public function render_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html( $this->title() ) . '</h1>';
    echo '<form method="post" action="options.php">';
      settings_fields( $this->menu_slug() );
      do_settings_sections( $this->menu_slug() );
      submit_button();
    echo '</form>';

    echo '<hr/><h2>' . esc_html__('Shortcode', 'mad-suite') . '</h2>';
    echo '<p><code>[product_note_field]</code> ';
    echo esc_html__('Opcionalmente: ', 'mad-suite');
    echo '<code>id</code>, <code>label</code>, <code>required</code> (0/1), <code>maxlength</code>.';
    echo '</p>';

    echo '<p><strong>' . esc_html__('Campos ACF (opcional):', 'mad-suite') . '</strong> ';
    echo esc_html__('Si tienes ACF, el plugin respetará ', 'mad-suite');
    echo '<code>enable_note</code>, <code>note_label</code>, <code>note_required</code>, <code>note_maxlength</code>.';
    echo '</p>';

    echo '</div>';
  }

  /* ========== Helpers de ajustes ========== */

  private function get_settings() {
    $defaults = [
      'default_cats'  => [], // term_ids product_cat
      'default_tags'  => [], // term_ids product_tag
      'autorender'    => 1,  // 1 = imprime antes del botón
    ];
    $option_key = MAD_Suite_Core::option_key( $this->slug );
    $opt = get_option( $option_key, [] );
    return wp_parse_args( $opt, $defaults );
  }

  private function is_acf_active() {
    return function_exists('get_field');
  }

  private function product_belongs_to_defaults( $product_id ) {
    $s = $this->get_settings();

    $in_cat = false;
    if ( ! empty($s['default_cats']) ) {
      $in_cat = has_term( $s['default_cats'], 'product_cat', $product_id );
    }

    $in_tag = false;
    if ( ! empty($s['default_tags']) ) {
      $in_tag = has_term( $s['default_tags'], 'product_tag', $product_id );
    }

    return ( $in_cat || $in_tag );
  }

  /**
   * Lógica final de "activado?":
   * Si ACF existe:
   *   - Si enable_note está activo en el producto => true
   *   - Si no, revisar si el producto cae en categorías/etiquetas por defecto => true/false
   * Si ACF no existe:
   *   - Depender solo de categorías/etiquetas por defecto (o permitir siempre con shortcode)
   */
  private function is_enabled_for_product( $product_id ) {
    if ( $this->is_acf_active() ) {
      $acf_enable = (bool) get_field('enable_note', $product_id);
      if ( $acf_enable ) return true;
      return $this->product_belongs_to_defaults( $product_id );
    } else {
      return $this->product_belongs_to_defaults( $product_id );
    }
  }

  private function get_label_required_maxlength( $product_id ) {
    $label     = __('Tu nota para este producto', 'mad-suite');
    $required  = false;
    $maxlength = 0;

    if ( $this->is_acf_active() ) {
      $acf_label = get_field('note_label', $product_id);
      if ( $acf_label ) $label = $acf_label;

      $required = (bool) get_field('note_required', $product_id);
      $m = (int) ( get_field('note_maxlength', $product_id) ?: 0 );
      if ( $m > 0 ) $maxlength = $m;
    }

    return [$label, $required, $maxlength];
  }

  /* ========== Frontend render ========== */

  public function render_field_hook() {
    global $product;
    if ( ! $product ) return;

    $product_id = $product->get_id();
    if ( ! $this->is_enabled_for_product($product_id) ) return;

    list($label, $required, $maxlength) = $this->get_label_required_maxlength($product_id);

    // Nonce
    wp_nonce_field( 'product_note_field', 'product_note_nonce' );

    echo '<div class="product-note-field" style="margin:12px 0;">';
    echo '<label for="product_note" style="display:block;margin-bottom:6px;">' . esc_html($label);
    if ( $required ) echo ' <span style="color:#d63638">*</span>';
    echo '</label>';
    printf(
      '<textarea id="product_note" name="product_note" rows="4" %s style="width:100%%;max-width:100%%;"></textarea>',
      $maxlength > 0 ? 'maxlength="'.intval($maxlength).'"' : ''
    );
    echo '</div>';
  }

  /* ========== Validación / guardado / display / pedido ========== */

  public function validate_before_add( $passed, $product_id, $quantity, $variation_id = 0 ) {
    // No romper flujo si no hay nonce (p.ej. productos sin campo)
    if ( ! isset($_POST['product_note_nonce']) || ! wp_verify_nonce( $_POST['product_note_nonce'], 'product_note_field' ) ) {
      return $passed;
    }

    $target_id = $variation_id ?: $product_id;

    // Solo exigir si el campo aplica al producto
    if ( ! $this->is_enabled_for_product($product_id) ) return $passed;

    list($_label, $required, $maxlength) = $this->get_label_required_maxlength($product_id);

    $note = isset($_POST['product_note']) ? trim( wp_unslash($_POST['product_note']) ) : '';

    if ( $required && $note === '' ) {
      wc_add_notice( __('Por favor, escribe la nota para este producto.', 'mad-suite'), 'error' );
      return false;
    }
    if ( $maxlength > 0 && mb_strlen($note) > $maxlength ) {
      wc_add_notice( sprintf( __('La nota supera el máximo de %d caracteres.', 'mad-suite'), $maxlength ), 'error' );
      return false;
    }
    return $passed;
  }

  public function save_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
    if ( isset($_POST['product_note']) && $_POST['product_note'] !== '' ) {
      $cart_item_data['product_note'] = sanitize_textarea_field( wp_unslash($_POST['product_note']) );
    }
    return $cart_item_data;
  }

  public function display_item_data( $item_data, $cart_item ) {
    if ( isset($cart_item['product_note']) && $cart_item['product_note'] !== '' ) {
      $item_data[] = [
        'name'    => __('Nota', 'mad-suite'),
        'value'   => esc_html( $cart_item['product_note'] ),
        'display' => nl2br( esc_html( $cart_item['product_note'] ) ),
      ];
    }
    return $item_data;
  }

  public function add_meta_to_order_item( $item, $cart_item_key, $values, $order ) {
    if ( isset($values['product_note']) && $values['product_note'] !== '' ) {
      $item->add_meta_data( __('Nota del producto', 'mad-suite'), $values['product_note'], true );
    }
  }

  /* ========== Shortcode ========== */

  public function shortcode_field( $atts = [] ) {
    $atts = shortcode_atts([
      'id'        => 0,
      'label'     => '',
      'required'  => '',
      'maxlength' => '',
    ], $atts, 'product_note_field');

    // Contexto producto
    $product_id = absint($atts['id']);
    if ( ! $product_id ) {
      global $product;
      if ( $product instanceof WC_Product ) $product_id = $product->get_id();
    }
    if ( ! $product_id ) return '';

    // Chequear si aplica al producto (si no ACF, permiten defaults por taxonomías)
    if ( ! $this->is_enabled_for_product($product_id) ) return '';

    // Label/required/maxlength
    list($acf_label, $acf_required, $acf_maxlength) = $this->get_label_required_maxlength($product_id);

    $label     = $atts['label']     !== '' ? $atts['label']     : ( $acf_label ?: __('Tu nota para este producto', 'mad-suite') );
    $required  = $atts['required']  !== '' ? (bool) intval($atts['required']) : (bool) $acf_required;
    $maxlength = $atts['maxlength'] !== '' ? max(0, intval($atts['maxlength'])) : (int) $acf_maxlength;

    static $printed_nonce = false;
    $nonce_html = '';
    if ( ! $printed_nonce ) {
      $nonce_html = wp_nonce_field( 'product_note_field', 'product_note_nonce', true, false );
      $printed_nonce = true;
    }

    ob_start(); ?>
      <div class="product-note-field" style="margin:12px 0;">
        <?php echo $nonce_html; ?>
        <label for="product_note" style="display:block;margin-bottom:6px;">
          <?php echo esc_html($label); ?>
          <?php if ( $required ): ?><span style="color:#d63638">*</span><?php endif; ?>
        </label>
        <textarea id="product_note" name="product_note" rows="4"
          <?php echo $maxlength > 0 ? 'maxlength="'.intval($maxlength).'"' : ''; ?>
          style="width:100%;max-width:100%;"></textarea>
      </div>
    <?php
    return ob_get_clean();
  }

  /* ========== Campos de ajustes (callbacks) ========== */

  public function field_cats_callback() {
    $s = $this->get_settings();
    $selected = array_map('intval', (array)$s['default_cats']);
    $option_key = MAD_Suite_Core::option_key( $this->slug );
    
    // Obtener categorías con jerarquía
    $terms = get_terms([
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'orderby'    => 'name',
      'parent'     => 0  // Solo padres primero
    ]);
    
    echo '<div id="mad-categories-wrapper" style="max-height:300px;overflow:auto;border:1px solid #ddd;padding:8px;background:#fafafa;">';
    
    if ( ! is_wp_error($terms) && ! empty($terms) ) {
      foreach ( $terms as $parent ) {
        // Categoría padre
        $this->render_category_checkbox( $parent, $selected, $option_key, 0 );
        
        // Buscar hijas recursivamente
        $this->render_child_categories( $parent->term_id, $selected, $option_key, 1 );
      }
    } else {
      echo '<em>' . esc_html__('No se pudieron cargar las categorías.', 'mad-suite') . '</em>';
    }
    
    echo '</div>';
    echo '<p class="description">' . esc_html__('Las categorías hijas aparecen indentadas bajo sus padres. Al marcar un padre se seleccionan automáticamente todas sus hijas.', 'mad-suite') . '</p>';
    
    // JavaScript para manejar la cascada
    $this->render_cascade_script();
  }

  /**
   * Renderiza un checkbox de categoría con el nivel de indentación
   */
  private function render_category_checkbox( $term, $selected, $option_key, $level ) {
    $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
    $style = 'display:block;margin-bottom:4px;';
    
    // Estilo especial para padres
    if ( $level === 0 ) {
      $style .= 'font-weight:600;margin-top:8px;';
    }
    
    printf(
      '<label style="%5$s">%6$s<input type="checkbox" name="%1$s[default_cats][]" value="%2$d" %3$s /> %4$s</label>',
      esc_attr($option_key),
      esc_attr($term->term_id),
      checked( in_array($term->term_id, $selected, true), true, false ),
      esc_html($term->name),
      $style,
      $indent
    );
  }

  /**
   * Renderiza recursivamente las categorías hijas
   */
  private function render_child_categories( $parent_id, $selected, $option_key, $level ) {
    $children = get_terms([
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
      'orderby'    => 'name',
      'parent'     => $parent_id
    ]);
    
    if ( ! is_wp_error($children) && ! empty($children) ) {
      foreach ( $children as $child ) {
        $this->render_category_checkbox( $child, $selected, $option_key, $level );
        
        // Buscar nietas (recursión)
        $this->render_child_categories( $child->term_id, $selected, $option_key, $level + 1 );
      }
    }
  }

  public function field_tags_callback() {
    $s = $this->get_settings();
    $selected = array_map('intval', (array)$s['default_tags']);
    $terms = get_terms(['taxonomy'=>'product_tag','hide_empty'=>false]);
    $option_key = MAD_Suite_Core::option_key( $this->slug );
    
    echo '<div style="max-height:240px;overflow:auto;border:1px solid #ddd;padding:8px;">';
    if ( ! is_wp_error($terms) ) {
      foreach ( $terms as $t ) {
        printf(
          '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%1$s[default_tags][]" value="%2$d" %3$s /> %4$s</label>',
          esc_attr($option_key),
          esc_attr($t->term_id),
          checked( in_array($t->term_id, $selected, true), true, false ),
          esc_html($t->name)
        );
      }
    } else {
      echo '<em>' . esc_html__('No se pudieron cargar las etiquetas.', 'mad-suite') . '</em>';
    }
    echo '</div>';
  }

  public function field_autorender_callback() {
    $s = $this->get_settings();
    $option_key = MAD_Suite_Core::option_key( $this->slug );
    
    printf(
      '<label><input type="checkbox" name="%1$s[autorender]" value="1" %2$s /> %3$s</label>',
      esc_attr($option_key),
      checked( (int)$s['autorender'], 1, false ),
      esc_html__('Imprimir el textarea automáticamente antes del botón "Añadir al carrito". (Si lo desmarcas, usa el shortcode)', 'mad-suite')
    );
  }

};