<?php
/**
 * Customer email: quote ready / send quote.
 *
 * The admin triggers this manually from the order edit screen once prices
 * have been set. Includes an optional admin note.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class MAD_Quotes_Email_Send_Quote
 */
class MAD_Quotes_Email_Send_Quote extends WC_Email {

    public function __construct() {
        $this->id             = 'mad_quotes_send_quote';
        $this->title          = __( '[MAD Quotes] Presupuesto enviado al cliente', 'mad-suite' );
        $this->description    = __( 'Se envía al cliente cuando el administrador envía el presupuesto finalizado.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Tu presupuesto está listo', 'mad-suite' );
        $this->subject = __( '[{blogname}] Tu presupuesto (Pedido #{order_number})', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-send.php';
        $this->template_plain = 'emails/plain/mad-quote-send.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_quotes_send_quote_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    /**
     * Fire the email.
     *
     * @param int    $order_id
     * @param string $admin_note  Optional note from admin shown in the email body.
     */
    public function trigger( $order_id, $admin_note = '' ) {
        if ( ! $order_id ) return;

        $send = apply_filters( 'mad_quotes_send_quote_email', true, $order_id );
        if ( ! $send ) return;

        $this->object = wc_get_order( $order_id );
        if ( ! $this->object ) return;

        if ( ! $this->is_enabled() ) return;

        $this->recipient = $this->object->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_date}']   = date_i18n( wc_date_format(), strtotime( $this->object->get_date_created() ) );
        $this->placeholders['{order_number}'] = $this->object->get_order_number();
        $this->admin_note = sanitize_textarea_field( $admin_note );

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
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
                'admin_note'    => $this->admin_note ?? '',
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
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
                'admin_note'    => $this->admin_note ?? '',
            ],
            '',
            $this->template_base
        );
    }

    public function get_default_subject() {
        return __( '[{blogname}] Tu presupuesto (Pedido #{order_number})', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Tu presupuesto está listo', 'mad-suite' );
    }
}
