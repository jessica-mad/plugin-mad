<?php
/**
 * Customer email: reservation expired (6.3, variants A/B/C/D).
 *
 * A = quote_purchase, attempts left  → "Volver a reservar" button.
 * B = quote_purchase, no attempts    → next attempt date + contact.
 * C = pro_purchase,   attempts left  → "Volver a comprar" button.
 * D = pro_purchase,   no attempts    → next attempt date + contact.
 *
 * Also reused when the admin rejects an uploaded proof (on-hold →
 * cancelled) — same outcome for the customer as a plain expiry.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Reservation_Expired extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var MAD_Reservations */
    private $reservations;

    public function __construct() {
        $this->id             = 'mad_quotes_reservation_expired';
        $this->title          = __( '[MAD Quotes] Reserva caducada (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía cuando una reserva con plazo caduca sin comprobante, o el admin rechaza el comprobante subido. Cuatro variantes según el tipo de pedido y los intentos restantes.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Su reserva ha caducado', 'mad-suite' );
        $this->subject = __( 'Su reserva ha caducado — Olofane', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-reservation-expired.php';
        $this->template_plain = 'emails/plain/mad-quote-reservation-expired.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        $this->reservations = new MAD_Reservations();

        add_action( 'mad_reservation_expired', [ $this, 'trigger' ], 10, 1 );

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

        return wc_get_template_html( $this->template_html, $this->template_vars( false ), '', $this->template_base );
    }

    public function get_content_plain() {
        if ( ! $this->object ) {
            return $this->get_heading() . "\n\n" . __( '[Vista previa — necesitas un pedido real para ver el contenido completo]', 'mad-suite' );
        }

        return wc_get_template_html( $this->template_plain, $this->template_vars( true ), '', $this->template_base );
    }

    /** 'a'|'b'|'c'|'d' según el tipo de pedido y si le quedan intentos. */
    private function variant_for( WC_Order $order ): string {
        $is_pro    = 'pro_purchase' === $order->get_meta( '_mad_order_type' );
        $remaining = $this->reservations->order_attempts_remaining( $order );

        if ( $is_pro ) return $remaining > 0 ? 'c' : 'd';
        return $remaining > 0 ? 'a' : 'b';
    }

    private function template_vars( bool $plain_text ): array {
        $order   = $this->object;
        $variant = $this->variant_for( $order );
        $is_pro  = in_array( $variant, [ 'c', 'd' ], true );
        $has_attempts = in_array( $variant, [ 'a', 'c' ], true );

        $retry_url = '';
        if ( $has_attempts ) {
            if ( $is_pro ) {
                $retry_url = apply_filters( 'mad_quotes_recompra_url', wc_get_page_permalink( 'shop' ), $order );
            } else {
                $parent_quote = wc_get_order( (int) $order->get_meta( '_mad_quote_parent' ) );
                $retry_url    = $parent_quote instanceof WC_Order ? mad_quotes_quote_selection_url( $parent_quote ) : '';
            }
        }

        return [
            'order'              => $order,
            'email_heading'      => $this->get_heading(),
            'body_text'          => $this->get_body_text( $order, $variant ),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain_text,
            'email'              => $this,
            'retry_url'          => $retry_url,
            'retry_label'        => $is_pro ? __( 'Volver a comprar', 'mad-suite' ) : __( 'Volver a reservar', 'mad-suite' ),
        ];
    }

    public function get_default_subject() {
        return __( 'Su reserva ha caducado — Olofane', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Su reserva ha caducado', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults( 'a' )['es'];
    }

    /** ES/EN/FR tal cual (sección 6.3), una variante a la vez. */
    private function body_text_defaults( string $variant ): array {
        $texts = [
            'a' => [
                'es' => "El plazo de su reserva ha finalizado sin que hayamos recibido el comprobante, por lo que las piezas vuelven a estar disponibles.\n\n"
                    . "Si todavía le interesan, puede volver a reservarlas desde su lista de precios. {plural:intentos|Le queda|Le quedan} {intentos} {plural:intentos|intento|intentos} de reserva.\n\n"
                    . 'Quedamos a su disposición para cualquier consulta.',
                'en' => "Your reservation period has ended without us receiving the proof of payment, so the pieces are available again.\n\n"
                    . "If you are still interested, you can reserve them again from your price list. You have {intentos} {plural:intentos|reservation attempt|reservation attempts} left.\n\n"
                    . 'We remain at your disposal for any questions.',
                'fr' => "Le délai de votre réservation est écoulé sans que nous ayons reçu le justificatif ; les pièces sont donc de nouveau disponibles.\n\n"
                    . "Si elles vous intéressent toujours, vous pouvez les réserver à nouveau depuis votre liste de prix. Il vous reste {intentos} {plural:intentos|tentative|tentatives} de réservation.\n\n"
                    . 'Nous restons à votre disposition pour toute question.',
            ],
            'b' => [
                'es' => "El plazo de su reserva ha finalizado sin que hayamos recibido el comprobante, por lo que las piezas vuelven a estar disponibles.\n\n"
                    . "Ha utilizado los intentos de reserva disponibles por hoy. Podrá volver a reservar a partir del {proximo_intento} desde su lista de precios.\n\n"
                    . 'Si prefiere no esperar, escríbanos a info@olofane.com o llámenos al 91 365 46 50 y le ayudaremos a completar la compra.',
                'en' => "Your reservation period has ended without us receiving the proof of payment, so the pieces are available again.\n\n"
                    . "You have used today's reservation attempts. You will be able to reserve again from {proximo_intento} from your price list.\n\n"
                    . 'If you would rather not wait, email us at info@olofane.com or call +34 91 365 46 50 and we will help you complete your purchase.',
                'fr' => "Le délai de votre réservation est écoulé sans que nous ayons reçu le justificatif ; les pièces sont donc de nouveau disponibles.\n\n"
                    . "Vous avez utilisé vos tentatives de réservation pour aujourd'hui. Vous pourrez réserver à nouveau à partir du {proximo_intento} depuis votre liste de prix.\n\n"
                    . "Si vous préférez ne pas attendre, écrivez-nous à info@olofane.com ou appelez le +34 91 365 46 50 et nous vous aiderons à finaliser votre achat.",
            ],
            'c' => [
                'es' => "El plazo de su reserva ha finalizado sin que hayamos recibido el comprobante, por lo que su pedido #{order_number} se ha cancelado y las piezas vuelven a estar disponibles.\n\n"
                    . "Si todavía le interesan, puede volver a comprarlas desde el siguiente enlace mientras sigan disponibles. {plural:intentos|Le queda|Le quedan} {intentos} {plural:intentos|intento|intentos} de reserva para estas piezas.\n\n"
                    . 'Quedamos a su disposición para cualquier consulta.',
                'en' => "Your reservation period has ended without us receiving the proof of payment, so your order #{order_number} has been cancelled and the pieces are available again.\n\n"
                    . "If you are still interested, you can purchase them again using the link below while they remain available. You have {intentos} {plural:intentos|reservation attempt|reservation attempts} left for these pieces.\n\n"
                    . 'We remain at your disposal for any questions.',
                'fr' => "Le délai de votre réservation est écoulé sans que nous ayons reçu le justificatif ; votre commande n°{order_number} a donc été annulée et les pièces sont de nouveau disponibles.\n\n"
                    . "Si elles vous intéressent toujours, vous pouvez les acheter à nouveau via le lien ci-dessous tant qu'elles restent disponibles. Il vous reste {intentos} {plural:intentos|tentative|tentatives} de réservation pour ces pièces.\n\n"
                    . 'Nous restons à votre disposition pour toute question.',
            ],
            'd' => [
                'es' => "El plazo de su reserva ha finalizado sin que hayamos recibido el comprobante, por lo que su pedido #{order_number} se ha cancelado y las piezas vuelven a estar disponibles.\n\n"
                    . "Ha utilizado los intentos de reserva disponibles para estas piezas. Podrá volver a comprarlas a partir del {proximo_intento}, si siguen disponibles.\n\n"
                    . 'Si prefiere no esperar, escríbanos a info@olofane.com o llámenos al 91 365 46 50 y le ayudaremos a completar la compra.',
                'en' => "Your reservation period has ended without us receiving the proof of payment, so your order #{order_number} has been cancelled and the pieces are available again.\n\n"
                    . "You have used the reservation attempts available for these pieces. You will be able to purchase them again from {proximo_intento}, if they are still available.\n\n"
                    . 'If you would rather not wait, email us at info@olofane.com or call +34 91 365 46 50 and we will help you complete your purchase.',
                'fr' => "Le délai de votre réservation est écoulé sans que nous ayons reçu le justificatif ; votre commande n°{order_number} a donc été annulée et les pièces sont de nouveau disponibles.\n\n"
                    . "Vous avez utilisé les tentatives de réservation disponibles pour ces pièces. Vous pourrez les acheter à nouveau à partir du {proximo_intento}, si elles sont toujours disponibles.\n\n"
                    . "Si vous préférez ne pas attendre, écrivez-nous à info@olofane.com ou appelez le +34 91 365 46 50 et nous vous aiderons à finaliser votre achat.",
            ],
        ];

        return $texts[ $variant ];
    }

    private function variant_labels(): array {
        return [
            'a' => __( 'Variante A — presupuesto, con intentos', 'mad-suite' ),
            'b' => __( 'Variante B — presupuesto, sin intentos', 'mad-suite' ),
            'c' => __( 'Variante C — profesional, con intentos', 'mad-suite' ),
            'd' => __( 'Variante D — profesional, sin intentos', 'mad-suite' ),
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Su reserva ha caducado — Olofane',
                'en' => 'Your reservation has expired — Olofane',
                'fr' => 'Votre réservation a expiré — Olofane',
            ],
            'heading' => [
                'es' => 'Su reserva ha caducado',
                'en' => 'Your reservation has expired',
                'fr' => 'Votre réservation a expiré',
            ],
        ] );

        foreach ( $this->variant_labels() as $variant => $label ) {
            $this->add_body_text_form_fields(
                $this->form_fields,
                __( 'Usa {intentos}, {proximo_intento}, {order_number} y {plural:intentos|singular|plural} según corresponda a esta variante.', 'mad-suite' ),
                $this->body_text_defaults( $variant ),
                'body_text_' . $variant,
                $label
            );
        }
    }

    private function get_body_text( WC_Order $order, string $variant ): string {
        $lang      = mad_quotes_order_lang( $order );
        $remaining = $this->reservations->order_attempts_remaining( $order );
        $next_at   = $this->reservations->order_next_attempt_at( $order );

        $text = $this->get_text_for_lang( 'body_text_' . $variant, $this->body_text_defaults( $variant )['es'] );
        $text = mad_quotes_apply_plural_placeholders( $text, [ 'intentos' => $remaining ], $lang );
        $text = str_replace( '{intentos}', (string) $remaining, $text );
        $text = str_replace( '{order_number}', $order->get_order_number(), $text );

        if ( $next_at ) {
            $text = str_replace(
                '{proximo_intento}',
                mad_quotes_madrid_time( $next_at ) . ' (' . date_i18n( 'd/m/Y', $next_at ) . ')',
                $text
            );
        }

        return $text;
    }

    private function get_preview_fallback_html(): string {
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo wp_kses_post( wpautop( $this->get_text_for_lang( 'body_text_a', $this->body_text_defaults( 'a' )['es'] ) ) );
        if ( $this->get_additional_content() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) );
        }
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }
}
