/**
 * JavaScript para interfaz de chat de MAD Gemini Assistant
 *
 * Maneja envío de mensajes, carga de conversaciones, upload de archivos,
 * y renderizado de respuestas con markdown.
 */

(function($) {
    'use strict';

    let currentConversationId = 0;
    let attachedFiles = [];

    $(document).ready(function() {
        initChat();
    });

    /**
     * Inicializar chat
     */
    function initChat() {
        currentConversationId = parseInt($('#conversation-id').val()) || 0;

        // Cargar conversación activa si existe
        if (currentConversationId > 0) {
            loadConversation(currentConversationId);
        }

        // Event listeners
        $('#chat-form').on('submit', handleSendMessage);
        $('#new-conversation-btn').on('click', createNewConversation);
        $('#file-upload').on('change', handleFileSelect);
        $('.conversation-item').on('click', function() {
            const convId = parseInt($(this).data('conversation-id'));
            loadConversation(convId);
        });
        $('.delete-conversation').on('click', function(e) {
            e.stopPropagation();
            const convId = parseInt($(this).data('conversation-id'));
            deleteConversation(convId);
        });

        // Enter para enviar (Shift+Enter para nueva línea)
        $('#message-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $('#chat-form').submit();
            }
        });

        // Auto-resize textarea
        $('#message-input').on('input', autoResizeTextarea);
    }

    /**
     * Manejar envío de mensaje
     */
    function handleSendMessage(e) {
        e.preventDefault();

        const message = $('#message-input').val().trim();

        if (!message && attachedFiles.length === 0) {
            return;
        }

        // Si no hay conversación, crear una
        if (currentConversationId === 0) {
            createNewConversation(function(newConvId) {
                sendMessage(newConvId, message);
            });
        } else {
            sendMessage(currentConversationId, message);
        }
    }

    /**
     * Enviar mensaje a API
     */
    function sendMessage(conversationId, message) {
        const formData = new FormData();
        formData.append('action', 'mad_gemini_send_message');
        formData.append('nonce', madGemini.nonce);
        formData.append('conversation_id', conversationId);
        formData.append('message', message);

        // Agregar archivos
        attachedFiles.forEach((file) => {
            formData.append('attachments[]', file);
        });

        // Agregar mensaje del usuario al chat
        appendMessage('user', message, attachedFiles);

        // Limpiar input y archivos
        $('#message-input').val('');
        attachedFiles = [];
        updateAttachmentsPreview();

        // Mostrar indicador de "escribiendo"
        showTypingIndicator();

        // Deshabilitar botón de envío
        setLoading(true);

        $.ajax({
            url: madGemini.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                hideTypingIndicator();
                setLoading(false);

                if (response.success) {
                    appendMessage('assistant', response.data.content);
                    scrollToBottom();

                    // Actualizar título si es la primera respuesta
                    refreshConversationsList();
                } else {
                    showError(response.data.message || madGemini.strings.error);
                }
            },
            error: function(xhr, status, error) {
                hideTypingIndicator();
                setLoading(false);
                showError(madGemini.strings.error + ': ' + error);
            }
        });
    }

    /**
     * Crear nueva conversación
     */
    function createNewConversation(callback) {
        $.ajax({
            url: madGemini.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_gemini_create_conversation',
                nonce: madGemini.nonce
            },
            success: function(response) {
                if (response.success) {
                    currentConversationId = response.data.conversation_id;
                    $('#conversation-id').val(currentConversationId);

                    // Limpiar chat
                    clearMessages();

                    // Actualizar lista de conversaciones
                    refreshConversationsList();

                    if (typeof callback === 'function') {
                        callback(currentConversationId);
                    }
                } else {
                    showError(response.data.message);
                }
            },
            error: function() {
                showError(madGemini.strings.error);
            }
        });
    }

    /**
     * Cargar conversación
     */
    function loadConversation(conversationId) {
        $.ajax({
            url: madGemini.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_gemini_load_conversation',
                nonce: madGemini.nonce,
                conversation_id: conversationId
            },
            success: function(response) {
                if (response.success) {
                    currentConversationId = conversationId;
                    $('#conversation-id').val(conversationId);

                    // Actualizar título
                    $('#current-conversation-title').text(response.data.conversation.title);

                    // Limpiar y cargar mensajes
                    clearMessages();

                    response.data.messages.forEach(function(msg) {
                        appendMessage(msg.role, msg.content, msg.attachments, false);
                    });

                    scrollToBottom();

                    // Marcar conversación como activa
                    $('.conversation-item').removeClass('active');
                    $('.conversation-item[data-conversation-id="' + conversationId + '"]').addClass('active');
                } else {
                    showError(response.data.message);
                }
            },
            error: function() {
                showError(madGemini.strings.error);
            }
        });
    }

    /**
     * Eliminar conversación
     */
    function deleteConversation(conversationId) {
        if (!confirm(madGemini.strings.delete_confirm)) {
            return;
        }

        $.ajax({
            url: madGemini.ajax_url,
            type: 'POST',
            data: {
                action: 'mad_gemini_delete_conversation',
                nonce: madGemini.nonce,
                conversation_id: conversationId
            },
            success: function(response) {
                if (response.success) {
                    // Remover de la lista
                    $('.conversation-item[data-conversation-id="' + conversationId + '"]').remove();

                    // Si era la conversación activa, crear una nueva
                    if (conversationId === currentConversationId) {
                        currentConversationId = 0;
                        $('#conversation-id').val(0);
                        clearMessages();
                    }

                    // Si no quedan conversaciones, mostrar mensaje
                    if ($('.conversation-item').length === 0) {
                        $('#conversations-list').html('<p class="no-conversations">' + 'No hay conversaciones aún' + '</p>');
                    }
                } else {
                    showError(response.data.message);
                }
            },
            error: function() {
                showError(madGemini.strings.error);
            }
        });
    }

    /**
     * Manejar selección de archivos
     */
    function handleFileSelect(e) {
        const files = Array.from(e.target.files);

        files.forEach(file => {
            // Validar tamaño
            if (file.size > madGemini.max_file_size) {
                alert(madGemini.strings.file_too_large);
                return;
            }

            // Validar extensión
            const extension = file.name.split('.').pop().toLowerCase();
            if (!madGemini.allowed_extensions.includes(extension)) {
                alert(madGemini.strings.invalid_file_type);
                return;
            }

            attachedFiles.push(file);
        });

        updateAttachmentsPreview();
        e.target.value = ''; // Reset input
    }

    /**
     * Actualizar preview de archivos adjuntos
     */
    function updateAttachmentsPreview() {
        const $preview = $('#attachments-preview');
        $preview.empty();

        if (attachedFiles.length === 0) {
            $preview.hide();
            return;
        }

        $preview.show();

        attachedFiles.forEach((file, index) => {
            const $item = $('<div class="attachment-item"></div>');

            // Si es imagen, mostrar preview
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $item.css('background-image', 'url(' + e.target.result + ')');
                };
                reader.readAsDataURL(file);
            } else {
                $item.addClass('file-attachment');
                $item.html('<span class="dashicons dashicons-media-document"></span>');
            }

            const $remove = $('<button type="button" class="remove-attachment">&times;</button>');
            $remove.on('click', function() {
                attachedFiles.splice(index, 1);
                updateAttachmentsPreview();
            });

            $item.append($remove);
            $item.append('<span class="file-name">' + file.name + '</span>');
            $preview.append($item);
        });
    }

    /**
     * Agregar mensaje al chat
     */
    function appendMessage(role, content, attachments = [], scroll = true) {
        const $container = $('#chat-messages');

        // Remover mensaje de bienvenida si existe
        $container.find('.welcome-message').remove();

        const $message = $('<div class="message message-' + role + '"></div>');

        // Icono
        const icon = role === 'user'
            ? '<span class="dashicons dashicons-admin-users"></span>'
            : '<span class="dashicons dashicons-admin-customizer"></span>';
        $message.append('<div class="message-icon">' + icon + '</div>');

        const $content = $('<div class="message-content"></div>');

        // Attachments (solo para mensajes del usuario)
        if (attachments && attachments.length > 0) {
            const $attachments = $('<div class="message-attachments"></div>');

            if (Array.isArray(attachments)) {
                attachments.forEach(file => {
                    if (file instanceof File) {
                        const $att = $('<div class="attachment-preview"></div>');
                        $att.text(file.name);
                        $attachments.append($att);
                    } else if (file.name) {
                        const $att = $('<div class="attachment-preview"></div>');
                        $att.text(file.name);
                        $attachments.append($att);
                    }
                });
            }

            $content.append($attachments);
        }

        // Texto (renderizar markdown para respuestas del asistente)
        const $text = $('<div class="message-text"></div>');
        if (role === 'assistant' && typeof marked !== 'undefined') {
            $text.html(marked.parse(content));
        } else {
            $text.text(content);
        }
        $content.append($text);

        $message.append($content);
        $container.append($message);

        if (scroll) {
            scrollToBottom();
        }
    }

    /**
     * Mostrar indicador de "escribiendo"
     */
    function showTypingIndicator() {
        const $indicator = $('<div class="message message-assistant typing-indicator"></div>');
        $indicator.html('<div class="message-icon"><span class="dashicons dashicons-admin-customizer"></span></div><div class="message-content"><div class="typing-dots"><span></span><span></span><span></span></div></div>');
        $('#chat-messages').append($indicator);
        scrollToBottom();
    }

    /**
     * Ocultar indicador de "escribiendo"
     */
    function hideTypingIndicator() {
        $('.typing-indicator').remove();
    }

    /**
     * Limpiar mensajes
     */
    function clearMessages() {
        $('#chat-messages').html('<div class="welcome-message"><div class="gemini-icon"><span class="dashicons dashicons-admin-customizer"></span></div><h3>¡Hola! Soy tu asistente Gemini</h3><p>¿En qué puedo ayudarte hoy?</p></div>');
    }

    /**
     * Scroll al final
     */
    function scrollToBottom() {
        const $container = $('#chat-messages');
        $container.scrollTop($container[0].scrollHeight);
    }

    /**
     * Mostrar error
     */
    function showError(message) {
        const $error = $('<div class="notice notice-error is-dismissible"><p>' + message + '</p></div>');
        $('.mad-gemini-chat-container').prepend($error);
        setTimeout(() => $error.fadeOut(), 5000);
    }

    /**
     * Establecer estado de carga
     */
    function setLoading(loading) {
        if (loading) {
            $('#send-button').prop('disabled', true).addClass('loading');
            $('#message-input').prop('disabled', true);
        } else {
            $('#send-button').prop('disabled', false).removeClass('loading');
            $('#message-input').prop('disabled', false).focus();
        }
    }

    /**
     * Auto-resize de textarea
     */
    function autoResizeTextarea() {
        const $textarea = $(this);
        $textarea.css('height', 'auto');
        $textarea.css('height', Math.min($textarea[0].scrollHeight, 200) + 'px');
    }

    /**
     * Refrescar lista de conversaciones
     */
    function refreshConversationsList() {
        // Recargar página para actualizar la lista
        // En una versión más avanzada, esto se haría via AJAX
        setTimeout(() => location.reload(), 1000);
    }

})(jQuery);
