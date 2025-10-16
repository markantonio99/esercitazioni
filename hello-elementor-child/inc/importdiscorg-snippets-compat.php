<?php
/**
 * Discoteche.org - File di compatibilità
 *
 * Questo file garantisce la retrocompatibilità con il vecchio sistema
 * Tutte le funzioni vecchie sono mantenute ma vengono reindirizzate alle nuove implementazioni
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

// Verifica se le nuove classi sono disponibili, altrimenti non fare nulla (il sistema vecchio funzionerà)
if (!class_exists('Discorg_Image_Processor') || !class_exists('Discorg_Background_Removal')) {
    return;
}

// Evita doppio caricamento
if (defined('DISCORG_COMPAT_LOADED')) {
    return;
}
define('DISCORG_COMPAT_LOADED', true);

/**
 * Retrocompatibilità: discorg_auto_remove_bg_uniform
 * 
 * @param string $src_path Percorso del file
 * @param float $fuzz_percent Percentuale di tolleranza (non usata nella nuova implementazione)
 * @param float $corner_ratio Rapporto angoli (non usato nella nuova implementazione)
 * @return string Percorso del file elaborato o vuoto se fallito
 */
if (!function_exists('discorg_auto_remove_bg_uniform')) {
    function discorg_auto_remove_bg_uniform($src_path, $fuzz_percent = 6, $corner_ratio = 0.03) {
        // Verifica che il servizio di Remove.bg sia disponibile
        if (!discorg_background_removal()->is_service_available()) {
            error_log('Discorg: Remove.bg API Key non configurata. Lo scontorno non sarà effettuato.');
            return '';
        }
        
        // Usa la nuova implementazione
        $result = discorg_background_removal()->remove_background($src_path);
        
        if ($result['success'] && !empty($result['file_path'])) {
            return $result['file_path'];
        }
        
        return '';
    }
}

/**
 * Retrocompatibilità: discorg_process_and_attach
 *
 * @param int $post_id ID post
 * @param int $attach_id ID allegato
 * @param string $type Tipo (logo/cover)
 * @param bool $set_featured Imposta come featured
 * @param bool $write_meta Scrivi metadati
 * @param bool $want_auto Rimuovi sfondo
 * @return array Risultato
 */
if (!function_exists('discorg_process_and_attach')) {
    function discorg_process_and_attach($post_id, $attach_id, $type, $set_featured = false, $write_meta = true, $want_auto = false) {
        return discorg_image_processor()->process_and_attach($post_id, $attach_id, $type, $set_featured, $write_meta, $want_auto);
    }
}

/**
 * Retrocompatibilità: discorg_attach_from_url
 *
 * @param int $post_id ID post
 * @param string $img_url URL immagine
 * @param string $suffix Suffisso
 * @param string $alt Alt text
 * @return int ID allegato
 */
if (!function_exists('discorg_attach_from_url')) {
    function discorg_attach_from_url($post_id, $img_url, $suffix, $alt) {
        return discorg_image_processor()->attach_from_url($post_id, $img_url, $suffix, $alt);
    }
}

/**
 * Retrocompatibilità: discorg_handle_image_upload
 *
 * @param array $request Dati richiesta
 * @return string HTML risposta
 */
if (!function_exists('discorg_handle_image_upload')) {
    function discorg_handle_image_upload($request = null) {
        if ($request === null) {
            $request = $_POST;
        }
        return discorg_image_processor()->handle_image_upload($request);
    }
}

/**
 * Retrocompatibilità: discorg_backfill_logo_url_if_missing
 *
 * @param int $post_id ID post
 * @return bool Risultato
 */
if (!function_exists('discorg_backfill_logo_url_if_missing')) {
    function discorg_backfill_logo_url_if_missing($post_id) {
        return discorg_image_backfill()->backfill_logo_if_missing($post_id);
    }
}

/**
 * Retrocompatibilità: discorg_fetch_og_image
 *
 * @param string $url URL del sito
 * @return string URL dell'immagine OG
 */
