<?php
/**
 * Admin email: new quote request received.
 *
 * Fires when a customer submits a quote request (order placed via the
 * Quotes gateway, status = quote-pending).
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class MAD_Quotes_Email_New_Request
 */
class MAD_Quotes_Email_New_Request extends WC_Email {

    public function __construct() {
        $this->id             = 'mad_quotes_new_request';
        $this->title          = __( '[MAD Quotes] Nueva solicitud de presupuesto', 'mad-suite' );
        $this->description    = __( 'Se envía al administrador cuando llega una nueva solicitud de presupuesto.', 'mad-suite' );
        $this->customer_email = false;

        $this->heading = __( 'Nueva solicitud de presupuesto #{order_number}', 'mad-suite' );
        $this->subject = __( '[{blogname}] Solicitud de presupuesto (Pedido #{order_number}) – {order_date}', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-new-request.php';
        $this->template_plain = 'emails/plain/mad-quote-new-request.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_quotes_pending_notification', [ $this, 'trigger' ] );

        parent::__construct();

        $this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
    }

    /**
     * Fire the email.
     *
     * @param int $order_id
     */
    public function trigger( $order_id ) {
        if ( ! $order_id ) return;

        $send = apply_filters( 'mad_quotes_send_new_request_email', true, $order_id );
        if ( ! $send ) return;

        $this->object = wc_get_order( $order_id );
        if ( ! $this->object ) return;

        $quote_status = $this->object->get_meta( '_mad_quote_status' );
        if ( 'quote-pending' !== $quote_status ) return;

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) return;

        $this->placeholders['{order_date}']   = date_i18n( wc_date_format(), strtotime( $this->object->get_date_created() ) );
        $this->placeholders['{order_number}'] = $this->object->get_order_number();

        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => true,
                'plain_text'    => false,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => true,
                'plain_text'    => true,
                'email'         => $this,
            ],
            '',
            $this->template_base
        );
    }

    public function get_default_subject() {
        return __( '[{blogname}] Solicitud de presupuesto (Pedido #{order_number}) – {order_date}', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Nueva solicitud de presupuesto #{order_number}', 'mad-suite' );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'    => [
                'title'   => __( 'Activar/desactivar', 'mad-suite' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar esta notificación', 'mad-suite' ),
                'default' => 'yes',
            ],
            'recipient'  => [
                'title'       => __( 'Destinatario', 'mad-suite' ),
                'type'        => 'text',
                'description' => sprintf( __( 'Separados por coma. Por defecto: %s', 'mad-suite' ), get_option( 'admin_email' ) ),
                'default'     => get_option( 'admin_email' ),
            ],
            'subject'    => [
                'title'       => __( 'Asunto', 'mad-suite' ),
                'type'        => 'text',
                'description' => sprintf( __( 'Asunto del email. Deja vacío para usar el predeterminado: <code>%s</code>.', 'mad-suite' ), $this->get_default_subject() ),
                'default'     => '',
            ],
            'heading'    => [
                'title'       => __( 'Cabecera', 'mad-suite' ),
                'type'        => 'text',
                'description' => sprintf( __( 'Título del email. Deja vacío para usar el predeterminado: <code>%s</code>.', 'mad-suite' ), $this->get_default_heading() ),
                'default'     => '',
            ],
            'email_type' => [
                'title'       => __( 'Tipo de email', 'mad-suite' ),
                'type'        => 'select',
                'description' => __( 'Elige el formato del email.', 'mad-suite' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
            ],
        ];
    }
}
