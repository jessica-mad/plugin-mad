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
