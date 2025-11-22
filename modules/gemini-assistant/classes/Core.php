<?php
/**
 * Clase Core para MAD Gemini Assistant
 *
 * Orquesta todas las funcionalidades del módulo: AJAX handlers,
 * admin pages, enqueue de assets, etc.
 *
 * @package MAD_Gemini_Assistant
 */

if (!defined('ABSPATH')) exit;

class MAD_Gemini_Core {
    /**
     * Slug del módulo
     */
    private $slug = 'gemini-assistant';

    /**
     * Instancia de Conversation
     */
    private $conversation;

    /**
     * Instancia de FileHandler
     */
    private $file_handler;

    /**
     * Constructor
     */
    public function __construct() {
        // Cargar clases necesarias
        require_once __DIR__ . '/Conversation.php';
        require_once __DIR__ . '/FileHandler.php';
        require_once __DIR__ . '/API.php';

        $this->conversation = new MAD_Gemini_Conversation();
        $this->file_handler = new MAD_Gemini_FileHandler();
    }

    /**
     * Inicializar hooks
     */
    public function init() {
        // AJAX handlers
        add_action('wp_ajax_mad_gemini_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_mad_gemini_create_conversation', [$this, 'ajax_create_conversation']);
        add_action('wp_ajax_mad_gemini_load_conversation', [$this, 'ajax_load_conversation']);
        add_action('wp_ajax_mad_gemini_delete_conversation', [$this, 'ajax_delete_conversation']);
        add_action('wp_ajax_mad_gemini_update_title', [$this, 'ajax_update_title']);
        add_action('wp_ajax_mad_gemini_test_connection', [$this, 'ajax_test_connection']);
    }

    /**
     * Inicializar admin
     */
    public function admin_init() {
        // Handler para guardar configuración
        add_action('admin_post_mads_gemini_save_settings', [$this, 'handle_save_settings']);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_assets($hook) {
        // Solo cargar en las páginas del módulo
        if (strpos($hook, 'mad-suite-gemini-assistant') === false) {
            return;
        }

        $module_url = plugin_dir_url(dirname(__FILE__));

        // Estilos
        wp_enqueue_style(
            'mad-gemini-admin-chat',
            $module_url . 'assets/css/admin-chat.css',
            [],
            '1.0.0'
        );

        wp_enqueue_style(
            'mad-gemini-admin-settings',
            $module_url . 'assets/css/admin-settings.css',
            [],
            '1.0.0'
        );

        // Scripts
        wp_enqueue_script(
            'mad-gemini-admin-chat',
            $module_url . 'assets/js/admin-chat.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_enqueue_script(
            'mad-gemini-admin-settings',
            $module_url . 'assets/js/admin-settings.js',
            ['jquery'],
            '1.0.0',
            true
        );

        // Localizar script
        wp_localize_script('mad-gemini-admin-chat', 'madGemini', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mad_gemini_nonce'),
            'strings' => [
                'sending' => __('Enviando...', 'mad-suite'),
                'error' => __('Error', 'mad-suite'),
                'delete_confirm' => __('¿Estás seguro de eliminar esta conversación?', 'mad-suite'),
                'no_api_key' => __('Por favor configura tu API Key primero', 'mad-suite'),
                'file_too_large' => sprintf(__('El archivo excede %s', 'mad-suite'), size_format(20971520)),
                'invalid_file_type' => __('Tipo de archivo no permitido', 'mad-suite'),
            ],
            'allowed_extensions' => MAD_Gemini_FileHandler::get_allowed_extensions(),
            'max_file_size' => 20971520, // 20MB
        ]);

        wp_localize_script('mad-gemini-admin-settings', 'madGeminiSettings', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mad_gemini_nonce'),
            'strings' => [
                'testing' => __('Probando conexión...', 'mad-suite'),
                'success' => __('Conexión exitosa', 'mad-suite'),
                'error' => __('Error de conexión', 'mad-suite'),
            ],
        ]);

