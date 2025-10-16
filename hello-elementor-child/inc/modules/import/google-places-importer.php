<?php
/**
 * Discoteche.org - Google Places Importer
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per l'importazione da Google Places API
 */
class Discorg_Google_Places_Importer {
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Percorso di log
     */
    private $log_path = '';
    
    /**
     * Google Places API Key
     */
    private $api_key = '';
    
    /**
     * Ottiene o crea l'istanza singleton
     *
     * @return Discorg_Google_Places_Importer
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Imposta il percorso di log PRIMA di qualsiasi log
        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit($upload_dir['basedir']) . 'import-logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        $this->log_path = $log_dir . '/import-' . date('Y-m-d') . '.log';

        // Imposta la API key da costante o configurazione (fallback a opzione tema)
        $this->api_key = defined('DISCORG_GOOGLE_PLACES_API_KEY')
            ? DISCORG_GOOGLE_PLACES_API_KEY
            : Discorg_Config::get_google_places_api_key();
        if (empty($this->api_key)) {
            $this->log('ATTENZIONE: DISCORG_GOOGLE_PLACES_API_KEY non configurata (costante o opzione).');
        }
    }
    
    /**
     * Restituisce la API key
     * 
     * @return string
     */
    public function get_api_key() {
        return $this->api_key;
    }
    
    /**
     * Definisce le regioni italiane con le loro città
     *
     * @return array Mappa regioni e città
     */
    public function get_regions_cities_map() {
        return [
            'Lombardia' => ['Milano', 'Bergamo', 'Brescia', 'Como', 'Monza', 'Varese', 'Pavia', 'Lecco', 'Mantova'],
            'Lazio' => ['Roma', 'Latina', 'Viterbo', 'Frosinone', 'Rieti'],
            'Veneto' => ['Venezia', 'Verona', 'Padova', 'Vicenza', 'Treviso', 'Rovigo', 'Belluno'],
            'Emilia-Romagna' => ['Bologna', 'Rimini', 'Riccione', 'Parma', 'Modena', 'Ferrara', 'Ravenna', 'Cesenatico'],
            'Campania' => ['Napoli', 'Salerno', 'Caserta', 'Avellino', 'Benevento', 'Sorrento', 'Positano'],
            'Sicilia' => ['Palermo', 'Catania', 'Messina', 'Siracusa', 'Taormina', 'Trapani', 'Agrigento'],
            'Puglia' => ['Bari', 'Lecce', 'Gallipoli', 'Taranto', 'Brindisi', 'Foggia', 'Polignano a Mare', 'Otranto'],
            'Toscana' => ['Firenze', 'Pisa', 'Livorno', 'Siena', 'Lucca', 'Arezzo', 'Grosseto', 'Viareggio', 'Massa'],
            'Piemonte' => ['Torino', 'Novara', 'Alessandria', 'Asti', 'Cuneo', 'Vercelli', 'Biella', 'Verbania'],
            'Liguria' => ['Genova', 'Sanremo', 'La Spezia', 'Savona', 'Imperia', 'Rapallo', 'Portofino'],
            'Sardegna' => ['Cagliari', 'Sassari', 'Olbia', 'Alghero', 'Porto Cervo', 'Costa Smeralda'],
            'Calabria' => ['Reggio Calabria', 'Cosenza', 'Catanzaro', 'Lamezia Terme', 'Tropea', 'Vibo Valentia'],
            'Marche' => ['Ancona', 'Pesaro', 'Ascoli Piceno', 'Macerata', 'Fermo', 'Urbino', 'San Benedetto del Tronto'],
            'Abruzzo' => ['L\'Aquila', 'Pescara', 'Teramo', 'Chieti', 'Montesilvano'],
            'Friuli-Venezia Giulia' => ['Trieste', 'Udine', 'Pordenone', 'Gorizia', 'Lignano Sabbiadoro'],
            'Trentino-Alto Adige' => ['Trento', 'Bolzano', 'Merano', 'Rovereto', 'Madonna di Campiglio'],
            'Umbria' => ['Perugia', 'Terni', 'Assisi', 'Foligno', 'Spoleto', 'Orvieto'],
            'Basilicata' => ['Potenza', 'Matera'],
            'Molise' => ['Campobasso', 'Isernia', 'Termoli'],
            'Valle d\'Aosta' => ['Aosta', 'Courmayeur', 'Cervinia'],
        ];
    }
    
    /**
     * Cerca discoteche in una città tramite Google Places API
     *
     * @param string $city Nome della città
     * @param string $region Nome della regione
     * @return array Risultati della ricerca
     */
    public function search_venues($city, $region = '') {
        $results = [];
        $this->log("Inizio ricerca discoteche a $city, $region");
        
        // Cerca con query "discoteche {città}"
        $disco_results = $this->search_places("discoteche $city");
        $this->log("Trovate " . count($disco_results) . " risultati per 'discoteche $city'");
        
        // Cerca con query "night club {città}"
        $nightclub_results = $this->search_places("night club $city");
        $this->log("Trovate " . count($nightclub_results) . " risultati per 'night club $city'");
        
        // Unisci i risultati ed elimina i duplicati
        $combined = array_merge($disco_results, $nightclub_results);
        
        // Elimina duplicati basati su place_id
        $unique = [];
        foreach ($combined as $place) {
            if (!isset($unique[$place['place_id']])) {
                $unique[$place['place_id']] = $place;
            }
        }
        
        $results = array_values($unique);
        $this->log("Totale risultati unici: " . count($results));
        
        // Verifica se i loghi sono già trasparenti
        foreach ($results as &$place) {
            $place['has_transparent_logo'] = false;
            
            if (!empty($place['icon']) && $this->is_png_with_transparency($place['icon'])) {
                $place['has_transparent_logo'] = true;
            }
        }
        
        return $results;
    }
    
