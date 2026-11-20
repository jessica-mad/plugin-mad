<?php
/**
 * Customer email: "Proforma" (6.2 — antes "Reserva confirmada").
 *
 * Fires the instant a reservation order is created (quote_purchase or
 * pro_purchase) — genera la factura proforma (módulo Proforma, sin
 * depender de ningún plugin de terceros) y la adjunta en PDF junto con los
 * datos bancarios y el plazo para subir el comprobante.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Reservation_Confirmed extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var string Ruta local de la proforma ya generada, para adjuntar en este envío. */
    private $pdf_attachment_path = '';

    public function __construct() {
        $this->id             = 'mad_quotes_reservation_confirmed';
        $this->title          = __( '[MAD Quotes] Proforma (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía al confirmar una compra con plazo (presupuesto o profesional) — lleva la factura proforma adjunta en PDF y los datos bancarios.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Su proforma', 'mad-suite' );
        $this->subject = __( 'Su proforma y cómo completar la compra — Olofane (pedido #{order_number})', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-reservation-confirmed.php';
        $this->template_plain = 'emails/plain/mad-quote-reservation-confirmed.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_reservation_created', [ $this, 'trigger' ], 10, 1 );

        parent::__construct();
    }

    public function trigger( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        if ( ! $this->is_enabled() ) return;

        $this->object = $order;

        $this->recipient = $order->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->pdf_attachment_path             = $this->generate_proforma( $order );

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

    /**
     * Resuelve el módulo Proforma (sin acoplarlo por require — mismo
     * patrón que el resto del plugin para hablar entre módulos) y le pide
     * el PDF. Si el módulo no está habilitado o algo falla al generarlo,
     * el email se manda igual, solo sin adjunto — nunca debe bloquear la
     * compra.
     */
    private function generate_proforma( WC_Order $order ): string {
        $info = MAD_Suite_Core::instance()->get_all_modules_info();
        if ( empty( $info['proforma']['enabled'] ) ) return '';

        $module = $info['proforma']['instance'] ?? null;
        if ( ! $module || ! method_exists( $module, 'generate_proforma_pdf' ) ) return '';

        return (string) ( $module->generate_proforma_pdf( $order ) ?? '' );
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
                'body_text'          => $this->get_body_text( $this->object, false ),
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
                'body_text'          => $this->get_body_text( $this->object, true ),
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
        return __( 'Su proforma y cómo completar la compra — Olofane (pedido #{order_number})', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Su proforma', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 2 del documento de cambios) — {datos_bancarios} se sustituye en get_body_text() (HTML o texto plano según el caso). */
    private function body_text_defaults(): array {
        return [
            'es' => "Gracias por su compra. Le adjuntamos la factura proforma de las piezas que ha seleccionado.\n\n"
                . "Las piezas quedan apartadas para usted hasta las {hora_limite} (hora de Madrid). Para completar la compra, realice una transferencia de {total} a la siguiente cuenta y adjunte el comprobante con el botón de abajo:\n\n"
                . "{datos_bancarios}\n\n"
                . "Si no recibimos el comprobante antes de esa hora, la compra se anulará y las piezas volverán a estar disponibles. Una vez confirmado el pago, le enviaremos la factura.\n\n"
                . 'Quedamos a su disposición para cualquier consulta.',
            'en' => "Thank you for your purchase. Please find attached the pro forma invoice for the pieces you selected.\n\n"
                . "The pieces are held for you until {hora_limite} (Madrid time). To complete your purchase, please make a bank transfer of {total} to the account below and attach the proof of payment using the button below:\n\n"
                . "{datos_bancarios}\n\n"
                . "If we do not receive the proof of payment by then, the purchase will be cancelled and the pieces will become available again. Once the payment is confirmed, we will send you the invoice.\n\n"
                . 'We remain at your disposal for any questions.',
            'fr' => "Merci pour votre achat. Vous trouverez ci-joint la facture pro forma des pièces sélectionnées.\n\n"
                . "Les pièces restent à votre disposition jusqu'à {hora_limite} (heure de Madrid). Pour finaliser votre achat, effectuez un virement de {total} sur le compte ci-dessous et joignez le justificatif à l'aide du bouton ci-dessous :\n\n"
                . "{datos_bancarios}\n\n"
                . "Si nous ne recevons pas le justificatif avant cette heure, l'achat sera annulé et les pièces seront de nouveau disponibles. Une fois le paiement confirmé, nous vous enverrons la facture.\n\n"
                . 'Nous restons à votre disposition pour toute question.',
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Su proforma y cómo completar la compra — Olofane (pedido #{order_number})',
                'en' => 'Your pro forma invoice and how to complete your purchase — Olofane (order #{order_number})',
                'fr' => 'Votre facture pro forma et comment finaliser votre achat — Olofane (commande n°{order_number})',
            ],
            'heading' => [
                'es' => 'Su proforma',
                'en' => 'Your pro forma invoice',
                'fr' => 'Votre facture pro forma',
            ],
        ] );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {hora_limite}, {total} y {datos_bancarios} (se sustituye por los datos bancarios y el concepto de la transferencia).', 'mad-suite' ),
            $this->body_text_defaults()
        );
    }

    private function get_body_text( WC_Order $order, bool $plain ): string {
        $lang    = mad_quotes_order_lang( $order );
        $minutes = (int) $order->get_meta( '_mad_reservation_minutes' );
        $created = $order->get_date_created();
        $until   = ( $created ? $created->getTimestamp() : time() ) + $minutes * MINUTE_IN_SECONDS;

        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{hora_limite}', mad_quotes_madrid_time( $until ), $text );
        $text = str_replace( '{total}', wp_strip_all_tags( wc_price( $order->get_total() ) ), $text );
        $text = str_replace(
            '{datos_bancarios}',
            $plain ? mad_quotes_bacs_details_text( $order ) : mad_quotes_bacs_details_html( $order ),
            $text
        );

        return $text;
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
