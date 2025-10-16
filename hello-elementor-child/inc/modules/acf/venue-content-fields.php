<?php
/**
 * ACF Local Fields: Discoteca – Contenuti AI
 * Crea i 6 campi Textarea solo se mancanti (idempotente).
 */
if (!defined('ABSPATH')) exit;

add_action('acf/init', function() {
    if (!function_exists('acf_add_local_field_group')) return;

    $group_key = 'group_discorg_venue_content_ai';
    // Idempotenza: se il gruppo con questa key esiste già, non ricrearlo
    if (function_exists('acf_get_field_group') && acf_get_field_group($group_key)) {
        return;
    }

    $post_type = defined('DISCORG_POST_TYPE') ? DISCORG_POST_TYPE : 'discoteche';

    acf_add_local_field_group(array(
        'key' => $group_key,
        'title' => 'Discoteca – Contenuti AI',
        'fields' => array(
            array(
                'key' => 'field_discorg_venue_desc_intro',
                'label' => 'Introduzione (80–120 parole)',
                'name' => 'venue_desc_intro',
                'type' => 'textarea',
                'rows' => 5,
                'new_lines' => 'br',
            ),
            array(
                'key' => 'field_discorg_venue_desc_story',
                'label' => 'Storia / Descrizione lunga',
                'name' => 'venue_desc_story',
                'type' => 'textarea',
                'rows' => 8,
                'new_lines' => 'br',
            ),
            array(
                'key' => 'field_discorg_venue_howto_train',
                'label' => 'Come arrivare (Treno/Bus)',
                'name' => 'venue_howto_train',
                'type' => 'textarea',
                'rows' => 5,
                'new_lines' => 'br',
            ),
            array(
                'key' => 'field_discorg_venue_howto_car',
                'label' => 'Come arrivare (Auto/Parcheggio)',
                'name' => 'venue_howto_car',
                'type' => 'textarea',
                'rows' => 5,
                'new_lines' => 'br',
            ),
            array(
                'key' => 'field_discorg_venue_policies',
                'label' => 'Servizi & Policy',
                'name' => 'venue_policies',
                'type' => 'textarea',
                'rows' => 6,
                'new_lines' => 'br',
            ),
            array(
                'key' => 'field_discorg_venue_faq_markdown',
                'label' => 'FAQ (Markdown)',
                'name' => 'venue_faq_markdown',
                'type' => 'textarea',
                'rows' => 8,
                'new_lines' => 'br',
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => $post_type,
                ),
            ),
        ),
        'position' => 'normal',
        'style' => 'default',
        'active' => true,
        'show_in_rest' => 0,
    ));
});
