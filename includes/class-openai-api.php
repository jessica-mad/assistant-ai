<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class OpenAIAssistantChat {
    private $api_key;
    private $assistant_id;

    public function __construct($api_key, $assistant_id) {
        $this->api_key = $api_key;
        $this->assistant_id = $assistant_id;
    }

    public function sendMessage($message, $thread_id = null) {
        // Si no hay un thread_id, crear uno nuevo
        if (!$thread_id) {
            $thread_url = 'https://api.openai.com/v1/threads';
            $thread_response = $this->apiRequest($thread_url, []);
            $thread_id = $thread_response['id'] ?? null;
            
            if (!$thread_id) {
                return ["error" => "Error creando thread", "thread_id" => null];
            }
        }

        // Añadir mensaje al thread existente
        $message_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";
        $message_response = $this->apiRequest($message_url, [
            'role' => 'user',
            'content' => $message
        ]);

        if (!isset($message_response['id'])) {
            return ["error" => "Error enviando mensaje al thread", "thread_id" => $thread_id];
        }

        // Crear un run con instrucciones para generar sugerencias al final con formato específico
        $run_url = "https://api.openai.com/v1/threads/{$thread_id}/runs";
        $run_response = $this->apiRequest($run_url, [
            'assistant_id' => $this->assistant_id,
            'instructions' => "Responde de manera útil y clara, como mucho 300 caracteres. Al final de tu respuesta, SIEMPRE incluye exactamente 3 sugerencias de preguntas relacionadas con el formato específico siguiente:

SUGERENCIAS:
Q1- ¿Primera pregunta sugerida?;
Q2- ¿Segunda pregunta sugerida?;
Q3- ¿Tercera pregunta sugerida?;

Es muy importante mantener este formato exacto con 'SUGERENCIAS:' seguido por cada pregunta con los prefijos Q1-, Q2-, Q3- y separadas por punto y coma. Este formato es necesario para que la interfaz pueda mostrar correctamente los botones de sugerencia."
        ]);

        $run_id = $run_response['id'] ?? null;
        
        if (!$run_id) {
            return ["error" => "Error creando run", "thread_id" => $thread_id];
        }

        // Verificar estado del run
        $max_attempts = 80;
        while ($max_attempts > 0) {
            $run_status_url = "https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}";
            $run_status = $this->apiRequest($run_status_url, [], 'GET');

            switch ($run_status['status']) {
                case 'completed':
                    // Obtener mensajes
                    $messages_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";
                    $messages_response = $this->apiRequest($messages_url, [], 'GET');

                    // Extraer la respuesta del asistente
                    if (!empty($messages_response['data'])) {
                        foreach ($messages_response['data'] as $message) {
                            if ($message['role'] == 'assistant' && $message['run_id'] == $run_id) {
                                return [
                                    "response" => $message['content'][0]['text']['value'],
                                    "thread_id" => $thread_id
                                ];
                            }
                        }
                    }
                    return ["error" => "No se pudo obtener respuesta", "thread_id" => $thread_id];

                case 'failed':
                    return [
                        "error" => "Run fallido: " . ($run_status['last_error']['message'] ?? 'Error desconocido'),
                        "thread_id" => $thread_id
                    ];

                case 'requires_action':
                    return ["error" => "Se requiere acción adicional", "thread_id" => $thread_id];
            }

            sleep(0.5);
            $max_attempts--;
        }

        return ["error" => "Tiempo de espera agotado", "thread_id" => $thread_id];
    }

    private function apiRequest($url, $data = [], $method = 'POST') {
        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
                'OpenAI-Beta' => 'assistants=v2'
            ],
            'timeout' => 30
        ];
        
        if ($method == 'POST') {
            $args['method'] = 'POST';
            $args['body'] = json_encode($data);
            $response = wp_remote_post($url, $args);
        } else {
            $args['method'] = 'GET';
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            throw new Exception('API Request Error: ' . $response->get_error_message());
        }
        
        $http_status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded_response = json_decode($body, true);
        
        if ($http_status >= 400) {
            error_log("OpenAI API Request Failed - Status: $http_status");
            error_log("Response: " . print_r($decoded_response, true));
            throw new Exception("API Request Failed: " . 
                ($decoded_response['error']['message'] ?? 'Unknown error')
            );
        }
        
        return $decoded_response;
    }
}