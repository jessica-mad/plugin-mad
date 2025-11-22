<?php
/**
 * Vista: Interfaz de Chat
 *
 * Interfaz principal para conversar con Gemini AI.
 *
 * @var array $settings Configuración del módulo
 * @var MAD_Gemini_Conversation $conversation Instancia de Conversation
 * @var int $user_id ID del usuario actual
 */

if (!defined('ABSPATH')) exit;

// Verificar si hay API key configurada
$has_api_key = !empty($settings['api_key']);

// Obtener conversaciones del usuario
$conversations = $conversation->get_by_user($user_id, 50);

// Conversación activa (la más reciente o crear una nueva)
$active_conversation_id = 0;
if (!empty($conversations)) {
    $active_conversation_id = $conversations[0]->id;
}
?>

<div class="mad-gemini-chat-container">
    <?php if (!$has_api_key): ?>
        <div class="notice notice-warning">
            <p>
                <?php _e('No has configurado tu API Key de Gemini.', 'mad-suite'); ?>
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings')); ?>">
                    <?php _e('Configúrala aquí', 'mad-suite'); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <div class="mad-gemini-layout">
        <!-- Sidebar de conversaciones -->
        <div class="mad-gemini-sidebar">
            <div class="sidebar-header">
                <button type="button" id="new-conversation-btn" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Nueva conversación', 'mad-suite'); ?>
                </button>
            </div>

            <div class="conversations-list" id="conversations-list">
                <?php if (empty($conversations)): ?>
                    <p class="no-conversations"><?php _e('No hay conversaciones aún', 'mad-suite'); ?></p>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <div class="conversation-item <?php echo $conv->id === $active_conversation_id ? 'active' : ''; ?>"
                             data-conversation-id="<?php echo esc_attr($conv->id); ?>">
                            <div class="conversation-title">
                                <?php echo esc_html($conv->title); ?>
                            </div>
                            <div class="conversation-meta">
                                <span class="conversation-date">
                                    <?php echo esc_html(human_time_diff(strtotime($conv->updated_at), current_time('timestamp'))); ?>
                                    <?php _e('ago', 'mad-suite'); ?>
                                </span>
                                <button type="button" class="delete-conversation" data-conversation-id="<?php echo esc_attr($conv->id); ?>" title="<?php esc_attr_e('Eliminar', 'mad-suite'); ?>">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Área principal de chat -->
        <div class="mad-gemini-main">
            <div class="chat-header">
                <h2 id="current-conversation-title">
                    <?php
                    if ($active_conversation_id > 0 && !empty($conversations)) {
                        echo esc_html($conversations[0]->title);
                    } else {
                        _e('Gemini Assistant', 'mad-suite');
                    }
                    ?>
                </h2>
                <div class="chat-model-info">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <?php echo esc_html($settings['model']); ?>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages">
                <div class="welcome-message">
                    <div class="gemini-icon">
                        <span class="dashicons dashicons-admin-customizer"></span>
                    </div>
                    <h3><?php _e('¡Hola! Soy tu asistente Gemini', 'mad-suite'); ?></h3>
                    <p><?php _e('Puedo ayudarte con preguntas, análisis de imágenes, revisión de documentos y mucho más.', 'mad-suite'); ?></p>
                    <ul class="capabilities-list">
                        <li><span class="dashicons dashicons-yes"></span> <?php _e('Responder preguntas y explicar conceptos', 'mad-suite'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <?php _e('Analizar imágenes y documentos', 'mad-suite'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <?php _e('Generar y revisar código', 'mad-suite'); ?></li>
                        <li><span class="dashicons dashicons-yes"></span> <?php _e('Ayudar con tareas creativas', 'mad-suite'); ?></li>
                    </ul>
                </div>
            </div>

            <div class="chat-input-container">
                <form id="chat-form" enctype="multipart/form-data">
                    <input type="hidden" id="conversation-id" value="<?php echo esc_attr($active_conversation_id); ?>">

                    <div class="attachments-preview" id="attachments-preview"></div>

                    <div class="input-wrapper">
                        <div class="input-actions">
                            <label for="file-upload" class="file-upload-label" title="<?php esc_attr_e('Adjuntar archivo', 'mad-suite'); ?>">
                                <span class="dashicons dashicons-paperclip"></span>
                            </label>
                            <input type="file"
                                   id="file-upload"
                                   name="attachments[]"
                                   multiple
                                   accept="<?php echo esc_attr(MAD_Gemini_FileHandler::get_allowed_extensions_string()); ?>"
                                   style="display: none;">
                        </div>

                        <textarea id="message-input"
                                  name="message"
                                  placeholder="<?php esc_attr_e('Escribe tu mensaje aquí...', 'mad-suite'); ?>"
                                  rows="3"
                                  <?php echo !$has_api_key ? 'disabled' : ''; ?>></textarea>

                        <button type="submit"
                                id="send-button"
                                class="button button-primary"
                                <?php echo !$has_api_key ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                            <span class="button-text"><?php _e('Enviar', 'mad-suite'); ?></span>
                        </button>
                    </div>

                    <div class="input-help">
                        <small>
                            <?php _e('Archivos permitidos:', 'mad-suite'); ?>
                            <?php echo esc_html(implode(', ', MAD_Gemini_FileHandler::get_allowed_extensions())); ?>
                            (<?php _e('máx. 20MB por archivo', 'mad-suite'); ?>)
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
