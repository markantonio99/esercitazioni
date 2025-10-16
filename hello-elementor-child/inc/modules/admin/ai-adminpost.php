<?php
/**
 * Discoteche.org — Admin-post fallback per AI inline (senza JS)
 * - Azione: discorg_ai_generate_field
 * - Riuso funzione: discorg_ai_generate_venue_content($post_id, $force, ?array $only_fields, ?string $custom_prompt)
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('discorg_ai_adminpost_generate_field')) {
    function discorg_ai_adminpost_generate_field() {
        // Verifica nonce
        if (!isset($_POST['discorg_ai_nonce']) || !wp_verify_nonce($_POST['discorg_ai_nonce'], 'discorg_ai_metabox')) {
            wp_die('Nonce non valida');
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $field   = sanitize_text_field($_POST['field'] ?? 'all');
        $force   = ((int) ($_POST['force'] ?? 0)) === 1;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('Permessi insufficienti');
        }

        // Helpers e generatore
        if (!function_exists('discorg_openai_is_connected')) {
            @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
        }
        if (!function_exists('discorg_ai_generate_venue_content')) {
            @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-venue-generator.php';
        }

        if (!discorg_openai_is_connected()) {
            set_transient('discorg_ai_notice_' . get_current_user_id(), array('ok' => false, 'msg' => 'API non configurata'), 60);
            wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $post_id . '&action=edit'));
            exit;
        }

        $only = ($field === 'all') ? null : array($field);
        $res  = discorg_ai_generate_venue_content($post_id, $force, $only, null);
        $ok   = (bool) $res;
        $msg  = ($field === 'all' ? 'Tutti i campi' : 'Campo ' . $field) . ($ok ? ' aggiornato' : ' non aggiornato');

        set_transient('discorg_ai_notice_' . get_current_user_id(), array('ok' => $ok, 'msg' => $msg), 60);
        wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $post_id . '&action=edit'));
        exit;
    }
}

add_action('admin_post_discorg_ai_generate_field', 'discorg_ai_adminpost_generate_field');

// Admin notice post-redirect
add_action('admin_notices', function () {
    $t = get_transient('discorg_ai_notice_' . get_current_user_id());
    if ($t) {
        echo '<div class="' . ($t['ok'] ? 'updated' : 'error') . '"><p><strong>AI:</strong> ' . esc_html($t['msg']) . '</p></div>';
        delete_transient('discorg_ai_notice_' . get_current_user_id());
    }
});
