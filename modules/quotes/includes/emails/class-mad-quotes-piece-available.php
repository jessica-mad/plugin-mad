<?php
/**
 * Customer email: the piece a visitor subscribed to is available again
 * (section 4 of the "cambios tras la prueba" document — "Avísenme").
 *
 * Fires once per subscriber, in their own stored language, when a piece's
 * purchase is NOT completed (expiry, rejected proof, or manual admin void)
 * and it becomes available again. Never fires when the piece is sold — the
 * subscriber list is cleared silently in that case (see
 * MAD_Reservations::release_reservation()).
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Piece_Available extends WC_Email {

    use MAD_Email_WPML_Trait;

    /** @var string */
    private $piece_name = '';

    /** @var string */
    private $piece_url = '';

    /** @var bool true mientras se está enviando de verdad (no en la vista previa del admin). */
    private $rendering = false;

    public function __construct() {
        $this->id             = 'mad_quotes_piece_available';
        $this->title          = __( '[MAD Quotes] La pieza sigue disponible (cliente)', 'mad-suite' );
        $this->description    = __( 'Se envía a cada inscrito en "Avísenme" cuando la compra de esa pieza no se completa (caducidad, comprobante rechazado o anulación manual) y vuelve a estar disponible. Nunca se envía si la pieza se vende.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'La pieza sigue disponible', 'mad-suite' );
        $this->subject = __( '{pieza} vuelve a estar disponible — Olofane', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-piece-available.php';
        $this->template_plain = 'emails/plain/mad-quote-piece-available.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        add_action( 'mad_quotes_piece_available_again', [ $this, 'trigger' ], 10, 2 );

        parent::__construct();
    }

    /**
     * @param int   $product_id
     * @param array $subscribers Lista de ['email'=>string,'lang'=>string,'time'=>int] — ya se le quita la meta al producto antes de llamar aquí.
     */
    public function trigger( $product_id, array $subscribers ) {
        if ( ! $this->is_enabled() || empty( $subscribers ) ) return;

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $this->piece_name = $product->get_name();
        $this->piece_url  = get_permalink( $product_id );
        $this->rendering  = true;

        foreach ( $subscribers as $entry ) {
            $email = isset( $entry['email'] ) ? sanitize_email( (string) $entry['email'] ) : '';
            if ( ! $email || ! is_email( $email ) ) continue;

            $lang = in_array( $entry['lang'] ?? '', [ 'es', 'en', 'fr' ], true ) ? $entry['lang'] : 'es';
            $orig = $this->switch_lang( $lang );

            $this->recipient                  = $email;
            $this->placeholders['{pieza}']     = $this->piece_name;
            $content = $this->get_content();

            $sent = $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $content,
                $this->get_headers(),
                $this->get_attachments()
            );

            $this->restore_lang( $orig );

            do_action( 'mad_quotes_email_logged', $product_id, $this->id, $email, (bool) $sent, $content );
        }

        $this->rendering = false;
    }

    /** Igual que MAD_Email_WPML_Trait::switch_to_order_language(), pero a partir de un código de idioma directo — aquí no hay pedido, cada inscrito guarda ya su propio idioma. */
    private function switch_lang( string $lang ): ?string {
        $orig = apply_filters( 'wpml_current_language', null );
        if ( ! $orig || $lang === $orig ) return null;

        do_action( 'wpml_switch_language', $lang );
        $locale = apply_filters( 'wpml_language_locale', null, $lang );
        if ( $locale ) switch_to_locale( $locale );

        return $orig;
    }

    private function restore_lang( ?string $orig ): void {
        if ( null === $orig ) return;
        do_action( 'wpml_switch_language', $orig );
        restore_current_locale();
    }

    public function get_content_html() {
        if ( ! $this->rendering ) {
            return $this->get_preview_fallback_html();
        }

        return wc_get_template_html( $this->template_html, $this->template_vars( false ), '', $this->template_base );
    }

    public function get_content_plain() {
        if ( ! $this->rendering ) {
            return $this->get_heading() . "\n\n" . __( '[Vista previa — necesitas un envío real para ver el contenido completo]', 'mad-suite' );
        }

        return wc_get_template_html( $this->template_plain, $this->template_vars( true ), '', $this->template_base );
    }

    private function template_vars( bool $plain_text ): array {
        return [
            'email_heading'      => $this->get_heading(),
            'body_text'          => $this->get_body_text(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin'      => false,
            'plain_text'         => $plain_text,
            'email'              => $this,
            'piece_url'          => $this->piece_url,
            'piece_name'         => $this->piece_name,
        ];
    }

    public function get_default_subject() {
        return __( '{pieza} vuelve a estar disponible — Olofane', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'La pieza sigue disponible', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 4 del documento de cambios). */
    private function body_text_defaults(): array {
        return [
            'es' => 'Le escribimos porque nos pidió que le avisáramos: la compra de {pieza} no se ha completado y la pieza vuelve a estar disponible. Al tratarse de una pieza única, otra persona podría comprarla en cualquier momento. Quedamos a su disposición para cualquier consulta.',
            'en' => 'We are writing because you asked us to let you know: the purchase of {pieza} was not completed and the piece is available again. As it is a one-of-a-kind piece, someone else may purchase it at any time. We remain at your disposal for any questions.',
            'fr' => "Nous vous écrivons car vous nous avez demandé de vous prévenir : l'achat de {pieza} n'a pas abouti et la pièce est de nouveau disponible. S'agissant d'une pièce unique, quelqu'un d'autre pourrait l'acheter à tout moment. Nous restons à votre disposition pour toute question.",
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => '{pieza} vuelve a estar disponible — Olofane',
                'en' => '{pieza} is available again — Olofane',
                'fr' => '{pieza} est de nouveau disponible — Olofane',
            ],
            'heading' => [
                'es' => 'La pieza sigue disponible',
                'en' => 'The piece is still available',
                'fr' => 'La pièce est toujours disponible',
            ],
        ] );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {pieza}.', 'mad-suite' ),
            $this->body_text_defaults()
        );
    }

    private function get_body_text(): string {
        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        return str_replace( '{pieza}', $this->piece_name, $text );
    }

    private function get_preview_fallback_html(): string {
        ob_start();
        do_action( 'woocommerce_email_header', $this->get_heading(), $this );
        echo wp_kses_post( wpautop( str_replace( '{pieza}', __( 'Pieza de ejemplo', 'mad-suite' ), $this->get_text_for_default_lang( 'body_text', $this->get_default_body_text() ) ) ) );
        if ( $this->get_additional_content() ) {
            echo wp_kses_post( wpautop( wptexturize( $this->get_additional_content() ) ) );
        }
        do_action( 'woocommerce_email_footer', $this );
        return ob_get_clean();
    }
}
