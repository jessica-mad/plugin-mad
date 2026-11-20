<?php
/**
 * Customer email: proof of payment received (6.4).
 *
 * Fires right after the AI verifies the uploaded proof's amount matches
 * (order moves from quote-reserved/on-hold to on-hold, awaiting manual
 * admin review — see Module::ajax_upload_payment_proof()).
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Proof_Received extends WC_Email {

    use MAD_Email_WPML_Trait;

    public function __construct() {
        $this->id             = 'mad_quotes_proof_received';
        $this->title          = __( '[MAD Quotes] Comprobante recibido (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía al cliente cuando su comprobante de transferencia se verifica correctamente y queda a la espera de revisión del admin.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Comprobante recibido', 'mad-suite' );
        $this->subject = __( 'Hemos recibido su comprobante — Olofane', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-proof-received.php';
        $this->template_plain = 'emails/plain/mad-quote-proof-received.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_payment_proof_received', [ $this, 'trigger' ], 10, 1 );

        parent::__construct();
    }

    public function trigger( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        if ( ! $this->is_enabled() ) return;

        $this->object = $order;

        $this->recipient = $order->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_number}'] = $order->get_order_number();

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
                'body_text'          => $this->get_body_text( $this->object ),
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
        return __( 'Hemos recibido su comprobante — Olofane', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Comprobante recibido', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 6.4). */
    private function body_text_defaults(): array {
        return [
            'es' => "Hemos recibido el comprobante de su transferencia. Lo revisaremos en breve y le confirmaremos la compra por correo.\n\nMientras tanto, las piezas quedan apartadas para usted.",
            'en' => "We have received your bank transfer receipt. We will review it shortly and confirm your purchase by email.\n\nIn the meantime, the pieces are set aside for you.",
            'fr' => "Nous avons bien reçu le justificatif de votre virement. Nous le vérifierons sous peu et vous confirmerons l'achat par e-mail.\n\nEn attendant, les pièces vous sont réservées.",
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Hemos recibido su comprobante — Olofane',
                'en' => 'We have received your proof of payment — Olofane',
                'fr' => 'Nous avons bien reçu votre justificatif — Olofane',
            ],
            'heading' => [
                'es' => 'Comprobante recibido',
                'en' => 'Proof of payment received',
                'fr' => 'Justificatif reçu',
            ],
        ] );
        $this->add_body_text_form_fields( $this->form_fields, '', $this->body_text_defaults() );
    }

    private function get_body_text( WC_Order $order ): string {
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