    /**
     * Verifica se un'immagine PNG ha trasparenza
     *
     * @param string $image_url URL dell'immagine
     * @return bool True se l'immagine è PNG con trasparenza
     */
    public function is_png_with_transparency($image_url) {
        if (empty($image_url)) {
            return false;
        }
        
        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            return false;
        }
        
        // Controlla il tipo di file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $temp_file);
        finfo_close($finfo);
        
        $result = false;
        
        // Verifica se è PNG
        if ($mime === 'image/png') {
            // Controlla se ha un canale alpha
            if (function_exists('imagecreatefrompng')) {
                $image = @imagecreatefrompng($temp_file);
                if ($image) {
                    // Verifica se l'immagine supporta l'alpha blending
                    imageAlphaBlending($image, true);
                    imageSaveAlpha($image, true);
                    
                    // Controlla se ci sono pixel trasparenti
                    $width = imagesx($image);
                    $height = imagesy($image);
                    
                    // Controlla solo un campione di pixel per prestazioni
                    $sample_size = min(100, $width * $height);
                    $step_x = max(1, floor($width / sqrt($sample_size)));
                    $step_y = max(1, floor($height / sqrt($sample_size)));
                    
                    for ($x = 0; $x < $width; $x += $step_x) {
                        for ($y = 0; $y < $height; $y += $step_y) {
                            $color_index = imagecolorat($image, $x, $y);
                            $color = imagecolorsforindex($image, $color_index);
                            if ($color['alpha'] > 0) {
                                // Trovato un pixel con alpha > 0
                                $result = true;
                                break 2;
                            }
                        }
                    }
                    
                    imagedestroy($image);
                }
            }
        }
        
