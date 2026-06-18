<?php
/**
 * WC_Email: customer confirmation when a quote request is received.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Confirmation extends WC_Email {

    public function __construct() {
        $this->id             = 'mad_quotes_confirmation';
        $this->title          = __( '[MAD Quotes] Confirmación de solicitud (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía al cliente cuando recibimos su solicitud de presupuesto.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Hemos recibido tu solicitud', 'mad-suite' );
        $this->subject = __( '[{blogname}] Solicitud de presupuesto recibida (#{order_number})', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-confirmation.php';
        $this->template_plain = 'emails/plain/mad-quote-confirmation.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_quotes_new_request', [ $this, 'trigger' ] );

        parent::__construct();
    }

    public function trigger( $order_id ) {
        if ( ! $order_id ) return;
        if ( ! $this->is_enabled() ) return;

        $this->object = wc_get_order( $order_id );
        if ( ! $this->object ) return;

        $this->recipient = $this->object->get_billing_email();
        if ( ! $this->get_recipient() ) return;

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
        // Guard: $this->object can be null when WC generates a preview/test email.
        if ( ! $this->object ) {
            return $this->get_preview_fallback_html();
        }

        return wc_get_template_html(
            $this->template_html,
            [
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
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
        return __( '[{blogname}] Solicitud de presupuesto recibida (#{order_number})', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Hemos recibido tu solicitud', 'mad-suite' );
    }

    private function get_preview_fallback_html(): string {
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo '<p>' . esc_html__( 'Vista previa del email de confirmación de solicitud de presupuesto.', 'mad-suite' ) . '</p>';
        if ( $this->get_additional_content() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) );
        }
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }
}
