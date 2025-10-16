<?php
/**
 * Discoteche.org - ACF Mapper
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per il mapping dei dati da Google Places a ACF Fields
 */
class Discorg_ACF_Mapper {
    
    /**
     * Effettua il mapping da Google Places ai campi ACF
     *
     * @param int $post_id ID del post
     * @param array $place Dati del posto da Google Places
     * @return bool Successo dell'operazione
     */
    public function map_google_place_to_acf($post_id, $place) {
        // 1. venue_phone → phone_number da Places API
        if (!empty($place['formatted_phone_number'])) {
            discorg_update_acf($post_id, 'venue_phone', $place['formatted_phone_number']);
        }
        
        // 2. venue_website → website da Places API
        if (!empty($place['website'])) {
            discorg_update_acf($post_id, 'venue_website', $place['website']);
        }
        
        // 3. venue_address → formatted_address da Places API
        $address = [];
        
        // Estrai componenti indirizzo
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
                    
                    $address['street'] = $street;
                } elseif (in_array('locality', $component['types'])) {
                    $address['city'] = $component['long_name'];
                } elseif (in_array('administrative_area_level_1', $component['types'])) {
                    $address['region'] = $component['long_name'];
                } elseif (in_array('postal_code', $component['types'])) {
                    $address['postcode'] = $component['long_name'];
                } elseif (in_array('country', $component['types'])) {
                    $address['country'] = $component['short_name'];
                }
            }
        }
        
        if (!empty($address)) {
            discorg_update_acf($post_id, 'venue_address', $address);
        }
        
        // 4. venue_geo_lat → geometry.location.lat da Places API
        if (!empty($place['geometry']['location']['lat'])) {
            discorg_update_acf($post_id, 'venue_geo_lat', $place['geometry']['location']['lat']);
        }
        
        // 5. venue_geo_lng → geometry.location.lng da Places API
        if (!empty($place['geometry']['location']['lng'])) {
            discorg_update_acf($post_id, 'venue_geo_lng', $place['geometry']['location']['lng']);
        }
        
        // 6. venue_sameas → URL profili social (usa helper repeater per ACF)
        $urls = [];
        
        // Website (se presente)
        if (!empty($place['website'])) {
            $urls[] = $place['website'];
        }
        
        // Prova a dedurre i profili social di base dal nome/website
        if (!empty($place['website'])) {
            $domain = strtolower(parse_url($place['website'], PHP_URL_HOST));
            
            // Se il dominio non è già facebook, aggiungi un handle "dedotto"
            if ($domain && strpos($domain, 'facebook.com') === false) {
                $fb_handle = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($place['name']));
                $urls[] = 'https://www.facebook.com/' . $fb_handle;
            }
            
            // Se il dominio non è già instagram, aggiungi un handle "dedotto"
            if ($domain && strpos($domain, 'instagram.com') === false) {
                $ig_handle = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($place['name']));
                $urls[] = 'https://www.instagram.com/' . $ig_handle;
            }
        }
        
        // Filtra eventuali URL Google Maps per non mostrarli come "Profili ufficiali"
        $urls = array_values(array_filter($urls, function($u) {
            $host = parse_url($u, PHP_URL_HOST);
            if (!$host) return false;
            $host = strtolower($host);
            if (strpos($host, 'google.') !== false && (strpos($u, '/maps') !== false || strpos($u, 'cid=') !== false)) return false;
            if ($host === 'goo.gl' || strpos($host, 'maps.app.goo.gl') !== false) return false;
            return true;
        }));
        
        if (!empty($urls)) {
            // Usa helper che svuota le righe e inserisce 1 riga per URL (via add_row)
            discorg_set_repeater_urls($post_id, 'venue_sameas', 'url', $urls);
        }
        
        // 7, 8, 9. Le immagini sono gestite dalla classe Discorg_Google_Places_Importer
        // che usa discorg_image_processor()->process_and_attach() per venue_logo, immagine_desktop e immagine_mobile
        
        // Gestisci orari di apertura se disponibili
        if (!empty($place['opening_hours']['weekday_text'])) {
            update_post_meta($post_id, 'opening_hours', $place['opening_hours']['weekday_text']);
        }
        
        // Gestisci prezzo se disponibile (price_level è un numero da 0 a 4)
        if (isset($place['price_level'])) {
            $price_level = (int) $place['price_level'];
            $price_range = '';
            
            switch ($price_level) {
                case 0:
                    $price_range = '€';
                    break;
                case 1:
                    $price_range = '€€';
                    break;
                case 2:
                    $price_range = '€€€';
                    break;
                case 3:
                    $price_range = '€€€€';
                    break;
                case 4:
                    $price_range = '€€€€€';
                    break;
            }
            
            if ($price_range) {
                update_post_meta($post_id, 'price_range', $price_range);
            }
        }
        
        // Gestisci valutazione
        if (!empty($place['rating'])) {
            update_post_meta($post_id, 'rating', $place['rating']);
            
            if (!empty($place['user_ratings_total'])) {
                update_post_meta($post_id, 'ratings_count', $place['user_ratings_total']);
            }
        }
        
        return true;
    }
}
