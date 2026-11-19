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

        $this->heading = __( 'Una pieza de tu lista de precios se ha vendido', 'mad-suite' );
        $this->subject = __( '[{blogname}] Una pieza de tu lista de precios ya no está disponible', 'mad-suite' );

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
            'body_text'          => $this->get_body_text( $quote, $sold_names ),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain_text,
            'email'              => $this,
            'available_names'    => $available_names,
            'selection_url'      => ! empty( $available_names ) ? mad_quotes_quote_selection_url( $quote ) : '',
        ];
    }

    public function get_default_subject() {
        return __( '[{blogname}] Una pieza de tu lista de precios ya no está disponible', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Una pieza de tu lista de precios se ha vendido', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return __( 'Hola {customer_name}, queremos avisarte de que {piezas} de tu lista de precios ya se ha vendido y ya no está disponible.', 'mad-suite' );
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {customer_name} y {piezas}. Si quedan otras piezas disponibles, se añade automáticamente un botón para verlas.', 'mad-suite' )
        );
    }

    private function get_body_text( WC_Order $quote, array $sold_names ): string {
        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = str_replace( '{customer_name}', $quote->get_billing_first_name(), $text );
        $text = str_replace( '{piezas}', implode( ', ', $sold_names ), $text );
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
