<?php
/**
 * Customer email: reservation voided by an admin (6.6).
 *
 * Fires when a reservation is manually voided — typically because the
 * piece sold in the physical shop and stock was set to 0 by hand (ATUM or
 * wp-admin). The consumed attempt is returned to the customer.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Reservation_Cancelled extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var MAD_Reservations */
    private $reservations;

    public function __construct() {
        $this->id             = 'mad_quotes_reservation_cancelled';
        $this->title          = __( '[MAD Quotes] Reserva anulada (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía cuando el admin anula manualmente una reserva (p. ej. la pieza se vendió en la tienda física). El intento consumido se devuelve al cliente.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Reserva anulada', 'mad-suite' );
        $this->subject = __( 'Su reserva ha sido anulada — Olofane', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-reservation-cancelled.php';
        $this->template_plain = 'emails/plain/mad-quote-reservation-cancelled.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        $this->reservations = new MAD_Reservations();

        add_action( 'mad_reservation_voided', [ $this, 'trigger' ], 10, 1 );

        parent::__construct();
    }

    public function trigger( $order ) {
        if ( ! $order instanceof WC_Order ) return;

        // El intento se devuelve siempre, esté o no activado este email —
        // es un efecto de la anulación, no de la notificación. Guarda
        // propia por si mad_reservation_voided se disparase más de una vez
        // para el mismo pedido.
        if ( '1' !== $order->get_meta( '_mad_attempt_returned' ) ) {
            $order->update_meta_data( '_mad_attempt_returned', '1' );
            $order->save();
            $this->reservations->return_attempt_for_order( $order );
        }

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
        return __( 'Su reserva ha sido anulada — Olofane', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Reserva anulada', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 6.6). */
    private function body_text_defaults(): array {
        return [
            'es' => 'Lamentamos informarle de que hemos tenido que anular su reserva porque la pieza ya no está disponible. No se ha descontado ningún intento de reserva. Quedamos a su disposición para cualquier consulta.',
            'en' => 'We regret to inform you that we have had to cancel your reservation as the piece is no longer available. No reservation attempt has been used. We remain at your disposal for any questions.',
            'fr' => "Nous sommes au regret de vous informer que nous avons dû annuler votre réservation, la pièce n'étant plus disponible. Aucune tentative de réservation n'a été décomptée. Nous restons à votre disposition pour toute question.",
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Su reserva ha sido anulada — Olofane',
                'en' => 'Your reservation has been cancelled — Olofane',
                'fr' => 'Votre réservation a été annulée — Olofane',
            ],
            'heading' => [
                'es' => 'Reserva anulada',
                'en' => 'Reservation cancelled',
                'fr' => 'Réservation annulée',
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
