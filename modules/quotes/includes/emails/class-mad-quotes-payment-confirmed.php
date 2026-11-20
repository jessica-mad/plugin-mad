<?php
/**
 * Customer email: payment confirmed, invoice attached (section 3 of the
 * "cambios tras la prueba" document).
 *
 * Fires when the admin approves the payment from the proof-of-transfer
 * box ("Aprobar pago" — Module::do_approve_payment(), action
 * mad_quotes_payment_approved). Generates the real invoice (fiscal
 * numbering, via the Proforma module) and attaches it. Replaces WC's
 * native "order is processing" customer email for reservation orders
 * (Module::suppress_native_processing_email()) so the
 * customer only gets this one, with Olofane's own trilingual text.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Payment_Confirmed extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var string Ruta local de la factura ya generada, para adjuntar en este envío. */
    private $pdf_attachment_path = '';

    public function __construct() {
        $this->id             = 'mad_quotes_payment_confirmed';
        $this->title          = __( '[MAD Quotes] Pago confirmado (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía al aprobar el pago desde la caja de comprobante — lleva la factura adjunta en PDF. Sustituye al email nativo de WooCommerce "Pedido en procesamiento" para las compras con plazo.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Pago confirmado', 'mad-suite' );
        $this->subject = __( 'Hemos confirmado su pago — Olofane (pedido #{order_number})', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-payment-confirmed.php';
        $this->template_plain = 'emails/plain/mad-quote-payment-confirmed.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_quotes_payment_approved', [ $this, 'trigger' ], 10, 1 );

        parent::__construct();
    }

    public function trigger( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        if ( ! $this->is_enabled() ) return;

        $this->object = $order;

        $this->recipient = $order->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->pdf_attachment_path             = $this->generate_invoice( $order );

        $orig_lang = $this->switch_to_order_language( $order );
        $content   = $this->get_content();

        $sent = $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $content,
            $this->get_headers(),
            $this->get_attachments()
        );

        $this->restore_order_language( $orig_lang );

        do_action( 'mad_quotes_email_logged', $order->get_id(), $this->id, $this->get_recipient(), (bool) $sent, $content );
    }

    /** Mismo patrón de resolución cruzada de módulos que el email "Proforma" — nunca debe bloquear el envío si el módulo no está disponible. */
    private function generate_invoice( WC_Order $order ): string {
        $info = MAD_Suite_Core::instance()->get_all_modules_info();
        if ( empty( $info['proforma']['enabled'] ) ) return '';

        $module = $info['proforma']['instance'] ?? null;
        if ( ! $module || ! method_exists( $module, 'generate_invoice_pdf' ) ) return '';

        return (string) ( $module->generate_invoice_pdf( $order ) ?? '' );
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
        return __( 'Hemos confirmado su pago — Olofane (pedido #{order_number})', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Pago confirmado', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 3 del documento de cambios). */
    private function body_text_defaults(): array {
        return [
            'es' => 'Hemos confirmado su pago. Le adjuntamos la factura de su compra. Nos pondremos en contacto con usted para coordinar la recogida o el envío de las piezas. Quedamos a su disposición para cualquier consulta.',
            'en' => 'We have confirmed your payment. Please find attached the invoice for your purchase. We will contact you to arrange the collection or delivery of the pieces. We remain at your disposal for any questions.',
            'fr' => 'Nous avons confirmé votre paiement. Vous trouverez ci-joint la facture de votre achat. Nous vous contacterons pour organiser le retrait ou la livraison des pièces. Nous restons à votre disposition pour toute question.',
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Hemos confirmado su pago — Olofane (pedido #{order_number})',
                'en' => 'We have confirmed your payment — Olofane (order #{order_number})',
                'fr' => 'Nous avons confirmé votre paiement — Olofane (commande n°{order_number})',
            ],
            'heading' => [
                'es' => 'Pago confirmado',
                'en' => 'Payment confirmed',
                'fr' => 'Paiement confirmé',
            ],
        ] );
        $this->add_body_text_form_fields( $this->form_fields, '', $this->body_text_defaults() );
    }

    private function get_body_text(): string {
        return $this->get_body_text_for_lang( $this->get_default_body_text() );
    }

    private function get_preview_fallback_html(): string {
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo wp_kses_post( wpautop( $this->get_text_for_default_lang( 'body_text', $this->get_default_body_text() ) ) );
        if ( $this->get_additional_content() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) );
        }
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }
}
