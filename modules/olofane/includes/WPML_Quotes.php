<?php
/**
 * WPML + Quotes compatibility for Olofane.
 *
 * Adds a meta box to ALL WooCommerce order edit screens with:
 *  - Customer note display
 *  - Admin reply field (Spanish input)
 *  - [Traducir] button that previews the AI translation in the customer's language
 *  - [Enviar] button that sends the translated reply via WooCommerce order note
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_WPML_Quotes {

    private array $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    public function init(): void {
        add_action( 'add_meta_boxes',                        [ $this, 'register_meta_box' ] );
        add_action( 'wp_ajax_mad_olofane_translate_reply',   [ $this, 'ajax_translate_reply' ] );
        add_action( 'wp_ajax_mad_olofane_send_reply',        [ $this, 'ajax_send_reply' ] );
        add_action( 'admin_enqueue_scripts',                 [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Meta box ─────────────────────────────────────────────────────────────

    public function register_meta_box(): void {
        // Register for WooCommerce orders (classic editor)
        foreach ( [ 'shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
            add_meta_box(
                'mad-olofane-wpml-reply',
                __( 'Respuesta al cliente (traducción WPML)', 'mad-suite' ),
                [ $this, 'render_meta_box' ],
                $screen,
                'normal',
                'default'
            );
        }

        // HPOS support (WooCommerce ≥ 7.1)
        add_meta_box(
            'mad-olofane-wpml-reply',
            __( 'Respuesta al cliente (traducción WPML)', 'mad-suite' ),
            [ $this, 'render_meta_box' ],
            wc_get_page_screen_id( 'shop-order' ),
            'normal',
            'default'
        );
    }

    public function render_meta_box( $post_or_order ): void {
        $order = $this->get_order( $post_or_order );
        if ( ! $order ) return;

        $order_id      = $order->get_id();
        $customer_note = $order->get_customer_note();
        $order_lang    = $order->get_meta( 'wpml_language' ) ?: apply_filters( 'wpml_current_language', 'es' );

        $nonce = wp_create_nonce( 'mad_olofane_reply_' . $order_id );
        ?>
        <div id="mad-olofane-reply-box" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-lang="<?php echo esc_attr( $order_lang ); ?>">

            <?php if ( $customer_note ) : ?>
                <div class="mad-olofane-customer-note" style="background:#f6f7f7;border-left:4px solid #72aee6;padding:8px 12px;margin-bottom:12px;">
                    <strong><?php esc_html_e( 'Nota del cliente:', 'mad-suite' ); ?></strong>
                    <p style="margin:4px 0 0;"><?php echo wp_kses_post( nl2br( $customer_note ) ); ?></p>
                </div>
            <?php else : ?>
                <p class="description"><?php esc_html_e( 'El cliente no dejó nota en el pedido.', 'mad-suite' ); ?></p>
            <?php endif; ?>

            <p>
                <label for="mad-olofane-reply-es"><strong><?php esc_html_e( 'Tu respuesta (en español):', 'mad-suite' ); ?></strong></label><br>
                <textarea id="mad-olofane-reply-es" rows="4" class="widefat" placeholder="<?php esc_attr_e( 'Escribe tu respuesta aquí…', 'mad-suite' ); ?>"></textarea>
            </p>

            <p>
                <button type="button" class="button" id="mad-olofane-btn-translate">
                    🌐 <?php esc_html_e( 'Traducir', 'mad-suite' ); ?>
                </button>
                <span id="mad-olofane-translate-status" style="margin-left:8px;font-style:italic;"></span>
            </p>

            <div id="mad-olofane-translation-preview" style="display:none;">
                <p>
                    <label for="mad-olofane-reply-translated">
                        <strong>
                            <?php printf(
                                /* translators: %s: language code */
                                esc_html__( 'Traducción (%s) — revisa antes de enviar:', 'mad-suite' ),
                                '<span id="mad-olofane-lang-label">' . esc_html( strtoupper( $order_lang ) ) . '</span>'
                            ); ?>
                        </strong>
                    </label><br>
                    <textarea id="mad-olofane-reply-translated" rows="4" class="widefat"></textarea>
                </p>
                <p>
                    <button type="button" class="button button-primary" id="mad-olofane-btn-send">
                        ✉️ <?php esc_html_e( 'Enviar respuesta al cliente', 'mad-suite' ); ?>
                    </button>
                    <span id="mad-olofane-send-status" style="margin-left:8px;font-style:italic;"></span>
                </p>
            </div>

            <p class="description" style="margin-top:12px;">
                <?php
                $quotes_url   = admin_url( 'admin.php?page=mad-suite-quotes' );
                $email_url    = admin_url( 'admin.php?page=wc-settings&tab=email' );
                $wpml_url     = admin_url( 'admin.php?page=wpml-string-translation/menu/string-translation.php' );
                printf(
                    '%s <a href="%s">%s</a> · <a href="%s">%s</a> · <a href="%s">%s</a>',
                    esc_html__( 'Accesos rápidos:', 'mad-suite' ),
                    esc_url( $quotes_url ),
                    esc_html__( 'MAD Quotes', 'mad-suite' ),
                    esc_url( $email_url ),
                    esc_html__( 'Emails WooCommerce', 'mad-suite' ),
                    esc_url( $wpml_url ),
                    esc_html__( 'WPML Cadenas', 'mad-suite' )
                );
                ?>
            </p>
        </div>
        <?php
    }

    // ── AJAX: translate reply ─────────────────────────────────────────────────

    public function ajax_translate_reply(): void {
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $text     = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
        $lang     = sanitize_key( $_POST['lang'] ?? 'en' );

        if ( ! $order_id || ! $text ) {
            wp_send_json_error( [ 'message' => __( 'Datos incompletos.', 'mad-suite' ) ] );
        }

        check_ajax_referer( 'mad_olofane_reply_' . $order_id, 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'mad-suite' ) ] );
        }

        $translated = $this->translate_text( $text, $lang );
        if ( is_wp_error( $translated ) ) {
            wp_send_json_error( [ 'message' => $translated->get_error_message() ] );
        }

        wp_send_json_success( [ 'translated' => $translated ] );
    }

    // ── AJAX: send translated reply as order note ─────────────────────────────

    public function ajax_send_reply(): void {
        $order_id = absint( $_POST['order_id'] ?? 0 );
        $text     = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );

        if ( ! $order_id || ! $text ) {
            wp_send_json_error( [ 'message' => __( 'Datos incompletos.', 'mad-suite' ) ] );
        }

        check_ajax_referer( 'mad_olofane_reply_' . $order_id, 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'mad-suite' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado.', 'mad-suite' ) ] );
        }

        // Add note visible to customer
        $note_id = $order->add_order_note( $text, true, true );

        if ( ! $note_id ) {
            wp_send_json_error( [ 'message' => __( 'Error al guardar la nota.', 'mad-suite' ) ] );
        }

        wp_send_json_success( [ 'note_id' => $note_id ] );
    }

    // ── Admin assets ─────────────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        $order_hooks = [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $hook, $order_hooks, true ) ) return;

        $screen = get_current_screen();
        if ( ! $screen ) return;
        if ( ! in_array( $screen->post_type ?? $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) return;

        wp_enqueue_script(
            'mad-olofane-wpml-admin',
            plugin_dir_url( dirname( __DIR__ ) ) . 'modules/olofane/assets/js/wpml-quotes.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_localize_script( 'mad-olofane-wpml-admin', 'madOlofaneWpml', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'translating'  => __( 'Traduciendo…', 'mad-suite' ),
            'sending'      => __( 'Enviando…', 'mad-suite' ),
            'sent'         => __( '✓ Nota enviada al cliente.', 'mad-suite' ),
            'errorGeneric' => __( 'Error. Por favor, inténtalo de nuevo.', 'mad-suite' ),
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function get_order( $post_or_order ): ?WC_Order {
        if ( $post_or_order instanceof WC_Order ) return $post_or_order;
        if ( $post_or_order instanceof WP_Post ) return wc_get_order( $post_or_order->ID );
        return null;
    }

    private function translate_text( string $text, string $target_lang ): string|\WP_Error {
        $prompt_key = 'ai_prompt_translate_' . $target_lang;
        $prompt_tpl = $this->settings[ $prompt_key ] ?? "Translate the following text to {$target_lang}. Return only the translated text:\n\n{text}";
        $prompt     = str_replace( '{text}', $text, $prompt_tpl );

        $provider = $this->settings['ai_provider'] ?? 'claude';

        if ( $provider === 'openai' ) {
            return $this->call_openai( $prompt );
        }

        return $this->call_claude( $prompt );
    }

    private function call_claude( string $prompt ): string|\WP_Error {
        $api_key = $this->settings['ai_api_key_claude'] ?? '';
        $model   = $this->settings['ai_model_claude'] ?? 'claude-sonnet-4-6';

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'API Key de Claude no configurada.', 'mad-suite' ) );
        }

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'timeout' => 60,
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $api_key,
                    'anthropic-version' => '2023-06-01',
                ],
                'body' => wp_json_encode( [
                    'model'      => $model,
                    'max_tokens' => 1024,
                    'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new WP_Error( 'claude_error', $body['error']['message'] ?? __( 'Error de API.', 'mad-suite' ) );
        }

        return trim( $body['content'][0]['text'] ?? '' );
    }

    private function call_openai( string $prompt ): string|\WP_Error {
        $api_key = $this->settings['ai_api_key_openai'] ?? '';
        $model   = $this->settings['ai_model_openai'] ?? 'gpt-4o';

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'API Key de OpenAI no configurada.', 'mad-suite' ) );
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => 60,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body' => wp_json_encode( [
                    'model'      => $model,
                    'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
                    'max_tokens' => 1024,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            return new WP_Error( 'openai_error', $body['error']['message'] ?? __( 'Error de API.', 'mad-suite' ) );
        }

        return trim( $body['choices'][0]['message']['content'] ?? '' );
    }
}
