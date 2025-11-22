<?php
/**
 * Clase Activator para MAD Gemini Assistant
 *
 * Maneja la activación y desactivación del módulo, creando tablas necesarias
 * para almacenar conversaciones y mensajes.
 *
 * @package MAD_Gemini_Assistant
 */

if (!defined('ABSPATH')) exit;

class MAD_Gemini_Activator {
    /**
     * Activar módulo
     */
    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Tabla para conversaciones
        $table_conversations = $wpdb->prefix . 'mad_gemini_conversations';
        $sql_conversations = "CREATE TABLE IF NOT EXISTS $table_conversations (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            model varchar(100) NOT NULL DEFAULT 'gemini-2.5-flash',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Tabla para mensajes
        $table_messages = $wpdb->prefix . 'mad_gemini_messages';
        $sql_messages = "CREATE TABLE IF NOT EXISTS $table_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) UNSIGNED NOT NULL,
            role enum('user','assistant') NOT NULL,
            content longtext NOT NULL,
            attachments longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_conversations);
        dbDelta($sql_messages);

        // Agregar opciones por defecto si no existen
        $option_key = 'madsuite_gemini_assistant_settings';
        if (!get_option($option_key)) {
            $defaults = [
                'api_key' => '',
                'model' => 'gemini-2.5-flash',
                'temperature' => 0.7,
                'max_tokens' => 8192,
                'top_p' => 0.95,
                'top_k' => 40,
            ];
            add_option($option_key, $defaults);
        }

        // Marcar versión de base de datos
        update_option('mad_gemini_assistant_db_version', '1.0.0');
    }

    /**
     * Desactivar módulo (limpiar transients, etc)
     */
    public static function deactivate() {
        // Limpiar transients si existen
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mad_gemini_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mad_gemini_%'");
    }

    /**
     * Desinstalar módulo (eliminar tablas y opciones)
     */
    public static function uninstall() {
        global $wpdb;

        // Eliminar tablas
        $table_conversations = $wpdb->prefix . 'mad_gemini_conversations';
        $table_messages = $wpdb->prefix . 'mad_gemini_messages';

        $wpdb->query("DROP TABLE IF EXISTS $table_messages");
        $wpdb->query("DROP TABLE IF EXISTS $table_conversations");

        // Eliminar opciones
        delete_option('madsuite_gemini_assistant_settings');
        delete_option('mad_gemini_assistant_db_version');

        // Limpiar todos los transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mad_gemini_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mad_gemini_%'");
    }
}
