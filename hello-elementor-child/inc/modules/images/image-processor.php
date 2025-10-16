<?php
/**
 * Discoteche.org - Processore immagini
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per processare le immagini
 */
class Discorg_Image_Processor {
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Ottiene o crea l'istanza singleton
     *
     * @return Discorg_Image_Processor
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Processa un'immagine e la collega a un post
     *
     * @param int $post_id ID del post
     * @param int $attach_id ID dell'allegato temporaneo
     * @param string $type Tipo di immagine (logo/cover)
     * @param bool $set_featured Imposta come immagine in evidenza
     * @param bool $write_meta Scrivi metadata per l'immagine
     * @param bool $want_auto Rimuovi sfondo (solo per loghi)
     * @return array Risultato dell'operazione
     */
    public function process_and_attach($post_id, $tmp_attach_id, $type, $set_featured = false, $write_meta = true, $want_auto = false) {
        $file = get_attached_file($tmp_attach_id);
        if (!$file || !file_exists($file)) {
            return ['ok' => false, 'error' => 'File non trovato'];
        }
        
        $title  = get_the_title($post_id);
        $city   = get_post_meta($post_id, 'city', true);
        $region = get_post_meta($post_id, 'region', true);
        $slug   = sanitize_title($title);
        
        // Token versione condiviso (anti-cache)
        $version = gmdate('YmdHis');
        
        $alt     = trim($title . ($city ? ' — ' . $city : '') . ($region ? ', ' . $region : '') . ' | ' . DISCORG_BRAND);
        $caption = ($type === 'logo') ? ($title . ' — Logo ufficiale') : ($title . ' — Cover ufficiale');
        $desc    = ($type === 'logo') ? ('Logo della discoteca ' . $title . ($city ? ' a ' . $city : '')) 
                                     : ('Immagine di copertina della discoteca ' . $title . ($city ? ' a ' . $city : ''));
        
        // Sorgente per l'editor (eventualmente scontornata)
        $src_for_edit = $file;
        $tmp_auto = '';
        
        if ($want_auto && $type === 'logo') {
            // Usa Remove.bg per rimuovere lo sfondo
            $bg_remover = discorg_background_removal();
            $result = $bg_remover->remove_background($file);
            
            if ($result['success'] && !empty($result['file_path'])) {
                $src_for_edit = $result['file_path'];
                $tmp_auto = $result['file_path'];
            } else {
                // Log dell'errore ma continua con l'immagine originale
                error_log('Remove.bg fallback: ' . $result['error']);
            }
        }
        
        // Helper: crea variante, salva e ritorna [attachment_id, path]
        $make_variant = function($max_w, $suffix) use ($src_for_edit, $post_id, $slug, $alt, $caption, $desc, $write_meta, $version) {
            $ed = wp_get_image_editor($src_for_edit);
            if (is_wp_error($ed)) return [0, ''];
            
            // Strip EXIF se disponibile
            if (class_exists('Imagick')) {
                try {
                    $im = new Imagick($src_for_edit);
                    @$im->stripImage();
                    $im->writeImage($src_for_edit);
                    $im->clear();
                    $im->destroy();
                } catch (Exception $e) {
                    error_log('Imagick error: ' . $e->getMessage());
                }
            }
            
            $ed->resize($max_w, $max_w, false);
            
            $uploads = wp_upload_dir();
            $is_logo = (strpos($suffix, 'logo') !== false);
            
            // Nome file versionato
            $base = 'discoteca-' . $slug . '-' . $suffix . '-v' . $version;
            
            if ($is_logo) {
                // LOGO → PNG con alpha
                $dest = trailingslashit($uploads['path']) . $base . '.png';
                if (method_exists($ed, 'set_mime_type')) $ed->set_mime_type('image/png');
                $saved = $ed->save($dest, 'image/png');
                if (is_wp_error($saved)) return [0, ''];
            } else {
                // COVER → WebP (fallback JPG)
                $dest = trailingslashit($uploads['path']) . $base . '.webp';
                if (method_exists($ed, 'set_mime_type')) $ed->set_mime_type('image/webp');
                $saved = $ed->save($dest, 'image/webp');
                if (is_wp_error($saved)) {
                    $ed = wp_get_image_editor($src_for_edit);
                    if (is_wp_error($ed)) return [0, ''];
                    $ed->resize($max_w, $max_w, false);
                    $dest = trailingslashit($uploads['path']) . $base . '.jpg';
                    $saved = $ed->save($dest, 'image/jpeg');
                    if (is_wp_error($saved)) return [0, ''];
                }
            }
            
            $ft  = wp_check_filetype($dest);
            $att = [
                'post_mime_type' => $ft['type'],
                'post_title'     => wp_strip_all_tags(get_the_title($post_id) . ' – ' . $suffix),
                'post_content'   => $write_meta ? $desc    : '',
                'post_excerpt'   => $write_meta ? $caption : '',
                'post_status'    => 'inherit',
            ];
            $new_id = wp_insert_attachment($att, $dest, $post_id);
            if (is_wp_error($new_id)) return [0, ''];
            
            $meta = wp_generate_attachment_metadata($new_id, $dest);
            wp_update_attachment_metadata($new_id, $meta);
            update_post_meta($new_id, '_wp_attachment_image_alt', $alt);
            
            return [$new_id, $dest];
        };
        
        $result = ['ok' => false, 'error' => 'Nessuna elaborazione completata'];
        
        if ($type === 'logo') {
            // opz: elimina il precedente logo (gestisce sia return format Array che ID)
            if (function_exists('get_field')) {
                $old = get_field('venue_logo', $post_id);
                $old_id = 0;
                if (is_array($old) && !empty($old['ID'])) {
                    $old_id = (int) $old['ID'];
                } elseif (is_numeric($old)) {
                    $old_id = (int) $old;
                }
                if ($old_id) wp_delete_attachment($old_id, true);
            }
            list($logo_id,) = $make_variant(800, 'logo');
            if ($logo_id) {
                discorg_update_acf($post_id, 'venue_logo', $logo_id);
                $result = ['ok' => true, 'logo_id' => $logo_id, 'tmp' => $tmp_auto];
            }
        } else {
            // opz: elimina precedenti cover (gestisce sia return format Array che ID)
            if (function_exists('get_field')) {
                $old_d = get_field('immagine_desktop', $post_id);
                $old_m = get_field('immagine_mobile',  $post_id);
                $old_d_id = 0;
                $old_m_id = 0;
                if (is_array($old_d) && !empty($old_d['ID'])) {
                    $old_d_id = (int) $old_d['ID'];
                } elseif (is_numeric($old_d)) {
                    $old_d_id = (int) $old_d;
                }
                if (is_array($old_m) && !empty($old_m['ID'])) {
                    $old_m_id = (int) $old_m['ID'];
                } elseif (is_numeric($old_m)) {
                    $old_m_id = (int) $old_m;
                }
                if ($old_d_id) wp_delete_attachment($old_d_id, true);
                if ($old_m_id) wp_delete_attachment($old_m_id, true);
            }
            list($desk_id,) = $make_variant(1600, 'cover-desktop');
            list($mob_id,)  = $make_variant(800, 'cover-mobile');
            
            if ($desk_id || $mob_id) {
                if ($desk_id) discorg_update_acf($post_id, 'immagine_desktop', $desk_id);
                if ($mob_id)  discorg_update_acf($post_id, 'immagine_mobile',  $mob_id);
                if ($desk_id && $set_featured) set_post_thumbnail($post_id, $desk_id);
                $result = [
                    'ok' => true,
                    'desktop_id' => $desk_id,
                    'mobile_id' => $mob_id
                ];
            }
        }
        
        // Elimina file temporaneo se esiste
        if (!empty($tmp_auto) && file_exists($tmp_auto) && $tmp_auto !== $file) {
            @unlink($tmp_auto);
        }
        
        return $result;
    }
    
