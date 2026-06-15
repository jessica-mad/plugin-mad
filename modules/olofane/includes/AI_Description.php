<?php
/**
 * AI-powered product description generation for Olofane.
 *
 * Supports Claude (Anthropic) and OpenAI. Integrates with WPML to
 * auto-generate EN/FR translations when the Spanish description is saved.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_AI_Description {

    private array $settings;

    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    public function init(): void {
        // Button on individual product edit screen (after featured image)
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

        // Bulk action in product list
        add_filter( 'bulk_actions-edit-product',        [ $this, 'register_bulk_action' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ $this, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices',                    [ $this, 'bulk_action_notice' ] );

        // AJAX handler for single product generation
        add_action( 'wp_ajax_mad_olofane_generate_description', [ $this, 'ajax_generate_description' ] );

        // Hook into save_post to auto-translate when Spanish description changes
        if ( ! empty( $this->settings['ai_wpml_enabled'] ) ) {
            add_action( 'save_post_product', [ $this, 'maybe_auto_translate' ], 20, 3 );
        }

        // JS for the auto-translate confirmation dialog
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ── Meta box ─────────────────────────────────────────────────────────────

    public function register_meta_box(): void {
        add_meta_box(
            'mad-olofane-ai-description',
            __( 'Descripción con IA (Olofane)', 'mad-suite' ),
            [ $this, 'render_meta_box' ],
            'product',
            'side',
            'low'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        $lang    = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $post->ID, 'element_type' => 'post_product' ] );
        $is_orig = ( $lang === null || $lang === apply_filters( 'wpml_default_language', null ) );
        ?>
        <div id="mad-olofane-ai-box">
            <?php if ( ! $is_orig ) : ?>
                <p class="description" style="color:#d63638;">
                    <?php esc_html_e( 'Este producto es una traducción. Editar su descripción aquí sólo afecta a este idioma.', 'mad-suite' ); ?>
                </p>
            <?php endif; ?>

            <p>
                <button type="button"
                        class="button"
                        id="mad-olofane-generate-btn"
                        data-product-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'mad_olofane_generate_' . $post->ID ) ); ?>">
                    ✨ <?php esc_html_e( 'Generar descripción con IA', 'mad-suite' ); ?>
                </button>
            </p>
            <p id="mad-olofane-status" style="display:none;font-style:italic;"></p>
        </div>

        <style>
            #mad-olofane-ai-box .button { width: 100%; text-align: center; }
        </style>
        <?php
    }

    // ── Admin assets ─────────────────────────────────────────────────────────

    public function enqueue_admin_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'edit.php' ], true ) ) return;
        if ( get_post_type() !== 'product' && ! ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'product' ) ) return;

        wp_enqueue_script(
            'mad-olofane-admin',
            plugin_dir_url( dirname( __DIR__ ) ) . 'modules/olofane/assets/js/admin.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );

        wp_localize_script( 'mad-olofane-admin', 'madOlofane', [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'generating'           => __( 'Generando descripción…', 'mad-suite' ),
            'done'                 => __( 'Descripción generada y guardada.', 'mad-suite' ),
            'error'                => __( 'Error al generar la descripción.', 'mad-suite' ),
            'translateConfirm'     => __( 'Estás editando una descripción en un idioma que no es el original. ¿Quieres guardar solo en este idioma (sin actualizar los demás)?', 'mad-suite' ),
        ] );
    }

    // ── AJAX: generate single description ────────────────────────────────────

    public function ajax_generate_description(): void {
        $product_id = absint( $_POST['product_id'] ?? 0 );

        if ( ! $product_id || ! current_user_can( 'edit_product', $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'mad-suite' ) ] );
        }

        check_ajax_referer( 'mad_olofane_generate_' . $product_id, 'nonce' );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( [ 'message' => __( 'Producto no encontrado.', 'mad-suite' ) ] );
        }

        $description = $this->generate_description( $product->get_name() );
        if ( is_wp_error( $description ) ) {
            wp_send_json_error( [ 'message' => $description->get_error_message() ] );
        }

        // Save to post content
        wp_update_post( [ 'ID' => $product_id, 'post_content' => $description ] );

        // Auto-translate EN/FR
        $translations = [];
        if ( ! empty( $this->settings['ai_wpml_enabled'] ) ) {
            $translations = $this->translate_and_save( $product_id, $description );
        }

        wp_send_json_success( [
            'description'  => $description,
            'translations' => $translations,
        ] );
    }

    // ── Bulk action ───────────────────────────────────────────────────────────

    public function register_bulk_action( array $actions ): array {
        $actions['mad_olofane_generate_descriptions'] = __( 'Generar descripciones con IA', 'mad-suite' );
        return $actions;
    }

    public function handle_bulk_action( string $redirect, string $action, array $ids ): string {
        if ( $action !== 'mad_olofane_generate_descriptions' ) return $redirect;

        $count = 0;
        foreach ( $ids as $post_id ) {
            $product = wc_get_product( absint( $post_id ) );
            if ( ! $product ) continue;

            $description = $this->generate_description( $product->get_name() );
            if ( is_wp_error( $description ) ) continue;

            wp_update_post( [ 'ID' => $post_id, 'post_content' => $description ] );

            if ( ! empty( $this->settings['ai_wpml_enabled'] ) ) {
                $this->translate_and_save( (int) $post_id, $description );
            }

            $count++;
        }

        return add_query_arg( 'mad_olofane_generated', $count, $redirect );
    }

    public function bulk_action_notice(): void {
        if ( ! isset( $_GET['mad_olofane_generated'] ) ) return;
        $count = absint( $_GET['mad_olofane_generated'] );
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php printf(
                    /* translators: %d: number of products */
                    esc_html( _n(
                        'Descripción generada con IA para %d producto.',
                        'Descripciones generadas con IA para %d productos.',
                        $count,
                        'mad-suite'
                    ) ),
                    $count
                ); ?>
            </p>
        </div>
        <?php
    }

    // ── Auto-translate on save ────────────────────────────────────────────────

    public function maybe_auto_translate( int $post_id, WP_Post $post, bool $update ): void {
        if ( ! $update ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        // Only trigger for the original language
        $default_lang = apply_filters( 'wpml_default_language', null );
        $post_lang    = apply_filters( 'wpml_element_language_code', null, [
            'element_id'   => $post_id,
            'element_type' => 'post_product',
        ] );

        if ( $default_lang && $post_lang && $post_lang !== $default_lang ) return;

        $description = $post->post_content;
        if ( empty( trim( $description ) ) ) return;

        $prev = get_post_meta( $post_id, '_mad_olofane_last_desc_hash', true );
        $hash = md5( $description );
        if ( $prev === $hash ) return;

        update_post_meta( $post_id, '_mad_olofane_last_desc_hash', $hash );
        $this->translate_and_save( $post_id, $description );
    }

    // ── Core: generate description ────────────────────────────────────────────

    private function generate_description( string $product_name ): string|\WP_Error {
        $prompt_tpl = $this->settings['ai_prompt_description'] ?? '';
        $prompt     = str_replace( '{product_name}', $product_name, $prompt_tpl );

        return $this->call_ai( $prompt );
    }

    // ── Core: translate and save into WPML translations ──────────────────────

    private function translate_and_save( int $es_product_id, string $es_description ): array {
        $results = [];

        foreach ( [ 'en' => 'ai_prompt_translate_en', 'fr' => 'ai_prompt_translate_fr' ] as $lang => $prompt_key ) {
            $prompt_tpl = $this->settings[ $prompt_key ] ?? '';
            $prompt     = str_replace( '{text}', $es_description, $prompt_tpl );

            $translation = $this->call_ai( $prompt );
            if ( is_wp_error( $translation ) ) {
                $results[ $lang ] = [ 'error' => $translation->get_error_message() ];
                continue;
            }

            // Get WPML translation post ID for this language
            $translated_id = apply_filters( 'wpml_object_id', $es_product_id, 'product', false, $lang );

            if ( $translated_id && (int) $translated_id !== $es_product_id ) {
                wp_update_post( [ 'ID' => (int) $translated_id, 'post_content' => $translation ] );
                $results[ $lang ] = [ 'product_id' => $translated_id, 'ok' => true ];
            } else {
                // No WPML translation yet — store in meta for later reference
                update_post_meta( $es_product_id, '_mad_olofane_pending_trans_' . $lang, $translation );
                $results[ $lang ] = [ 'pending' => true ];
            }
        }

        return $results;
    }

    // ── Core: call AI provider ────────────────────────────────────────────────

    private function call_ai( string $prompt ): string|\WP_Error {
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
                    'messages'   => [
                        [ 'role' => 'user', 'content' => $prompt ],
                    ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? __( 'Error de API Claude.', 'mad-suite' );
            return new WP_Error( 'claude_api_error', $msg );
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
                    'model'    => $model,
                    'messages' => [
                        [ 'role' => 'user', 'content' => $prompt ],
                    ],
                    'max_tokens' => 1024,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? __( 'Error de API OpenAI.', 'mad-suite' );
            return new WP_Error( 'openai_api_error', $msg );
        }

        return trim( $body['choices'][0]['message']['content'] ?? '' );
    }
}
