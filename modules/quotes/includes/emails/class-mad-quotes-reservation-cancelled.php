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

        $this->heading = __( 'Tu reserva ha sido anulada', 'mad-suite' );
        $this->subject = __( '[{blogname}] Reserva anulada — Pedido #{order_number}', 'mad-suite' );

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
        // es un efecto de la anulación, no de la notificación.
        $this->reservations->return_attempt_for_order( $order );

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
        return __( '[{blogname}] Reserva anulada — Pedido #{order_number}', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Tu reserva ha sido anulada', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( 'Hola {customer_name}, lamentamos informarte de que tu reserva de {piezas} ha sido anulada por nuestro equipo. Te hemos devuelto el intento de reserva utilizado, así que puedes volver a intentarlo cuando quieras.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {customer_name} y {piezas}.', 'mad-suite' )
        );
    }

    private function get_body_text( WC_Order $order ): string {
        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{customer_name}', $order->get_billing_first_name(), $text );
        $text = str_replace( '{piezas}', mad_quotes_order_items_text_list( $order ), $text );
        return $text;
    }

    private function get_preview_fallback_html(): string {
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo wp_kses_post( wpautop( $this->get_body_text_for_lang( $this->get_default_body_text() ) ) );
        if ( $this->get_additional_content() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) );
        }
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }
}
