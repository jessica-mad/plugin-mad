<?php
/**
 * Clase API para MAD Gemini Assistant
 *
 * Wrapper para comunicación con Google Gemini API REST.
 * Maneja autenticación, peticiones y respuestas.
 *
 * @package MAD_Gemini_Assistant
 */

if (!defined('ABSPATH')) exit;

class MAD_Gemini_API {
    /**
     * Base URL de la API
     */
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * API Key
     */
    private $api_key;

    /**
     * Configuración
     */
    private $settings;

    /**
     * Constructor
     */
    public function __construct($api_key, $settings = []) {
        $this->api_key = $api_key;
        $this->settings = wp_parse_args($settings, [
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.7,
            'max_tokens' => 8192,
            'top_p' => 0.95,
            'top_k' => 40,
        ]);
    }

    /**
     * Generar contenido
     *
     * @param string $prompt Texto del prompt
     * @param array $history Historial de conversación
     * @param array $attachments Archivos adjuntos
     * @return array|WP_Error Respuesta o error
     */
    public function generate_content($prompt, $history = [], $attachments = []) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('No se ha configurado la API Key de Gemini.', 'mad-suite'));
        }

        // Construir el array de contenidos
        $contents = [];

        // Agregar historial si existe
        if (!empty($history)) {
            foreach ($history as $msg) {
                $contents[] = [
                    'role' => $msg['role'],
                    'parts' => $this->build_parts($msg['content'], $msg['attachments'] ?? [])
                ];
            }
        }

        // Agregar mensaje actual del usuario
        $contents[] = [
            'role' => 'user',
            'parts' => $this->build_parts($prompt, $attachments)
        ];

        // Construir cuerpo de la petición
        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) $this->settings['temperature'],
                'maxOutputTokens' => (int) $this->settings['max_tokens'],
                'topP' => (float) $this->settings['top_p'],
                'topK' => (int) $this->settings['top_k'],
            ]
        ];

        // Endpoint
        $model = $this->settings['model'];
        $endpoint = self::API_BASE_URL . "/models/{$model}:generateContent";

        // Realizar petición
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->api_key,
            ],
            'body' => wp_json_encode($body),
            'timeout' => 60,
        ]);

        // Verificar errores de conexión
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        // Verificar errores de API
        if ($status_code !== 200) {
            $error_message = $data['error']['message'] ?? __('Error desconocido de la API', 'mad-suite');
            return new WP_Error('api_error', sprintf(__('Error de API (%d): %s', 'mad-suite'), $status_code, $error_message));
        }

        // Extraer texto de la respuesta
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'text' => $data['candidates'][0]['content']['parts'][0]['text'],
                'raw' => $data,
            ];
        }

        return new WP_Error('invalid_response', __('Respuesta inválida de la API', 'mad-suite'));
    }

    /**
     * Construir array de parts para la petición
     *
     * @param string $text Texto
     * @param array $attachments Archivos adjuntos
     * @return array
     */
    private function build_parts($text, $attachments = []) {
        $parts = [];

        // Agregar archivos adjuntos primero (imágenes antes de texto según mejores prácticas)
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['inline_data'])) {
                    $parts[] = [
                        'inline_data' => $attachment['inline_data']
                    ];
                }
            }
        }

        // Agregar texto
        if (!empty($text)) {
            $parts[] = ['text' => $text];
        }

        return $parts;
    }

    /**
     * Test de conexión API
     *
     * @return array|WP_Error
     */
    public function test_connection() {
        $test_prompt = 'Responde solo con "OK" si puedes leer este mensaje.';

        $response = $this->generate_content($test_prompt);

        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'success' => true,
            'message' => __('Conexión exitosa con Gemini API', 'mad-suite'),
            'model' => $this->settings['model'],
        ];
    }

    /**
     * Obtener lista de modelos disponibles
     *
     * @return array
     */
    public static function get_available_models() {
        return [
            'gemini-2.5-flash' => 'Gemini 2.5 Flash (Recomendado)',
            'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash Experimental',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash',
            'gemini-pro' => 'Gemini Pro',
        ];
    }

    /**
     * Validar API Key
     *
     * @param string $api_key
     * @return bool|WP_Error
     */
    public static function validate_api_key($api_key) {
        if (empty($api_key)) {
            return new WP_Error('empty_key', __('La API Key no puede estar vacía', 'mad-suite'));
        }

        // La API key de Google generalmente tiene un formato específico
        if (strlen($api_key) < 30) {
            return new WP_Error('invalid_format', __('El formato de la API Key parece inválido', 'mad-suite'));
        }

        return true;
    }

    /**
     * Encriptar API Key para almacenamiento
     *
     * @param string $api_key
     * @return string
     */
    public static function encrypt_api_key($api_key) {
        // Usar función de WordPress para encriptar si está disponible
        if (defined('AUTH_KEY') && defined('AUTH_SALT')) {
            $salt = AUTH_KEY . AUTH_SALT;
            return base64_encode($api_key . '::' . hash_hmac('sha256', $api_key, $salt));
        }
        // Fallback: solo base64 (no es seguro pero mejor que nada)
        return base64_encode($api_key);
    }

    /**
     * Desencriptar API Key
     *
     * @param string $encrypted
     * @return string
     */
    public static function decrypt_api_key($encrypted) {
        $decoded = base64_decode($encrypted);

        // Si contiene el separador, es que fue encriptado con hash
        if (strpos($decoded, '::') !== false) {
            list($api_key, $hash) = explode('::', $decoded, 2);
            return $api_key;
        }

        // Fallback: solo base64
        return $decoded;
    }
}
