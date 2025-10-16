<?php
/**
 * Discoteche.org - Backfill immagini
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per il backfill delle immagini mancanti
 */
class Discorg_Image_Backfill {
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Ottiene o crea l'istanza singleton
     *
     * @return Discorg_Image_Backfill
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Cerca e aggiunge un logo se mancante
     *
     * @param int $post_id ID del post discoteca
     * @return bool True se un logo è stato aggiunto, false altrimenti
     */
    public function backfill_logo_if_missing($post_id) {
        $logo = get_post_meta($post_id, 'logo_url', true);
        if (!empty($logo)) return false; // Logo già presente
        
        // 1. Prova con il logo ufficiale dal sito (JSON-LD/itemprop/img alt)
        $site = get_post_meta($post_id, 'website', true);
        $site_logo = $this->fetch_site_logo($site);
        if ($site_logo) {
            update_post_meta($post_id, 'logo_url', $site_logo);
            return true;
        }

        // 2. Prova con l'immagine OG dal sito web
        $og = $this->fetch_og_image($site);
        if ($og) {
            update_post_meta($post_id, 'logo_url', $og);
            return true;
        }
        
        // 2. Prova con Facebook (dai SameAs)
        $sameas = get_post_meta($post_id, 'sameas', true);
        if ($sameas) {
            foreach (array_filter(array_map('trim', explode('|', $sameas))) as $url) {
                if (stripos($url, 'facebook.com') !== false) {
                    $fb = $this->fetch_fb_page_picture($url);
                    if ($fb) {
                        update_post_meta($post_id, 'logo_url', $fb);
                        return true;
                    }
                }
            }
        }
        
        // 3. Prova con Google Places
        $q = get_the_title($post_id) . ' ' . get_post_meta($post_id, 'city', true) . ' discoteca';
        list($gurl, $attr) = $this->fetch_google_places_photo($q);
        if ($gurl) {
            update_post_meta($post_id, 'logo_url', $gurl);
            if ($attr) {
                update_post_meta($post_id, 'photo_attribution_html', $attr);
            }
            return true;
        }
        
        // 4. Usa l'immagine di default se definita
        if (defined('DISCORG_DEFAULT_IMAGE') && DISCORG_DEFAULT_IMAGE) {
            update_post_meta($post_id, 'logo_url', DISCORG_DEFAULT_IMAGE);
            return true;
        }
        
        return false;
    }
    
    /**
     * Estrae l'immagine OG da un URL
     *
     * @param string $url URL del sito
     * @return string URL dell'immagine OG o vuoto
     */
    public function fetch_og_image($url) {
        if (empty($url)) return '';
        
        $resp = wp_remote_get($url, [
            'timeout' => 10,
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (compatible; DiscothequeOrgBot/1.0; +https://discoteche.org/bot)'
        ]);
        
        if (is_wp_error($resp)) {
            error_log('Errore fetch OG: ' . $resp->get_error_message());
            return '';
        }
        
        $html = wp_remote_retrieve_body($resp);
        if (!$html) return '';
        
        // Cerca meta tag Open Graph
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return esc_url_raw($m[1]);
        }
        
        // Fallback su Twitter Card
        if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            return esc_url_raw($m[1]);
        }
        
