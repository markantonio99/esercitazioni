<?php
/**
 * Discoteche.org - Pagina caricamento immagini
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Visualizza la pagina di caricamento immagini
 */
function discorg_image_uploader_page() {
    if (!current_user_can('upload_files')) {
        return;
    }
    
    // Ottiene la lista di discoteche senza immagini complete
    $incomplete_discos = discorg_get_incomplete_discos();
    
    // Visualizza eventuali risultati di un upload precedente
    if (!empty($_POST['discorg_img_nonce']) && wp_verify_nonce($_POST['discorg_img_nonce'], 'discorg_img_upload')) {
        echo discorg_handle_image_upload();
    }
    
    ?>
    <div class="wrap">
        <h1>Carica immagine discoteca</h1>
        <p>Seleziona la discoteca, carica l'immagine e scegli l'uso. Verranno creati i formati ottimizzati e aggiornati i campi ACF.</p>
        
        <form method="post" enctype="multipart/form-data" id="discorg-img-form">
            <?php wp_nonce_field('discorg_img_upload', 'discorg_img_nonce'); ?>
            
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="discorg-search">Discoteca</label></th>
                    <td>
                        <input type="hidden" name="post_id" id="discorg-post-id" required>
                        <input type="text" id="discorg-search" class="regular-text" placeholder="Inizia a digitare il nome…" autocomplete="off">
                        <p class="description">Cerca per titolo nel CPT <code>discoteche</code>.</p>
                        <ul id="discorg-suggest" style="margin:.5em 0 0;max-height:220px;overflow:auto;border:1px solid #ddd;display:none;background:#fff;"></ul>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="discorg-file">Immagine</label></th>
                    <td>
                        <input type="file" name="discorg_file" id="discorg-file" accept="image/*" required>
                        <p class="description">JPG/PNG/WebP consigliati.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Tipo utilizzo</th>
                    <td>
                        <fieldset>
                            <label><input type="radio" name="discorg_type" value="cover" checked> Cover (aggiorna ACF <code>immagine_desktop</code> + <code>immagine_mobile</code>)</label><br>
                            <label><input type="radio" name="discorg_type" value="logo"> Logo (aggiorna ACF <code>venue_logo</code>)</label>
                        </fieldset>
                    </td>
                </tr>
                
                <tr id="discorg-auto-bg-row" style="display:none">
                    <th scope="row">Logo: scontorno automatico</th>
                    <td>
                        <label>
                            <input type="checkbox" name="discorg_auto_bgremove" value="1">
                            Rimuovi automaticamente lo sfondo (integrazione <a href="https://www.remove.bg/" target="_blank">Remove.bg</a>)
                        </label>
                        <p class="description">
                            <?php if (discorg_background_removal()->is_service_available()): ?>
                                <span style="color: green;">✓</span> Servizio Remove.bg attivo e configurato.
                                Utilizza intelligenza artificiale per risultati professionali.
                            <?php else: ?>
                                <span style="color: red;">✗</span> Servizio Remove.bg non disponibile.
                                <a href="<?php echo admin_url('options-general.php?page=discorg-settings'); ?>">Configura l'API key</a>
                                per attivare lo scontorno automatico.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Opzioni</th>
                    <td>
                        <label><input type="checkbox" name="discorg_set_featured" value="1" checked> Imposta come Immagine in evidenza (se <em>cover</em>)</label><br>
                        <label><input type="checkbox" name="discorg_use_title_caption" value="1" checked> Scrivi Title/Caption/Descrizione media</label>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Carica e ottimizza immagine'); ?>
        </form>
        
        <?php if (!empty($incomplete_discos)): ?>
        <h2 style="margin-top:20px">Discoteche senza immagini complete</h2>
        <p class="description">Restano qui finché non hanno tutte le immagini. Usa le azioni per caricarle al volo.</p>
        
        <table class="widefat striped" style="margin-top:10px">
            <thead>
                <tr>
                    <th>Titolo</th>
                    <th>Località</th>
                    <th style="text-align:center">Desktop</th>
                    <th style="text-align:center">Mobile</th>
                    <th style="text-align:center">Logo</th>
                    <th style="text-align:center">Featured</th>
                    <th>Anteprima</th>
                    <th style="width:220px">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($incomplete_discos as $disco): ?>
                <?php
                    $location = trim(($disco['city'] ?: '') . ($disco['region'] ? ', ' . $disco['region'] : ''));
                    $label = esc_attr($disco['title'] . ($disco['city'] ? ' — ' . $disco['city'] : '') . ($disco['region'] ? ', ' . $disco['region'] : ''));
                    
                    $desktop_status = !$disco['missing']['desktop'] 
                        ? '<span style="color:#3c763d;font-weight:600">✓</span>' 
                        : '<span style="color:#a00;font-weight:600">—</span>';
                    
                    $mobile_status = !$disco['missing']['mobile'] 
                        ? '<span style="color:#3c763d;font-weight:600">✓</span>' 
                        : '<span style="color:#a00;font-weight:600">—</span>';
                    
                    $logo_status = !$disco['missing']['logo'] 
                        ? '<span style="color:#3c763d;font-weight:600">✓</span>' 
                        : '<span style="color:#a00;font-weight:600">—</span>';
                    
                    $featured_status = !$disco['missing']['featured'] 
                        ? '<span style="color:#3c763d;font-weight:600">✓</span>' 
                        : '<span style="color:#a00;font-weight:600">—</span>';
                ?>
                <tr>
                    <td><strong><?php echo esc_html($disco['title']); ?></strong></td>
                    <td><?php echo esc_html($location); ?></td>
                    <td style="text-align:center"><?php echo $desktop_status; ?></td>
                    <td style="text-align:center"><?php echo $mobile_status; ?></td>
                    <td style="text-align:center"><?php echo $logo_status; ?></td>
                    <td style="text-align:center"><?php echo $featured_status; ?></td>
                    <td><?php echo $disco['feat']; ?></td>
                    <td>
                        <a href="#" class="button button-primary discorg-pick" data-post="<?php echo $disco['ID']; ?>" data-type="cover" data-title="<?php echo $label; ?>">Carica cover</a>
                        <a href="#" class="button discorg-pick" style="margin-left:6px" data-post="<?php echo $disco['ID']; ?>" data-type="logo" data-title="<?php echo $label; ?>">Carica logo</a>
                        <a href="<?php echo esc_url($disco['edit']); ?>" class="button-link" style="margin-left:8px">Modifica</a>
                        <a href="<?php echo esc_url($disco['view']); ?>" class="button-link" target="_blank" style="margin-left:6px">Vedi</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="notice notice-success inline" style="margin-top:20px">
            <p>🎉 Tutte le discoteche hanno logo + cover desktop + cover mobile.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Ottiene le discoteche senza immagini complete
 *
 * @param int $limit Limite massimo di risultati
 * @return array Lista di discoteche incomplete
 */
function discorg_get_incomplete_discos($limit = 500) {
    $q = new WP_Query([
        'post_type'      => DISCORG_POST_TYPE,
        'posts_per_page' => $limit,
        'post_status'    => ['publish', 'pending', 'draft', 'future', 'private'],
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    
    $rows = [];
    
    foreach ($q->posts as $pid) {
        $desk_id = function_exists('get_field') 
            ? get_field('immagine_desktop', $pid) 
            : (int)get_post_meta($pid, 'immagine_desktop', true);
        
        $mob_id = function_exists('get_field') 
            ? get_field('immagine_mobile', $pid) 
            : (int)get_post_meta($pid, 'immagine_mobile', true);
        
        $logo_id = function_exists('get_field') 
            ? get_field('venue_logo', $pid) 
            : (int)get_post_meta($pid, 'venue_logo', true);
        
        $feat_id = has_post_thumbnail($pid) ? get_post_thumbnail_id($pid) : 0;
        
        $missing = [
            'desktop'  => empty($desk_id),
            'mobile'   => empty($mob_id),
            'logo'     => empty($logo_id),
            'featured' => empty($feat_id)
        ];
        
        if ($missing['desktop'] || $missing['mobile'] || $missing['logo']) {
            $rows[] = [
                'ID'     => $pid,
                'title'  => get_the_title($pid),
                'city'   => get_post_meta($pid, 'city', true),
                'region' => get_post_meta($pid, 'region', true),
                'missing' => $missing,
                'feat'   => $feat_id ? wp_get_attachment_image($feat_id, [80, 80], false, ['style' => 'border-radius:6px']) : '',
                'edit'   => get_edit_post_link($pid),
                'view'   => get_permalink($pid),
            ];
        }
    }
    
    return $rows;
}
