<?php
/**
 * Customer email: factura proforma adjunta en PDF.
 *
 * Se dispara automáticamente al llegar a la página de confirmación de un
 * pedido que queda a la espera de pago por transferencia (ver Module.php,
 * maybe_send_proforma() en woocommerce_thankyou).
 *
 * @package MAD_Suite/Proforma/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Proforma_Email extends WC_Email {

    /** @var string Ruta local del PDF ya generado, para adjuntar en este envío. */
    private $pdf_attachment_path = '';

    public function __construct() {
        $this->id             = 'mad_proforma';
        $this->title          = __( '[MAD Proforma] Factura proforma', 'mad-suite' );
        $this->description    = __( 'Se envía al cliente con la factura proforma adjunta en PDF, apenas su pedido queda a la espera de pago por transferencia.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Tu factura proforma', 'mad-suite' );
        $this->subject = __( '[{blogname}] Factura proforma — Pedido #{order_number}', 'mad-suite' );

        $this->template_html  = 'emails/mad-proforma.php';
        $this->template_plain = 'emails/plain/mad-proforma.php';
        $this->template_base  = MAD_PROFORMA_TEMPLATE_PATH;

        add_action( 'mad_proforma_ready', [ $this, 'trigger' ], 10, 2 );

        parent::__construct();
    }

    /**
     * @param int    $order_id
     * @param string $pdf_path Ruta local del PDF ya generado (se adjunta si existe).
     */
    public function trigger( $order_id, $pdf_path = '' ) {
        if ( ! $order_id ) return;

        $this->object = wc_get_order( $order_id );
        if ( ! $this->object ) return;
        if ( ! $this->is_enabled() ) return;

        $this->recipient = $this->object->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_date}']   = date_i18n( wc_date_format(), strtotime( $this->object->get_date_created() ) );
        $this->placeholders['{order_number}'] = $this->object->get_order_number();

        $this->pdf_attachment_path = $pdf_path;

        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    public function get_attachments() {
        $attachments = parent::get_attachments();
        if ( $this->pdf_attachment_path && file_exists( $this->pdf_attachment_path ) ) {
            $attachments[] = $this->pdf_attachment_path;
        }
        return $attachments;
    }

    public function get_content_html() {
        if ( ! $this->object ) {
            return $this->get_preview_fallback_html();
        }

        return wc_get_template_html(
            $this->template_html,
            [
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'body_text'          => $this->get_body_text(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => false,
                'email'              => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        if ( ! $this->object ) {
            return $this->get_heading() . "\n\n" . __( '[Vista previa — necesitas un pedido real para ver el contenido completo]', 'mad-suite' );
        }

        return wc_get_template_html(
            $this->template_plain,
            [
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'body_text'          => $this->get_body_text(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => true,
                'email'              => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_default_subject() {
        return __( '[{blogname}] Factura proforma — Pedido #{order_number}', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Tu factura proforma', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( 'Hola {customer_name}, adjuntamos la factura proforma de tu pedido. Este documento NO es una factura válida a efectos fiscales — es solo de referencia mientras se confirma el pago. Realiza la transferencia bancaria y sube el comprobante para completar tu compra.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->form_fields['body_text'] = [
            'title'       => __( 'Texto del cuerpo', 'mad-suite' ),
            'type'        => 'textarea',
            'desc_tip'    => true,
            'description' => __( 'Usa {customer_name} para el nombre del cliente.', 'mad-suite' ),
            'default'     => $this->get_default_body_text(),
            'css'         => 'width:400px; height: 100px;',
        ];
    }

    private function get_body_text(): string {
        $text = $this->get_option( 'body_text', $this->get_default_body_text() );
        $text = str_replace( '{customer_name}', $this->object->get_billing_first_name(), $text );
        return $this->format_string( $text );
    }

    private function get_preview_fallback_html(): string {
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo '<p>' . esc_html( $this->get_option( 'body_text', $this->get_default_body_text() ) ) . '</p>';
        if ( $this->get_additional_content() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) );
        }
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }
}
