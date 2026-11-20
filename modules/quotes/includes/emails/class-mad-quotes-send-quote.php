<?php
/**
 * Customer email: quote ready / send quote.
 *
 * The admin triggers this manually from the order edit screen once prices
 * have been set. Includes an optional admin note.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Quotes_Email_Send_Quote extends WC_Email {

    use MAD_Email_WPML_Trait;

    public function __construct() {
        $this->id             = 'mad_quotes_send_quote';
        $this->title          = __( '[MAD Quotes] Presupuesto enviado al cliente', 'mad-suite' );
        $this->description    = __( 'Se envía al cliente cuando el administrador envía el presupuesto finalizado.', 'mad-suite' );
        $this->customer_email = true;

        $this->heading = __( 'Su lista de precios', 'mad-suite' );
        $this->subject = __( 'Su lista de precios — Olofane', 'mad-suite' );

        $this->template_html  = 'emails/mad-quote-send.php';
        $this->template_plain = 'emails/plain/mad-quote-send.php';
        $this->template_base  = MAD_QUOTES_TEMPLATE_PATH;

        // accepted_args=2: sin esto, WordPress solo pasa $order_id a trigger()
        // (el default es 1 argumento) y $admin_note se pierde siempre, por
        // más que se escriba algo en el campo de nota del admin.
        add_action( 'mad_quotes_send_quote_notification', [ $this, 'trigger' ], 10, 2 );

        parent::__construct();
    }

    /**
     * Fire the email.
     *
     * @param int    $order_id
     * @param string $admin_note  Optional note from admin shown in the email body.
     */
    public function trigger( $order_id, $admin_note = '' ) {
        if ( ! $order_id ) return;

        $send = apply_filters( 'mad_quotes_send_quote_email', true, $order_id );
        if ( ! $send ) return;

        $this->object = wc_get_order( $order_id );
        if ( ! $this->object ) return;

        if ( ! $this->is_enabled() ) return;

        $this->recipient = $this->object->get_billing_email();
        if ( ! $this->get_recipient() ) return;

        $this->placeholders['{order_date}']   = date_i18n( wc_date_format(), strtotime( $this->object->get_date_created() ) );
        $this->placeholders['{order_number}'] = $this->object->get_order_number();
        $this->admin_note = sanitize_textarea_field( $admin_note );

        $orig_lang = $this->switch_to_order_language( $this->object );

        // Capturado ANTES de restaurar el idioma — ver la misma nota en
        // class-mad-quotes-confirmation.php.
        $content = $this->get_content();

        $sent = $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $content,
            $this->get_headers(),
            $this->get_attachments()
        );

        $this->restore_order_language( $orig_lang );

        do_action( 'mad_quotes_email_logged', $order_id, $this->id, $this->get_recipient(), (bool) $sent, $content );
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
                'admin_note'         => $this->admin_note ?? '',
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
                'admin_note'         => $this->admin_note ?? '',
            ],
            '',
            $this->template_base
        );
    }

    public function get_default_subject() {
        return __( 'Su lista de precios — Olofane', 'mad-suite' );
    }

    public function get_default_heading() {
        return __( 'Su lista de precios', 'mad-suite' );
    }

    public function get_default_body_text(): string {
        return $this->body_text_defaults()['es'];
    }

    /** ES/EN/FR tal cual (sección 6.1). */
    private function body_text_defaults(): array {
        return [
            'es' => "Le enviamos la lista de precios correspondiente a su solicitud.\n\n"
                . "Desde el siguiente enlace podrá seleccionar las piezas que desea adquirir. Al confirmar su selección, quedarán en proceso de compra para usted durante {plazo}, el tiempo para realizar la transferencia y adjuntar el comprobante.\n\n"
                . "Le recordamos que, al tratarse de artículos únicos, las piezas no quedan en proceso de compra hasta ese momento: permanecen disponibles hasta que la compra se confirma con el pago.\n\n"
                . "Dispone de {intentos_total} {plural:intentos_total|intento|intentos} de compra cada {ventana}. Si no pudiera completar la compra a tiempo, podrá volver a intentarlo desde este mismo enlace mientras su presupuesto esté vigente.\n\n"
                . "Si necesita transporte, consúltenos y le prepararemos un presupuesto personalizado. También puede recoger su pieza en nuestra tienda de Madrid.\n\n"
                . 'Quedamos a su disposición para cualquier consulta.',
            'en' => "We are sending you the price list corresponding to your request.\n\n"
                . "Using the link below, you can select the pieces you would like to purchase. Once you confirm your selection, they will be put into the purchase process for you for {plazo}, giving you time to make the bank transfer and attach the proof of payment.\n\n"
                . "Please note that, as these are one-of-a-kind items, the pieces are not put into the purchase process until then: they remain available until the purchase is confirmed with payment.\n\n"
                . "You have {intentos_total} {plural:intentos_total|purchase attempt|purchase attempts} every {ventana}. If you are unable to complete the purchase in time, you can try again from this same link while your quote remains valid.\n\n"
                . "If you need delivery, get in touch and we will prepare a personalised shipping quote. You can also collect your piece at our shop in Madrid.\n\n"
                . 'We remain at your disposal for any questions.',
            'fr' => "Nous vous envoyons la liste de prix correspondant à votre demande.\n\n"
                . "Le lien ci-dessous vous permet de sélectionner les pièces que vous souhaitez acquérir. Une fois votre sélection confirmée, elles seront mises en cours d'achat pour vous pendant {plazo}, le temps d'effectuer le virement et de joindre le justificatif.\n\n"
                . "Nous vous rappelons que, s'agissant d'articles uniques, les pièces ne sont pas mises en cours d'achat jusque-là : elles restent disponibles jusqu'à la confirmation de l'achat par le paiement.\n\n"
                . "Vous disposez de {intentos_total} {plural:intentos_total|tentative|tentatives} d'achat sur une période de {ventana}. Si vous ne pouviez pas finaliser l'achat à temps, vous pourrez réessayer depuis ce même lien tant que votre devis reste valable.\n\n"
                . "Si vous avez besoin d'une livraison, contactez-nous et nous vous préparerons un devis de transport personnalisé. Vous pouvez également retirer votre pièce dans notre boutique de Madrid.\n\n"
                . 'Nous restons à votre disposition pour toute question.',
        ];
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->add_subject_heading_footer_form_fields( $this->form_fields, [
            'subject' => [
                'es' => 'Su lista de precios — Olofane',
                'en' => 'Your price list — Olofane',
                'fr' => 'Votre liste de prix — Olofane',
            ],
            'heading' => [
                'es' => 'Su lista de precios',
                'en' => 'Your price list',
                'fr' => 'Votre liste de prix',
            ],
        ] );
        $this->add_body_text_form_fields(
            $this->form_fields,
            __( 'Usa {plazo} (plazo de reserva), {intentos_total}, {ventana} y {plural:intentos_total|singular|plural} (sección "Reservas con plazo" de los ajustes).', 'mad-suite' ),
            $this->body_text_defaults()
        );
    }

    private function get_body_text( WC_Order $order ): string {
        $lang     = mad_quotes_order_lang( $order );
        $settings = mad_quotes_get_settings();
        $intentos = (int) $settings['reservation_attempts'];

        $text = $this->get_body_text_for_lang( $this->get_default_body_text() );
        $text = mad_quotes_apply_plural_placeholders( $text, [ 'intentos_total' => $intentos ], $lang );
        $text = str_replace( '{plazo}', mad_quotes_format_duration( (int) $settings['reservation_minutes'], $lang ), $text );
        $text = str_replace( '{intentos_total}', (string) $intentos, $text );
        $text = str_replace( '{ventana}', mad_quotes_format_window( (int) $settings['reservation_window_hours'], $lang ), $text );
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
