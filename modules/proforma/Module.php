<?php
/**
 * Módulo: MAD Proforma
 *
 * Genera la factura proforma (numeración propia "PRO-", no fiscal) y la
 * factura real (numeración fiscal correlativa propia) con un generador de
 * PDF propio (FPDF, vendorizado en vendor/fpdf/) — sin depender de ningún
 * plugin de terceros. Antes se apoyaba en "PDF Invoices & Packing Slips
 * for WooCommerce"; se dejó de usar por decisión de @Jessica tras el
 * documento "Cambios tras la prueba en la web" ("no queremos depender de
 * ese plugin, vamos a hacerlo nosotros").
 *
 * La proforma se genera al crear un pedido de compra con plazo (desde
 * Quotes, vía la acción mad_reservation_created) y queda como referencia
 * aunque se apruebe el pago — nunca se borra. La factura solo se genera
 * UNA VEZ, al aprobar el pago (mad_quotes_payment_approved, también desde
 * Quotes), y asigna su número fiscal en ese momento, nunca antes.
 *
 * @package MAD_Suite/Proforma
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MAD_PROFORMA_DIR',           plugin_dir_path( __FILE__ ) );
define( 'MAD_PROFORMA_URL',           plugin_dir_url( __FILE__ ) );
define( 'MAD_PROFORMA_TEMPLATE_PATH', MAD_PROFORMA_DIR . 'templates/' );

return new class( MAD_Suite_Core::instance() ) implements MAD_Suite_Module {

    private $core;
    private $slug = 'proforma';

    public function __construct( $core ) {
        $this->core = $core;
    }

    public function slug()       { return $this->slug; }
    public function title()      { return __( 'Facturación (Proforma y Factura)', 'mad-suite' ); }
    public function menu_label() { return __( 'Proforma', 'mad-suite' ); }
    public function menu_slug()  { return 'mad-' . $this->slug; }

    public function description() {
        return __( 'Genera la factura proforma y la factura real en PDF (numeración propia, sin depender de ningún plugin de terceros) para los pedidos de compra con plazo de Quotes.', 'mad-suite' );
    }

    public function required_plugins() {
        return [
            'WooCommerce' => 'woocommerce/woocommerce.php',
        ];
    }

    /* ================================================================ */
    /*  init()                                                           */
    /* ================================================================ */

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        require_once MAD_PROFORMA_DIR . 'includes/class-mad-invoice-pdf.php';
        add_filter( 'woocommerce_email_classes', [ $this, 'register_email_class' ] );

        $settings = $this->get_settings();
        if ( ! empty( $settings['auto_send'] ) ) {
            // Pedidos ajenos a Quotes (ningún _mad_order_type) que queden
            // pendientes de pago por transferencia — los de Quotes generan
            // y envían su propia proforma al crearse (ver más abajo).
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

    /* ================================================================ */
    /*  Ajustes                                                           */
    /* ================================================================ */

    private function get_settings(): array {
        $opts = get_option( MAD_Suite_Core::option_key( $this->slug ), [] );
        return wp_parse_args( is_array( $opts ) ? $opts : [], [
            'auto_send'            => true,
            'company_name'         => get_bloginfo( 'name' ),
            'company_vat'          => '',
            'company_address'      => '',
            'company_logo_url'     => '',
            'legal_footer'         => '',
            'invoice_prefix'       => 'FAC-',
            'invoice_reset_yearly' => true,
        ] );
    }

    public function sanitize_settings( $input ): array {
        return [
            'auto_send'            => ! empty( $input['auto_send'] ),
            'company_name'         => sanitize_text_field( $input['company_name'] ?? '' ),
            'company_vat'          => sanitize_text_field( $input['company_vat'] ?? '' ),
            'company_address'      => sanitize_textarea_field( $input['company_address'] ?? '' ),
            'company_logo_url'     => sanitize_text_field( $input['company_logo_url'] ?? '' ),
            'legal_footer'         => sanitize_textarea_field( $input['legal_footer'] ?? '' ),
            'invoice_prefix'       => sanitize_text_field( $input['invoice_prefix'] ?? 'FAC-' ),
            'invoice_reset_yearly' => ! empty( $input['invoice_reset_yearly'] ),
        ];
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'mad-suite' ) );
        }
        $settings   = $this->get_settings();
        $option_key = MAD_Suite_Core::option_key( $this->slug );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( $this->menu_slug() ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Envío automático (pedidos ajenos a Quotes)', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[auto_send]" value="1" <?php checked( ! empty( $settings['auto_send'] ) ); ?>>
                                <?php esc_html_e( 'Enviar la proforma por email apenas un pedido normal (no de Quotes) llega a la página de confirmación en espera de pago.', 'mad-suite' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Nombre de la empresa', 'mad-suite' ); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[company_name]" value="<?php echo esc_attr( $settings['company_name'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'NIF / CIF', 'mad-suite' ); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[company_vat]" value="<?php echo esc_attr( $settings['company_vat'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Dirección', 'mad-suite' ); ?></th>
                        <td><textarea class="large-text" rows="3" name="<?php echo esc_attr( $option_key ); ?>[company_address]"><?php echo esc_textarea( $settings['company_address'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Logo (URL de la imagen)', 'mad-suite' ); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[company_logo_url]" value="<?php echo esc_attr( $settings['company_logo_url'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Solo PNG/JPG, y debe estar en este mismo sitio (en la Biblioteca de medios).', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Texto legal de pie de página', 'mad-suite' ); ?></th>
                        <td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $option_key ); ?>[legal_footer]"><?php echo esc_textarea( $settings['legal_footer'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Prefijo de la factura', 'mad-suite' ); ?></th>
                        <td><input type="text" class="small-text" name="<?php echo esc_attr( $option_key ); ?>[invoice_prefix]" value="<?php echo esc_attr( $settings['invoice_prefix'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Numeración de factura', 'mad-suite' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[invoice_reset_yearly]" value="1" <?php checked( ! empty( $settings['invoice_reset_yearly'] ) ); ?>>
                                <?php esc_html_e( 'Reiniciar la numeración cada año (prefijo-AÑO-0001). Si se desmarca, es una secuencia única sin reiniciar nunca.', 'mad-suite' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'La numeración de la PROFORMA no se toca aquí: siempre es PRO- + número de pedido.', 'mad-suite' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to WooCommerce Emails settings */
                    wp_kses_post( __( 'El asunto, encabezado y texto del email genérico de proforma (pedidos ajenos a Quotes) son editables desde %s → "Factura proforma".', 'mad-suite' ) ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce → Ajustes → Emails', 'mad-suite' ) . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /* ================================================================ */
    /*  Email genérico (pedidos ajenos a Quotes)                          */
    /* ================================================================ */

    public function register_email_class( array $classes ): array {
        require_once MAD_PROFORMA_DIR . 'includes/emails/class-mad-proforma-email.php';
        $classes['MAD_Proforma_Email'] = new MAD_Proforma_Email();
        return $classes;
    }

    /* ================================================================ */
    /*  Generación de PDF — API pública usada por Quotes                  */
    /* ================================================================ */

    /**
     * Genera (o regenera, a partir de los mismos datos guardados) la
     * proforma de un pedido. No asigna ningún número fiscal — PRO- +
     * número de pedido, siempre el mismo, se puede volver a generar sin
     * límite.
     *
     * @return string|null Ruta local del PDF, o null si no se pudo generar.
     */
    public function generate_proforma_pdf( WC_Order $order ): ?string {
        $number = 'PRO-' . $order->get_order_number();
        $minutes = (int) $order->get_meta( '_mad_reservation_minutes' );
        $created = $order->get_date_created();
        $until   = $minutes ? ( ( $created ? $created->getTimestamp() : time() ) + $minutes * MINUTE_IN_SECONDS ) : 0;

        return $this->render_and_save( $order, 'proforma', $number, $until, 'proforma-order-' . $order->get_id() . '.pdf' );
    }

    /**
     * Genera la factura real — numeración fiscal correlativa propia,
     * asignada UNA SOLA VEZ (la primera vez que se llama para un pedido;
     * llamadas posteriores reutilizan el mismo número, nunca incrementan
     * el contador de nuevo).
     *
     * @return string|null Ruta local del PDF, o null si no se pudo generar.
     */
    public function generate_invoice_pdf( WC_Order $order ): ?string {
        $number = (string) $order->get_meta( '_mad_invoice_number' );
        if ( '' === $number ) {
            $number = $this->next_invoice_number();
            $order->update_meta_data( '_mad_invoice_number', $number );
            $order->update_meta_data( '_mad_invoice_generated_at', time() );
            $order->save();
        }

        return $this->render_and_save( $order, 'invoice', $number, 0, 'invoice-order-' . $order->get_id() . '.pdf' );
    }

    /** Siguiente número de factura, incrementado de forma atómica (ver cabecera del archivo sobre su alcance). */
    private function next_invoice_number(): string {
        global $wpdb;
        $settings = $this->get_settings();
        $yearly   = ! empty( $settings['invoice_reset_yearly'] );
        $year     = gmdate( 'Y' );
        $option   = $yearly ? 'mad_proforma_invoice_seq_' . $year : 'mad_proforma_invoice_seq_all';

        if ( false === get_option( $option, false ) ) {
            add_option( $option, '0', '', 'no' );
        }
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $prefix = (string) ( $settings['invoice_prefix'] ?? 'FAC-' );
        return $prefix . ( $yearly ? $year . '-' : '' ) . sprintf( '%04d', $seq );
    }

    private function render_and_save( WC_Order $order, string $type, string $number, int $until, string $filename ): ?string {
        try {
            $pdf  = new MAD_Invoice_PDF( $this->get_settings() );
            $data = $pdf->render( $order, $type, $number, $until );
        } catch ( \Throwable $e ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->error(
                    "Error generando PDF ($type) para el pedido #" . $order->get_id() . ': ' . $e->getMessage(),
                    [ 'source' => 'mad-proforma' ]
                );
            }
            return null;
        }

        if ( empty( $data ) ) return null;

        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'mad-proforma/';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        if ( ! file_exists( $dir . 'index.php' ) ) {
            file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
        }

        $path = $dir . $filename;
        file_put_contents( $path, $data );

        return $path;
    }

    /* ================================================================ */
    /*  Flujo genérico (pedidos ajenos a Quotes)                          */
    /* ================================================================ */

    /**
     * Envía la proforma por email apenas el cliente llega a la página de
     * confirmación, si el pedido quedó pendiente de pago. Pedidos de
     * Quotes (quote_purchase/pro_purchase) quedan fuera: esos generan y
     * envían su propia proforma al crearse, con sus propios datos
     * bancarios y plazo — enviarla aquí también la duplicaría.
     */
    public function maybe_send_proforma( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        if ( $order->get_meta( '_mad_order_type' ) ) return;
        if ( ! $order->has_status( [ 'pending', 'on-hold' ] ) ) return;
        if ( $order->get_meta( '_mad_proforma_sent' ) ) return;

        $pdf_path = $this->generate_proforma_pdf( $order );
        if ( ! $pdf_path ) return;

        $order->update_meta_data( '_mad_proforma_sent', '1' );
        $order->save();

        do_action( 'mad_proforma_ready', $order_id, $pdf_path );
    }

    /** Enlace de descarga directa en la propia página de confirmación (pedidos ajenos a Quotes — los de Quotes ya tienen su propio botón en la página de pago). */
    public function render_download_link( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( '_mad_order_type' ) ) return;
        if ( ! $order->has_status( [ 'pending', 'on-hold' ] ) ) return;

        $url = $this->get_download_url( $order, 'proforma' );
        echo '<p><a class="button" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Descargar factura proforma (PDF)', 'mad-suite' )
            . '</a></p>';
    }

    /** @param string $type 'proforma'|'invoice' */
    public function get_download_url( WC_Order $order, string $type = 'proforma' ): string {
        return add_query_arg( [
            'mad_proforma' => $order->get_id(),
            'type'         => $type,
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

        $type = isset( $_GET['type'] ) && 'invoice' === $_GET['type'] ? 'invoice' : 'proforma';

        if ( 'invoice' === $type ) {
            if ( '' === (string) $order->get_meta( '_mad_invoice_number' ) ) {
                wp_die( esc_html__( 'Todavía no hay factura para este pedido — se genera al aprobar el pago.', 'mad-suite' ) );
            }
            $pdf_path = $this->generate_invoice_pdf( $order );
        } else {
            $pdf_path = $this->generate_proforma_pdf( $order );
        }

        if ( ! $pdf_path || ! file_exists( $pdf_path ) ) {
            wp_die( esc_html__( 'No se pudo generar el documento para este pedido.', 'mad-suite' ) );
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . $type . '-' . $order->get_order_number() . '.pdf"' );
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
            __( 'Proforma / Factura', 'mad-suite' ),
            [ $this, 'render_meta_box' ],
            $screen,
            'side',
            'default'
        );
    }

    public function render_meta_box( $post_or_order ): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order ) return;

        $has_proforma = $order->get_meta( '_mad_order_type' ) || $order->has_status( [ 'pending', 'on-hold' ] );
        $invoice_number = (string) $order->get_meta( '_mad_invoice_number' );

        if ( $has_proforma ) {
            $url = $this->get_download_url( $order, 'proforma' );
            echo '<p><a class="button button-small" href="' . esc_url( $url ) . '" target="_blank">'
                . esc_html__( 'Ver proforma (PDF)', 'mad-suite' ) . '</a></p>';
        }

        if ( $invoice_number ) {
            $url = $this->get_download_url( $order, 'invoice' );
            echo '<p><a class="button button-small button-primary" href="' . esc_url( $url ) . '" target="_blank">'
                . esc_html( sprintf(
                    /* translators: %s: número de factura */
                    __( 'Ver factura %s (PDF)', 'mad-suite' ),
                    $invoice_number
                ) ) . '</a></p>';
        } elseif ( ! $has_proforma ) {
            echo '<p style="color:#888;">' . esc_html__( 'Sin proforma ni factura para este pedido.', 'mad-suite' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'La factura se genera automáticamente al aprobar el pago.', 'mad-suite' ) . '</p>';
        }
    }
};
