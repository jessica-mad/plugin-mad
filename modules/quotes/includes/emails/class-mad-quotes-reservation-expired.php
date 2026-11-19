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
        $this->description    = __( 'Se envía cuando una reserva con plazo caduca sin comprobante, o el admin rechaza el comprobante subido.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Tu reserva ha caducado', 'mad-suite' );
        $this->subject = __( '[{blogname}] Reserva caducada — Pedido #{order_number}', 'mad-suite' );

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

    private function template_vars( bool $plain_text ): array {
        $order    = $this->object;
        $is_pro   = 'pro_purchase' === $order->get_meta( '_mad_order_type' );
        $lang     = mad_quotes_order_lang( $order );
        $settings = mad_quotes_get_settings();

        $remaining = $this->reservations->order_attempts_remaining( $order );
        $next_at   = $this->reservations->order_next_attempt_at( $order );

        $retry_url = '';
        if ( $remaining > 0 ) {
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
            'body_text'          => $this->get_body_text( $order ),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain_text,
            'email'              => $this,
            'is_pro'             => $is_pro,
            'has_attempts'       => $remaining > 0,
            'attempts_text'      => $remaining > 0 ? $this->attempts_remaining_text( $remaining, $lang ) : '',
            'no_attempts_text'   => $remaining > 0 ? '' : $this->no_attempts_text( $next_at, $lang, $settings ),
            'retry_url'          => $retry_url,
            'retry_label'        => $is_pro ? __( 'Volver a comprar', 'mad-suite' ) : __( 'Volver a reservar', 'mad-suite' ),
        ];
    }

    public function get_default_subject() {
        return __( '[{blogname}] Reserva caducada — Pedido #{order_number}', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Tu reserva ha caducado', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( 'Hola {customer_name}, tu reserva de {piezas} ha caducado porque no recibimos el comprobante de pago a tiempo. Las piezas han vuelto a estar disponibles para otros clientes.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {customer_name} y {piezas}. Los intentos restantes (o la próxima fecha disponible) se añaden automáticamente debajo.', 'mad-suite' )
        );
    }

    private function get_body_text( WC_Order $order ): string {
        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{customer_name}', $order->get_billing_first_name(), $text );
        $text = str_replace( '{piezas}', mad_quotes_order_items_text_list( $order ), $text );
        return $text;
    }

    /** "{plural:intentos|...}" ya resuelto, en los 3 idiomas — mismo texto que la página de selección. */
    private function attempts_remaining_text( int $remaining, string $lang ): string {
        $templates = [
            'es' => '{plural:intentos|Te queda|Te quedan} {intentos} {plural:intentos|intento|intentos} de reserva.',
            'en' => 'You have {intentos} {plural:intentos|attempt|attempts} left to reserve.',
            'fr' => 'Il vous reste {intentos} {plural:intentos|tentative|tentatives} de réservation.',
        ];
        $text = mad_quotes_apply_plural_placeholders( $templates[ $lang ] ?? $templates['es'], [ 'intentos' => $remaining ], $lang );
        return str_replace( '{intentos}', (string) $remaining, $text );
    }

    /** Sin intentos: próxima fecha disponible (hora de Madrid) + contacto. */
    private function no_attempts_text( ?int $next_at, string $lang, array $settings ): string {
        $when    = $next_at ? ( mad_quotes_madrid_time( $next_at ) . ' (' . date_i18n( 'd/m/Y', $next_at ) . ')' ) : '';
        $contact = trim( trim( (string) ( $settings['contact_email'] ?? '' ) ) . ' · ' . trim( (string) ( $settings['contact_phone'] ?? '' ) ), ' ·' );

        $templates = [
            'es' => 'Has agotado tus intentos de reserva. Podrás volver a intentarlo a partir del %1$s. Si necesitas ayuda, contáctanos: %2$s.',
            'en' => 'You have used all your reservation attempts. You can try again from %1$s. If you need help, contact us: %2$s.',
            'fr' => 'Vous avez épuisé vos tentatives de réservation. Vous pourrez réessayer à partir du %1$s. Besoin d\'aide ? Contactez-nous : %2$s.',
        ];
        return sprintf( $templates[ $lang ] ?? $templates['es'], $when, $contact );
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