        @unlink($temp_file);
        return $result;
    }
    
    /**
     * Esegue ricerca su Google Places API
     *
     * @param string $query Query di ricerca
     * @return array Risultati della ricerca
     */
    private function search_places($query) {
        if (empty($this->api_key)) {
            $this->log('API key Google Places mancante: impossibile eseguire search_places.');
            return [];
        }
        $url = add_query_arg([
            'query' => $query,
            'key' => $this->api_key,
            'language' => 'it',
            'region' => 'it',
            'type' => 'night_club',
        ], 'https://maps.googleapis.com/maps/api/place/textsearch/json');
        
        $response = wp_remote_get($url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            $this->log("Errore API: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || $data['status'] !== 'OK') {
            $error = !empty($data['error_message']) ? $data['error_message'] : 'Unknown error';
            $this->log("API Error: $error");
            return [];
        }
        
        return $data['results'];
    }
    
    /**
     * Ottiene dettagli completi di un posto tramite Place Details API
     *
     * @param string $place_id ID del posto
     * @return array Dettagli completi del posto
     */
    public function get_place_details($place_id) {
        if (empty($this->api_key)) {
            $this->log('API key Google Places mancante: impossibile eseguire get_place_details.');
            return [];
        }
        $url = add_query_arg([
            'place_id' => $place_id,
            'key' => $this->api_key,
            'language' => 'it',
            'fields' => 'name,place_id,formatted_address,formatted_phone_number,website,url,icon,photos,opening_hours,rating,user_ratings_total,price_level,geometry,editorial_summary,types,address_components',
        ], 'https://maps.googleapis.com/maps/api/place/details/json');
        
        $response = wp_remote_get($url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            $this->log("Errore API Details: " . $response->get_error_message());
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || $data['status'] !== 'OK') {
            $error = !empty($data['error_message']) ? $data['error_message'] : 'Unknown error';
            $this->log("API Details Error: $error");
            return [];
        }
        
        $place = $data['result'];
        
        // Aggiungi informazione se il logo è trasparente
        if (!empty($place['icon'])) {
            $place['has_transparent_logo'] = $this->is_png_with_transparency($place['icon']);
        } else {
            $place['has_transparent_logo'] = false;
        }
        
        return $place;
    }
    
    /**
     * Scarica un'immagine da Google Places Photos API
     *
     * @param string $photo_reference Riferimento foto
     * @param int $max_width Larghezza massima
     * @return string URL della foto scaricata
     */
    public function get_place_photo($photo_reference, $max_width = 1600) {
        if (empty($this->api_key)) {
            $this->log('API key Google Places mancante: impossibile generare URL foto.');
            return '';
        }

        // Endpoint ufficiale Photos (ritorna 302 verso URL finale dell'immagine)
        $endpoint = add_query_arg([
            'photoreference' => $photo_reference,
            'key'            => $this->api_key,
            'maxwidth'       => $max_width,
        ], 'https://maps.googleapis.com/maps/api/place/photo');

        // Risolvi manualmente i redirect per ottenere l'URL finale dell'immagine
        // Alcuni hosting/validator di WordPress rifiutano URL "indiretti" e senza estensione
        $attempt_url = $endpoint;

        for ($i = 0; $i < 3; $i++) {
            $resp = wp_remote_head($attempt_url, [
                'timeout'      => 20,
                'redirection'  => 0,   // non seguire redirect automaticamente
                'sslverify'    => true,
            ]);

            if (is_wp_error($resp)) {
                $this->log('Photos HEAD error: ' . $resp->get_error_message());
                break;
            }

            $code    = (int) wp_remote_retrieve_response_code($resp);
            $headers = wp_remote_retrieve_headers($resp);
            $ctype   = '';
            if (is_array($headers)) {
                $ctype = isset($headers['content-type']) ? $headers['content-type'] : (isset($headers['Content-Type']) ? $headers['Content-Type'] : '');
            } elseif (is_object($headers) && method_exists($headers, 'getAll')) {
                $all = $headers->getAll();
                $ctype = isset($all['content-type']) ? $all['content-type'] : (isset($all['Content-Type']) ? $all['Content-Type'] : '');
            } else {
                // Requests_Utility_CaseInsensitiveDictionary consente accesso case-insensitive
                if (isset($headers['content-type'])) $ctype = $headers['content-type'];
            }

            // Se è un redirect, prendi Location e riprova
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $location = '';
                if (is_array($headers)) {
                    $location = isset($headers['location']) ? $headers['location'] : (isset($headers['Location']) ? $headers['Location'] : '');
                } else {
                    // oggetto headers case-insensitive
                    if (isset($headers['location'])) $location = $headers['location'];
                }

                if (!empty($location)) {
                    // Gestisci eventuali Location relative
                    if (strpos($location, 'http') !== 0) {
                        $parsed  = wp_parse_url($attempt_url);
                        $scheme  = $parsed['scheme'] ?? 'https';
                        $host    = $parsed['host'] ?? '';
                        $prefix  = $scheme . '://' . $host;
                        if (!empty($parsed['port'])) {
                            $prefix .= ':' . $parsed['port'];
                        }
                        if (!str_starts_with($location, '/')) {
                            $location = '/' . ltrim($location, '/');
                        }
                        $location = $prefix . $location;
                    }
                    $attempt_url = $location;
                    continue;
                }

                // niente location → interrompi
                break;
            }

            // 200 OK e content-type immagine → abbiamo l'URL finale
            if ($code === 200 && is_string($ctype) && stripos($ctype, 'image/') === 0) {
                return $attempt_url;
            }

            // Qualsiasi altro caso → interrompi e usa endpoint come fallback
            break;
        }

        // Fallback: restituisci l'endpoint Photos (potrà ancora funzionare su alcuni ambienti)
        return $endpoint;
    }
    
    /**
     * Importa una discoteca da Google Places in WordPress
     *
     * @param array $place Dati del posto
     * @param string $city Nome della città
     * @param string $region Nome della regione
     * @return array Risultato dell'importazione
     */
    public function import_venue($place, $city, $region) {
        $result = [
            'success' => false,
            'message' => '',
            'post_id' => 0,
            'is_duplicate' => false,
        ];
        
        // Ottieni place_id e nome
        $place_id = $place['place_id'];
        $place_name = $place['name'] ?? 'Unknown';
        
        error_log('=== VENUE IMPORT STARTED ===');
        error_log('Venue: ' . $place_name . ' (ID: ' . $place_id . ')');
        
        // Verifica se esiste già
        $existing = $this->check_duplicate($place);
        if ($existing) {
            $result['is_duplicate'] = true;
            $result['message'] = 'Discoteca già esistente (ID: ' . $existing . ')';
            $result['post_id'] = $existing;
            return $result;
        }
        
        // Crea post type discoteca
        $post_data = [
            'post_title' => $place['name'],
            'post_type' => DISCORG_POST_TYPE,
            'post_status' => 'publish',
            'post_content' => '',
            'post_name' => sanitize_title($place['name'] . '-' . $city),
        ];
        
        // Se c'è una descrizione in editorial_summary, usala come excerpt
        if (!empty($place['editorial_summary']['overview'])) {
            $post_data['post_excerpt'] = $place['editorial_summary']['overview'];
        }
        
        // Inserisci post
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            $this->log("Errore inserimento post: " . $post_id->get_error_message());
            $result['message'] = 'Errore creazione post: ' . $post_id->get_error_message();
            return $result;
        }
        
        // Salva Google Place ID come meta
        update_post_meta($post_id, 'google_place_id', $place_id);
        
        // Processa i dati indirizzo
        $this->process_address_data($post_id, $place, $city, $region);
        
        // Gestisci tassonomia località
        $this->set_location_taxonomy($post_id, $city, $region);
        
        // Gestisci immagini
        $image_results = $this->process_images($post_id, $place);
        
        // Aggiorna ACF fields tramite la classe mapper
        require_once dirname(__FILE__) . '/acf-mapper.php';
        $mapper = new Discorg_ACF_Mapper();
        $mapper->map_google_place_to_acf($post_id, $place);
        
        // Imposta Rank Math SEO
        $this->set_seo_metadata($post_id, $place, $city);

        // Hook post-import singolo: consente azioni come generazione AI contenuti
        do_action('discorg_import_venue_completed', $post_id, $place, $city, $region);
        
        $result['success'] = true;
        $result['post_id'] = $post_id;
        $result['message'] = 'Discoteca importata con successo';
        $result['image_results'] = $image_results;
        
        return $result;
    }
    
    /**
     * Verifica se una discoteca esiste già
     *
     * @param array $place Dati del posto
     * @return int|false ID del post esistente o false
     */
    private function check_duplicate($place) {
        // Cerca per place_id
        $args = [
            'post_type' => DISCORG_POST_TYPE,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => 'google_place_id',
                    'value' => $place['place_id'],
                    'compare' => '=',
                ]
            ],
            'fields' => 'ids',
            'posts_per_page' => 1,
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return $query->posts[0];
        }
        
        // Cerca per nome e città (deduplica)
        $city = '';
        if (!empty($place['address_components'])) {
            foreach ($place['address_components'] as $component) {
                if (in_array('locality', $component['types'])) {
                    $city = $component['long_name'];
                    break;
                }
            }
        }
        
        if ($city) {
            $by_title = get_page_by_title($place['name'], OBJECT, DISCORG_POST_TYPE);
            if ($by_title && get_post_meta($by_title->ID, 'city', true) === $city) {
                return (int) $by_title->ID;
            }
        }
        
        return false;
    }
    
    /**
     * Processa i dati dell'indirizzo
     *
     * @param int $post_id ID del post
     * @param array $place Dati del posto
     * @param string $city Nome della città
     * @param string $region Nome della regione
     */
    private function process_address_data($post_id, $place, $city, $region) {
        // Estrai componenti indirizzo
        $street = '';
        $postal_code = '';
        $country = 'IT';
        
        if (!empty($place['address_components'])) {
            foreach ($place['address_components'] as $component) {
                if (in_array('route', $component['types'])) {
                    $street = $component['long_name'];
                    
                    // Aggiungi il numero civico se disponibile
                    foreach ($place['address_components'] as $subcomp) {
                        if (in_array('street_number', $subcomp['types'])) {
                            $street .= ' ' . $subcomp['long_name'];
                            break;
                        }
                    }
                } elseif (in_array('postal_code', $component['types'])) {
                    $postal_code = $component['long_name'];
                } elseif (in_array('country', $component['types'])) {
                    $country = $component['short_name'];
                }
            }
        }
        
        // Salva meta dati base
        update_post_meta($post_id, 'street', $street);
        update_post_meta($post_id, 'city', $city);
        update_post_meta($post_id, 'region', $region);
        update_post_meta($post_id, 'postcode', $postal_code);
        update_post_meta($post_id, 'country', $country);
        
        // Salva coordinate
        if (!empty($place['geometry']['location']['lat'])) {
            update_post_meta($post_id, 'lat', $place['geometry']['location']['lat']);
        }
        
        if (!empty($place['geometry']['location']['lng'])) {
            update_post_meta($post_id, 'lng', $place['geometry']['location']['lng']);
        }
        
        // Salva telefono
        if (!empty($place['formatted_phone_number'])) {
            update_post_meta($post_id, 'phone', $place['formatted_phone_number']);
        }
        
        // Salva website
        if (!empty($place['website'])) {
            update_post_meta($post_id, 'website', $place['website']);
        }
        
        // Nota: non salviamo più l'URL di Google Maps nei meta 'sameas' per evitare che compaia nei Profili ufficiali.
        // I profili ufficiali sono gestiti in Discorg_ACF_Mapper (website + eventuali social dedotti).
    }
    
    /**
     * Imposta la tassonomia località
     *
     * @param int $post_id ID del post
     * @param string $city Nome della città
     * @param string $region Nome della regione
     */
    private function set_location_taxonomy($post_id, $city, $region) {
        if (!taxonomy_exists(DISCORG_TAX_LOCALITA)) {
            $this->log("Tassonomia " . DISCORG_TAX_LOCALITA . " non esiste, creandola...");
            
            // Registra tassonomia se non esiste
            register_taxonomy(
                DISCORG_TAX_LOCALITA,
                DISCORG_POST_TYPE,
                [
                    'hierarchical' => true,
                    'label' => 'Località',
                    'public' => true,
                    'show_ui' => true,
                    'show_in_menu' => true
                ]
            );
            
            if (!taxonomy_exists(DISCORG_TAX_LOCALITA)) {
                $this->log("Impossibile creare tassonomia " . DISCORG_TAX_LOCALITA);
                return;
            }
        }
        
        // Per SEO: usa solo la città, non la regione
        $city_term = term_exists($city, DISCORG_TAX_LOCALITA);
        
        if (!$city_term) {
            $this->log("Creazione termine città: $city");
            $city_term = wp_insert_term($city, DISCORG_TAX_LOCALITA);
        }
        
        if (is_wp_error($city_term)) {
            $this->log("Errore creazione termine città: " . $city_term->get_error_message());
            return;
        }
        
        $city_term_id = is_array($city_term) ? $city_term['term_id'] : $city_term;
        
        // Assegna solo il termine città al post
        $result = wp_set_object_terms($post_id, $city_term_id, DISCORG_TAX_LOCALITA);
        
        if (is_wp_error($result)) {
            $this->log("Errore assegnazione termine: " . $result->get_error_message());
        } else {
            $this->log("Termine $city (ID: $city_term_id) assegnato con successo");
        }
    }
    
    /**
     * Seleziona il photo_reference più adatto a rappresentare un logo.
     * Criteri:
     *  - Preferisce immagini quasi quadrate (|w-h|/max(w,h) piccolo)
     *  - Penalizza immagini con area molto grande (di solito sono foto di ambienti)
     *  - Fallback alla prima foto se i metadati width/height non sono affidabili
     *
     * @param array $place Dati del posto (risultato Place Details)
     * @return string photo_reference selezionato o stringa vuota
     */
    private function select_logo_photo_ref($place) {
        if (empty($place['photos']) || !is_array($place['photos'])) return '';
        $bestRef = '';
        $bestScore = PHP_INT_MAX;

        foreach ($place['photos'] as $p) {
            if (empty($p['photo_reference'])) continue;
            $w = isset($p['width']) ? (int) $p['width'] : 0;
            $h = isset($p['height']) ? (int) $p['height'] : 0;

            // Differenza di ratio rispetto al quadrato
            $ratioDiff = ($w > 0 && $h > 0) ? (abs($w - $h) / max($w, $h)) : 1.0;

            // Penalità dimensione (favorisci immagini non enormi per il logo)
            $area = ($w > 0 && $h > 0) ? ($w * $h) : 0;
            $sizePenalty = $area > 0 ? ($area / (800 * 800)) : 1.0; // 0 quando 800x800, >0 più cresce l'area

            // Score complessivo (più basso è meglio)
            $score = $ratioDiff + 0.1 * $sizePenalty;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestRef = $p['photo_reference'];
            }
        }

        if ($bestRef) return $bestRef;
        return !empty($place['photos'][0]['photo_reference']) ? $place['photos'][0]['photo_reference'] : '';
    }

    /**
     * Processa le immagini
     *
     * @param int $post_id ID del post
     * @param array $place Dati del posto
     * @return array Risultati del processamento immagini
     */
    private function process_images($post_id, $place) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        error_log('=== PROCESSING IMAGES ===');
        error_log('Post ID: ' . $post_id);
        error_log('Post title: ' . get_the_title($post_id));

        $debug = [];
        $debug[] = 'Processing images for post ' . $post_id . ' (' . get_the_title($post_id) . ')';

        $result = [
            'logo_processed' => false,
            'desktop_processed' => false,
            'mobile_processed' => false,
            'used_remove_bg' => false,
            'errors' => [],
        ];
        
        $title = get_the_title($post_id);
        $city = get_post_meta($post_id, 'city', true);
        $region = get_post_meta($post_id, 'region', true);
        $alt = trim($title . ($city ? ' — ' . $city : '') . ($region ? ', ' . $region : '') . ' | ' . DISCORG_BRAND);
        $slug = sanitize_title($title . '-' . $city);
        
        // Verifica che photos sia un array e abbia elementi
        if (empty($place['photos']) || !is_array($place['photos'])) {
            $message = "Errore: Nessuna foto disponibile per $title";
            $this->log($message);
            error_log($message);
            $result['errors'][] = 'Nessuna foto disponibile';
            return $result;
        }
        
        error_log('Photos count: ' . count($place['photos']));
        error_log('Photos data: ' . print_r($place['photos'], true));
        
        // ---- LOGO: preferisci il logo ufficiale dal sito, altrimenti usa PRIMA FOTO ----
        $site_logo_url = '';
        if (!empty($place['website'])) {
            $site_logo_url = discorg_image_backfill()->fetch_site_logo($place['website']);
            if ($site_logo_url) {
                $debug[] = 'Site logo URL: ' . $site_logo_url;
            }
        }
        if ($site_logo_url || !empty($place['photos'][0]['photo_reference'])) {
            if (!empty($site_logo_url)) {
                $logo_url = $site_logo_url;
                error_log('Using site logo URL: ' . $logo_url);
                $debug[] = 'Logo URL (site): ' . $logo_url;
            } else {
                // Seleziona la foto più adatta al logo (quasi quadrata, non enorme)
                $photo_ref = $this->select_logo_photo_ref($place);
                $debug[] = 'Logo photo_ref selected: ' . $photo_ref;
                $logo_url = $this->get_place_photo($photo_ref, 800); // Max 800px per logo
                error_log('Downloading logo from: ' . $logo_url);
                $debug[] = 'Logo URL (places): ' . $logo_url;
            }
            
            // 1. Scarica e crea un allegato temporaneo
            $tmp_attachment_id = discorg_image_processor()->sideload_url_as_attachment($post_id, $logo_url, 'discoteca-' . $slug . '-logo-src');
            error_log('Logo temporary attachment ID: ' . (is_wp_error($tmp_attachment_id) ? 'ERROR' : $tmp_attachment_id));
            $debug[] = 'Logo tmp attachment: ' . (is_wp_error($tmp_attachment_id) ? 'ERROR' : $tmp_attachment_id);
            
            if (!is_wp_error($tmp_attachment_id)) {
                // 2. Rimuovi sfondo sempre per i loghi (migliora l'aspetto)
                $needs_remove_bg = true;
                $this->log("Logo scontornato necessario: SI (prima foto usata come logo)");
                
                // 3. Usa il processore immagini esistente
                $image_processor = discorg_image_processor();
                $logo_result = $image_processor->process_and_attach(
                    $post_id,              // ID post
                    $tmp_attachment_id,    // ID dell'allegato temporaneo
                    'logo',                // Tipo: 'logo'
                    false,                 // Non impostare come featured
                    true,                  // Scrivere meta
                    $needs_remove_bg       // Usa Remove.bg
                );
                
                if ($logo_result && !empty($logo_result['ok'])) {
                    // Successo: l'immagine è stata processata
                    $logo_id = $logo_result['logo_id'];
                    $result['logo_processed'] = true;
                    $result['used_remove_bg'] = !empty($logo_result['tmp']);
                    
                    // Salva l'ID nel campo ACF
                    $saved_logo = discorg_update_acf($post_id, 'venue_logo', $logo_id);
                    if ($saved_logo) {
                        $message = "Campo ACF venue_logo aggiornato con ID: $logo_id";
                        $this->log($message);
                        error_log('Saving to ACF venue_logo: ' . $logo_id);
                        error_log($message);
                        $debug[] = 'Saved venue_logo ID=' . $logo_id;
                    } else {
                        $this->log("ERRORE: impossibile salvare venue_logo via ACF/meta");
                        $debug[] = 'Failed to save venue_logo via ACF/meta';
                    }
                } else {
                    // Errore nel processamento → fallback: usa direttamente l'allegato temporaneo
                    $result['errors'][] = 'Processamento logo fallito: ' . ($logo_result['error'] ?? 'Errore sconosciuto') . ' (fallback su tmp attachment)';
                    $this->log("Errore processamento logo, fallback su tmp attachment ID: " . (is_wp_error($tmp_attachment_id) ? 'ERR' : $tmp_attachment_id));
                    if (!is_wp_error($tmp_attachment_id) && $tmp_attachment_id) {
                        $saved_logo_tmp = discorg_update_acf($post_id, 'venue_logo', (int)$tmp_attachment_id);
                        if ($saved_logo_tmp) {
                            $result['logo_processed'] = true; // almeno popolato
                            $debug[] = 'Fallback saved venue_logo with tmp ID=' . (int)$tmp_attachment_id;
                        } else {
                            $debug[] = 'Fallback failed saving venue_logo tmp ID=' . (int)$tmp_attachment_id;
                        }
                    }
                }
            } else {
                // Errore nel download
                $result['errors'][] = 'Download logo fallito: ' . $tmp_attachment_id->get_error_message();
                $this->log("Errore download logo: " . $tmp_attachment_id->get_error_message());
            }
        }
        
        // ---- IMMAGINI: Usa la SECONDA FOTO per Desktop e Mobile ----
        // Cerca una foto diversa da quella usata per il logo
        $cover_photo_ref = '';
        if (!empty($place['photos'][1]['photo_reference'])) {
            // Usa photos[1] per cover (seconda foto dell'array)
            $cover_photo_ref = $place['photos'][1]['photo_reference'];
        } elseif (count($place['photos']) > 1) {
            // Fallback: usa un'altra foto disponibile
            $cover_photo_ref = $place['photos'][count($place['photos'])-1]['photo_reference'];
        } elseif (!empty($place['photos'][0]['photo_reference'])) {
            // Fallback: se c'è solo una foto, usala sia per logo che per cover
            $cover_photo_ref = $place['photos'][0]['photo_reference'];
        }
        
        if ($cover_photo_ref) {
            // ---- DESKTOP/MOBILE IMAGES (single processing) ----
            $desktop_url = $this->get_place_photo($cover_photo_ref, 1920); // Max 1920px for desktop source
            error_log('Downloading desktop image from: ' . $desktop_url);
            $debug[] = 'Desktop source URL: ' . $desktop_url;
            
            // 1. Scarica e crea un allegato temporaneo (sorgente unica)
            $desktop_tmp_id = discorg_image_processor()->sideload_url_as_attachment($post_id, $desktop_url, 'discoteca-' . $slug . '-cover-src');
            $debug[] = 'Desktop tmp attachment: ' . (is_wp_error($desktop_tmp_id) ? 'ERROR: ' . $desktop_tmp_id->get_error_message() : $desktop_tmp_id);
            
            if (!is_wp_error($desktop_tmp_id)) {
                // 2. Processa con il processore immagini: genera sia desktop che mobile in un’unica chiamata
                $cover_result = discorg_image_processor()->process_and_attach(
                    $post_id,
                    $desktop_tmp_id,
                    'cover',
                    true,        // Set as featured
                    true,        // Write meta
                    false        // No bg removal
                );
                
                if ($cover_result && !empty($cover_result['desktop_id'])) {
                    $desktop_id = $cover_result['desktop_id'];
                    $saved_desk = discorg_update_acf($post_id, 'immagine_desktop', $desktop_id);
                    $result['desktop_processed'] = true;
                    if ($saved_desk) { $debug[] = 'Saved immagine_desktop ID=' . $desktop_id; } else { $debug[] = 'Failed to save immagine_desktop ID=' . $desktop_id; }
                    $message = "Campo ACF immagine_desktop aggiornato con ID: $desktop_id";
                    $this->log($message);
                    error_log('Saving to ACF immagine_desktop: ' . $desktop_id);
                    error_log($message);
                } else {
                    // Fallback: usa direttamente l'allegato temporaneo come desktop (e come mobile se serve)
                    $this->log("Errore processamento immagine desktop - fallback su tmp attachment ID: " . (is_wp_error($desktop_tmp_id) ? 'ERR' : $desktop_tmp_id));
                    $result['errors'][] = 'Processamento immagine desktop fallito (fallback su tmp attachment)';
                    if (!is_wp_error($desktop_tmp_id) && $desktop_tmp_id) {
                        discorg_update_acf($post_id, 'immagine_desktop', (int)$desktop_tmp_id);
                        $result['desktop_processed'] = true;
                        // se non avremo mobile_id sotto, useremo lo stesso tmp anche per mobile
                    }
                }

                // 3. Aggiorna anche l'immagine mobile se disponibile dal risultato
                if (!empty($cover_result['mobile_id'])) {
                    $mobile_id = $cover_result['mobile_id'];
                    $saved_mob = discorg_update_acf($post_id, 'immagine_mobile', $mobile_id);
                    $result['mobile_processed'] = true;
                    if ($saved_mob) { $debug[] = 'Saved immagine_mobile ID=' . $mobile_id; } else { $debug[] = 'Failed to save immagine_mobile ID=' . $mobile_id; }
                    $message2 = "Campo ACF immagine_mobile aggiornato con ID: $mobile_id";
                    $this->log($message2);
                    error_log('Saving to ACF immagine_mobile: ' . $mobile_id);
                    error_log($message2);
                } else {
                    // Fallback mobile: se non abbiamo mobile_id e c'è il tmp, riusa il tmp
                    if (!is_wp_error($desktop_tmp_id) && $desktop_tmp_id) {
                        $saved_mob_tmp = discorg_update_acf($post_id, 'immagine_mobile', (int)$desktop_tmp_id);
                        $result['mobile_processed'] = true;
                        $this->log("Fallback mobile: impostata immagine_mobile con tmp attachment ID: $desktop_tmp_id");
                        $debug[] = 'Fallback mobile saved immagine_mobile tmp ID=' . (int)$desktop_tmp_id . ($saved_mob_tmp ? '' : ' (save failed)');
                    }
                }
            }
            
        } else {
            $this->log("Errore: Nessuna foto disponibile per cover di $title");
            $result['errors'][] = 'Nessuna foto disponibile per cover';
        }
        
        $result['debug'] = $debug;
        return $result;
    }
    
    /**
     * Imposta i metadati SEO usando Rank Math
     *
     * @param int $post_id ID del post
     * @param array $place Dati del posto
     * @param string $city Nome della città
     */
    private function set_seo_metadata($post_id, $place, $city) {
        if (!class_exists('RankMath')) {
            return;
        }
        
        $title = get_the_title($post_id);
        $rating = !empty($place['rating']) ? round($place['rating'], 1) : '';
        $rating_text = $rating ? " $rating stelle su Google." : '';
        
        // Meta title
        $meta_title = "$title - Discoteca a $city | Orari, Info, Contatti";
        update_post_meta($post_id, 'rank_math_title', $meta_title);
        
        // Meta description
        $meta_desc = "Scopri $title a $city: indirizzo, telefono, orari.$rating_text Prenota il tuo tavolo!";
        update_post_meta($post_id, 'rank_math_description', $meta_desc);
        
        // Focus keyword
        $focus_keyword = "$title $city";
        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
    }
    
    /**
     * Scrive un messaggio nel log
     *
     * @param string $message Messaggio da loggare
     * @return void
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[$timestamp] $message" . PHP_EOL;
        
        // Scrivi nel file di log (solo se il path è disponibile)
        if (!empty($this->log_path)) {
            @file_put_contents($this->log_path, $log_message, FILE_APPEND);
        }
        
        // Scrivi anche in error_log per debug
        error_log("GooglePlacesImporter: $message");
    }
}

/**
 * Helper function per accedere all'istanza
 *
 * @return Discorg_Google_Places_Importer
 */
