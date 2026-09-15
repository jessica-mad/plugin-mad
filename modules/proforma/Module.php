<?php
/**
 * Módulo: MAD Proforma
 *
 * Complemento para "PDF Invoices & Packing Slips for WooCommerce" (plugin
 * gratuito) que agrega un tipo de documento "Factura proforma": se genera
 * y se envía por email automáticamente en cuanto un pedido llega a la
 * página de confirmación en estado "pendiente"/"en espera" (transferencia
 * bancaria todavía sin comprobante) — reutilizando la misma plantilla y
 * datos de empresa que ya tienen configurados en ese plugin para la
 * factura real.
 *
 * Numeración propia (prefijo "PRO-" + número de pedido), separada de la
 * secuencia fiscal de facturas reales: una proforma no es un documento
 * fiscal y no debe interferir con esa numeración correlativa.
 *
 * IMPORTANTE (compatibilidad): este complemento se engancha a clases
 * internas de "PDF Invoices & Packing Slips for WooCommerce" que no están
 * documentadas como API pública estable en todas sus versiones. Si la
 * clase base no existe (plugin inactivo o versión incompatible), el
 * módulo lo detecta y avisa en vez de fallar — pero si la plantilla PDF
 * sale rota, es la primera zona a revisar (ver includes/class-mad-proforma-document.php).
 *
 * @package MAD_Suite/Proforma
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MAD_PROFORMA_DIR',           plugin_dir_path( __FILE__ ) );
define( 'MAD_PROFORMA_URL',           plugin_dir_url( __FILE__ ) );
define( 'MAD_PROFORMA_TEMPLATE_PATH', MAD_PROFORMA_DIR . 'templates/' );

return new class( MAD_Suite_Core::instance() ) implements MAD_Suite_Module {

    private $core;
    private $slug       = 'proforma';
    private $compatible = false;

    public function __construct( $core ) {
        $this->core = $core;
    }

    public function slug()       { return $this->slug; }
    public function title()      { return __( 'Facturas Proforma', 'mad-suite' ); }
    public function menu_label() { return __( 'Proforma', 'mad-suite' ); }
    public function menu_slug()  { return 'mad-' . $this->slug; }

    public function description() {
        return __( 'Genera y envía automáticamente una factura proforma (no fiscal) cuando un pedido queda a la espera de pago por transferencia — reutilizando la plantilla de PDF Invoices & Packing Slips for WooCommerce.', 'mad-suite' );
    }

    public function required_plugins() {
        return [
            'WooCommerce' => 'woocommerce/woocommerce.php',
            'PDF Invoices & Packing Slips for WooCommerce' => 'woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packing-slips.php',
        ];
    }

    /* ================================================================ */
    /*  init()                                                           */
    /* ================================================================ */

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        // Nunca asumir que la clase interna del plugin de facturas existe —
        // si no está (plugin inactivo o versión con una API distinta a la
        // que soporta este complemento), avisamos y no activamos nada más.
        $this->compatible = class_exists( 'WPO_WCPDF_Invoice' );
        if ( ! $this->compatible ) {
            add_action( 'admin_notices', [ $this, 'incompatible_notice' ] );
            return;
        }

        require_once MAD_PROFORMA_DIR . 'includes/class-mad-proforma-document.php';
        add_filter( 'wpo_wcpdf_document_classes', [ $this, 'register_document_class' ] );
        add_filter( 'woocommerce_email_classes',  [ $this, 'register_email_class' ] );

        $settings = $this->get_settings();
        if ( ! empty( $settings['auto_send'] ) ) {
            add_action( 'woocommerce_thankyou', [ $this, 'maybe_send_proforma' ], 20 );
        }
        add_action( 'woocommerce_thankyou', [ $this, 'render_download_link' ], 21 );

        add_action( 'template_redirect', [ $this, 'maybe_handle_download' ] );
        add_action( 'add_meta_boxes',    [ $this, 'register_meta_box' ] );
    }

    public function admin_init() {
        register_setting( $this->menu_slug(), MAD_Suite_Core::option_key( $this->slug ), [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => [],
        ] );
    }

    public function incompatible_notice(): void {
        echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf(
            /* translators: %s: class name */
            __( 'MAD Suite – El módulo <strong>Facturas Proforma</strong> no encontró la clase <code>%s</code> de "PDF Invoices & Packing Slips for WooCommerce". Confirmá que el plugin esté activo y, si el problema sigue, puede que esta versión use una API interna distinta a la que soporta este complemento.', 'mad-suite' ),
            'WPO_WCPDF_Invoice'
        ) ) . '</p></div>';
    }

    /* ================================================================ */
    /*  Ajustes                                                           */
    /* ================================================================ */

    private function get_settings(): array {
        $opts = get_option( MAD_Suite_Core::option_key( $this->slug ), [] );
        return wp_parse_args( is_array( $opts ) ? $opts : [], [
            'auto_send' => true,
        ] );
    }

    public function sanitize_settings( $input ): array {
        return [ 'auto_send' => ! empty( $input['auto_send'] ) ];
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'mad-suite' ) );
        }
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

            <?php if ( ! $this->compatible ) : ?>
                <div class="notice notice-error"><p>
                    <?php esc_html_e( 'No se detectó una versión compatible de "PDF Invoices & Packing Slips for WooCommerce". El módulo está inactivo hasta resolverlo.', 'mad-suite' ); ?>
                </p></div>
            <?php else : ?>
                <div class="notice notice-success"><p>
                    <?php esc_html_e( '"PDF Invoices & Packing Slips for WooCommerce" detectado correctamente.', 'mad-suite' ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( $this->menu_slug() ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Envío automático', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( MAD_Suite_Core::option_key( $this->slug ) ); ?>[auto_send]" value="1" <?php checked( ! empty( $settings['auto_send'] ) ); ?>>
                                <?php esc_html_e( 'Enviar la proforma por email apenas el pedido llega a la página de confirmación en espera de pago (pendiente / en espera).', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to WooCommerce Emails settings */
                    wp_kses_post( __( 'El asunto, encabezado y texto del email son editables desde %s → "Factura proforma".', 'mad-suite' ) ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce → Ajustes → Emails', 'mad-suite' ) . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /* ================================================================ */
    /*  Documento / email                                                 */
    /* ================================================================ */

    public function register_document_class( array $classes ): array {
        $classes['proforma'] = 'MAD_Proforma_Document';
        return $classes;
    }

    public function register_email_class( array $classes ): array {
        require_once MAD_PROFORMA_DIR . 'includes/emails/class-mad-proforma-email.php';
        $classes['MAD_Proforma_Email'] = new MAD_Proforma_Email();
        return $classes;
    }

    /**
     * Genera el PDF de la proforma para un pedido y lo guarda en uploads.
     * Sobrescribe el mismo archivo en cada llamada (nombre fijo por
     * pedido) para no acumular copias viejas.
     *
     * @return string|null Ruta local del PDF, o null si no se pudo generar.
     */
    private function generate_pdf( WC_Order $order ): ?string {
        if ( ! class_exists( 'MAD_Proforma_Document' ) ) return null;

        try {
            $document = new MAD_Proforma_Document( $order );
            if ( ! $document->is_allowed() ) return null;
            $pdf_data = $document->get_pdf();
        } catch ( \Throwable $e ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    'Error generando PDF de proforma para el pedido #' . $order->get_id() . ': ' . $e->getMessage(),
                    [ 'source' => 'mad-proforma' ]
                );
            }
            return null;
        }

        if ( empty( $pdf_data ) ) return null;

        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'mad-proforma/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! file_exists( $dir . 'index.php' ) ) {
            file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
        }

        $path = $dir . 'proforma-order-' . $order->get_id() . '.pdf';
        file_put_contents( $path, $pdf_data );

        return $path;
    }

    /**
     * Envía la proforma por email apenas el cliente llega a la página de
     * confirmación, si el pedido quedó pendiente de pago. Se manda una
     * sola vez por pedido (flag en meta) aunque el cliente recargue la
     * página.
     */
    public function maybe_send_proforma( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( ! $order->has_status( [ 'pending', 'on-hold' ] ) ) return;
        if ( $order->get_meta( '_mad_proforma_sent' ) ) return;

        $pdf_path = $this->generate_pdf( $order );
        if ( ! $pdf_path ) return;

        $order->update_meta_data( '_mad_proforma_sent', '1' );
        $order->save();

        do_action( 'mad_proforma_ready', $order_id, $pdf_path );
    }

    /** Enlace de descarga directa en la propia página de confirmación. */
    public function render_download_link( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( ! $order->has_status( [ 'pending', 'on-hold' ] ) ) return;

        $url = $this->get_download_url( $order );
        echo '<p><a class="button" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Descargar factura proforma (PDF)', 'mad-suite' )
            . '</a></p>';
    }

    private function get_download_url( WC_Order $order ): string {
        return add_query_arg( [
            'mad_proforma' => $order->get_id(),
            'key'          => $order->get_order_key(),
        ], home_url( '/' ) );
    }

    /** Sirve el PDF cuando se visita la URL de descarga (clave del pedido como autenticación, igual que la pantalla nativa de pago de WooCommerce). */
    public function maybe_handle_download(): void {
        if ( empty( $_GET['mad_proforma'] ) ) return;

        $order_id = absint( $_GET['mad_proforma'] );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) wp_die( esc_html__( 'Pedido no encontrado.', 'mad-suite' ), '', [ 'response' => 404 ] );

        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        $is_owner = '' !== $key && hash_equals( (string) $order->get_order_key(), $key );
        $is_admin_user = current_user_can( 'manage_woocommerce' );
        if ( ! $is_owner && ! $is_admin_user ) {
            wp_die( esc_html__( 'No tienes permisos para ver este documento.', 'mad-suite' ), '', [ 'response' => 403 ] );
        }

        $pdf_path = $this->generate_pdf( $order );
        if ( ! $pdf_path || ! file_exists( $pdf_path ) ) {
            wp_die( esc_html__( 'No se pudo generar la factura proforma para este pedido (puede que ya no esté pendiente de pago).', 'mad-suite' ) );
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="proforma-' . $order->get_order_number() . '.pdf"' );
        header( 'Content-Length: ' . filesize( $pdf_path ) );
        readfile( $pdf_path );
        exit;
    }

    /* ================================================================ */
    /*  Meta box en el pedido (admin)                                     */
    /* ================================================================ */

    public function register_meta_box(): void {
        $screen = function_exists( 'wc_get_page_screen_id' )
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'mad-proforma',
            __( 'Factura proforma', 'mad-suite' ),
            [ $this, 'render_meta_box' ],
            $screen,
            'side',
            'default'
        );
    }

    public function render_meta_box( $post_or_order ): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order ) return;

        if ( ! $order->has_status( [ 'pending', 'on-hold' ] ) ) {
            echo '<p style="color:#888;">' . esc_html__( 'Solo disponible mientras el pedido está pendiente de pago.', 'mad-suite' ) . '</p>';
            return;
        }

        $url = $this->get_download_url( $order );
        echo '<p><a class="button button-small" href="' . esc_url( $url ) . '" target="_blank">'
            . esc_html__( 'Descargar / ver PDF', 'mad-suite' )
            . '</a></p>';
        echo '<p class="description">' . esc_html( $order->get_meta( '_mad_proforma_sent' )
            ? __( 'Ya se envió por email al cliente.', 'mad-suite' )
            : __( 'Todavía no se envió por email (se envía sola al llegar a la página de confirmación, o podés compartir este link manualmente).', 'mad-suite' )
        ) . '</p>';
    }
};
