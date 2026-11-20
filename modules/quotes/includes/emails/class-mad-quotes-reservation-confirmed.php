<?php
/**
 * Customer email: reservation confirmed (6.2).
 *
 * Fires the instant a reservation order is created (quote_purchase or
 * pro_purchase) — includes the bank details and the deadline to upload
 * proof of payment before the reservation expires.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Reservation_Confirmed extends WC_Email {

    use MAD_Email_WPML_Trait;

    public function __construct() {
        $this->id             = 'mad_quotes_reservation_confirmed';
        $this->title          = __( '[MAD Quotes] Reserva confirmada (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía al confirmar una reserva con plazo (presupuesto o profesional) — incluye los datos bancarios y el plazo para subir el comprobante.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'En proceso de compra', 'mad-suite' );
        $this->subject = __( 'Su compra en proceso — Olofane (pedido #{order_number})', 'mad-suite' );

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
        return __( 'Su compra en proceso — Olofane (pedido #{order_number})', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'En proceso de compra', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 6.2) — {datos_bancarios} se sustituye en get_body_text() (HTML o texto plano según el caso). */
    private function body_text_defaults(): array {
        return [
            'es' => "Hemos puesto en proceso de compra, para usted, las piezas que ha seleccionado hasta las {hora_limite} (hora de Madrid).\n\n"
                . "Para completar la compra, realice una transferencia de {total} a la siguiente cuenta y adjunte el comprobante con el botón «Adjuntar comprobante» que encontrará más abajo:\n\n"
                . "{datos_bancarios}\n\n"
                . "Si no recibimos el comprobante antes de esa hora, el proceso de compra se liberará y las piezas volverán a estar disponibles.\n\n"
                . 'Quedamos a su disposición para cualquier consulta.',
            'en' => "We have put the pieces you selected into the purchase process for you until {hora_limite} (Madrid time).\n\n"
                . "To complete your purchase, please make a bank transfer of {total} to the account below and attach the proof of payment using the \u{201c}Attach proof of payment\u{201d} button below:\n\n"
                . "{datos_bancarios}\n\n"
                . "If we do not receive the proof of payment by then, the purchase process will be released and the pieces will become available again.\n\n"
                . 'We remain at your disposal for any questions.',
            'fr' => "Nous avons mis en cours d'achat, pour vous, les pièces sélectionnées jusqu'à {hora_limite} (heure de Madrid).\n\n"
                . "Pour finaliser votre achat, effectuez un virement de {total} sur le compte ci-dessous et joignez le justificatif à l'aide du bouton « Joindre le justificatif » ci-dessous :\n\n"
                . "{datos_bancarios}\n\n"
                . "Si nous ne recevons pas le justificatif avant cette heure, le processus d'achat sera libéré et les pièces seront de nouveau disponibles.\n\n"
                . 'Nous restons à votre disposition pour toute question.',
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Su compra en proceso — Olofane (pedido #{order_number})',
                'en' => 'Your purchase in progress — Olofane (order #{order_number})',
                'fr' => 'Votre achat en cours — Olofane (commande n°{order_number})',
            ],
            'heading' => [
                'es' => 'En proceso de compra',
                'en' => 'Purchase in progress',
                'fr' => 'Achat en cours',
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
