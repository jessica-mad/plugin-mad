<?php
/**
 * Customer email: a piece from your price list was sold (6.5).
 *
 * Fires per QUOTE (not per order) — sent to every OTHER valid quote that
 * still contained a piece that just sold, except the buyer. Several
 * pieces from the same quote selling within a 10-minute window are
 * batched into one email (MAD_Reservations::notify_pieces_sold()).
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Piece_Sold extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var MAD_Reservations */
    private $reservations;

    /** @var int[] */
    private $sold_product_ids = [];

    public function __construct() {
        $this->id             = 'mad_quotes_piece_sold';
        $this->title          = __( '[MAD Quotes] Pieza vendida (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía a los demás presupuestos vigentes que tenían una pieza que acaba de venderse — nunca al comprador.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Pieza vendida', 'mad-suite' );
        $this->subject = __( 'Una pieza de su lista de precios ya no está disponible — Olofane', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-piece-sold.php';
        $this->template_plain = 'emails/plain/mad-quote-piece-sold.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        $this->reservations = new MAD_Reservations();

        add_action( 'mad_quotes_pieces_sold_notice', [ $this, 'trigger' ], 10, 2 );

        parent::__construct();
    }

    public function trigger( $quote, array $product_ids ) {
        if ( ! $quote instanceof WC_Order ) return;
        if ( ! $this->is_enabled() ) return;
        if ( empty( $product_ids ) ) return;

        $this->object           = $quote;
        $this->sold_product_ids = array_map( 'absint', $product_ids );

        $this->recipient = $quote->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_number}'] = $quote->get_order_number();

        $orig_lang = $this->switch_to_order_language( $quote );
        $content   = $this->get_content();

        $sent = $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $content,
            $this->get_headers(),
            $this->get_attachments()
        );

        $this->restore_order_language( $orig_lang );

        do_action( 'mad_quotes_email_logged', $quote->get_id(), $this->id, $this->get_recipient(), (bool) $sent, $content );
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
        $quote = $this->object;

        $sold_names      = [];
        $available_names = [];

        foreach ( $quote->get_items() as $item ) {
            $product_id = $item->get_product_id();

            if ( in_array( $product_id, $this->sold_product_ids, true ) ) {
                $sold_names[] = $item->get_name();
                continue;
            }

            $product = $item->get_product();
            if ( $product && 'available' === $this->reservations->product_state( $product ) ) {
                $available_names[] = $item->get_name();
            }
        }

        return [
            'order'              => $quote,
            'email_heading'      => $this->get_heading(),
            'body_text'          => $this->get_body_text( $quote, $sold_names, $available_names ),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain_text,
            'email'              => $this,
            'available_names'    => $available_names,
            // Solo tiene sentido enlazar a la página de selección si el
            // presupuesto ya está enviado (quote-pending todavía no tiene
            // lista de precios que mostrar).
            'selection_url'      => ( ! empty( $available_names ) && 'quote-sent' === $quote->get_status() ) ? mad_quotes_quote_selection_url( $quote ) : '',
        ];
    }

    public function get_default_subject() {
        return __( 'Una pieza de su lista de precios ya no está disponible — Olofane', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Pieza vendida', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /**
     * ES/EN/FR tal cual (sección 6.5). {frase_disponibles} se sustituye por
     * la frase "el resto sigue disponible" o por nada si no queda ninguna
     * pieza — así el email sigue siendo UN solo texto editable por idioma,
     * sin perder la condición del documento.
     */
    private function body_text_defaults(): array {
        return [
            'es' => "Le informamos de que la siguiente pieza de su lista de precios ha sido vendida: {pieza}.\n\n"
                . "{frase_disponibles}"
                . 'Si lo desea, estaremos encantados de ayudarle a encontrar una pieza similar. Puede responder directamente a este correo.',
            'en' => "We would like to let you know that the following piece from your price list has been sold: {pieza}.\n\n"
                . "{frase_disponibles}"
                . 'If you wish, we would be happy to help you find a similar piece. You can reply directly to this email.',
            'fr' => "Nous vous informons que la pièce suivante de votre liste de prix a été vendue : {pieza}.\n\n"
                . "{frase_disponibles}"
                . 'Si vous le souhaitez, nous serons ravis de vous aider à trouver une pièce similaire. Vous pouvez répondre directement à cet e-mail.',
        ];
    }

    private function available_sentence( string $lang ): string {
        $sentences = [
            'es' => "El resto de piezas de su solicitud siguen disponibles en su lista de precios.\n\n",
            'en' => "The other pieces in your request are still available in your price list.\n\n",
            'fr' => "Les autres pièces de votre demande restent disponibles dans votre liste de prix.\n\n",
        ];
        return $sentences[ $lang ] ?? $sentences['es'];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Una pieza de su lista de precios ya no está disponible — Olofane',
                'en' => 'A piece from your price list is no longer available — Olofane',
                'fr' => "Une pièce de votre liste de prix n'est plus disponible — Olofane",
            ],
            'heading' => [
                'es' => 'Pieza vendida',
                'en' => 'Piece sold',
                'fr' => 'Pièce vendue',
            ],
        ] );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {pieza} (nombre de la pieza vendida, o la lista si se agruparon varias) y {frase_disponibles} (se sustituye por la frase de "el resto sigue disponible", u omite si no queda ninguna).', 'mad-suite' ),
            $this->body_text_defaults()
        );
    }

    private function get_body_text( WC_Order $quote, array $sold_names, array $available_names ): string {
        $lang = mad_quotes_order_lang( $quote );

        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{pieza}', implode( ', ', $sold_names ), $text );
        $text = str_replace( '{frase_disponibles}', empty( $available_names ) ? '' : $this->available_sentence( $lang ), $text );
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
