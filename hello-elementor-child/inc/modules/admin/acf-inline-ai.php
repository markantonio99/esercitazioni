<?php
/**
 * Discoteche.org — Controlli AI inline per campi ACF (server-side)
 * - Stampa barra pulsanti sotto i 6 campi target via acf/render_field/name=...
 * - Mostra badge stato API/Modello
 * - Idempotente: evita duplicazioni per field key
 */
if (!defined('ABSPATH')) exit;

// Assicurati helpers OpenAI
if (!function_exists('discorg_openai_is_connected')) {
    @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
}

if (!function_exists('discorg_acf_ai_controls')) {
    /**
     * Stampa i controlli AI inline sotto un field ACF target
     *
     * @param array $field Field array fornito da ACF
     * @return void
     */
    function discorg_acf_ai_controls($field) {
        // Evita duplicazioni per lo stesso field (chiave ACF)
        static $printed = array();
        $key = isset($field['key']) ? (string) $field['key'] : (isset($field['name']) ? (string) $field['name'] : '');
        if (!$key || isset($printed[$key])) {
            return;
        }
        $printed[$key] = true;

        $name = isset($field['name']) ? (string) $field['name'] : '';
        if (!$name) return;
        // Solo textarea (i 6 target sono textarea)
        if (!isset($field['type']) || $field['type'] !== 'textarea') return;

        $connected = function_exists('discorg_openai_is_connected') ? discorg_openai_is_connected() : false;
        $model     = function_exists('discorg_openai_model') ? discorg_openai_model() : '';
        $badge     = $connected ? 'Connessa' : 'Non connessa';
        ?>
        <div class="discorg-ai-inline" data-field="<?php echo esc_attr($name); ?>" style="margin-top:6px;">
            <div class="discorg-ai-status" style="margin:2px 0 6px 0; color:#1d3b70;">
                <strong>API:</strong> <?php echo esc_html($badge); ?>
                <?php if ($model): ?> — <strong>Modello:</strong> <?php echo esc_html($model); ?><?php endif; ?>
            </div>
            <div class="discorg-ai-inline-controls">
                <label style="margin-right:6px;">
                    <input type="checkbox" class="discorg-ai-force"> Forza
                </label>
                <button type="button" class="button" data-action="regen">Rigenera</button>
                <button type="button" class="button" data-action="regen-prompt" style="margin-left:6px;">Rigenera con prompt…</button>
            </div>
            <!-- Fallback server-side senza JS -->
            <form class="discorg-ai-inline-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;margin-left:8px;margin-top:6px;">
                <input type="hidden" name="action" value="discorg_ai_generate_field">
                <input type="hidden" name="post_id" value="<?php echo (int) get_the_ID(); ?>">
                <input type="hidden" name="field" value="<?php echo esc_attr($name); ?>">
                <input type="hidden" name="force" class="discorg-ai-force-hidden" value="0">
                <?php wp_nonce_field('discorg_ai_metabox','discorg_ai_nonce'); ?>
                <button type="submit" class="button button-secondary">Rigenera (server)</button>
            </form>
        </div>
        <?php
    }
}

// Registra i 6 hook per nome campo (priorità 20 per stare dopo l'input)
add_action('acf/render_field/name=venue_desc_intro',   'discorg_acf_ai_controls', 20, 1);
add_action('acf/render_field/name=venue_desc_story',   'discorg_acf_ai_controls', 20, 1);
add_action('acf/render_field/name=venue_howto_train',  'discorg_acf_ai_controls', 20, 1);
add_action('acf/render_field/name=venue_howto_car',    'discorg_acf_ai_controls', 20, 1);
add_action('acf/render_field/name=venue_policies',     'discorg_acf_ai_controls', 20, 1);
add_action('acf/render_field/name=venue_faq_markdown', 'discorg_acf_ai_controls', 20, 1);
