<?php
/**
 * Template for chat interface with assistant selection
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
$active_assistant_id = $_SESSION['openai_active_assistant_id'] ?? '';
?>
<div id="chat-container">
    <?php if (!empty($assistants) && count($assistants) > 1): ?>
    <div class="assistant-selector">
        <label for="assistant-select"><?php esc_html_e('Seleccionar asistente:', 'openai-assistant-chat'); ?></label>
        <select id="assistant-select">
            <?php foreach ($assistants as $id => $assistant): ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($active_assistant_id, $id); ?>>
                    <?php echo esc_html($assistant['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['openai_thread_id']) && $_SESSION['openai_thread_id']): ?>
    <div class="thread-info">
        Thread ID: <?php echo esc_html($_SESSION['openai_thread_id']); ?>
    </div>
    <?php endif; ?>
    
    <div id="messages">
        <?php if (empty($messages)): ?>
            <div class="message assistant-message">
                <?php 
                $assistant_name = !empty($active_assistant_id) && isset($assistants[$active_assistant_id]['name']) 
                    ? $assistants[$active_assistant_id]['name'] 
                    : 'Asistente';
                ?>
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