<?php
/**
 * Discoteche.org - Rimozione sfondo immagini
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per la rimozione dello sfondo delle immagini
 */
class Discorg_Background_Removal {
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * API endpoint
     */
    const API_ENDPOINT = 'https://api.remove.bg/v1.0/removebg';
    
    /**
     * Attiva il fallback in caso di errori
     */
    private $fallback_enabled = true;
    
    /**
     * Ottiene o crea l'istanza singleton
     *
     * @return Discorg_Background_Removal
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Ottiene la chiave API
     *
     * @return string
     */
    private function get_api_key() {
        return Discorg_Config::get_removebg_api_key();
    }
    
    /**
     * Verifica se il servizio è configurato e disponibile
     *
     * @return bool
     */
    public function is_service_available() {
        return !empty($this->get_api_key());
    }
    
    /**
     * Rimuove lo sfondo da un'immagine
     *
     * @param string $file_path Percorso del file immagine
     * @param array $options Opzioni aggiuntive
     * @return array Array con 'success', 'file_path' e 'error'
     */
    public function remove_background($file_path, $options = []) {
        // Verifica che la chiave API sia disponibile
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return $this->handle_fallback($file_path, 'API key non configurata');
        }
        
        // Verifica che il file esista
        if (!file_exists($file_path)) {
            return [
                'success' => false,
                'file_path' => '',
                'error' => 'File non trovato: ' . $file_path
            ];
        }
        
        // Opzioni di default
        $defaults = [
            'size' => 'auto',
            'type' => 'auto',
            'format' => 'png',
            'bg_color' => '',
            'channels' => 'rgba'
        ];
        
        // Unisci le opzioni
        $options = array_merge($defaults, $options);
        
        // Prepara la richiesta API
        $result = $this->make_api_request($file_path, $options, $api_key);
        
        // Log della richiesta per debug
        error_log('Remove.bg API response: ' . json_encode([
            'status' => $result['status_code'],
            'error' => $result['error'],
            'size' => filesize($file_path)
        ]));
        
        // Gestisce la risposta
        if ($result['status_code'] !== 200) {
            return $this->handle_fallback($file_path, 'API error: ' . $result['status_code'] . ' - ' . $result['error']);
        }
        
        // Salva l'immagine elaborata
        $uploads = wp_upload_dir();
        $output_path = trailingslashit($uploads['path']) . 'discorg-nobg-' . wp_generate_uuid4() . '.png';
        
        $saved = file_put_contents($output_path, $result['body']);
        if ($saved === false) {
            return $this->handle_fallback($file_path, 'Errore nel salvare il file elaborato');
        }
        
        return [
            'success' => true,
            'file_path' => $output_path,
            'error' => ''
        ];
    }
    
    /**
     * Effettua la richiesta API
     *
     * @param string $file_path Percorso del file
     * @param array $options Opzioni API
     * @param string $api_key Chiave API
     * @return array Risultato con status_code, body ed error
     */
    private function make_api_request($file_path, $options, $api_key) {
        if (!function_exists('curl_init')) {
            return [
                'status_code' => 500,
                'body' => '',
                'error' => 'cURL non disponibile'
            ];
        }
        
        try {
            $ch = curl_init();
            
            // Prepara i dati per la richiesta
            $post_fields = [
                'image_file' => new CURLFile($file_path)
            ];
            
            // Aggiungi tutte le opzioni
            foreach ($options as $key => $value) {
                if (!empty($value)) {
                    $post_fields[$key] = $value;
                }
            }
            
            // Configura cURL
            curl_setopt_array($ch, [
                CURLOPT_URL => self::API_ENDPOINT,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => $post_fields,
                CURLOPT_HTTPHEADER => [
                    'X-Api-Key: ' . $api_key,
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FAILONERROR => false
            ]);
            
            // Esegui la richiesta
            $response_body = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = '';
            
            // Gestisci errori
            if ($response_body === false) {
                $error = curl_error($ch);
            } elseif ($status_code !== 200) {
                // Prova a parsificare l'errore se è in formato JSON
                $error_data = json_decode($response_body, true);
                if (is_array($error_data) && isset($error_data['errors'])) {
                    $error = implode(', ', array_column($error_data['errors'], 'title'));
                } else {
                    $error = 'Errore del servizio Remove.bg';
                }
            }
            
            curl_close($ch);
            
            return [
                'status_code' => $status_code,
                'body' => $response_body,
                'error' => $error
            ];
        } catch (Exception $e) {
            return [
                'status_code' => 500,
                'body' => '',
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Gestione del fallback quando l'API non è disponibile
     *
     * @param string $file_path Percorso del file originale
     * @param string $error_msg Messaggio di errore
     * @return array Risultato con file originale o errore
     */
    private function handle_fallback($file_path, $error_msg) {
        error_log('Discorg Remove.bg fallback: ' . $error_msg);
        
        if ($this->fallback_enabled) {
            // Ritorna il file originale come fallback
            return [
                'success' => false,
                'file_path' => $file_path, // Usa il file originale
                'error' => $error_msg,
                'fallback' => true
            ];
        } else {
            // Non usa fallback, ritorna solo l'errore
            return [
                'success' => false,
                'file_path' => '',
                'error' => $error_msg,
                'fallback' => false
            ];
        }
    }
    
    /**
     * Attiva/disattiva il fallback
     *
     * @param bool $enabled True per attivare, false per disattivare
     * @return void
     */
    public function set_fallback($enabled) {
        $this->fallback_enabled = (bool) $enabled;
    }
}

/**
 * Helper function per accedere all'istanza
 *
 * @return Discorg_Background_Removal
 */
function discorg_background_removal() {
    return Discorg_Background_Removal::get_instance();
}
