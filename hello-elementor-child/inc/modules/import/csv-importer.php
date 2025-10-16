<?php
/**
 * Discoteche.org - Importatore CSV
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per l'importazione da CSV
 */
class Discorg_CSV_Importer {
    /**
     * Gestisce l'upload del file CSV
     *
     * @param string $tmp_path Percorso temporaneo del file
     * @return array Risultato dell'importazione
     */
    public function handle_csv_upload($tmp_path) {
        // Togli BOM se presente
        $raw = file_get_contents($tmp_path);
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            file_put_contents($tmp_path, substr($raw, 3));
        }
        
        $fh = fopen($tmp_path, 'r');
        if (!$fh) {
            return ['created' => 0, 'updated' => 0, 'errors' => 1];
        }
        
        $headers = fgetcsv($fh, 0, ',');
        if (!$headers) {
            return ['created' => 0, 'updated' => 0, 'errors' => 1];
        }
        
        $headers = array_map(function($h) {
            return trim(mb_strtolower($h));
        }, $headers);
        
        $created = 0;
        $updated = 0;
        $errors = 0;
        
        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            $result = $this->process_row($row, $headers);
            
            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $errors++;
            }
        }
        
        fclose($fh);
        
        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors
        ];
    }
    
    /**
     * Processa una riga del CSV
     *
     * @param array $row Riga del CSV
     * @param array $headers Intestazioni
     * @return string 'created', 'updated' o 'error'
     */
    private function process_row($row, $headers) {
        if (count($row) !== count($headers)) {
            return 'error';
        }
        
        $data = array_combine($headers, $row);
        $get = function($k, $def = '') use ($data) {
            return isset($data[$k]) ? trim($data[$k]) : $def;
        };
        
        $title = $get('title');
        $city = $get('city');
        
        if ($title === '') {
            return 'error';
        }
        
        // Dedup: Titolo + City
        $post = get_page_by_title($title, OBJECT, DISCORG_POST_TYPE);
        if ($post && $city && get_post_meta($post->ID, 'city', true) !== $city) {
            $post = null;
        }
        
        $postarr = [
            'post_type' => DISCORG_POST_TYPE,
            'post_title' => $title,
            'post_status' => 'publish'
        ];
        
        if (($ex = $get('excerpt')) !== '') {
            $postarr['post_excerpt'] = $ex;
        }
        
        if (($co = $get('content')) !== '') {
            $postarr['post_content'] = $co;
        }
        
        $result = 'error';
        
        if ($post) {
            $postarr['ID'] = $post->ID;
            $post_id = wp_update_post($postarr, true);
            $result = 'updated';
        } else {
            $post_id = wp_insert_post($postarr, true);
            $result = 'created';
        }
        
        if (is_wp_error($post_id)) {
            return 'error';
        }
        
        // Salva meta dati
        $meta_fields = [
            'street', 'city', 'region', 'postcode', 'country', 
            'phone', 'website', 'lat', 'lng', 
            'logo_url', 'image_desktop_url', 'image_mobile_url', 'sameas'
        ];
        
        foreach ($meta_fields as $k) {
            $v = $get($k);
            if ($v !== '') {
                update_post_meta($post_id, $k, $v);
            }
        }
        
        // Tassonomia localita: "Regione|Città"
        $this->process_locality_taxonomy($post_id, $get('localita_path'));
        
        // Aggiorna i campi ACF e genera immagini
        discorg_populate_acf_and_images($post_id);
        
        return $result;
    }
    
    /**
     * Processa la tassonomia della località
     *
     * @param int $post_id ID del post
     * @param string $loc_path Percorso della località
     * @return bool True se operazione completata con successo
     */
    private function process_locality_taxonomy($post_id, $loc_path) {
        if (empty($loc_path) || !taxonomy_exists(DISCORG_TAX_LOCALITA)) {
            return false;
        }
        
        $raw = array_map('trim', explode('|', $loc_path));
        $parts = [];
        
        foreach ($raw as $p) {
            // Filtra anomalie numeriche (es. "44")
            if ($p === '' || preg_match('/^\d+$/', $p) || mb_strlen($p) < 3) {
                continue;
            }
            
            $p = preg_replace('/\s+/', ' ', $p);
            $parts[] = $p;
        }
        
        if (empty($parts)) {
            return false;
        }
        
        $parent = 0;
        $term_ids = [];
        
        foreach ($parts as $p) {
            $exists = term_exists($p, DISCORG_TAX_LOCALITA, $parent);
            
            if (!$exists) {
                $exists = wp_insert_term($p, DISCORG_TAX_LOCALITA, ['parent' => $parent]);
            }
            
            if (!is_wp_error($exists)) {
                $parent = $exists['term_id'];
                $term_ids[] = $exists['term_id'];
            }
        }
        
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, DISCORG_TAX_LOCALITA);
            return true;
        }
        
        return false;
    }
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param string $tmp_path Percorso del file temporaneo
 * @return array Risultato dell'importazione
 */
function discorg_handle_csv_upload($tmp_path) {
    $importer = new Discorg_CSV_Importer();
    return $importer->handle_csv_upload($tmp_path);
}