    /**
     * Scarica un'immagine da URL e la collega a un post
     *
     * @param int $post_id ID del post
     * @param string $img_url URL dell'immagine
     * @param string $suffix Suffisso per il nome del file
     * @param string $alt Testo alternativo
     * @return int|bool ID dell'allegato o false
     */
    public function attach_from_url($post_id, $img_url, $suffix, $alt) {
        if (empty($img_url)) return 0;
        
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $tmp_id = media_sideload_image($img_url, $post_id, null, 'id');
        if (is_wp_error($tmp_id)) return 0;
        
        $src = get_attached_file($tmp_id);
        $ed  = wp_get_image_editor($src);
        if (is_wp_error($ed)) return $tmp_id;
        
        if (class_exists('Imagick')) {
            try {
                $im = new Imagick($src);
                @$im->stripImage();
                $im->writeImage($src);
                $im->destroy();
            } catch(Exception $e) {
                error_log('Imagick error: ' . $e->getMessage());
            }
        }
        
        $ed->resize(1600, 1600, false);
        
        $uploads = wp_upload_dir();
        $slug  = sanitize_title(get_the_title($post_id));
        $base  = 'discoteca-' . $slug . '-' . $suffix;
        $dest  = trailingslashit($uploads['path']) . $base . '.webp';
        
        if (method_exists($ed, 'set_mime_type')) $ed->set_mime_type('image/webp');
        $res = $ed->save($dest, 'image/webp');
        if (is_wp_error($res)) {
            $ed = wp_get_image_editor($src);
            if (!is_wp_error($ed)) {
                $ed->resize(1600, 1600, false);
                $dest = trailingslashit($uploads['path']) . $base . '.jpg';
                $res = $ed->save($dest, 'image/jpeg');
            }
        }
        
        if (is_wp_error($res)) {
            wp_delete_attachment($tmp_id, true);
            return 0;
        }
        
        $type = wp_check_filetype($dest);
        $att = [
            'post_mime_type' => $type['type'],
            'post_title'     => get_the_title($post_id) . ' – ' . $suffix,
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $new_id = wp_insert_attachment($att, $dest, $post_id);
        if (is_wp_error($new_id)) {
            wp_delete_attachment($tmp_id, true);
            return 0;
        }
        
        $meta = wp_generate_attachment_metadata($new_id, $dest);
        wp_update_attachment_metadata($new_id, $meta);
        update_post_meta($new_id, '_wp_attachment_image_alt', $alt);
        
        wp_delete_attachment($tmp_id, true);
        return $new_id;
    }
    
    /**
     * Sideload robusto da URL: assegna un'estensione coerente al Content-Type e crea un allegato temporaneo.
     *
     * @param int $post_id
     * @param string $url
     * @param string $preferred_basename Es. 'discoteca-milano-logo-src'
     * @return int|WP_Error ID allegato o WP_Error
     */
    public function sideload_url_as_attachment($post_id, $url, $preferred_basename = 'discorg-image') {
        if (empty($url)) {
            return new WP_Error('empty_url', 'URL vuoto');
        }

        // Determina estensione dal Content-Type
        $ext = '.jpg';
        $head = wp_remote_head($url, [
            'timeout'     => 20,
            'redirection' => 5,
            'sslverify'   => true,
        ]);
        if (!is_wp_error($head)) {
            $ctype = wp_remote_retrieve_header($head, 'content-type');
            if (is_string($ctype)) {
                $ct = strtolower($ctype);
                if (strpos($ct, 'image/png') === 0) {
                    $ext = '.png';
                } elseif (strpos($ct, 'image/webp') === 0) {
                    $ext = '.webp';
                } elseif (strpos($ct, 'image/jpeg') === 0 || strpos($ct, 'image/jpg') === 0) {
                    $ext = '.jpg';
                }
            }
        }

        // Scarica su file temporaneo
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        // Costruisci array per media_handle_sideload con nome file "sensato"
        $filename = sanitize_file_name($preferred_basename . $ext);
        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($tmp);
        }

        return $id;
    }

