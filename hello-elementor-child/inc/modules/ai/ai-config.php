<?php
/**
 * Discoteche.org - OpenAI Helpers
 *
 * - Accesso centralizzato a chiave e modello
 * - Funzione Chat Completions non-streaming
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('discorg_openai_api_key')) {
    /**
     * Ritorna la chiave OpenAI:
     * - priorità alla costante DISCORG_OPENAI_API_KEY (wp-config.php)
     * - fallback all'opzione salvata in admin (discorg_openai_api_key)
     */
    function discorg_openai_api_key() {
        if (defined('DISCORG_OPENAI_API_KEY')) return trim((string) DISCORG_OPENAI_API_KEY);
        return trim((string) get_option('discorg_openai_api_key', ''));
    }
}

if (!function_exists('discorg_openai_model')) {
    /**
     * Ritorna il modello OpenAI:
     * - priorità alla costante DISCORG_OPENAI_MODEL
     * - fallback all'opzione 'discorg_openai_model' (default gpt-4.1-mini)
     * - se 'custom', usa l'opzione 'discorg_openai_model_custom'
     */
    function discorg_openai_model() {
        if (defined('DISCORG_OPENAI_MODEL')) return trim((string) DISCORG_OPENAI_MODEL);
        $model = get_option('discorg_openai_model', 'gpt-4.1-mini');
        if ($model === 'custom') {
            $custom = trim((string) get_option('discorg_openai_model_custom', ''));
            return $custom ?: 'gpt-4.1-mini';
        }
        return $model ?: 'gpt-4.1-mini';
    }
}

if (!function_exists('discorg_openai_is_connected')) {
    /**
     * True se esiste una chiave configurata (const o opzione)
     */
    function discorg_openai_is_connected() {
        return discorg_openai_api_key() !== '';
    }
}

if (!function_exists('discorg_openai_chat_complete')) {
    /**
     * Esegue una Chat Completion (non-streaming) su OpenAI.
     *
     * @param array $messages array di messaggi [{role, content}...]
     * @param string|null $model modello (se null usa discorg_openai_model())
     * @param float $temperature
     * @return array|WP_Error JSON decodificato oppure WP_Error
     */
    function discorg_openai_chat_complete(array $messages, ?string $model = null, float $temperature = 0.2) {
        $api_key = discorg_openai_api_key();
        if (!$api_key) return new WP_Error('discorg_no_openai_key', 'OpenAI API key assente');
        $model = $model ?: discorg_openai_model();

        $body = array(
            'model' => $model,
            'temperature' => $temperature,
            'messages' => $messages,
            'response_format' => array('type' => 'json_object'),
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 45,
            'body'    => wp_json_encode($body),
        );

        // Endpoint corretto Chat Completions
        $res = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $json = json_decode((string) wp_remote_retrieve_body($res), true);

        if ($code >= 400) {
            // Non esporre la chiave nei log
            return new WP_Error('discorg_openai_http', 'Errore OpenAI', array(
                'status' => $code,
                'resp'   => $json
            ));
        }
        return $json;
    }
}