        return '';
    }
    
    /**
     * Cerca un logo in una pagina del sito:
     * - JSON-LD @type Organization/LocalBusiness con campo "logo"
     * - <meta itemprop="logo" ...>
     * - <img ... alt="...logo...">
     *
     * @param string $url
     * @return string URL del logo o vuoto
     */
    public function fetch_site_logo($url) {
        if (empty($url)) return '';

        $resp = wp_remote_get($url, [
            'timeout' => 12,
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (compatible; DiscothequeOrgBot/1.0; +https://discoteche.org/bot)'
        ]);
        if (is_wp_error($resp)) {
            error_log('Errore fetch_site_logo: ' . $resp->get_error_message());
            return '';
        }
        $html = wp_remote_retrieve_body($resp);
        if (!$html) return '';

        // Canonical base per risolvere URL relativi
        $baseParts = wp_parse_url($url);
        $baseOrigin = '';
        if (is_array($baseParts) && !empty($baseParts['host'])) {
            $baseOrigin = ($baseParts['scheme'] ?? 'https') . '://' . $baseParts['host'] . (!empty($baseParts['port']) ? ':' . $baseParts['port'] : '');
        }

        // 0) <link rel="icon"...> / apple-touch-icon (evita SVG)
        if (preg_match('#<link[^>]+rel=["\'](?:shortcut icon|icon|apple-touch-icon[^"\']*)["\'][^>]+href=["\']([^"\']+)#i', $html, $m)) {
            $u = trim($m[1]);
            if ($u && stripos($u, '.svg') === false) {
                if (strpos($u, 'http') !== 0 && $baseOrigin) {
                    $u = rtrim($baseOrigin, '/') . '/' . ltrim($u, '/');
                }
                $u = esc_url_raw($u);
                if ($u) return $u;
            }
        }

        // 1) JSON-LD con "logo"
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ms)) {
            foreach ($ms[1] as $json) {
                $json = trim($json);
                $data = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Può essere un singolo oggetto o un array
                    $candidates = is_array($data) && isset($data[0]) ? $data : [$data];
                    foreach ($candidates as $obj) {
                        if (!is_array($obj)) continue;
                        $type = isset($obj['@type']) ? (is_array($obj['@type']) ? implode(',', $obj['@type']) : $obj['@type']) : '';
                        if ($type && preg_match('/Organization|LocalBusiness|NightClub|Club/i', $type)) {
                            if (!empty($obj['logo'])) {
                                if (is_string($obj['logo'])) {
                                    $u = esc_url_raw($obj['logo']);
                                    if ($u) return $u;
                                } elseif (is_array($obj['logo']) && !empty($obj['logo']['url'])) {
                                    $u = esc_url_raw($obj['logo']['url']);
                                    if ($u) return $u;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 2) <meta itemprop="logo" content="...">
        if (preg_match('#<meta[^>]+itemprop=["\']logo["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            $u = esc_url_raw($m[1]);
            if ($u) return $u;
        }

        // 3) <img alt="...logo..." src="..."> (evita SVG non supportato)
        if (preg_match('#<img[^>]+alt=["\'][^"\']*logo[^"\']*["\'][^>]+src=["\']([^"\']+)#i', $html, $m)) {
            $u = esc_url_raw($m[1]);
            if ($u && stripos($u, '.svg') === false) return $u;
        }

        // 4) Fallback: filename che contiene 'logo'/'logotype'/'brand' (png/webp/jpg/gif), evita SVG
        if (preg_match('#<img[^>]+src=["\']([^"\']*(logo|logotype|brand)[^"\']+\.(?:png|webp|jpe?g|gif))["\'][^>]*>#i', $html, $m)) {
            $u = esc_url_raw($m[1]);
            if ($u && stripos($u, '.svg') === false) return $u;
        }

        return '';
    }

    /**
     * Ottiene l'immagine profilo di una pagina Facebook
     *
     * @param string $page_url URL della pagina Facebook
     * @return string URL dell'immagine profilo o vuoto
     */
    public function fetch_fb_page_picture($page_url) {
        if (empty($page_url)) return '';
        
        // Estrae l'username o l'ID della pagina
        if (!preg_match('#facebook\.com/([^/?]+)#i', $page_url, $m)) return '';
        
        $username = $m[1];
        $graph_url = 'https://graph.facebook.com/' . $username . '/picture?type=large';
        
        // Verifica che l'URL sia raggiungibile
        $response = wp_remote_head($graph_url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        
        return $graph_url;
    }
    
    /**
     * Ottiene foto da Google Places API
     *
     * @param string $query Query di ricerca
     * @return array [URL immagine, Attribuzione HTML]
     */
    public function fetch_google_places_photo($query) {
        // Verifica che la chiave API sia configurata
        $api_key = defined('DISCORG_GOOGLE_PLACES_API_KEY') ? DISCORG_GOOGLE_PLACES_API_KEY : '';
        if (empty($api_key)) {
            return ['', ''];
        }
        
        // Effettua una ricerca testuale per trovare il luogo
        $search_url = add_query_arg([
            'query' => rawurlencode($query),
            'key' => $api_key,
        ], 'https://maps.googleapis.com/maps/api/place/textsearch/json');
        
        $response = wp_remote_get($search_url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            error_log('Google Places API error: ' . $response->get_error_message());
            return ['', ''];
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Verifica che la risposta contenga risultati con foto
        if (empty($data['results'][0]['photos'][0]['photo_reference'])) {
            return ['', ''];
        }
        
        // Ottieni il riferimento foto e attributi
        $photo_ref = $data['results'][0]['photos'][0]['photo_reference'];
        $attribution = !empty($data['results'][0]['photos'][0]['html_attributions'][0]) 
                     ? $data['results'][0]['photos'][0]['html_attributions'][0] 
                     : '';
        
        // Costruisci URL per l'immagine
        $photo_url = add_query_arg([
            'maxwidth' => 1600,
            'photoreference' => $photo_ref,
            'key' => $api_key,
        ], 'https://maps.googleapis.com/maps/api/place/photo');
        
        return [$photo_url, $attribution];
    }
    
    /**
     * Popola i campi ACF e le immagini per una discoteca
     *
     * @param int $post_id ID del post discoteca
     * @return bool True se operazione completata con successo
     */
    public function populate_acf_and_images($post_id) {
        // Aggiorna campi ACF testuali
        $phone = get_post_meta($post_id, 'phone', true);
        $website = get_post_meta($post_id, 'website', true);
        
        if ($phone)   discorg_update_acf($post_id, 'venue_phone', $phone);
        if ($website) discorg_update_acf($post_id, 'venue_website', $website);
        
        // Aggiorna indirizzo
        $addr = [];
        foreach (['street', 'city', 'region', 'postcode', 'country'] as $k) {
            $v = get_post_meta($post_id, $k, true);
            if ($v !== '') $addr[$k] = $v;
        }
        if ($addr) discorg_update_acf($post_id, 'venue_address', $addr);
        
        // Aggiorna coordinate
        $lat = get_post_meta($post_id, 'lat', true);
        $lng = get_post_meta($post_id, 'lng', true);
        if ($lat !== '') discorg_update_acf($post_id, 'venue_geo_lat', $lat);
        if ($lng !== '') discorg_update_acf($post_id, 'venue_geo_lng', $lng);
        
        // Aggiorna SameAs (filtra URL non social: esclude Google Maps e simili) usando helper repeater
        $sameas = get_post_meta($post_id, 'sameas', true);
        if ($sameas) {
            $urls = array_filter(array_map('trim', explode('|', $sameas)));
            $clean_urls = [];

            $is_google_maps = function($u) {
                $host = parse_url($u, PHP_URL_HOST);
                if (!$host) return false;
                $host = strtolower($host);
                // esclude domini google maps/shortener
                if (strpos($host, 'google.') !== false && (strpos($u, '/maps') !== false || strpos($u, 'cid=') !== false)) return true;
                if ($host === 'goo.gl' || strpos($host, 'maps.app.goo.gl') !== false) return true;
                return false;
            };

            foreach ($urls as $u) {
                if (!$is_google_maps($u)) {
                    $u = esc_url_raw($u);
                    if ($u) $clean_urls[] = $u;
                }
            }

            if ($clean_urls) {
                discorg_set_repeater_urls($post_id, 'venue_sameas', 'url', $clean_urls);
            }
        }
        
        // Alt SEO per le immagini
        $title = get_the_title($post_id);
        $city = get_post_meta($post_id, 'city', true);
        $region = get_post_meta($post_id, 'region', true);
        $alt = trim($title . ($city ? ' — ' . $city : '') . ($region ? ', ' . $region : '') . ' | ' . DISCORG_BRAND);
        
        // Backfill immagine se manca
        $this->backfill_logo_if_missing($post_id);
        
        // Ottieni le URL delle immagini
        $logo_url = get_post_meta($post_id, 'logo_url', true);
        $desk_url = get_post_meta($post_id, 'image_desktop_url', true);
        $mob_url = get_post_meta($post_id, 'image_mobile_url', true);
        
        // Allega le immagini
        $processor = discorg_image_processor();
        $logo_id = $processor->attach_from_url($post_id, $logo_url, 'logo', $alt);
        $desk_id = $processor->attach_from_url($post_id, $desk_url, 'cover-desktop', $alt);
        $mob_id = $processor->attach_from_url($post_id, $mob_url, 'cover-mobile', $alt);
        
        // Aggiorna i campi ACF con gli ID degli allegati
        if ($logo_id) discorg_update_acf($post_id, 'venue_logo', $logo_id);
        if ($desk_id) discorg_update_acf($post_id, 'immagine_desktop', $desk_id);
        if ($mob_id)  discorg_update_acf($post_id, 'immagine_mobile', $mob_id);
        
        // Imposta immagine in evidenza se mancante
        if (!has_post_thumbnail($post_id)) {
            $thumb_id = $desk_id ?: ($logo_id ?: $mob_id);
            if ($thumb_id) set_post_thumbnail($post_id, $thumb_id);
        }
        
        return true;
    }
}

/**
 * Helper function per accedere all'istanza
 *
 * @return Discorg_Image_Backfill
 */
function discorg_image_backfill() {
    return Discorg_Image_Backfill::get_instance();
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param int $post_id ID del post
 * @return bool True se operazione completata con successo
 */
function discorg_backfill_logo_url_if_missing($post_id) {
    return discorg_image_backfill()->backfill_logo_if_missing($post_id);
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param string $url URL del sito
 * @return string URL dell'immagine OG
 */
function discorg_fetch_og_image($url) {
    return discorg_image_backfill()->fetch_og_image($url);
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param string $page_url URL della pagina Facebook
 * @return string URL dell'immagine profilo
 */
function discorg_fb_page_picture($page_url) {
    return discorg_image_backfill()->fetch_fb_page_picture($page_url);
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param string $query Query di ricerca
 * @return array [URL immagine, Attribuzione HTML]
 */
function discorg_google_places_photo($query) {
    return discorg_image_backfill()->fetch_google_places_photo($query);
}

/**
 * Funzione di retrocompatibilità per il codice esistente
 * 
 * @param int $post_id ID del post
 * @return bool True se operazione completata con successo
 */
function discorg_populate_acf_and_images($post_id) {
    return discorg_image_backfill()->populate_acf_and_images($post_id);
}
