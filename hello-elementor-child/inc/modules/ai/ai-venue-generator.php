<?php
/**
 * Discoteche.org - Generatore contenuti AI per CPT "discoteche"
 *
 * - Genera 6 campi ACF testuali via OpenAI Chat Completions (non-streaming)
 * - Scrive solo i campi vuoti (default); supporta $force=true per sovrascrivere
 * - Agganci automatici al termine dell'import singolo
 * - Endpoint AJAX per metabox manuale (tutti o singolo campo)
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('discorg_ai_generate_venue_content')) {
    /**
     * Genera contenuti AI per i 6 campi ACF.
     * - Scrive solo i campi vuoti, a meno che $force=true o si passi $only_fields.
     * - $only_fields: null (tutti) oppure array di nomi specifici da generare.
     *
     * @param int $post_id
     * @param bool $force
     * @param array<string>|null $only_fields
     * @return bool true se almeno un campo è stato scritto
     */
    function discorg_ai_generate_venue_content($post_id, $force = false, ?array $only_fields = null, ?string $custom_prompt = null) {
        $pt = defined('DISCORG_POST_TYPE') ? DISCORG_POST_TYPE : 'discoteche';
        if (get_post_type($post_id) !== $pt) return false;

        if (!function_exists('discorg_openai_is_connected')) {
            // Carica helpers se non presenti
            @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
        }
        if (!discorg_openai_is_connected()) {
            error_log('discorg_ai: key mancante, skip generation for post ' . $post_id);
            return false;
        }

        // Campi target
        $fields = array(
            'venue_desc_intro',
            'venue_desc_story',
            'venue_howto_train',
            'venue_howto_car',
            'venue_policies',
            'venue_faq_markdown',
        );
        if (is_array($only_fields) && $only_fields) {
            $fields = array_values(array_intersect($fields, $only_fields));
        }

        // Lettura esistenti
        $existing = array();
        foreach ($fields as $f) {
            $existing[$f] = function_exists('get_field') ? get_field($f, $post_id) : get_post_meta($post_id, $f, true);
        }
        $need = array_filter($fields, function($f) use ($existing, $force) {
            return $force || empty(trim((string)$existing[$f]));
        });
        if (!$need) {
            error_log('discorg_ai: tutti i campi già compilati per post ' . $post_id);
            return true;
        }

        // Contesto
        $title  = get_the_title($post_id);
        $city   = (string) get_post_meta($post_id, 'city', true);
        $region = (string) get_post_meta($post_id, 'region', true);
        $addr   = (string) get_post_meta($post_id, 'street', true);
        $site   = (string) get_post_meta($post_id, 'website', true);
        $rating = (string) get_post_meta($post_id, 'rating', true);

        $sys = "Sei un assistente che scrive testi in italiano per pagine di discoteche. ";
        $sys .= "Non inventare dati sensibili (prezzi/orari/linee specifiche). Produci testi generici, utili e coerenti. ";
        $sys .= "Rispondi in JSON con le seguenti chiavi SOLO per i campi richiesti: ";
        $sys .= "venue_desc_intro, venue_desc_story, venue_howto_train, venue_howto_car, venue_policies, venue_faq_markdown. ";
        $sys .= "Intro 80–120 parole; FAQ in Markdown; toni informativi, non promozionali spinti.";

        $usr = array(
            'title'  => $title,
            'city'   => $city,
            'region' => $region,
            'address'=> $addr,
            'website'=> $site,
            'rating' => $rating,
            'generate_only' => array_values($need),
        );

        // Se presente, aggiungi prompt personalizzato al contesto utente
        if ($custom_prompt) {
            $usr['custom_prompt'] = $custom_prompt;
        }
        $messages = array(
            array('role' => 'system', 'content' => $sys),
            array('role' => 'user', 'content'  => wp_json_encode($usr)),
        );

        if (!function_exists('discorg_openai_chat_complete')) {
            @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
        }
        $resp = discorg_openai_chat_complete($messages, null, 0.2);
        if (is_wp_error($resp)) {
            error_log('discorg_ai: errore OpenAI ' . $resp->get_error_message());
            return false;
        }

        // Guardia parsing risposta
        if (!is_array($resp) || empty($resp['choices'][0]['message']['content'])) {
            error_log('discorg_ai: risposta inattesa da OpenAI');
            return false;
        }
        $content = $resp['choices'][0]['message']['content'];
        $data = json_decode((string)$content, true);
        if (!is_array($data)) $data = array();

        // Scrittura solo campi richiesti
        $written = 0;
        $generated = array();
        foreach ($need as $f) {
            if (!empty($data[$f])) {
                $ok = discorg_update_acf($post_id, $f, (string) $data[$f]);
                if ($ok) {
                    $written++;
                    $generated[$f] = (string) $data[$f];
                }
            }
        }
        error_log('discorg_ai: scritti ' . $written . ' campi per post ' . $post_id);
        return $written > 0 ? $generated : false;
    }
}

// Handler post-import (default: solo campi vuoti)
if (!function_exists('discorg_ai_generate_venue_content_on_import')) {
    function discorg_ai_generate_venue_content_on_import($post_id, $place = null, $city = '', $region = '') {
        discorg_ai_generate_venue_content($post_id, false, null);
    }
    add_action('discorg_import_venue_completed', 'discorg_ai_generate_venue_content_on_import', 10, 4);
}

// AJAX per metabox (all / single field)
add_action('wp_ajax_discorg_ai_generate_venue', function() {
    $post_id = (int) ($_POST['post_id'] ?? 0);
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Permessi insufficienti'));
    }
    check_admin_referer('discorg_ai_metabox', 'discorg_ai_nonce');
    $force   = !empty($_POST['force']);
    $field   = sanitize_text_field($_POST['field'] ?? '');
    $only    = $field && $field !== 'all' ? array($field) : null;
    // Prompt personalizzato opzionale
    $prompt  = '';
    if (isset($_POST['prompt'])) {
        $prompt = wp_strip_all_tags(wp_unslash((string) $_POST['prompt']));
    }

    $result = discorg_ai_generate_venue_content($post_id, $force, $only, $prompt ?: null);
    if (is_array($result) && !empty($result)) {
        wp_send_json_success(array('message' => 'Contenuti aggiornati', 'fields' => $result));
    } elseif ($result === true) {
        wp_send_json_success(array('message' => 'Contenuti aggiornati', 'fields' => array()));
    } else {
        wp_send_json_error(array('message' => 'Nessun aggiornamento'));
    }
});
