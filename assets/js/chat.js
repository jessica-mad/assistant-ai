jQuery(document).ready(function($) {
    // Auto-scroll to bottom of messages
    function scrollToBottom() {
        const messagesContainer = document.getElementById('messages');
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Hacer scroll al final del contenedor del chat para mostrar las sugerencias
        const chatContainer = document.getElementById('chat-container');
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    }
    
    scrollToBottom();
    
    // Get if this is a fixed assistant
    const isFixedAssistant = openai_chat_vars.is_fixed_assistant || false;
    
    // Función para obtener sugerencias del asistente actual
    function getAssistantSuggestions() {
        return new Promise((resolve, reject) => {
            const currentAssistantId = $('#assistant-select').val() || openai_chat_vars.current_assistant_id;
            
            $.ajax({
                url: openai_chat_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'openai_get_assistant_suggestions',
                    assistant_id: currentAssistantId,
                    nonce: openai_chat_vars.nonce
                },
                success: function(response) {
                    if (response.success && response.data.suggestions) {
                        resolve(response.data.suggestions);
                    } else {
                        // Fallback a sugerencias por defecto si no hay configuradas
                        resolve([
                            '¿Cómo puedo ayudarte hoy?',
                            '¿Tienes alguna pregunta específica?',
                            '¿En qué tema te gustaría profundizar?'
                        ]);
                    }
                },
                error: function() {
                    // Fallback en caso de error
                    resolve([
                        '¿Cómo puedo ayudarte hoy?',
                        '¿Tienes alguna pregunta específica?',
                        '¿En qué tema te gustaría profundizar?'
                    ]);
                }
            });
        });
    }
    
    // Función para mostrar sugerencias iniciales
    async function showInitialSuggestions() {
        try {
            console.log('Obteniendo sugerencias del asistente actual');
            const suggestions = await getAssistantSuggestions();
            console.log('Sugerencias obtenidas:', suggestions);
            renderSuggestionButtons(suggestions);
        } catch (error) {
            console.error('Error obteniendo sugerencias:', error);
            // Mostrar sugerencias por defecto en caso de error
            renderSuggestionButtons([
                '¿Cómo puedo ayudarte hoy?',
                '¿Tienes alguna pregunta específica?',
                '¿En qué tema te gustaría profundizar?'
            ]);
        }
    }
    
    // Función mejorada para extraer sugerencias de la respuesta del asistente
    function extractSuggestions(text) {
        const suggestions = [];
        
        // Buscar el formato específico "SUGERENCIAS:"
        // Mejorado para capturar múltiples formatos posibles
        const regex = /SUGERENCIAS:\s*Q1-\s*(.*?);\s*Q2-\s*(.*?);\s*Q3-\s*(.*?);/s;
        const sugerenciasMatch = text.match(regex);
        
        if (sugerenciasMatch && sugerenciasMatch.length >= 4) {
            console.log('Formato de sugerencias encontrado!');
            
            // Eliminar las sugerencias del texto original en DOM
            const sugerenciasElement = $('.assistant-message').last();
            if (sugerenciasElement.length) {
                let contenidoTexto = sugerenciasElement.html();
                // Reemplazar la sección de sugerencias con un texto vacío
                contenidoTexto = contenidoTexto.replace(/SUGERENCIAS:([^]*?)(?:$|(?:\n\n))/i, '');
                sugerenciasElement.html(contenidoTexto);
            }
            
            // Añadir cada sugerencia individual al array
            for (let i = 1; i <= 3; i++) {
                if (sugerenciasMatch[i] && sugerenciasMatch[i].trim()) {
                    suggestions.push(sugerenciasMatch[i].trim());
                }
            }
            
            console.log('Sugerencias extraídas:', suggestions);
        } else {
            console.log('No se encontró el formato específico de sugerencias');
            
            // Si no se encontraron sugerencias con el formato específico, buscar preguntas en el texto
            const questionMatches = text.match(/(?:\\¿|\?|\¿)([^?\\¿]+)(?:\\?|\?)/g);
            if (questionMatches) {
                questionMatches.forEach(match => {
                    // Limpiar la pregunta y añadirla si es razonable
                    const question = match.replace(/[\\¿\\?]/g, '').trim();
                    if (question.length > 10 && question.length < 100 && !suggestions.includes(question)) {
                        suggestions.push(question);
                    }
                });
            }
            
            // Si aún no hay suficientes sugerencias, buscar frases con "puedes" o "quieres"
            if (suggestions.length < 3) {
                const phrases = text.split(/[.!?]\\s+/);
                phrases.forEach(phrase => {
                    if ((phrase.includes('puedes') || phrase.includes('quieres') || 
                        phrase.includes('deseas') || phrase.includes('te gustaría')) && 
                        phrase.length > 15 && phrase.length < 100) {
                        const cleanPhrase = phrase.trim() + '?';
                        if (!suggestions.includes(cleanPhrase)) {
                            suggestions.push(cleanPhrase);
                        }
                    }
                });
            }
        }
        
        // Limitar a 3 sugerencias
        return suggestions.slice(0, 3);
    }
    
    // Función para renderizar los botones de sugerencia
    function renderSuggestionButtons(suggestions) {
        // Eliminar sugerencias anteriores si existen
        $('.suggestion-buttons').remove();
        
        if (suggestions && suggestions.length > 0) {
            console.log('Renderizando botones de sugerencia:', suggestions);
            
            const $suggestionsContainer = $('<div class="suggestion-buttons"></div>');
            
            suggestions.forEach(suggestion => {
                const $button = $('<button type="button" class="suggestion-button"></button>')
                    .text(suggestion)
                    .on('click', function() {
                        // Establecer el valor en el campo de entrada
                        $('#message-input').val(suggestion);
                        
                        // Enviar el formulario automáticamente
                        $('#message-form').submit();
                        
                        // Remover los botones después de hacer clic para evitar clics duplicados
                        $('.suggestion-buttons').remove();
                    });
                
                $suggestionsContainer.append($button);
            });
            
            // Añadir DESPUÉS del formulario en lugar de antes
            $('#message-form').after($suggestionsContainer);
        } else {
            console.log('No hay sugerencias para mostrar');
        }
    }
    
    // Mostrar sugerencias iniciales al cargar
    showInitialSuggestions();
    
    // Change assistant handler
    $('#assistant-select').on('change', function() {
        // Skip if this is a fixed assistant
        if (isFixedAssistant) {
            console.log('This is a fixed assistant, cannot change');
            return;
        }
        
        const assistantId = $(this).val();
        
        // Show typing indicator
        $('#typing-indicator').show();
        scrollToBottom();
        
        // Send AJAX request to change assistant
        $.ajax({
            url: openai_chat_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'openai_change_assistant',
                assistant_id: assistantId,
                nonce: openai_chat_vars.nonce
            },
            success: function(response) {
                // Hide typing indicator
                $('#typing-indicator').hide();
                
                if (response.success) {
                    // Clear messages
                    $('#messages').empty();
                    
                    // Add welcome message from new assistant
                    const assistantName = response.data.assistant_name || 'Asistente';
                    const welcomeHtml = `
                        <div class="message assistant-message">
                            Hola, soy ${assistantName}. ¿En qué puedo ayudarte?
                        </div>
                    `;
                    $('#messages').append(welcomeHtml);
                    
                    // Re-add typing indicator
                    $('#messages').append(`
                        <div class="typing-indicator" id="typing-indicator">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                    `);
                    $('#typing-indicator').hide();
                    
                    // Update thread info
                    $('.thread-info').remove();
                    
                    // Mostrar sugerencias del nuevo asistente
                    showInitialSuggestions();
                    
                    scrollToBottom();
                } else {
                    // Show error message
                    const errorHtml = `
                        <div class="message error-message">
                            Error: ${response.data || 'No se pudo cambiar de asistente.'}
                        </div>
                    `;
                    $('#messages').append(errorHtml);
                    
                    scrollToBottom();
                }
            },
            error: function() {
                // Hide typing indicator
                $('#typing-indicator').hide();
                
                // Show error message
                const errorHtml = `
                    <div class="message error-message">
                        Error: No se pudo conectar con el servidor.
                    </div>
                `;
                $('#messages').append(errorHtml);
                
                scrollToBottom();
            }
        });
    });
    
    // Handle form submission
    $('#message-form').on('submit', function(e) {
        e.preventDefault();
        
        const messageInput = $('#message-input');
        const message = messageInput.val();
        
        if (!message) {
            return;
        }
        
        // Clear input
        messageInput.val('');
        
        // Add user message to chat
        $('#messages').append(`
            <div class="message user-message">
                ${message}
            </div>
        `);
        
        // Remover los botones de sugerencia mientras se espera la respuesta
        $('.suggestion-buttons').remove();
        
        scrollToBottom();
        
        // Show typing indicator
        $('#typing-indicator').show();
        scrollToBottom();
        
        // Send message to server
        $.ajax({
            url: openai_chat_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'openai_send_message',
                message: message,
                nonce: openai_chat_vars.nonce
            },
            success: function(response) {
                // Hide typing indicator
                $('#typing-indicator').hide();
                
                if (response.success) {
                    // Update chat with all messages
                    $('#messages').empty();
                    
                    // Variable para almacenar la última respuesta del asistente
                    let lastAssistantMessage = '';
                    
                    response.data.messages.forEach(function(msg) {
                        // Conservar el texto original para extraer sugerencias después
                        let originalText = msg.text;
                        
                        // Si es un mensaje del asistente, comprobar si contiene sugerencias
                        // No eliminamos la sección de SUGERENCIAS: aquí, lo haremos más tarde
                        // después de extraer las sugerencias
                        if (msg.type === 'assistant') {
                            lastAssistantMessage = originalText;
                        }
                        
                        const messageHtml = `
                            <div class="message ${msg.type}-message">
                                ${originalText.replace(/\n/g, '<br>')}
                            </div>
                        `;
                        $('#messages').append(messageHtml);
                    });
                    
                    // Update thread ID display if it exists
                    if (response.data.thread_id) {
                        if ($('.thread-info').length) {
                            $('.thread-info').text('Thread ID: ' + response.data.thread_id);
                        } else {
                            // Insertar después del selector de asistentes si existe
                            if ($('.assistant-selector').length) {
                                $('.assistant-selector').after(`
                                    <div class="thread-info">
                                        Thread ID: ${response.data.thread_id}
                                    </div>
                                `);
                            } else {
                                $('#chat-container').prepend(`
                                    <div class="thread-info">
                                        Thread ID: ${response.data.thread_id}
                                    </div>
                                `);
                            }
                        }
                    }
                    
                    // Re-add the typing indicator (hidden) for future use
                    $('#messages').append(`
                        <div class="typing-indicator" id="typing-indicator">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                    `);
                    $('#typing-indicator').hide();
                    
                    // Extraer sugerencias de la última respuesta del asistente
                    if (lastAssistantMessage) {
                        console.log('Procesando sugerencias de la respuesta del asistente');
                        const suggestions = extractSuggestions(lastAssistantMessage);
                        
                        // Ahora que ya extrajimos las sugerencias, podemos limpiar el mensaje del asistente
                        // eliminando la sección SUGERENCIAS:
                        const lastAssistantElement = $('.assistant-message').last();
                        if (lastAssistantElement.length) {
                            const cleanedText = lastAssistantMessage.replace(/SUGERENCIAS:([^]*?)(?:$|(?:\n\n))/i, '');
                            lastAssistantElement.html(cleanedText.replace(/\n/g, '<br>'));
                        }
                        
                        if (suggestions.length > 0) {
                            console.log('Sugerencias encontradas:', suggestions);
                            renderSuggestionButtons(suggestions);
                        } else {
                            console.log('No se encontraron sugerencias específicas');
                            // Mostrar sugerencias del asistente actual si no se encontraron específicas
                            showInitialSuggestions();
                        }
                    }
                    
                    // Hacer scroll al final después de procesar todo
                    scrollToBottom();
                } else {
                    // Show error message
                    $('#messages').append(`
                        <div class="message error-message">
                            Error: ${response.data}
                        </div>
                    `);
                    
                    // Mostrar sugerencias iniciales en caso de error
                    showInitialSuggestions();
                    
                    // Scroll al final
                    scrollToBottom();
                }
            },
            error: function() {
                // Hide typing indicator
                $('#typing-indicator').hide();
                
                // Show error message
                $('#messages').append(`
                    <div class="message error-message">
                        Error: Failed to communicate with the server.
                    </div>
                `);
                
                // Mostrar sugerencias iniciales en caso de error
                showInitialSuggestions();
                
                scrollToBottom();
            }
        });
    });
    
    // Variable global para almacenar la instancia de CodeMirror
    let cmEditor = null;
    
    // Añadir notificación visual para mensajes de éxito y error
    function showNotification(message, type = 'success') {
        // Eliminar notificaciones anteriores
        $('.css-notification').remove();
        
        // Crear notificación
        const $notification = $('<div class="css-notification"></div>')
            .addClass(type)
            .text(message)
            .css({
                'position': 'fixed',
                'top': '20px',
                'right': '20px',
                'padding': '10px 15px',
                'border-radius': '4px',
                'z-index': '9999',
                'color': '#fff',
                'box-shadow': '0 2px 10px rgba(0,0,0,0.2)',
                'opacity': '0',
                'transition': 'opacity 0.3s ease'
            });
        
        // Establecer colores según el tipo
        if (type === 'success') {
            $notification.css('background-color', '#4CAF50');
        } else if (type === 'error') {
            $notification.css('background-color', '#F44336');
        } else if (type === 'info') {
            $notification.css('background-color', '#2196F3');
        }
        
        // Añadir al cuerpo y mostrar con animación
        $('body').append($notification);
        
        // Forzar reflow para que la transición funcione
        $notification[0].offsetHeight;
        
        // Mostrar
        $notification.css('opacity', '1');
        
        // Ocultar después de 4 segundos
        setTimeout(function() {
            $notification.css('opacity', '0');
            setTimeout(function() {
                $notification.remove();
            }, 300);
        }, 4000);
    }
    
    // Inicializar CodeMirror si está disponible y el textarea existe
    if (typeof CodeMirror !== 'undefined' && $('#openai_custom_css').length > 0) {
        // Inicializar CodeMirror
        cmEditor = CodeMirror.fromTextArea(document.getElementById('openai_custom_css'), {
            lineNumbers: true,
            mode: 'css',
            theme: 'monokai',
            lineWrapping: true
        });
        
        console.log('CodeMirror inicializado');
    }
    
    // CSS Upload functionality for admin page
    if ($('#css-file-upload').length > 0 && $('#upload-css-btn').length > 0) {
        console.log('CSS upload functionality initialized');
        
        // Store original button text
        const originalBtnText = $('#upload-css-btn').text();
        
        $('#upload-css-btn').on('click', function() {
            console.log('Upload button clicked');
            
            const fileInput = document.getElementById('css-file-upload');
            const file = fileInput.files[0];
            
            if (!file) {
                // Display error notification visibly on the page
                $('<div class="css-notification error" style="background-color: #F44336; color: white; padding: 10px; margin: 10px 0; border-radius: 4px;">Please select a CSS file</div>')
                    .insertAfter('#upload-css-btn')
                    .delay(4000)
                    .fadeOut(300, function() { $(this).remove(); });
                    
                console.log('No file selected');
                return;
            }
            
            console.log('File selected:', file.name);
            
            // Update button to show progress
            const $btn = $(this);
            $btn.text('Loading...').prop('disabled', true);
            
            const reader = new FileReader();
            
            reader.onload = function(e) {
                try {
                    console.log('File read successfully');
                    const cssContent = e.target.result;
                    
                    // Update the textarea directly first
                    $('#openai_custom_css').val(cssContent);
                    
                    // Then update CodeMirror if available
                    if (typeof wp !== 'undefined' && wp.codeEditor && wp.codeEditor._instances) {
                        // Handle WordPress code editor
                        const editor = wp.codeEditor._instances[0];
                        if (editor && editor.codemirror) {
                            console.log('Updating WordPress Code Editor');
                            editor.codemirror.setValue(cssContent);
                        }
                    } else if (typeof CodeMirror !== 'undefined' && cmEditor) {
                        console.log('Updating CodeMirror');
                        cmEditor.setValue(cssContent);
                        cmEditor.refresh();
                    }
                    
                    // Display success notification visibly on the page
                    $('<div class="css-notification success" style="background-color: #4CAF50; color: white; padding: 10px; margin: 10px 0; border-radius: 4px;">CSS loaded into the editor. Click "Save Changes" to apply it.</div>')
                        .insertAfter('#upload-css-btn')
                        .delay(4000)
                        .fadeOut(300, function() { $(this).remove(); });
                    
                    // Reset the file input
                    $('#css-file-upload').val('');
                    
                } catch (error) {
                    console.error('Error loading CSS:', error);
                    
                    // Display error notification visibly on the page
                    $('<div class="css-notification error" style="background-color: #F44336; color: white; padding: 10px; margin: 10px 0; border-radius: 4px;">Error loading CSS file: ' + error.message + '</div>')
                        .insertAfter('#upload-css-btn')
                        .delay(4000)
                        .fadeOut(300, function() { $(this).remove(); });
                        
                } finally {
                    // Restore the button
                    $btn.text(originalBtnText).prop('disabled', false);
                }
            };
            
            reader.onerror = function() {
                console.error('Error reading file');
                
                // Display error notification visibly on the page
                $('<div class="css-notification error" style="background-color: #F44336; color: white; padding: 10px; margin: 10px 0; border-radius: 4px;">Error reading CSS file</div>')
                    .insertAfter('#upload-css-btn')
                    .delay(4000)
                    .fadeOut(300, function() { $(this).remove(); });
                    
                $btn.text(originalBtnText).prop('disabled', false);
            };
            
            reader.readAsText(file);
        });
        
        // Add event to the form to sync CodeMirror before submitting
        $('form').on('submit', function() {
            if (typeof wp !== 'undefined' && wp.codeEditor && wp.codeEditor._instances) {
                // WordPress code editor handling
                const editor = wp.codeEditor._instances[0];
                if (editor && editor.codemirror) {
                    console.log('Syncing WordPress Code Editor with textarea before form submission');
                    editor.codemirror.save();
                }
            } else if (typeof CodeMirror !== 'undefined' && cmEditor) {
                console.log('Syncing CodeMirror with textarea before form submission');
                cmEditor.save();
            }
            
            // Log to verify
            console.log('Textarea content before submission:', $('#openai_custom_css').val().substring(0, 50) + '...');
        });
    }
    
    // Detectar cambios en la URL (para detectar cuando se ha enviado el formulario)
    // Esto mostrará una notificación después de guardar los cambios
    if (window.location.href.includes('settings-updated=true')) {
        showNotification('Los cambios se han guardado correctamente', 'success');
    }
});