        // Incluir Marked.js para renderizado de markdown
        wp_enqueue_script(
            'marked-js',
            'https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js',
            [],
            '11.1.1',
            true
        );
    }

    /**
     * AJAX: Enviar mensaje
     */
    public function ajax_send_message() {
        check_ajax_referer('mad_gemini_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos', 'mad-suite')]);
        }

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

        if (empty($message)) {
            wp_send_json_error(['message' => __('El mensaje no puede estar vacío', 'mad-suite')]);
        }

        // Verificar que la conversación existe y pertenece al usuario
        if ($conversation_id > 0) {
            $user_id = get_current_user_id();
            if (!$this->conversation->belongs_to_user($conversation_id, $user_id)) {
                wp_send_json_error(['message' => __('Conversación no encontrada', 'mad-suite')]);
            }
        }

        // Procesar archivos adjuntos
        $attachments = [];
        if (!empty($_FILES['attachments'])) {
            $files = $this->rearray_files($_FILES['attachments']);
            $processed = $this->file_handler->process_uploads($files);

            if (is_wp_error($processed)) {
                wp_send_json_error(['message' => $processed->get_error_message()]);
            }

            $attachments = $processed;
        }

        // Guardar mensaje del usuario
        $message_id = $this->conversation->add_message($conversation_id, 'user', $message, $attachments);

        if ($message_id === false) {
            wp_send_json_error(['message' => __('Error al guardar mensaje', 'mad-suite')]);
        }

        // Obtener configuración
        $settings = $this->get_settings();

        // Verificar API key
        if (empty($settings['api_key'])) {
            wp_send_json_error(['message' => __('No se ha configurado la API Key', 'mad-suite')]);
        }

        // Desencriptar API key
        $api_key = MAD_Gemini_API::decrypt_api_key($settings['api_key']);

        // Crear instancia de API
        $api = new MAD_Gemini_API($api_key, $settings);

        // Obtener historial de conversación
        $history = $this->conversation->get_history_for_api($conversation_id, 20);

        // Enviar a Gemini API
        $response = $api->generate_content($message, $history, $attachments);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        // Guardar respuesta del asistente
        $assistant_message_id = $this->conversation->add_message(
            $conversation_id,
            'assistant',
            $response['text'],
            []
        );

        if ($assistant_message_id === false) {
            wp_send_json_error(['message' => __('Error al guardar respuesta', 'mad-suite')]);
        }

        // Si es el primer mensaje, generar título automático
        $message_count = $this->conversation->count_messages($conversation_id);
        if ($message_count === 2) { // user + assistant
            $this->conversation->auto_generate_title($conversation_id);
        }

        wp_send_json_success([
            'message_id' => $assistant_message_id,
            'content' => $response['text'],
            'user_message_id' => $message_id,
        ]);
    }

    /**
     * AJAX: Crear nueva conversación
     */
    public function ajax_create_conversation() {
        check_ajax_referer('mad_gemini_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos', 'mad-suite')]);
        }

        $user_id = get_current_user_id();
        $settings = $this->get_settings();

        $conversation_id = $this->conversation->create($user_id, '', $settings['model']);

        if ($conversation_id === false) {
            wp_send_json_error(['message' => __('Error al crear conversación', 'mad-suite')]);
        }

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'title' => sprintf(__('Nueva conversación', 'mad-suite')),
        ]);
    }

    /**
     * AJAX: Cargar conversación
     */
    public function ajax_load_conversation() {
        check_ajax_referer('mad_gemini_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos', 'mad-suite')]);
        }

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();

        if (!$this->conversation->belongs_to_user($conversation_id, $user_id)) {
            wp_send_json_error(['message' => __('Conversación no encontrada', 'mad-suite')]);
        }

        $conversation = $this->conversation->get($conversation_id);
        $messages = $this->conversation->get_messages($conversation_id);

        wp_send_json_success([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    /**
     * AJAX: Eliminar conversación
     */
    public function ajax_delete_conversation() {
        check_ajax_referer('mad_gemini_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos', 'mad-suite')]);
        }

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $user_id = get_current_user_id();

        if (!$this->conversation->belongs_to_user($conversation_id, $user_id)) {
            wp_send_json_error(['message' => __('Conversación no encontrada', 'mad-suite')]);
        }

        $deleted = $this->conversation->delete($conversation_id);

        if (!$deleted) {
            wp_send_json_error(['message' => __('Error al eliminar conversación', 'mad-suite')]);
        }

        wp_send_json_success(['message' => __('Conversación eliminada', 'mad-suite')]);
    }

    /**
     * AJAX: Actualizar título
     */
    public function ajax_update_title() {
        check_ajax_referer('mad_gemini_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos', 'mad-suite')]);
        }

        $conversation_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $user_id = get_current_user_id();

        if (!$this->conversation->belongs_to_user($conversation_id, $user_id)) {
            wp_send_json_error(['message' => __('Conversación no encontrada', 'mad-suite')]);
        }

        $updated = $this->conversation->update_title($conversation_id, $title);

        if (!$updated) {
            wp_send_json_error(['message' => __('Error al actualizar título', 'mad-suite')]);
        }

        wp_send_json_success(['message' => __('Título actualizado', 'mad-suite')]);
    }

    /**
     * AJAX: Test de conexión API
     */
    public function ajax_test_connection() {
        check_ajax_referer('mad_gemini_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Sin permisos', 'mad-suite')]);
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API Key vacía', 'mad-suite')]);
        }

        // Validar formato
        $validation = MAD_Gemini_API::validate_api_key($api_key);
        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => $validation->get_error_message()]);
        }

        // Test de conexión
        $settings = $this->get_settings();
        $api = new MAD_Gemini_API($api_key, $settings);
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handler: Guardar configuración
     */
    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos', 'mad-suite'));
        }

        check_admin_referer('mads_gemini_save_settings', 'mads_gemini_nonce');

        $option_key = MAD_Suite_Core::option_key($this->slug);
        $existing = $this->get_settings();

        $input = $_POST[$option_key] ?? [];

        // Sanitizar
        $sanitized = [
            'api_key' => isset($input['api_key']) && !empty($input['api_key'])
                ? MAD_Gemini_API::encrypt_api_key(sanitize_text_field($input['api_key']))
                : $existing['api_key'],
            'model' => isset($input['model']) ? sanitize_text_field($input['model']) : 'gemini-2.5-flash',
            'temperature' => isset($input['temperature']) ? floatval($input['temperature']) : 0.7,
            'max_tokens' => isset($input['max_tokens']) ? absint($input['max_tokens']) : 8192,
            'top_p' => isset($input['top_p']) ? floatval($input['top_p']) : 0.95,
            'top_k' => isset($input['top_k']) ? absint($input['top_k']) : 40,
        ];

        update_option($option_key, $sanitized);

        wp_safe_redirect(add_query_arg([
            'page' => 'mad-suite-gemini-assistant',
            'tab' => 'settings',
            'updated' => 'true',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Obtener configuración
     */
    public function get_settings() {
        $defaults = [
            'api_key' => '',
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.7,
            'max_tokens' => 8192,
            'top_p' => 0.95,
            'top_k' => 40,
        ];

        $option_key = MAD_Suite_Core::option_key($this->slug);
        $settings = get_option($option_key, []);

        return wp_parse_args($settings, $defaults);
    }

    /**
     * Reorganizar array de archivos de $_FILES
     */
    private function rearray_files($file_post) {
        $file_array = [];
        $file_count = count($file_post['name']);
        $file_keys = array_keys($file_post);

        for ($i = 0; $i < $file_count; $i++) {
            foreach ($file_keys as $key) {
                $file_array[$i][$key] = $file_post[$key][$i];
            }
        }

        return $file_array;
    }

    /**
     * Obtener instancia de Conversation
     */
    public function get_conversation_instance() {
        return $this->conversation;
    }
}