function discorg_google_places_importer() {
    return Discorg_Google_Places_Importer::get_instance();
}

/**
 * Ottiene la API key di Google Places
 * 
 * @return string
 */
function discorg_google_places_api_key() {
    $instance = Discorg_Google_Places_Importer::get_instance();
    return $instance->get_api_key();
}

/**
 * AJAX handler per la ricerca Google Places
 */
function discorg_search_google_places_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti']);
    }
    
    // Verifica nonce
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'discorg_google_places_search')) {
        wp_send_json_error(['message' => 'Nonce non valido']);
    }
    
    $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    $region = isset($_GET['region']) ? sanitize_text_field($_GET['region']) : '';
    
    if (empty($city)) {
        wp_send_json_error(['message' => 'Città non specificata']);
    }
    
    $importer = discorg_google_places_importer();
    if (empty($importer->get_api_key())) {
        wp_send_json_error(['message' => 'Google Places API Key non configurata. Vai in Impostazioni → Discoteche.org e inserisci la chiave, oppure definisci la costante DISCORG_GOOGLE_PLACES_API_KEY in wp-config.php.']);
    }

    $results = $importer->search_venues($city, $region);
    
    wp_send_json_success([
        'places' => $results,
        'total' => count($results)
    ]);
}

/**
 * AJAX handler per ottenere i dettagli di un posto
 */