if (!function_exists('discorg_fetch_og_image')) {
    function discorg_fetch_og_image($url) {
        return discorg_image_backfill()->fetch_og_image($url);
    }
}

/**
 * Retrocompatibilità: discorg_fb_page_picture
 *
 * @param string $page_url URL pagina Facebook
 * @return string URL immagine
 */
if (!function_exists('discorg_fb_page_picture')) {
    function discorg_fb_page_picture($page_url) {
        return discorg_image_backfill()->fetch_fb_page_picture($page_url);
    }
}

/**
 * Retrocompatibilità: discorg_google_places_photo
 *
 * @param string $query Query di ricerca
 * @return array [URL, attribution]
 */
if (!function_exists('discorg_google_places_photo')) {
    function discorg_google_places_photo($query) {
        return discorg_image_backfill()->fetch_google_places_photo($query);
    }
}

/**
 * Retrocompatibilità: discorg_populate_acf_and_images
 *
 * @param int $post_id ID post
 * @return bool Risultato
 */
if (!function_exists('discorg_populate_acf_and_images')) {
    function discorg_populate_acf_and_images($post_id) {
        return discorg_image_backfill()->populate_acf_and_images($post_id);
    }
}

/**
 * Retrocompatibilità: discorg_get_incomplete_discos
 *
 * @param int $limit Limite risultati
 * @return array Discoteche incomplete
 */
if (!function_exists('discorg_get_incomplete_discos')) {
    function discorg_get_incomplete_discos($limit = 500) {
        if (function_exists('discorg_get_incomplete_discos')) {
            return discorg_get_incomplete_discos($limit);
        }
        
        // Implementazione di fallback
        $q = new WP_Query([
            'post_type'      => DISCORG_POST_TYPE,
            'posts_per_page' => $limit,
            'post_status'    => ['publish','pending','draft','future','private'],
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        
        $rows = [];
        foreach ($q->posts as $pid) {
            $desk_id = function_exists('get_field') ? get_field('immagine_desktop', $pid) : (int)get_post_meta($pid, 'immagine_desktop', true);
            $mob_id  = function_exists('get_field') ? get_field('immagine_mobile',  $pid) : (int)get_post_meta($pid, 'immagine_mobile', true);
            $logo_id = function_exists('get_field') ? get_field('venue_logo',      $pid) : (int)get_post_meta($pid, 'venue_logo', true);
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
}

/**
 * Retrocompatibilità: discorg_handle_csv_upload
 *
 * @param string $tmp_path Percorso file temporaneo
 * @return array Risultato importazione
 */
if (!function_exists('discorg_handle_csv_upload')) {
    function discorg_handle_csv_upload($tmp_path) {
        $importer = new Discorg_CSV_Importer();
        return $importer->handle_csv_upload($tmp_path);
    }
}

/**
 * Retrocompatibilità: discorg_image_uploader_page
 */
if (!function_exists('discorg_image_uploader_page')) {
    function discorg_image_uploader_page() {
        require_once get_stylesheet_directory() . '/inc/modules/admin/image-uploader-page.php';
        discorg_image_uploader_page();
    }
}

/**
 * Retrocompatibilità: discorg_import_page_cb
 */
if (!function_exists('discorg_import_page_cb')) {
    function discorg_import_page_cb() {
        require_once get_stylesheet_directory() . '/inc/modules/admin/import-page.php';
        discorg_import_page();
    }
}

// Registra le pagine di amministrazione se necessario
if (!has_action('admin_menu', 'discorg_admin_pages')) {
    add_action('admin_menu', function() {
        // Pagina di importazione
        if (!function_exists('discorg_import_page')) {
            add_management_page(
                'Import Discoteche',
                'Import Discoteche',
                'manage_options',
                'discorg-import',
                'discorg_import_page_cb'
            );
        }
        
        // Pagina caricamento immagini
        if (!function_exists('discorg_image_uploader_page')) {
            add_management_page(
                'Carica immagine discoteca',
                'Carica immagine discoteca',
                'upload_files',
                'discorg-image-uploader',
                'discorg_image_uploader_page'
            );
        }
    });
}
