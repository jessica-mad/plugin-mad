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
     * Get body text for the current WPML language.
     * Falls back to the default-language option, then to the supplied default string.
     *
     * @param  string $default_text
     * @return string
     */
    private function get_body_text_for_lang( string $default_text ): string {
        $langs = $this->get_wpml_languages();
        if ( empty( $langs ) ) {
            return $this->get_option( 'body_text', $default_text );
        }

        $current_lang = (string) apply_filters( 'wpml_current_language', null );
        $default_lang = (string) apply_filters( 'wpml_default_language', null );

        $text = '';
        if ( $current_lang ) {
            $text = $this->get_option( 'body_text_' . $current_lang, '' );
        }
        if ( $text === '' && $default_lang && $default_lang !== $current_lang ) {
            $text = $this->get_option( 'body_text_' . $default_lang, '' );
        }
        return $text !== '' ? $text : $default_text;
    }

    /**
     * Resuelve {campo}_{idioma_actual} → {campo}_{idioma_por_defecto} → ''
     * (sin WPML, o sin override en ningún idioma: el llamador cae al campo
     * nativo de WC_Email — subject/heading/additional_content — que sigue
     * existiendo siempre, tal como pide la sección 6.0).
     */
    private function get_lang_option_value( string $field ): string {
        $langs = $this->get_wpml_languages();
        if ( empty( $langs ) ) return '';

        $current_lang = (string) apply_filters( 'wpml_current_language', null );
        $default_lang = (string) apply_filters( 'wpml_default_language', null );

        $value = $current_lang ? $this->get_option( $field . '_' . $current_lang, '' ) : '';
        if ( '' === $value && $default_lang && $default_lang !== $current_lang ) {
            $value = $this->get_option( $field . '_' . $default_lang, '' );
        }
        return $value;
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

    /**
     * Añade subject_{idioma}/heading_{idioma}/additional_content_{idioma}
     * por cada idioma activo de WPML — ADEMÁS de los campos nativos
     * "Asunto"/"Encabezado"/"Contenido adicional" de WC_Email (que no se
     * tocan: siguen siendo el valor de reserva cuando un idioma no tiene
     * override propio). Sin WPML no añade nada — el comportamiento de
     * siempre, un único valor para todos.
     */
    private function add_subject_heading_footer_form_fields( array &$form_fields ): void {
        $langs = $this->get_wpml_languages();
        if ( empty( $langs ) ) return;

        foreach ( $langs as $code => $name ) {
            $suffix = sprintf( ' <small>(%s)</small>', esc_html( strtoupper( $code ) ) );

            $form_fields[ 'subject_' . $code ] = [
                'title'       => __( 'Asunto', 'mad-suite' ) . $suffix,
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Vacío = se usa el "Asunto" de arriba.', 'mad-suite' ),
                'placeholder' => method_exists( $this, 'get_default_subject' ) ? $this->get_default_subject() : '',
                'default'     => '',
            ];
            $form_fields[ 'heading_' . $code ] = [
                'title'       => __( 'Encabezado', 'mad-suite' ) . $suffix,
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => __( 'Vacío = se usa el "Encabezado" de arriba.', 'mad-suite' ),
                'placeholder' => method_exists( $this, 'get_default_heading' ) ? $this->get_default_heading() : '',
                'default'     => '',
            ];
            $form_fields[ 'additional_content_' . $code ] = [
                'title'       => __( 'Contenido adicional (firma)', 'mad-suite' ) . $suffix,
                'type'        => 'textarea',
                'desc_tip'    => true,
                'description' => __( 'Vacío = se usa el "Contenido adicional" de arriba.', 'mad-suite' ),
                'css'         => 'width:400px;height:60px;',
                'default'     => '',
            ];
        }
    }

    /**
     * Append per-language body_text form fields (or a single field when WPML is absent).
     *
     * @param  array  $form_fields  Reference to the email's form_fields array.
     * @param  string $description  Optional custom field description.
     */
    private function add_body_text_form_fields( array &$form_fields, string $description = '' ): void {
        $langs = $this->get_wpml_languages();
        $desc  = $description ?: __( 'Usa {customer_name} para insertar el nombre del cliente.', 'mad-suite' );

        if ( empty( $langs ) ) {
            $form_fields['body_text'] = [
                'title'       => __( 'Cuerpo del email', 'mad-suite' ),
                'type'        => 'textarea',
                'description' => $desc,
                'default'     => $this->get_default_body_text(),
                'css'         => 'width:400px;height:120px;',
            ];
            return;
        }

        foreach ( $langs as $code => $name ) {
            $form_fields[ 'body_text_' . $code ] = [
                'title'       => sprintf(
                    '%s <small>(%s)</small>',
                    __( 'Cuerpo del email', 'mad-suite' ),
                    esc_html( strtoupper( $code ) )
                ),
                'type'        => 'textarea',
                'description' => $desc,
                'default'     => $this->get_default_body_text(),
                'css'         => 'width:400px;height:120px;',
            ];
        }
    }
}
