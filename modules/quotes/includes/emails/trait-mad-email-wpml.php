<?php
/**
 * Shared WPML helpers for MAD Quotes email classes.
 *
 * @package MAD_Suite/Quotes/Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

trait MAD_Email_WPML_Trait {

    /**
     * Return all WPML active languages as [ code => native_name ].
     * Returns [] when WPML is not available.
     */
    private function get_wpml_languages(): array {
        if ( ! function_exists( 'icl_get_languages' ) ) return [];
        $result = [];
        foreach ( icl_get_languages( 'skip_missing=0' ) as $code => $info ) {
            $result[ $code ] = $info['native_name'];
        }
        return $result;
    }

    /**
     * Switch WPML + WordPress locale to the language stored on the order.
     * Returns the original language code (to restore later), or null if no switch was needed.
     *
     * @param  WC_Order|null $order
     * @return string|null
     */
    private function switch_to_order_language( $order ): ?string {
        if ( ! $order ) return null;
        $order_lang = (string) $order->get_meta( '_mad_quote_lang' );
        if ( ! $order_lang ) return null;

        $orig_lang = apply_filters( 'wpml_current_language', null );
        if ( ! $orig_lang || $order_lang === $orig_lang ) return null;

        do_action( 'wpml_switch_language', $order_lang );
        $locale = apply_filters( 'wpml_language_locale', null, $order_lang );
        if ( $locale ) switch_to_locale( $locale );

        return $orig_lang;
    }

    /**
     * Restore WPML + WordPress locale after sending an email.
     *
     * @param  string|null $original Language code returned by switch_to_order_language().
     */
    private function restore_order_language( ?string $original ): void {
        if ( null === $original ) return;
        do_action( 'wpml_switch_language', $original );
        restore_current_locale();
    }

    /**
     * Valor por idioma de un campo de texto cualquiera (body_text,
     * body_text_a, subject, heading, additional_content...): idioma del
     * pedido → idioma por defecto → el valor por defecto DECLARADO para
     * ese idioma en form_fields (add_text_form_fields()) → $fallback.
     * Sin WPML, usa directamente el campo sin sufijo de idioma.
     */
    private function get_text_for_lang( string $field_name, string $fallback = '' ): string {
        $langs = $this->get_wpml_languages();
        if ( empty( $langs ) ) {
            return $this->get_option( $field_name, $fallback );
        }

        $current_lang = (string) apply_filters( 'wpml_current_language', null );
        $default_lang = (string) apply_filters( 'wpml_default_language', null );

        if ( $current_lang ) {
            $text = $this->get_option( $field_name . '_' . $current_lang, $this->lang_field_default( $field_name, $current_lang ) );
            if ( '' !== $text ) return $text;
        }
        if ( $default_lang && $default_lang !== $current_lang ) {
            $text = $this->get_option( $field_name . '_' . $default_lang, $this->lang_field_default( $field_name, $default_lang ) );
            if ( '' !== $text ) return $text;
        }
        return $fallback;
    }

    /** El "default" declarado en form_fields para {field}_{lang}, o '' si no hay ninguno. */
    private function lang_field_default( string $field_name, string $lang ): string {
        return (string) ( $this->form_fields[ $field_name . '_' . $lang ]['default'] ?? '' );
    }

    /**
     * Get body text for the current WPML language.
     * Falls back to the default-language option, then to the supplied default string.
     */
    private function get_body_text_for_lang( string $default_text ): string {
        return $this->get_text_for_lang( 'body_text', $default_text );
    }

    /**
     * Resuelve {campo}_{idioma_actual} → {campo}_{idioma_por_defecto} →
     * valor por defecto declarado para ese idioma → '' (y entonces el
     * llamador cae al campo nativo de WC_Email — subject/heading/
     * additional_content —, que sigue existiendo siempre).
     */
    private function get_lang_option_value( string $field ): string {
        return $this->get_text_for_lang( $field, '' );
    }

    /**
     * Asunto por idioma del pedido (sección 6.0 — hasta ahora el asunto era
     * un único valor en español para todos los idiomas). Pasa por
     * format_string() igual que el nativo, para que {order_number},
     * {blogname}, etc. se sigan sustituyendo.
     */
    public function get_subject() {
        $override = $this->get_lang_option_value( 'subject' );
        if ( '' === $override ) return parent::get_subject();

        return apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $override ), $this->object );
    }

    /** Encabezado por idioma del pedido — mismo mecanismo que get_subject(). */
    public function get_heading() {
        $override = $this->get_lang_option_value( 'heading' );
        if ( '' === $override ) return parent::get_heading();

        return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $override ), $this->object );
    }

    /** Firma/contenido adicional por idioma del pedido — mismo mecanismo que get_subject(). */
    public function get_additional_content() {
        $override = $this->get_lang_option_value( 'additional_content' );
        if ( '' === $override ) return parent::get_additional_content();

        return apply_filters( 'woocommerce_email_additional_content_' . $this->id, $this->format_string( $override ), $this->object );
    }

    /** "El equipo de Olofane" / "The Olofane team" / "L'équipe Olofane" — firma por defecto de todos los emails de cliente (sección 6.0). */
    private function olofane_signature_defaults(): array {
        return [
            'es' => 'El equipo de Olofane',
            'en' => 'The Olofane team',
            'fr' => "L'équipe Olofane",
        ];
    }

    /**
     * Añade subject_{idioma}/heading_{idioma}/additional_content_{idioma}
     * por cada idioma activo de WPML — ADEMÁS de los campos nativos
     * "Asunto"/"Encabezado"/"Contenido adicional" de WC_Email (que no se
     * tocan: siguen siendo el valor de reserva si WPML está desactivado).
     * Sin WPML no añade nada — el comportamiento de siempre, un único
     * valor para todos.
     *
     * @param array $defaults ['subject'=>[lang=>texto], 'heading'=>[...], 'additional_content'=>[...]].
     *                         Si se omite 'additional_content', se usa la firma de Olofane por defecto.
     */
    private function add_subject_heading_footer_form_fields( array &$form_fields, array $defaults = [] ): void {
        $langs = $this->get_wpml_languages();
        if ( empty( $langs ) ) return;

        $defaults['additional_content'] = $defaults['additional_content'] ?? $this->olofane_signature_defaults();

        foreach ( $langs as $code => $name ) {
            $suffix = sprintf( ' <small>(%s)</small>', esc_html( strtoupper( $code ) ) );

            $form_fields[ 'subject_' . $code ] = [
                'title'       => __( 'Asunto', 'mad-suite' ) . $suffix,
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Vacío = se usa el valor por defecto de este idioma.', 'mad-suite' ),
                'default'     => $defaults['subject'][ $code ] ?? '',
            ];
            $form_fields[ 'heading_' . $code ] = [
                'title'       => __( 'Encabezado', 'mad-suite' ) . $suffix,
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Vacío = se usa el valor por defecto de este idioma.', 'mad-suite' ),
                'default'     => $defaults['heading'][ $code ] ?? '',
            ];
            $form_fields[ 'additional_content_' . $code ] = [
                'title'       => __( 'Contenido adicional (firma)', 'mad-suite' ) . $suffix,
                'type'        => 'textarea',
                'desc_tip'    => true,
                'description' => __( 'Vacío = se usa el valor por defecto de este idioma.', 'mad-suite' ),
                'css'         => 'width:400px;height:60px;',
                'default'     => $defaults['additional_content'][ $code ] ?? '',
            ];
        }
    }

    /**
     * Append per-language body_text form fields (or a single field when WPML is absent).
     *
     * @param  array  $form_fields    Reference to the email's form_fields array.
     * @param  string $description    Optional custom field description.
     * @param  array  $lang_defaults  Opcional [lang => texto por defecto]. Sin esto, se usa
     *                                get_default_body_text() (un único texto) para todos los idiomas,
     *                                como antes.
     * @param  string $field_name     Nombre base del campo — "body_text" salvo que el email
     *                                tenga varias variantes de cuerpo (ej. "body_text_a").
     * @param  string $title          Título del campo en el admin.
     */
    private function add_body_text_form_fields(
        array &$form_fields,
        string $description = '',
        array $lang_defaults = [],
        string $field_name = 'body_text',
        string $title = ''
    ): void {
        $langs = $this->get_wpml_languages();
        $desc  = $description ?: __( 'Usa {customer_name} para insertar el nombre del cliente.', 'mad-suite' );
        $title = $title ?: __( 'Cuerpo del email', 'mad-suite' );

        if ( empty( $langs ) ) {
            $form_fields[ $field_name ] = [
                'title'       => $title,
                'type'        => 'textarea',
                'description' => $desc,
                'default'     => $lang_defaults['es'] ?? $this->get_default_body_text(),
                'css'         => 'width:400px;height:120px;',
            ];
            return;
        }

        foreach ( $langs as $code => $name ) {
            $form_fields[ $field_name . '_' . $code ] = [
                'title'       => sprintf( '%s <small>(%s)</small>', $title, esc_html( strtoupper( $code ) ) ),
                'type'        => 'textarea',
                'description' => $desc,
                'default'     => $lang_defaults[ $code ] ?? $this->get_default_body_text(),
                'css'         => 'width:400px;height:120px;',
            ];
        }
    }
}
