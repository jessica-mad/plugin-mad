<?php
/**
 * WC_Email: admin notification when a new quote request arrives.
 *
 * Fires immediately after the order is placed with quotes-gateway.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_New_Request extends WC_Email {

    public function __construct() {
        $this->id             = 'mad_quotes_new_request';
        $this->title          = __( '[MAD Quotes] Nueva solicitud (admin)', 'mad-suite' );
        $this->description    = __( 'Notificación al administrador cuando llega una nueva solicitud de presupuesto.', 'mad-suite' );
        $this->customer_email = false;

        $this->heading = __( 'Nueva solicitud de presupuesto', 'mad-suite' );
        $this->subject = __( '[{blogname}] Nueva solicitud de presupuesto de {customer_name} (#{order_number})', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-new-request.php';
        $this->template_plain = 'emails/plain/mad-quote-new-request.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        // Extra placeholder for customer name
        $this->placeholders['{customer_name}'] = '';

        add_action( 'mad_quotes_new_request', [ $this, 'trigger' ] );

        parent::__construct();
    }

    public function trigger( $order_id ) {
        if ( ! $order_id ) return;
        if ( ! $this->is_enabled() ) return;

        $this->object = wc_get_order( $order_id );
        if ( ! $this->object ) return;

        // Admin notification goes to the store email(s) configured in WC settings
        $this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );

        $this->placeholders['{order_date}']    = date_i18n( wc_date_format(), strtotime( $this->object->get_date_created() ) );
        $this->placeholders['{order_number}']  = $this->object->get_order_number();
        $this->placeholders['{customer_name}'] = $this->object->get_formatted_billing_full_name();

        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
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
                'body_text'          => $this->get_body_text( $this->object ),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => true,
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
                'body_text'          => $this->get_body_text( $this->object ),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => true,
                'plain_text'         => true,
                'email'              => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_default_subject() {
        return __( '[{blogname}] Nueva solicitud de presupuesto de {customer_name} (#{order_number})', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Nueva solicitud de presupuesto', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( 'Has recibido una nueva solicitud de presupuesto de {customer_name}.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->form_fields['recipient'] = [
            'title'       => __( 'Destinatario(s)', 'woocommerce' ),
            'type'        => 'text',
            'description' => __( 'Separa múltiples emails con comas.', 'mad-suite' ),
            'placeholder' => get_option( 'admin_email' ),
            'default'     => get_option( 'admin_email' ),
        ];
        $this->form_fields['body_text'] = [
            'title'       => __( 'Cuerpo del email', 'mad-suite' ),
            'type'        => 'textarea',
            'description' => __( 'Texto principal del email. Usa {customer_name} para insertar el nombre completo del cliente.', 'mad-suite' ),
            'default'     => $this->get_default_body_text(),
            'css'         => 'width:400px;height:120px;',
        ];
    }

    private function get_body_text( $order ): string {
        $text = $this->get_option( 'body_text', $this->get_default_body_text() );
        return str_replace( '{customer_name}', $order->get_formatted_billing_full_name(), $text );
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
