<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OpenAI_Assistant_Chat_Plugin {
    // Singleton instance
    private static $instance = null;
    
    // Class constructor
    private function __construct() {
        // Initialize the plugin
        add_action('init', array($this, 'init'));
        
        // Add shortcode
        add_shortcode('openai_assistant_chat', array($this, 'render_chat_shortcode'));
        
        // Register AJAX handlers
        add_action('wp_ajax_openai_send_message', array($this, 'ajax_send_message'));
        add_action('wp_ajax_nopriv_openai_send_message', array($this, 'ajax_send_message'));
        
        // Add AJAX handler for changing assistant
        add_action('wp_ajax_openai_change_assistant', array($this, 'ajax_change_assistant'));
        add_action('wp_ajax_nopriv_openai_change_assistant', array($this, 'ajax_change_assistant'));
        
        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        add_action('wp_ajax_openai_download_css_template', array($this, 'download_css_template'));
        add_action('wp_ajax_nopriv_openai_download_css_template', array($this, 'download_css_template'));
    }

    public function download_css_template() {
        // Set the appropriate headers for a CSS file download
        header('Content-Type: text/css');
        header('Content-Disposition: attachment; filename="openai-chat-template.css"');
        
        // Get the template file path
        $template_file = OPENAI_ASSISTANT_CHAT_PATH . 'assets/css/template.css';
        
        // Check if the file exists
        if (file_exists($template_file)) {
            // Output the file contents
            echo file_get_contents($template_file);
        } else {
            // If file doesn't exist, output an error message as CSS comment
            echo "/* Error: Template file not found. */";
        }
        
        exit;
    }
    
    // Get singleton instance
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Initialize plugin
    public function init() {
        // Start session if not already started
        if (!session_id()) {
            session_start();
        }
        
        // Initialize chat session if needed
        if (!isset($_SESSION['openai_chat_messages'])) {
            $_SESSION['openai_chat_messages'] = [];
        }
        
        if (!isset($_SESSION['openai_thread_id'])) {
            $_SESSION['openai_thread_id'] = null;
        }
        
        // Guardar el ID del asistente activo
        if (!isset($_SESSION['openai_active_assistant_id'])) {
            // Usar el primer asistente por defecto
            $assistants = $this->get_assistants();
            if (!empty($assistants)) {
                $_SESSION['openai_active_assistant_id'] = array_keys($assistants)[0];
            } else {
                $_SESSION['openai_active_assistant_id'] = '';
            }
        }
        
        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'register_scripts'));
    }
    
    // Método para obtener todos los asistentes registrados
    public function get_assistants() {
        $assistants = get_option('openai_assistants', array());
        if (empty($assistants) && get_option('openai_assistant_id')) {
            // Migrar configuración anterior a la nueva estructura
            $assistants = array(
                get_option('openai_assistant_id') => array(
                    'name' => __('Asistente Principal', 'openai-assistant-chat'),
                    'description' => ''
                )
            );
            update_option('openai_assistants', $assistants);
        }
        return $assistants;
    }
    
    // Register scripts and styles
    public function register_scripts() {
        // Register and enqueue CSS
        wp_register_style(
            'openai-assistant-chat-style',
            OPENAI_ASSISTANT_CHAT_URL . 'assets/css/chat.css',
            array(),
            OPENAI_ASSISTANT_CHAT_VERSION
        );
        
        // Check if there's custom CSS
        $custom_css = get_option('openai_custom_css', '');
        if (!empty($custom_css)) {
            // Deregister the default style and add custom inline style
            wp_deregister_style('openai-assistant-chat-style');
            wp_register_style(
                'openai-assistant-chat-style', 
                false, 
                array()
            );
            wp_add_inline_style('openai-assistant-chat-style', $custom_css);
        }
        
        // Register and enqueue JS
        wp_register_script(
            'openai-assistant-chat-script',
            OPENAI_ASSISTANT_CHAT_URL . 'assets/js/chat.js',
            array('jquery'),
            OPENAI_ASSISTANT_CHAT_VERSION,
            true
        );

        wp_enqueue_style('openai-assistant-chat-style');
        
        // Añadir lista de asistentes a los datos JS
        $assistants = $this->get_assistants();
        $active_assistant_id = isset($_SESSION['openai_active_assistant_id']) ? $_SESSION['openai_active_assistant_id'] : '';
        
        wp_localize_script('openai-assistant-chat-script', 'openai_chat_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('openai_assistant_chat_nonce'),
            'assistants' => $assistants,
            'active_assistant' => $active_assistant_id
        ));
    }
    
    // Render chat shortcode
    // Modificar la función del shortcode en class-openai-assistant-chat.php
