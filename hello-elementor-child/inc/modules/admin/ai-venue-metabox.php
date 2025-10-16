<?php
/**
 * Discoteche.org - Metabox AI per CPT "discoteche"
 *
 * - Mostra stato connessione OpenAI e modello attivo
 * - Pulsante "Compila TUTTI" e pulsanti per singolo campo
 * - Opzione "Forza sovrascrittura" (altrimenti compila solo i vuoti)
 * - Disabilita azioni se manca la chiave
 */
if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function() {
    $pt = defined('DISCORG_POST_TYPE') ? DISCORG_POST_TYPE : 'discoteche';
    add_meta_box(
        'discorg_ai_venue_box',
        'Contenuti AI Discoteca',
        'discorg_ai_venue_box_render',
        $pt,
        'side',
        'high'
    );
});

/**
 * Render del metabox
 */
function discorg_ai_venue_box_render($post) {
    // Nonce per AJAX
    wp_nonce_field('discorg_ai_metabox', 'discorg_ai_nonce');

    // Assicurati di avere le funzioni helper
    if (!function_exists('discorg_openai_is_connected')) {
        @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
    }

    $connected = function_exists('discorg_openai_is_connected') ? discorg_openai_is_connected() : false;
    $model     = function_exists('discorg_openai_model') ? esc_html(discorg_openai_model()) : '-';

    echo '<p><strong>Stato:</strong> ' . ($connected ? '<span style="color:green">Connessa</span>' : '<span style="color:red">Non connessa</span>') . '</p>';
    echo '<p><strong>Modello:</strong> ' . $model . '</p>';
    echo '<label><input type="checkbox" id="discorg_ai_force"> Forza sovrascrittura</label>';
    echo '<p style="margin-top:8px">';
    $btn = function($label, $field) use ($connected) {
        $dis = $connected ? '' : 'disabled';
        echo '<button type="button" class="button discorg-ai-btn" data-field="' . esc_attr($field) . "\" $dis>$label</button> ";
    };
    // Pulsanti
    $btn('Compila TUTTI', 'all');
    echo '</p><hr><p>';
    $btn('Intro', 'venue_desc_intro');
    $btn('Storia', 'venue_desc_story');
    echo '</p><p>';
    $btn('Treno/Bus', 'venue_howto_train');
    $btn('Auto/Parcheggio', 'venue_howto_car');
    echo '</p><p>';
    $btn('Servizi & Policy', 'venue_policies');
    $btn('FAQ (MD)', 'venue_faq_markdown');
    echo '</p>';

    if (!$connected) {
        echo '<div class="notice notice-error inline"><p>Chiave OpenAI mancante: configura la chiave nelle Impostazioni.</p></div>';
    }
    // Fallback server-side: Compila TUTTI (senza JS)
    ?>
    <form id="discorg_ai_server_all_form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-top:8px;">
        <input type="hidden" name="action" value="discorg_ai_generate_field">
        <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>">
        <input type="hidden" name="field" value="all">
        <input type="hidden" name="force" class="discorg-ai-force-hidden" value="0">
        <?php wp_nonce_field('discorg_ai_metabox','discorg_ai_nonce'); ?>
        <button type="submit" class="button button-primary">Compila TUTTI (server)</button>
    </form>
    <?php
    ?>
    <script>
    (function($){
        $(document).on('click','.discorg-ai-btn',function(){
            var field = $(this).data('field');
            var force = $('#discorg_ai_force').is(':checked') ? 1 : 0;
            var data = {
                action: 'discorg_ai_generate_venue',
                discorg_ai_nonce: $('#discorg_ai_nonce').val(),
                post_id: <?php echo (int) $post->ID; ?>,
                field: field,
                force: force
            };
            var $btn = $(this);
            var original = $btn.text();
            $btn.prop('disabled', true).text('Elaborazione...');
            $.post(ajaxurl, data).always(function(resp){
                $btn.prop('disabled', false).text(original);
                if (resp && resp.success) {
                    alert('OK: ' + (resp.data && resp.data.message ? resp.data.message : 'Aggiornato.'));
                } else {
                    alert('KO: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'Errore.'));
                }
            });
        });
        // Sincronizza checkbox "Forza" con i form server-side nel metabox/inline
        $(document).on('change', '.discorg-ai-force', function(){
            var $wrap = $(this).closest('.discorg-ai-inline');
            $wrap.find('form .discorg-ai-force-hidden').val($(this).is(':checked') ? 1 : 0);
        });
        // Sincronizza submit del form "Compila TUTTI (server)" con lo stato della checkbox globale
        $(document).on('submit', '#discorg_ai_server_all_form', function(){
            var force = $('#discorg_ai_force').is(':checked') ? 1 : 0;
            $(this).find('.discorg-ai-force-hidden').val(force);
        });
    })(jQuery);
    </script>
    <?php
}
