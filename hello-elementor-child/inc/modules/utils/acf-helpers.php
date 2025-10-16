<?php
/**
 * Discoteche.org - ACF Helpers
 *
 * Helper per aggiornare i campi ACF in modo robusto.
 * Tenta con update_field usando sia il nome campo che la field key,
 * ed esegue fallback su update_post_meta se ACF non è disponibile.
 */

if (!defined('ABSPATH')) exit;

/**
 * Aggiorna un campo ACF in modo sicuro/robusto.
 *
 * @param int    $post_id    ID del post
 * @param string $field_name Nome del campo ACF (es. 'venue_logo')
 * @param mixed  $value      Valore da salvare
 * @return bool              True se salvato, false altrimenti
 */
function discorg_update_acf($post_id, $field_name, $value) {
    // Se ACF non è disponibile, fallback su meta standard
    if (!function_exists('update_field')) {
        return (bool) update_post_meta($post_id, $field_name, $value);
    }

    // 1) Prova direttamente con il nome campo
    $ok = update_field($field_name, $value, $post_id);
    if ($ok) return true;

    // 2) Prova a recuperare la field key e riprovare
    $field_obj = null;

    // ACF 5/6: acf_get_field_object è più affidabile per contesto post
    if (function_exists('acf_get_field_object')) {
        $field_obj = acf_get_field_object($field_name, $post_id);
    }

    // Fallback: API pubblica get_field_object
    if (!$field_obj && function_exists('get_field_object')) {
        $field_obj = get_field_object($field_name, $post_id, false, false);
    }

    // ACF low-level
    if (!$field_obj && function_exists('acf_get_field')) {
        $field_obj = acf_get_field($field_name);
    }

    // Ultimo tentativo: scandisci tutti i field object del post e trova quello con lo stesso "name"
    if (!$field_obj && function_exists('get_field_objects')) {
        $all_fields = get_field_objects($post_id);
        if (is_array($all_fields)) {
            foreach ($all_fields as $fo) {
                if (!empty($fo['name']) && $fo['name'] === $field_name) {
                    $field_obj = $fo;
                    break;
                }
            }
        }
    }

    if (is_array($field_obj) && !empty($field_obj['key'])) {
        $key = $field_obj['key'];
        $ok2 = update_field($key, $value, $post_id);
        if ($ok2) return true;

        // Se update_field fallisce, prova meta grezzo + _field_key
        $meta_ok = update_post_meta($post_id, $field_name, $value);
        // Salva anche il meta che contiene la field key di ACF (necessario per mostrare in admin)
        update_post_meta($post_id, '_' . $field_name, $key);
        return (bool) $meta_ok;
    }

    // 3) Fallback finale: meta grezzo senza conoscere la field key
    // Questo può non far apparire il valore in admin per campi complessi (es. repeater),
    // ma per campi semplici (immagine, testo, numero) è sufficiente.
    return (bool) update_post_meta($post_id, $field_name, $value);
}

/**
 * Imposta un repeater ACF di sole URL, azzerando le righe precedenti
 * e aggiungendo una riga per ciascun URL passato.
 *
 * Usa add_row per affidare il mapping name→key ad ACF.
 *
 * @param int $post_id
 * @param string $repeater_field_name Es. 'venue_sameas'
 * @param string $url_subfield_name   Es. 'url'
 * @param string[] $urls
 * @return bool
 */
function discorg_set_repeater_urls($post_id, $repeater_field_name, $url_subfield_name, $urls) {
    if (empty($urls) || !is_array($urls)) {
        // Svuota comunque se array vuoto, per coerenza UI
        if (function_exists('update_field')) {
            update_field($repeater_field_name, [], $post_id);
        } else {
            delete_post_meta($post_id, $repeater_field_name);
        }
        return true;
    }

    if (!function_exists('add_row')) {
        // Fallback rudimentale: salva come meta semplice (non comparirà bene in admin)
        update_post_meta($post_id, $repeater_field_name, $urls);
        return true;
    }

    // Svuota repeater
    if (function_exists('update_field')) {
        update_field($repeater_field_name, [], $post_id);
    } else {
        delete_post_meta($post_id, $repeater_field_name);
    }

    // Aggiungi una riga per URL
    foreach ($urls as $u) {
        $u = esc_url_raw($u);
        if (!$u) continue;
        add_row($repeater_field_name, [ $url_subfield_name => $u ], $post_id);
    }

    return true;
}
