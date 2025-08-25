<?php
/**
 * Template for chat interface with a fixed assistant
 *
 * @package OpenAI_Assistant_Chat
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Obtener asistentes
$plugin_instance = OpenAI_Assistant_Chat_Plugin::get_instance();
$assistants = $plugin_instance->get_assistants();

// Obtener el ID del asistente fijo
$fixed_assistant_id = isset($atts['assistant_id']) ? sanitize_text_field($atts['assistant_id']) : '';
$fixed_assistant_name = isset($atts['assistant_name']) ? sanitize_text_field($atts['assistant_name']) : '';

// Si se proporcionó un nombre en lugar de un ID, buscar el ID correspondiente
if (empty($fixed_assistant_id) && !empty($fixed_assistant_name)) {
    foreach ($assistants as $id => $assistant) {
        if (strtolower($assistant['name']) === strtolower($fixed_assistant_name)) {
            $fixed_assistant_id = $id;
            break;
        }
    }
}

// Si no se encontró el asistente, usar el primero disponible
if (empty($fixed_assistant_id) || !array_key_exists($fixed_assistant_id, $assistants)) {
    $fixed_assistant_id = array_key_exists($_SESSION['openai_active_assistant_id'], $assistants) 
        ? $_SESSION['openai_active_assistant_id'] 
        : array_keys($assistants)[0];
}

// Establecer el asistente activo en la sesión
$_SESSION['openai_active_assistant_id'] = $fixed_assistant_id;

// Obtener información del asistente
$assistant_name = isset($assistants[$fixed_assistant_id]['name']) ? $assistants[$fixed_assistant_id]['name'] : 'Asistente';
?>
<div id="chat-container" class="fixed-assistant-chat">
    <div class="assistant-info">
        <h3><?php echo esc_html($assistant_name); ?></h3>
        <?php if (!empty($assistants[$fixed_assistant_id]['description'])): ?>
            <p class="assistant-description"><?php echo esc_html($assistants[$fixed_assistant_id]['description']); ?></p>
        <?php endif; ?>
    </div>
    
    <?php if (isset($_SESSION['openai_thread_id']) && $_SESSION['openai_thread_id']): ?>
    <div class="thread-info">
        Thread ID: <?php echo esc_html($_SESSION['openai_thread_id']); ?>
    </div>
    <?php endif; ?>
    
    <div id="messages">
        <?php if (empty($messages)): ?>
            <div class="message assistant-message">
                Hola, soy <?php echo esc_html($assistant_name); ?>. ¿En qué puedo ayudarte?
            </div>
        <?php else: ?>
            <?php foreach ($messages as $message): ?>
                <div class="message <?php echo esc_attr($message['type']); ?>-message">
                    <?php echo wp_kses_post(nl2br($message['text'])); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="typing-indicator" id="typing-indicator">
            <span class="dot"></span>
            <span class="dot"></span>
            <span class="dot"></span>
        </div>
    </div>
    
    <form id="message-form" autocomplete="off">
        <input type="text" id="message-input" name="message" placeholder="<?php esc_attr_e('Escribe tu mensaje...', 'openai-assistant-chat'); ?>" required autocomplete="off">
        <button type="submit"><?php esc_html_e('Enviar', 'openai-assistant-chat'); ?></button>
        <a href="<?php echo esc_url(add_query_arg('clear_chat', '1')); ?>" id="clear-button"><?php esc_html_e('Limpiar', 'openai-assistant-chat'); ?></a>
    </form>
    
    <!-- Los botones de sugerencia se insertarán aquí dinámicamente por JavaScript -->
</div>