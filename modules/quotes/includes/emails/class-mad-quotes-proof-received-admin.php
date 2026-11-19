<?php
/**
 * Admin email: proof of payment received (6.7, second notice).
 *
 * Internal notice only — stays Spanish-only, same as New_Request. Sent to
 * the same recipient configured for "Nueva solicitud (admin)".
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Proof_Received_Admin extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var string */
    private $ai_result_text = '';

    public function __construct() {
        $this->id             = 'mad_quotes_proof_received_admin';
        $this->title          = __( '[MAD Quotes] Comprobante recibido (admin)', 'mad-suite' );
        $this->description    = __( 'Aviso interno al recibirse un comprobante de transferencia de una reserva con plazo, con el resultado de la verificación por IA.', 'mad-suite' );
        $this->customer_email = false;

        $this->heading = __( 'Comprobante recibido', 'mad-suite' );
        $this->subject = __( '[{blogname}] Comprobante recibido — Pedido #{order_number}', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-proof-received-admin.php';
        $this->template_plain = 'emails/plain/mad-quote-proof-received-admin.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_payment_proof_received', [ $this, 'trigger' ], 10, 2 );

        parent::__construct();
    }

    public function trigger( $order, $result = null ) {
        if ( ! $order instanceof WC_Order ) return;
        if ( ! $this->is_enabled() ) return;

        $this->object    = $order;
        $this->recipient = $this->new_request_recipient();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_number}']  = $order->get_order_number();
        $this->placeholders['{customer_name}'] = $order->get_formatted_billing_full_name();

        $detected = is_array( $result ) && isset( $result['detected_amount'] ) ? (float) $result['detected_amount'] : null;
        $this->ai_result_text = null !== $detected
            ? sprintf(
                /* translators: %s: importe detectado por la IA */
                __( 'Importe detectado por la IA: %s. Coincide con el total del pedido.', 'mad-suite' ),
                wp_strip_all_tags( wc_price( $detected ) )
            )
            : __( 'La IA verificó el comprobante.', 'mad-suite' );

        $content = $this->get_content();

        $sent = $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $content,
            $this->get_headers(),
            $this->get_attachments()
        );

        do_action( 'mad_quotes_email_logged', $order->get_id(), $this->id, $this->get_recipient(), (bool) $sent, $content );
    }

    /** Mismo destinatario configurado para "Nueva solicitud (admin)" — este aviso no tiene campo propio. */
    private function new_request_recipient(): string {
        $settings  = get_option( 'woocommerce_mad_quotes_new_request_settings', [] );
        $recipient = is_array( $settings ) ? ( $settings['recipient'] ?? '' ) : '';
        return $recipient ?: get_option( 'admin_email' );
    }

    public function get_content_html() {
        if ( ! $this->object ) {
            return $this->get_preview_fallback_html();
        }

        return wc_get_template_html( $this->template_html, $this->template_vars( false ), '', $this->template_base );
    }

    public function get_content_plain() {
        if ( ! $this->object ) {
            return $this->get_heading() . "\n\n" . __( '[Vista previa — necesitas un pedido real para ver el contenido completo]', 'mad-suite' );
        }

        return wc_get_template_html( $this->template_plain, $this->template_vars( true ), '', $this->template_base );
    }

    private function template_vars( bool $plain_text ): array {
        $order = $this->object;

        return [
            'order'              => $order,
            'email_heading'      => $this->get_heading(),
            'body_text'          => $this->get_body_text( $order ),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => true,
            'plain_text'         => $plain_text,
            'email'              => $this,
            'ai_result_text'     => $this->ai_result_text,
            'order_edit_url'     => $order->get_edit_order_url(),
        ];
    }

    public function get_default_subject() {
        return __( '[{blogname}] Comprobante recibido — Pedido #{order_number}', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Comprobante recibido', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( '{customer_name} ha subido el comprobante de transferencia del pedido #{order_number}. Revísalo y aprueba o rechaza la reserva desde la ficha del pedido.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {customer_name} y {order_number}.', 'mad-suite' )
        );
    }

    private function get_body_text( WC_Order $order ): string {
        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{customer_name}', $order->get_formatted_billing_full_name(), $text );
        $text = str_replace( '{order_number}', $order->get_order_number(), $text );
        return $text;
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
