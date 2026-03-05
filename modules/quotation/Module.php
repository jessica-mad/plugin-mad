<?php
/**
 * Módulo: Cotizaciones (Quotation)
 *
 * Sistema de cotizaciones para WooCommerce:
 * - Oculta precios para invitados y usuarios sin rol "professional"
 * - El carrito WC actúa como lista de cotización (sin precios)
 * - El checkout WC recoge solo la dirección de envío (sin pago)
 * - Se crean pedidos WC con estado "Solicitud de cotización"
 * - El admin aprueba y envía cotización al cliente con precios reales + enlace de pago
 * - El cliente revisa, selecciona productos y paga con los métodos WC configurados
 * - Usuarios con rol "professional": ven precio profesional y compran con flujo WC normal
 *
 * @package MAD_Suite
 * @subpackage Quotation
 */

if ( ! defined('ABSPATH') ) exit;

// Archivos sin dependencia de WooCommerce: siempre seguros de incluir.
require_once __DIR__ . '/includes/Logger.php';
require_once __DIR__ . '/includes/RoleManager.php';

return new class( $core ) implements MAD_Suite_Module {

    private $core;
    private $slug         = 'quotation';
    private $logger;
    private $role_manager;

    // Objetos que requieren WooCommerce activo — se crean en init().
    private $price_ctrl  = null;
    private $cart_ux     = null;
    private $orders      = null;
    private $payment     = null;

    public function __construct( $core ) {
        $this->core         = $core;
        $this->logger       = new MADSuite\Modules\Quotation\Logger('quotation');
        $this->role_manager = new MADSuite\Modules\Quotation\RoleManager( $this->logger );
    }

    /* ========== Implementación MAD_Suite_Module ========== */

    public function slug()       { return $this->slug; }
    public function title()      { return __('Cotizaciones', 'mad-suite'); }
    public function menu_label() { return __('Cotizaciones', 'mad-suite'); }
    public function menu_slug()  { return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug; }

    public function description() {
        return __('Sistema de cotizaciones para WooCommerce: oculta precios, gestiona solicitudes por email y permite a profesionales comprar con precio especial.', 'mad-suite');
    }

    public function required_plugins() {
        return [ 'WooCommerce' => 'woocommerce/woocommerce.php' ];
    }

    public function init() {
        if ( ! class_exists('WooCommerce') ) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('El módulo "Cotizaciones" requiere WooCommerce activo.', 'mad-suite');
                echo '</p></div>';
            });
            return;
        }

        // Incluir archivos que dependen de WooCommerce (clases WC_Email, WC_Payment_Gateway…)
        // solo cuando WC está disponible.
        require_once __DIR__ . '/includes/PriceController.php';
        require_once __DIR__ . '/includes/QuoteCartUX.php';
        require_once __DIR__ . '/includes/QuoteOrders.php';
        require_once __DIR__ . '/includes/QuoteEmailAdmin.php';
        require_once __DIR__ . '/includes/QuoteEmailClient.php';
        require_once __DIR__ . '/includes/QuotePayment.php';

        // Instanciar componentes que dependen de WC
        $this->price_ctrl = new MADSuite\Modules\Quotation\PriceController( $this->role_manager, $this );
        $this->cart_ux    = new MADSuite\Modules\Quotation\QuoteCartUX( $this->role_manager, $this );
        $this->orders     = new MADSuite\Modules\Quotation\QuoteOrders( $this->role_manager, $this, $this->logger );
        $this->payment    = new MADSuite\Modules\Quotation\QuotePayment( $this->role_manager, $this, $this->logger );

        // Asegurar que el rol existe en cada carga
        $this->role_manager->create_role_if_not_exists();

        // Inicializar sub-componentes
        $this->price_ctrl->init();
        $this->cart_ux->init();
        $this->orders->init();
        $this->payment->init();

        // Shortcode página de revisión/pago del cliente
        add_shortcode( 'mad_quote_payment', [ $this->payment, 'render' ] );

        // Assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Mensaje personalizado en la página de "pedido recibido" para cotizaciones
        add_filter( 'woocommerce_thankyou_order_received_text', [ $this, 'quote_thankyou_text' ], 10, 2 );
    }

    public function admin_init() {
        if ( ! class_exists('WooCommerce') ) return;
        if ( ! $this->orders ) return; // init() no se ejecutó (WC no estaba activo)

        // Ajustes del módulo
        $option_key = MAD_Suite_Core::option_key( $this->slug );
        register_setting( $this->menu_slug(), $option_key );

        add_settings_section(
            'madq_general',
            __('Ajustes generales', 'mad-suite'),
            null,
            $this->menu_slug()
        );

        $fields = [
            [ 'madq_admin_email',    __('Email del administrador',          'mad-suite'), 'admin_email',    __('Email que recibirá las solicitudes de cotización.',   'mad-suite') ],
            [ 'madq_text_add_btn',   __('Texto botón "Añadir"',             'mad-suite'), 'text_add_btn',   __('Texto del botón en producto (modo cotización).',      'mad-suite') ],
            [ 'madq_text_hidden',    __('Texto precio oculto',              'mad-suite'), 'text_hidden_price', __('Texto que aparece donde iría el precio.',           'mad-suite') ],
            [ 'madq_text_view_cart', __('Texto "Ver mi lista"',             'mad-suite'), 'text_view_cart', __('Reemplaza "Ver carrito" en el mini-carrito.',         'mad-suite') ],
            [ 'madq_text_proceed',   __('Texto "Finalizar lista"',          'mad-suite'), 'text_proceed',   __('Reemplaza "Finalizar compra" en el carrito.',         'mad-suite') ],
            [ 'madq_text_order_btn', __('Texto botón submit checkout',      'mad-suite'), 'text_place_order', __('Reemplaza "Realizar pedido" en el checkout.',       'mad-suite') ],
        ];

        foreach ( $fields as [ $id, $title, $key, $description ] ) {
            $option_key_local = $option_key;
            $key_local = $key;
            add_settings_field(
                $id,
                $title,
                function() use ( $option_key_local, $key_local, $description ) {
                    $s   = $this->get_settings();
                    $val = $s[ $key_local ] ?? '';
                    printf(
                        '<input type="text" name="%s[%s]" value="%s" class="regular-text" /><p class="description">%s</p>',
                        esc_attr( $option_key_local ),
                        esc_attr( $key_local ),
                        esc_attr( $val ),
                        esc_html( $description )
                    );
                },
                $this->menu_slug(),
                'madq_general'
            );
        }

        // Selector de página de revisión/pago
        add_settings_field(
            'madq_payment_page',
            __('Página de revisión/pago', 'mad-suite'),
            [ $this, 'field_payment_page_callback' ],
            $this->menu_slug(),
            'madq_general'
        );

        // Auto-crear página de revisión/pago si aún no existe
        $this->maybe_create_pages();

        // Meta precio profesional en productos
        $this->role_manager->register_product_meta_hooks();

        // Admin de pedidos (botón "Enviar cotización", AJAX)
        $this->orders->admin_init();
    }

    public function render_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $this->title() ) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( $this->menu_slug() );
        do_settings_sections( $this->menu_slug() );
        submit_button();
        echo '</form>';

        echo '<hr/>';
        echo '<h2>' . esc_html__('Shortcodes disponibles', 'mad-suite') . '</h2>';
        echo '<ul>';
        echo '<li><code>[mad_quote_payment]</code> — ' . esc_html__('Página donde el cliente revisa y confirma su cotización para proceder al pago. Debe añadirse en la página configurada arriba.', 'mad-suite') . '</li>';
        echo '</ul>';

        echo '<hr/>';
        echo '<h2>' . esc_html__('Estados de pedido', 'mad-suite') . '</h2>';
        echo '<ul>';
        echo '<li><strong>' . esc_html__('Solicitud de cotización', 'mad-suite') . '</strong>: ' . esc_html__('El cliente ha enviado su lista de productos para cotización.', 'mad-suite') . '</li>';
        echo '<li><strong>' . esc_html__('Cotización enviada', 'mad-suite') . '</strong>: ' . esc_html__('El administrador ha enviado los precios al cliente.', 'mad-suite') . '</li>';
        echo '</ul>';
        echo '</div>';
    }

    /* ========== Assets ========== */

    public function enqueue_assets() {
        if ( ! function_exists('WC') ) return;

        $url = plugin_dir_url( __FILE__ ) . 'assets/';
        $ver = '1.0.0';

        // quote-cart.js: siempre activo para no profesionales
        if ( ! $this->role_manager->is_professional() ) {
            wp_enqueue_script(
                'mad-quote-cart',
                $url . 'quote-cart.js',
                [ 'jquery' ],
                $ver,
                true
            );
            wp_localize_script( 'mad-quote-cart', 'madQuote', [
                'isQuoteMode' => '1',
                'strings'     => [
                    'viewCart' => $this->get_setting('text_view_cart', __('Ver mi lista de cotización', 'mad-suite')),
                    'proceed'  => $this->get_setting('text_proceed', __('Finalizar lista de cotización', 'mad-suite')),
                ],
            ] );
        }

        // quote-payment.js: solo en la página de revisión/pago
        $s = $this->get_settings();
        if ( ! empty( $s['payment_page_id'] ) && is_page( (int) $s['payment_page_id'] ) ) {
            wp_enqueue_script(
                'mad-quote-payment',
                $url . 'quote-payment.js',
                [ 'jquery' ],
                $ver,
                true
            );
            wp_localize_script( 'mad-quote-payment', 'madQuotePayment', [
                'ajaxurl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('mad_quote_confirm'),
                'order_id'  => absint( $_GET['order_id']  ?? 0 ),
                'order_key' => sanitize_text_field( wp_unslash( $_GET['order_key'] ?? '' ) ),
                'strings'   => [
                    'selectOne' => __('Debes seleccionar al menos un producto.', 'mad-suite'),
                    'error'     => __('Error al procesar. Inténtalo de nuevo.', 'mad-suite'),
                ],
            ] );
        }
    }

    /* ========== Auto-crear páginas ========== */

    public function maybe_create_pages() {
        $s = $this->get_settings();
        if ( ! empty( $s['payment_page_id'] ) && get_post( (int) $s['payment_page_id'] ) ) return;

        $page_id = wp_insert_post( [
            'post_title'   => __('Revisar Cotización', 'mad-suite'),
            'post_name'    => 'revisar-cotizacion',
            'post_content' => '[mad_quote_payment]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            $option_key = MAD_Suite_Core::option_key( $this->slug );
            $opts = get_option( $option_key, [] );
            $opts['payment_page_id'] = $page_id;
            update_option( $option_key, $opts );
            $this->logger->info( "Página de cotización auto-creada: ID {$page_id}" );
        }
    }

    /* ========== Mensaje thank-you personalizado ========== */

    public function quote_thankyou_text( string $text, ?\WC_Order $order ): string {
        if ( ! $order || $order->get_meta('_mad_is_quote') !== '1' ) return $text;
        return __('Tu solicitud de cotización ha sido recibida correctamente. Revisaremos tu pedido y te enviaremos los precios por email en breve.', 'mad-suite');
    }

    /* ========== Helpers de ajustes ========== */

    public function get_settings(): array {
        $defaults = [
            'admin_email'      => get_option('admin_email'),
            'text_add_btn'     => __('Añadir a mi lista de cotización', 'mad-suite'),
            'text_hidden_price'=> __('Consulta el precio', 'mad-suite'),
            'text_view_cart'   => __('Ver mi lista de cotización', 'mad-suite'),
            'text_proceed'     => __('Finalizar lista de cotización', 'mad-suite'),
            'text_place_order' => __('Enviar solicitud de cotización', 'mad-suite'),
            'payment_page_id'  => 0,
        ];
        $opt = get_option( MAD_Suite_Core::option_key( $this->slug ), [] );
        return wp_parse_args( is_array( $opt ) ? $opt : [], $defaults );
    }

    public function get_setting( string $key, string $fallback = '' ): string {
        $s = $this->get_settings();
        return (string) ( $s[ $key ] ?? $fallback );
    }

    /* ========== Callbacks de campos de ajustes ========== */

    public function field_payment_page_callback() {
        $s       = $this->get_settings();
        $page_id = (int) ( $s['payment_page_id'] ?? 0 );
        $pages   = get_pages();
        $option_key = MAD_Suite_Core::option_key( $this->slug );
        echo '<select name="' . esc_attr( $option_key ) . '[payment_page_id]">';
        echo '<option value="0">' . esc_html__('— Selecciona una página —', 'mad-suite') . '</option>';
        foreach ( $pages as $page ) {
            printf(
                '<option value="%d"%s>%s</option>',
                $page->ID,
                selected( $page_id, $page->ID, false ),
                esc_html( $page->post_title )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Página donde se coloca el shortcode [mad_quote_payment]. Se auto-crea si está vacío.', 'mad-suite') . '</p>';
    }
};
