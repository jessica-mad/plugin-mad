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

        $this->heading = __( 'Tu reserva está confirmada', 'mad-suite' );
        $this->subject = __( '[{blogname}] Reserva confirmada — Pedido #{order_number}', 'mad-suite' );

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
        $this->placeholders['{total}']        = wp_strip_all_tags( wc_price( $order->get_total() ) );

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
        return __( '[{blogname}] Reserva confirmada — Pedido #{order_number}', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Tu reserva está confirmada', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( 'Hola {customer_name}, hemos reservado {piezas} para ti durante {plazo} (hasta las {hora_limite}). Realiza la transferencia con los datos de abajo y adjunta el comprobante antes de que termine el plazo — si no llega a tiempo, la reserva se cancelará y las piezas volverán a estar disponibles.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {customer_name}, {piezas}, {plazo} y {hora_limite}.', 'mad-suite' )
        );
    }

    private function get_body_text( WC_Order $order ): string {
        $lang    = mad_quotes_order_lang( $order );
        $minutes = (int) $order->get_meta( '_mad_reservation_minutes' );
        $created = $order->get_date_created();
        $until   = ( $created ? $created->getTimestamp() : time() ) + $minutes * MINUTE_IN_SECONDS;

        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{customer_name}', $order->get_billing_first_name(), $text );
        $text = str_replace( '{piezas}', mad_quotes_order_items_text_list( $order ), $text );
        $text = str_replace( '{plazo}', mad_quotes_format_duration( $minutes, $lang ), $text );
        $text = str_replace( '{hora_limite}', mad_quotes_madrid_time( $until ), $text );

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