function discorg_get_place_details_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti']);
    }
    
    // Verifica nonce
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'discorg_google_places_details')) {
        wp_send_json_error(['message' => 'Nonce non valido']);
    }
    
    $place_id = isset($_GET['place_id']) ? sanitize_text_field($_GET['place_id']) : '';
    
    if (empty($place_id)) {
        wp_send_json_error(['message' => 'ID del posto non specificato']);
    }
    
    $importer = discorg_google_places_importer();
    $place = $importer->get_place_details($place_id);
    
    if (empty($place)) {
        wp_send_json_error(['message' => 'Nessun dettaglio disponibile per questo posto']);
    }
    
    wp_send_json_success([
        'place' => $place
    ]);
}

/**
 * AJAX handler per importare i posti selezionati
 */
function discorg_import_selected_places_callback() {
    error_log('=== IMPORT SELECTED PLACES STARTED ===');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti']);
    }
    
    // Verifica nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'discorg_google_places_import')) {
        wp_send_json_error(['message' => 'Nonce non valido']);
    }
    
    $places = isset($_POST['places']) ? json_decode(stripslashes($_POST['places']), true) : [];
    $city = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
    $region = isset($_POST['region']) ? sanitize_text_field($_POST['region']) : '';
    
    error_log('Selected places: ' . print_r($places, true));
    error_log('City: ' . $city);
    error_log('Region: ' . $region);
    
    if (empty($places)) {
        wp_send_json_error(['message' => 'Nessun posto da importare']);
    }
    
    if (empty($city)) {
        wp_send_json_error(['message' => 'Città non specificata']);
    }
    
    $importer = discorg_google_places_importer();
    
    $results = [
        'imported' => 0,
        'duplicates' => 0,
        'errors' => 0,
        'transparent_logos' => 0,
        'removed_bg_logos' => 0,
        'details' => []
    ];
    
    foreach ($places as $place_id) {
        error_log('Processing place ID: ' . $place_id);
        
        // Prima ottieni dettagli base
        $place = $importer->get_place_details($place_id);
        error_log('Place data received: ' . (empty($place) ? 'NO' : 'YES'));
        
        // Chiamata specifica a Place Details per ottenere foto di alta qualità
        $api_key = $importer->get_api_key();
        $details_url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $details_url .= '?place_id=' . urlencode($place_id);
        $details_url .= '&fields=name,photos,website,formatted_address';
        $details_url .= '&key=' . $api_key;
        
        error_log('Calling Place Details API for place_id: ' . $place_id);
        error_log('Details URL: ' . $details_url);
        
        $details_response = wp_remote_get($details_url);
        
        if (is_wp_error($details_response)) {
            error_log('Place Details API error: ' . $details_response->get_error_message());
        } else {
            $details_body = wp_remote_retrieve_body($details_response);
            $details_data = json_decode($details_body, true);
            
            error_log('Place Details response status: ' . ($details_data['status'] ?? 'UNKNOWN'));
            
            if (!empty($details_data['result']) && !empty($details_data['result']['photos'])) {
                error_log('Photos available: ' . count($details_data['result']['photos']));
                error_log('Full photos array: ' . print_r($details_data['result']['photos'], true));
                
                // Aggiorna le foto nell'oggetto place
                if (is_array($place)) {
                    $place['photos'] = $details_data['result']['photos'];
                    error_log('Updated place photos array with ' . count($place['photos']) . ' photos');
                } else {
                    error_log('WARNING: place is not an array, cannot update photos');
                }
            } else {
                error_log('No photos found in Place Details API response');
            }
        }
        
        if (empty($place)) {
            $results['errors']++;
            $results['details'][] = [
                'place_id' => $place_id,
                'success' => false,
                'message' => 'Dettagli non disponibili'
            ];
            error_log('Skipping place ' . $place_id . ' - details not available');
            continue;
        }
        
        // Importa il posto
        error_log('Importing venue data for: ' . ($place['name'] ?? 'Unknown'));
        $import_result = $importer->import_venue($place, $city, $region);
        error_log('Import result: ' . ($import_result['success'] ? 'SUCCESS' : 'FAILED') . ' - ' . $import_result['message']);
        
        if ($import_result['is_duplicate']) {
            $results['duplicates']++;
            $results['details'][] = [
                'place_id' => $place_id,
                'success' => false,
                'is_duplicate' => true,
                'message' => $import_result['message'],
                'post_id' => $import_result['post_id']
            ];
        } elseif ($import_result['success']) {
            $results['imported']++;
            $results['details'][] = [
                'place_id' => $place_id,
                'success' => true,
                'message' => $import_result['message'],
                'post_id' => $import_result['post_id'],
                'image_results' => ($import_result['image_results'] ?? null)
            ];
            
            // Conteggia i loghi trasparenti e quelli scontornati
            if (!empty($import_result['image_results'])) {
                if (!empty($place['has_transparent_logo'])) {
                    $results['transparent_logos']++;
                } elseif (!empty($import_result['image_results']['used_remove_bg'])) {
                    $results['removed_bg_logos']++;
                }
            }
        } else {
            $results['errors']++;
            $results['details'][] = [
                'place_id' => $place_id,
                'success' => false,
                'message' => $import_result['message'],
                'image_results' => ($import_result['image_results'] ?? null)
            ];
        }
    }
    
    // Calcola il costo stimato (0.20$ per logo scontornato)
    $total_cost = $results['removed_bg_logos'] * 0.20;
    $results['cost'] = $total_cost;
    $results['saved'] = $results['transparent_logos'] * 0.20;
    
    wp_send_json_success($results);
}

// Registra gli endpoint AJAX
add_action('wp_ajax_discorg_search_google_places', 'discorg_search_google_places_callback');
add_action('wp_ajax_discorg_get_place_details', 'discorg_get_place_details_callback');
add_action('wp_ajax_discorg_import_selected_places', 'discorg_import_selected_places_callback');
