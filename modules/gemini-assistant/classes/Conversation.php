<?php
/**
 * Clase Conversation para MAD Gemini Assistant
 *
 * Maneja el CRUD de conversaciones y mensajes.
 * Utiliza tablas custom para almacenar el historial.
 *
 * @package MAD_Gemini_Assistant
 */

if (!defined('ABSPATH')) exit;

class MAD_Gemini_Conversation {
    /**
     * Nombre de tabla de conversaciones
     */
    private $table_conversations;

    /**
     * Nombre de tabla de mensajes
     */
    private $table_messages;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_conversations = $wpdb->prefix . 'mad_gemini_conversations';
        $this->table_messages = $wpdb->prefix . 'mad_gemini_messages';
    }

    /**
     * Crear nueva conversación
     *
     * @param int $user_id ID del usuario
     * @param string $title Título de la conversación
     * @param string $model Modelo de Gemini a usar
     * @return int|false ID de la conversación o false si falla
     */
    public function create($user_id, $title = '', $model = 'gemini-2.5-flash') {
        global $wpdb;

        // Si no hay título, generar uno automático
        if (empty($title)) {
            $title = sprintf(__('Conversación %s', 'mad-suite'), date('Y-m-d H:i'));
        }

        $result = $wpdb->insert(
            $this->table_conversations,
            [
                'user_id' => $user_id,
                'title' => sanitize_text_field($title),
                'model' => sanitize_text_field($model),
            ],
            ['%d', '%s', '%s']
        );

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Obtener conversación por ID
     *
     * @param int $conversation_id
     * @return object|null
     */
    public function get($conversation_id) {
        global $wpdb;

        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_conversations} WHERE id = %d",
            $conversation_id
        ));

        return $conversation;
    }

    /**
     * Obtener conversaciones de un usuario
     *
     * @param int $user_id
     * @param int $limit Límite de resultados
     * @return array
     */
    public function get_by_user($user_id, $limit = 50) {
        global $wpdb;

        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_conversations}
            WHERE user_id = %d
            ORDER BY updated_at DESC
            LIMIT %d",
            $user_id,
            $limit
        ));

        return $conversations ?: [];
    }

    /**
     * Actualizar título de conversación
     *
     * @param int $conversation_id
     * @param string $title
     * @return bool
     */
    public function update_title($conversation_id, $title) {
        global $wpdb;

        $result = $wpdb->update(
            $this->table_conversations,
            ['title' => sanitize_text_field($title)],
            ['id' => $conversation_id],
            ['%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Eliminar conversación y todos sus mensajes
     *
     * @param int $conversation_id
     * @return bool
     */
    public function delete($conversation_id) {
        global $wpdb;

        // Eliminar mensajes primero
        $wpdb->delete(
            $this->table_messages,
            ['conversation_id' => $conversation_id],
            ['%d']
        );

        // Eliminar conversación
        $result = $wpdb->delete(
            $this->table_conversations,
            ['id' => $conversation_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Agregar mensaje a conversación
     *
     * @param int $conversation_id
     * @param string $role 'user' o 'assistant'
     * @param string $content Contenido del mensaje
     * @param array $attachments Archivos adjuntos
     * @return int|false ID del mensaje o false
     */
    public function add_message($conversation_id, $role, $content, $attachments = []) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_messages,
            [
                'conversation_id' => $conversation_id,
                'role' => $role,
                'content' => wp_kses_post($content),
                'attachments' => !empty($attachments) ? wp_json_encode($attachments) : null,
            ],
            ['%d', '%s', '%s', '%s']
        );

        if ($result === false) {
            return false;
        }

        // Actualizar timestamp de conversación
        $wpdb->update(
            $this->table_conversations,
            ['updated_at' => current_time('mysql')],
            ['id' => $conversation_id],
            ['%s'],
            ['%d']
        );

        return $wpdb->insert_id;
    }

    /**
     * Obtener mensajes de una conversación
     *
     * @param int $conversation_id
     * @param int $limit Límite de mensajes (0 = todos)
     * @return array
     */
    public function get_messages($conversation_id, $limit = 0) {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_messages}
            WHERE conversation_id = %d
            ORDER BY created_at ASC",
            $conversation_id
        );

        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }

        $messages = $wpdb->get_results($sql);

        // Decodificar attachments JSON
        if ($messages) {
            foreach ($messages as &$message) {
                if (!empty($message->attachments)) {
                    $message->attachments = json_decode($message->attachments, true);
                } else {
                    $message->attachments = [];
                }
            }
        }

        return $messages ?: [];
    }

    /**
     * Obtener historial de conversación para enviar a API
     *
     * @param int $conversation_id
     * @param int $max_messages Máximo de mensajes a incluir (para limitar tokens)
     * @return array Array formateado para Gemini API
     */
    public function get_history_for_api($conversation_id, $max_messages = 20) {
        $messages = $this->get_messages($conversation_id, $max_messages);
        $history = [];

        foreach ($messages as $message) {
            $history[] = [
                'role' => $message->role,
                'content' => $message->content,
                'attachments' => $message->attachments,
            ];
        }

        return $history;
    }

    /**
     * Generar título automático basado en el primer mensaje
     *
     * @param int $conversation_id
     * @return string|false
     */
    public function auto_generate_title($conversation_id) {
        global $wpdb;

        // Obtener el primer mensaje del usuario
        $first_message = $wpdb->get_row($wpdb->prepare(
            "SELECT content FROM {$this->table_messages}
            WHERE conversation_id = %d AND role = 'user'
            ORDER BY created_at ASC
            LIMIT 1",
            $conversation_id
        ));

        if (!$first_message) {
            return false;
        }

        // Generar título (primeras 50 caracteres)
        $title = wp_trim_words($first_message->content, 8, '...');

        // Actualizar
        $this->update_title($conversation_id, $title);

        return $title;
    }

    /**
     * Contar mensajes de una conversación
     *
     * @param int $conversation_id
     * @return int
     */
    public function count_messages($conversation_id) {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_messages} WHERE conversation_id = %d",
            $conversation_id
        ));

        return (int) $count;
    }

    /**
     * Verificar si una conversación pertenece a un usuario
     *
     * @param int $conversation_id
     * @param int $user_id
     * @return bool
     */
    public function belongs_to_user($conversation_id, $user_id) {
        global $wpdb;

        $owner_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table_conversations} WHERE id = %d",
            $conversation_id
        ));

        return (int) $owner_id === (int) $user_id;
    }

    /**
     * Limpiar conversaciones antiguas (opcional, para mantenimiento)
     *
     * @param int $days Días de antigüedad
     * @return int Número de conversaciones eliminadas
     */
    public function cleanup_old($days = 90) {
        global $wpdb;

        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Obtener IDs de conversaciones a eliminar
        $conversation_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table_conversations} WHERE updated_at < %s",
            $date_threshold
        ));

        if (empty($conversation_ids)) {
            return 0;
        }

        // Eliminar mensajes
        $placeholders = implode(',', array_fill(0, count($conversation_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_messages} WHERE conversation_id IN ($placeholders)",
            ...$conversation_ids
        ));

        // Eliminar conversaciones
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_conversations} WHERE updated_at < %s",
            $date_threshold
        ));

        return (int) $deleted;
    }
}
