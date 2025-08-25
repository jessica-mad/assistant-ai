<?php
/**
 * Plugin Name: OpenAI Multi-Assistant Chat
 * Description: A WordPress plugin to integrate OpenAI's Assistant API for chat functionality.
 * Version: 4.0.0
 * Author: Angel
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OPENAI_ASSISTANT_CHAT_VERSION', '4.0.0');
define('OPENAI_ASSISTANT_CHAT_PATH', plugin_dir_path(__FILE__));
define('OPENAI_ASSISTANT_CHAT_URL', plugin_dir_url(__FILE__));

// Include required files
require_once OPENAI_ASSISTANT_CHAT_PATH . 'includes/class-openai-assistant-chat.php';
require_once OPENAI_ASSISTANT_CHAT_PATH . 'includes/class-openai-api.php';

// Initialize the plugin
function openai_assistant_chat_init() {
    $plugin = OpenAI_Assistant_Chat_Plugin::get_instance();
    return $plugin;
}

// Start the plugin
openai_assistant_chat_init();