public function render_chat_shortcode($atts) {
    // Parsear atributos
    $atts = shortcode_atts(array(
        'assistant_id' => '',
        'assistant_name' => '',
        'template' => 'default' // Puede ser 'default' o 'fixed'
    ), $atts, 'openai_assistant_chat');
    
    // Inicia/verifica la sesión
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Clear chat history if requested
    if (isset($_GET['clear_chat']) && $_GET['clear_chat'] == 1) {
        $_SESSION['openai_chat_messages'] = [];
        $_SESSION['openai_thread_id'] = null;
        
        // Get current page URL without the clear_chat parameter
        $current_url = remove_query_arg('clear_chat');
        
        // Redirect to current page without parameter
        wp_redirect($current_url);
        exit;
    }

    // Get messages from session
    $messages = isset($_SESSION['openai_chat_messages']) ? $_SESSION['openai_chat_messages'] : [];
    
    // Enqueue styles and scripts
    wp_enqueue_style('openai-assistant-chat-style');
    wp_enqueue_script('openai-assistant-chat-script');
    
    // Pasar información al script JS sobre si es un asistente fijo
    $is_fixed = ($atts['template'] === 'fixed' || !empty($atts['assistant_id']) || !empty($atts['assistant_name']));
    wp_localize_script('openai-assistant-chat-script', 'openai_chat_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('openai_assistant_chat_nonce'),
        'assistants' => $this->get_assistants(),
        'active_assistant' => $_SESSION['openai_active_assistant_id'],
        'is_fixed_assistant' => $is_fixed
    ));
    
    // Include chat template
    ob_start();
    
    // Elegir el template según los parámetros
    if ($is_fixed) {
        include OPENAI_ASSISTANT_CHAT_PATH . 'templates/chat-interface-fixed.php';
    } else {
        include OPENAI_ASSISTANT_CHAT_PATH . 'templates/chat-interface.php';
    }
    
    return ob_get_clean();
}
    
    // AJAX para cambiar de asistente
    public function ajax_change_assistant() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'openai_assistant_chat_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $assistant_id = sanitize_text_field($_POST['assistant_id'] ?? '');
        
        if (empty($assistant_id)) {
            wp_send_json_error('Assistant ID is required');
        }
        
        // Verificar que el asistente existe
        $assistants = $this->get_assistants();
        if (!array_key_exists($assistant_id, $assistants)) {
            wp_send_json_error('Invalid Assistant ID');
        }
        
        // Guardar el asistente activo y limpiar el chat
        $_SESSION['openai_active_assistant_id'] = $assistant_id;
        $_SESSION['openai_chat_messages'] = [];
        $_SESSION['openai_thread_id'] = null;
        
        wp_send_json_success([
            'assistant_id' => $assistant_id,
            'assistant_name' => $assistants[$assistant_id]['name']
        ]);
    }
    
    // AJAX handler for sending messages
    public function ajax_send_message() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'openai_assistant_chat_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $user_message = sanitize_text_field($_POST['message'] ?? '');
        
        if (empty($user_message)) {
            wp_send_json_error('Message is required');
        }
        
        // Add user message to session
        $_SESSION['openai_chat_messages'][] = [
            'type' => 'user',
            'text' => $user_message
        ];
        
        try {
            // Get API credentials from settings
            $api_key = get_option('openai_assistant_api_key');
            $assistant_id = $_SESSION['openai_active_assistant_id'] ?? '';
            
            if (empty($api_key) || empty($assistant_id)) {
                throw new Exception('API key or Assistant ID not configured');
            }
            
            $chat = new OpenAIAssistantChat($api_key, $assistant_id);
            
            // Send message and get response
            $result = $chat->sendMessage($user_message, $_SESSION['openai_thread_id']);
            
            // Update thread ID in session
            if (isset($result['thread_id'])) {
                $_SESSION['openai_thread_id'] = $result['thread_id'];
            }
            
            if (isset($result['error'])) {
                // Add error message to session
                $_SESSION['openai_chat_messages'][] = [
                    'type' => 'error',
                    'text' => $result['error']
                ];
                wp_send_json_error($result['error']);
            } else {
                // Add assistant response to session
                $_SESSION['openai_chat_messages'][] = [
                    'type' => 'assistant',
                    'text' => $result['response']
                ];
                
                // Return success with messages
                wp_send_json_success([
                    'messages' => $_SESSION['openai_chat_messages'],
                    'thread_id' => $_SESSION['openai_thread_id'],
                    'assistant_id' => $assistant_id
                ]);
            }
        } catch (Exception $e) {
            // Add error message to session
            $_SESSION['openai_chat_messages'][] = [
                'type' => 'error',
                'text' => $e->getMessage()
            ];
            wp_send_json_error($e->getMessage());
        }
    }
    
    // Add admin menu
    public function add_admin_menu() {
        $page_hook = add_options_page(
            __('OpenAI Assistant Chat Settings', 'openai-assistant-chat'),
            __('OpenAI Chat', 'openai-assistant-chat'),
            'manage_options',
            'openai-assistant-chat',
            array($this, 'render_settings_page')
        );
        
        // Load admin scripts only on our settings page
        add_action('admin_print_scripts-' . $page_hook, array($this, 'enqueue_admin_scripts'));
    }

    public function enqueue_admin_scripts() {
        wp_enqueue_script(
            'openai-assistant-chat-admin', 
            OPENAI_ASSISTANT_CHAT_URL . 'assets/js/chat.js', 
            array('jquery'), 
            OPENAI_ASSISTANT_CHAT_VERSION, 
            true
        );
        
        // Enqueue the WordPress code editor for CSS
        if (function_exists('wp_enqueue_code_editor')) {
            wp_enqueue_code_editor(array('type' => 'text/css'));
        }
    }
    
    // Register settings
    public function register_settings() {
        register_setting('openai_assistant_chat_settings', 'openai_assistant_api_key');
        register_setting('openai_assistant_chat_settings', 'openai_assistants', array($this, 'sanitize_assistants'));
        register_setting('openai_assistant_chat_settings', 'openai_custom_css', array($this, 'sanitize_css'));

        add_settings_section(
            'openai_assistant_chat_main_section',
            __('API Settings', 'openai-assistant-chat'),
            array($this, 'settings_section_callback'),
            'openai-assistant-chat'
        );
        
        add_settings_field(
            'openai_assistant_api_key',
            __('API Key', 'openai-assistant-chat'),
            array($this, 'api_key_field_callback'),
            'openai-assistant-chat',
            'openai_assistant_chat_main_section'
        );
        
        add_settings_section(
            'openai_assistant_chat_assistants_section',
            __('Asistentes Configurados', 'openai-assistant-chat'),
            array($this, 'assistants_section_callback'),
            'openai-assistant-chat'
        );
        
        add_settings_field(
            'openai_assistants',
            __('Asistentes', 'openai-assistant-chat'),
            array($this, 'assistants_field_callback'),
            'openai-assistant-chat',
            'openai_assistant_chat_assistants_section'
        );

        add_settings_section(
            'openai_assistant_chat_css_section',
            __('CSS Personalizado', 'openai-assistant-chat'),
            array($this, 'css_section_callback'),
            'openai-assistant-chat'
        );
        
        add_settings_field(
            'openai_custom_css',
            __('CSS Personalizado', 'openai-assistant-chat'),
            array($this, 'custom_css_field_callback'),
            'openai-assistant-chat',
            'openai_assistant_chat_css_section'
        );
    }
    

    
    // Settings section callback
    public function settings_section_callback() {
        echo '<p>' . esc_html__('Ingresa tu configuración de la API de OpenAI a continuación.', 'openai-assistant-chat') . '</p>';
    }

    public function css_section_callback() {
        echo '<p>' . esc_html__('Personaliza la apariencia del chat descargando la plantilla CSS y subiendo tu versión modificada.', 'openai-assistant-chat') . '</p>';
    }
    
    // API key field callback
    public function api_key_field_callback() {
        $api_key = get_option('openai_assistant_api_key');
        echo '<input type="password" id="openai_assistant_api_key" name="openai_assistant_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Tu clave de API de OpenAI.', 'openai-assistant-chat') . '</p>';
    }
    
    // Callback para sección de asistentes
    public function assistants_section_callback() {
        echo '<p>' . esc_html__('Configura los diferentes asistentes de OpenAI que quieres ofrecer en tu chat.', 'openai-assistant-chat') . '</p>';
    }
    
    // Callback para campo de asistentes
    public function assistants_field_callback() {
        $assistants = $this->get_assistants();
        ?>
        <div id="openai-assistants-container">
            <?php foreach ($assistants as $id => $assistant) : ?>
            <div class="assistant-entry">
                <p>
                    <label><?php esc_html_e('ID del Asistente', 'openai-assistant-chat'); ?></label>
                    <input type="text" name="openai_assistants[<?php echo esc_attr($id); ?>][id]" value="<?php echo esc_attr($id); ?>" readonly class="regular-text">
                </p>
                <p>
                    <label><?php esc_html_e('Nombre', 'openai-assistant-chat'); ?></label>
                    <input type="text" name="openai_assistants[<?php echo esc_attr($id); ?>][name]" value="<?php echo esc_attr($assistant['name'] ?? ''); ?>" required class="regular-text">
                </p>
                <p>
                    <label><?php esc_html_e('Descripción', 'openai-assistant-chat'); ?></label>
                    <textarea name="openai_assistants[<?php echo esc_attr($id); ?>][description]" class="large-text" rows="2"><?php echo esc_textarea($assistant['description'] ?? ''); ?></textarea>
                </p>
                <button type="button" class="button remove-assistant"><?php esc_html_e('Eliminar', 'openai-assistant-chat'); ?></button>
                <hr>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button button-secondary" id="add-openai-assistant"><?php esc_html_e('Añadir Asistente', 'openai-assistant-chat'); ?></button>
        
        <script>
        jQuery(document).ready(function($) {
            // Añadir nuevo asistente
            $('#add-openai-assistant').on('click', function() {
                const template = `
                    <div class="assistant-entry">
                        <p>
                            <label><?php esc_html_e('ID del Asistente', 'openai-assistant-chat'); ?></label>
                            <input type="text" name="openai_assistants[new][]" value="" required class="regular-text">
                        </p>
                        <p>
                            <label><?php esc_html_e('Nombre', 'openai-assistant-chat'); ?></label>
                            <input type="text" name="openai_assistants[new_name][]" value="" required class="regular-text">
                        </p>
                        <p>
                            <label><?php esc_html_e('Descripción', 'openai-assistant-chat'); ?></label>
                            <textarea name="openai_assistants[new_description][]" class="large-text" rows="2"></textarea>
                        </p>
                        <button type="button" class="button remove-assistant"><?php esc_html_e('Eliminar', 'openai-assistant-chat'); ?></button>
                        <hr>
                    </div>
                `;
                $('#openai-assistants-container').append(template);
            });
            
            // Eliminar asistente
            $(document).on('click', '.remove-assistant', function() {
                $(this).closest('.assistant-entry').remove();
            });
        });
        </script>
        <?php
    }

    public function custom_css_field_callback() {
        $custom_css = get_option('openai_custom_css', '');
        
        // Enqueue CodeMirror if available in WordPress (since WP 4.9)
        if (function_exists('wp_enqueue_code_editor')) {
            $settings = wp_enqueue_code_editor(array('type' => 'text/css'));
            
            // If successfully enqueued
            if ($settings !== false) {
                wp_add_inline_script(
                    'code-editor',
                    sprintf('jQuery(function() { wp.codeEditor.initialize("openai_custom_css", %s); });', wp_json_encode($settings))
                );
            }
        }
        ?>
        <div class="css-customization-controls">
            <div class="css-actions">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=openai_download_css_template')); ?>" class="button button-secondary">
                    <?php esc_html_e('Descargar Plantilla CSS', 'openai-assistant-chat'); ?>
                </a>
                <p class="description">
                    <?php esc_html_e('Descarga la plantilla CSS con comentarios que explican cada sección.', 'openai-assistant-chat'); ?>
                </p>
            </div>
            
            <div class="css-editor">
                <h4><?php esc_html_e('Editor CSS', 'openai-assistant-chat'); ?></h4>
                <textarea id="openai_custom_css" name="openai_custom_css" class="large-text code" rows="15"><?php echo esc_textarea($custom_css); ?></textarea>
                <p class="description">
                    <?php esc_html_e('Edita el CSS directamente en el editor.', 'openai-assistant-chat'); ?>
                </p>
            </div>
        </div>
        <?php
    }
    
    

    // Sanitizar datos de asistentes
    public function sanitize_assistants($input) {
        $output = array();
        
        // Procesar asistentes existentes
        if (isset($input) && is_array($input)) {
            foreach ($input as $key => $assistant) {
                if ($key !== 'new' && $key !== 'new_name' && $key !== 'new_description') {
                    $output[$key] = array(
                        'name' => sanitize_text_field($assistant['name'] ?? ''),
                        'description' => sanitize_textarea_field($assistant['description'] ?? '')
                    );
                }
            }
        }

       
        // Procesar nuevos asistentes
        if (isset($input['new']) && is_array($input['new'])) {
            $new_ids = $input['new'];
            $new_names = $input['new_name'] ?? array();
            $new_descriptions = $input['new_description'] ?? array();
            
            foreach ($new_ids as $index => $id) {
                $id = sanitize_text_field($id);
                if (!empty($id)) {
                    $output[$id] = array(
                        'name' => sanitize_text_field($new_names[$index] ?? ''),
                        'description' => sanitize_textarea_field($new_descriptions[$index] ?? '')
                    );
                }
            }
        }
        
        return $output;
    }

    public function sanitize_css($input) {
        // Basic sanitization for CSS
        return wp_strip_all_tags($input);
    }
    
    // Render settings page
    public function render_settings_page() {
        // Obtener lista de asistentes para la tabla
        $assistants = $this->get_assistants();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('openai_assistant_chat_settings');
                do_settings_sections('openai-assistant-chat');
                submit_button();
                ?>
            </form>
            
            <div class="shortcode-info">
                <h2><?php esc_html_e('Uso del Shortcode', 'openai-assistant-chat'); ?></h2>
                <p><?php esc_html_e('Para mostrar el chat en cualquier página o entrada, usa el siguiente shortcode:', 'openai-assistant-chat'); ?></p>
                
                <div class="shortcode-examples">
                    <h3><?php esc_html_e('Shortcode Básico', 'openai-assistant-chat'); ?></h3>
                    <code>[openai_assistant_chat]</code>
                    <p class="description"><?php esc_html_e('Muestra el chat con selector de asistentes.', 'openai-assistant-chat'); ?></p>
                    
                    <h3><?php esc_html_e('Shortcode con Asistente Específico (ID)', 'openai-assistant-chat'); ?></h3>
                    <code>[openai_assistant_chat assistant_id="ID_DEL_ASISTENTE"]</code>
                    <p class="description"><?php esc_html_e('Muestra el chat con un asistente específico según su ID.', 'openai-assistant-chat'); ?></p>
                    
                    <h3><?php esc_html_e('Shortcode con Asistente Específico (Nombre)', 'openai-assistant-chat'); ?></h3>
                    <code>[openai_assistant_chat assistant_name="NOMBRE_DEL_ASISTENTE"]</code>
                    <p class="description"><?php esc_html_e('Muestra el chat con un asistente específico según su nombre.', 'openai-assistant-chat'); ?></p>
                    
                </div>
            </div>
            
            <?php if (!empty($assistants)): ?>
            <div class="assistants-table-wrap">
                <h2><?php esc_html_e('Asistentes Disponibles', 'openai-assistant-chat'); ?></h2>
                <p><?php esc_html_e('Utiliza estos datos para los parámetros del shortcode:', 'openai-assistant-chat'); ?></p>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID del Asistente', 'openai-assistant-chat'); ?></th>
                            <th><?php esc_html_e('Nombre', 'openai-assistant-chat'); ?></th>
                            <th><?php esc_html_e('Descripción', 'openai-assistant-chat'); ?></th>
                            <th><?php esc_html_e('Shortcode de Ejemplo', 'openai-assistant-chat'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assistants as $id => $assistant): ?>
                        <tr>
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($assistant['name'] ?? ''); ?></td>
                            <td><?php echo esc_html($assistant['description'] ?? ''); ?></td>
                            <td><code>[openai_assistant_chat assistant_id="<?php echo esc_attr($id); ?>"]</code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
        </div>
        <?php
    }
}