    /**
     * Elabora la richiesta di caricamento di un'immagine
     *
     * @param array $request Parametri della richiesta
     * @return string HTML di risposta
     */
    public function handle_image_upload($request) {
        if (empty($request['post_id']) || empty($_FILES['discorg_file'])) {
            return '<div class="notice notice-error"><p>Seleziona discoteca e file.</p></div>';
        }
        
        $post_id      = (int) $request['post_id'];
        $type         = (isset($request['discorg_type']) && $request['discorg_type'] === 'logo') ? 'logo' : 'cover';
        $set_featured = !empty($request['discorg_set_featured']);
        $write_meta   = !empty($request['discorg_use_title_caption']);
        $want_auto    = !empty($request['discorg_auto_bgremove']); // Scontorno logo
        
        if (get_post_type($post_id) !== DISCORG_POST_TYPE) {
            return '<div class="notice notice-error"><p>ID non valido.</p></div>';
        }
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attach_id = media_handle_upload('discorg_file', $post_id);
        if (is_wp_error($attach_id)) {
            return '<div class="notice notice-error"><p>Upload fallito: ' . esc_html($attach_id->get_error_message()) . '</p></div>';
        }
        
        $res = $this->process_and_attach($post_id, $attach_id, $type, $set_featured, $write_meta, $want_auto);
        
        // rimuove il file grezzo caricato (usiamo le varianti generate)
        wp_delete_attachment($attach_id, true);
        
        if (empty($res['ok'])) {
            return '<div class="notice notice-error"><p>Elaborazione immagine non riuscita: ' . esc_html($res['error'] ?? 'Errore sconosciuto') . '</p></div>';
        }
        
        $html = '<div class="notice notice-success"><p><strong>Fatto!</strong> ';
        $html .= ($type === 'cover')
            ? 'Aggiornati ACF <code>immagine_desktop</code> e <code>immagine_mobile</code>' . ($set_featured ? ' + Featured impostata' : '') . '.'
            : 'Aggiornato ACF <code>venue_logo</code>' . ($want_auto ? ' (scontorno automatico attivo)' : '') . '.';
        $html .= '</p></div>';
        
        return $html;
    }
}

/**
 * Helper function per accedere all'istanza
 *
 * @return Discorg_Image_Processor
 */
function discorg_image_processor() {
    return Discorg_Image_Processor::get_instance();
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param int $post_id ID del post
 * @param int $attach_id ID dell'allegato
 * @param string $type Tipo di immagine
 * @param bool $set_featured Imposta come immagine in evidenza
 * @param bool $write_meta Scrivi metadata
 * @param bool $want_auto Rimuovi sfondo
 * @return array Risultato
 */
function discorg_process_and_attach($post_id, $attach_id, $type, $set_featured = false, $write_meta = true, $want_auto = false) {
    return discorg_image_processor()->process_and_attach($post_id, $attach_id, $type, $set_featured, $write_meta, $want_auto);
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param int $post_id ID del post
 * @param string $img_url URL dell'immagine
 * @param string $suffix Suffisso
 * @param string $alt Testo alternativo
 * @return int|bool ID dell'allegato o false
 */
function discorg_attach_from_url($post_id, $img_url, $suffix, $alt) {
    return discorg_image_processor()->attach_from_url($post_id, $img_url, $suffix, $alt);
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param array $request Parametri della richiesta
 * @return string HTML di risposta
 */
function discorg_handle_image_upload($request = null) {
    if ($request === null) {
        $request = $_POST;
    }
    return discorg_image_processor()->handle_image_upload($request);
}
