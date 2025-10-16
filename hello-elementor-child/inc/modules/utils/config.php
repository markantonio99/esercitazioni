<?php
/**
 * Discoteche.org - Gestione configurazione
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Gestisce le configurazioni e le opzioni del tema
 */
class Discorg_Config {
    /**
     * Prefisso per tutte le opzioni
     */
    const OPTION_PREFIX = 'discorg_';
    
    /**
     * Chiave per l'API Remove.bg
     */
    const REMOVEBG_API_KEY = 'removebg_api_key';
    
    /**
     * Ottiene una opzione salvata
     *
     * @param string $key Nome dell'opzione senza prefisso
     * @param mixed $default Valore predefinito se l'opzione non esiste
     * @return mixed Valore dell'opzione
     */
    public static function get_option($key, $default = '') {
        return get_option(self::OPTION_PREFIX . $key, $default);
    }
    
    /**
     * Salva una opzione
     *
     * @param string $key Nome dell'opzione senza prefisso
     * @param mixed $value Valore da salvare
     * @return bool True se salvata con successo, false altrimenti
     */
    public static function update_option($key, $value) {
        return update_option(self::OPTION_PREFIX . $key, $value);
    }
    
    /**
     * Elimina una opzione
     *
     * @param string $key Nome dell'opzione senza prefisso
     * @return bool True se eliminata con successo, false altrimenti
     */
    public static function delete_option($key) {
        return delete_option(self::OPTION_PREFIX . $key);
    }
    
    /**
     * Ottiene la chiave API di Remove.bg
     *
     * @return string Chiave API
     */
    public static function get_removebg_api_key() {
        // Se definita come costante in wp-config.php, ha la precedenza
        if (defined('DISCORG_REMOVEBG_API_KEY')) {
            return DISCORG_REMOVEBG_API_KEY;
        }
        
        return self::get_option(self::REMOVEBG_API_KEY, '');
    }
    
    /**
     * Salva la chiave API di Remove.bg
     *
     * @param string $api_key Chiave API
     * @return bool True se salvata con successo, false altrimenti
     */
    public static function set_removebg_api_key($api_key) {
        return self::update_option(self::REMOVEBG_API_KEY, $api_key);
    }
    
    /**
     * Ottiene la chiave API di Google Places
     *
     * @return string Chiave API
     */
    public static function get_google_places_api_key() {
        // Se definita come costante in wp-config.php, ha la precedenza
        if (defined('DISCORG_GOOGLE_PLACES_API_KEY')) {
            return DISCORG_GOOGLE_PLACES_API_KEY;
        }
        return self::get_option('google_places_api_key', '');
    }

    /**
     * Salva la chiave API di Google Places
     *
     * @param string $api_key Chiave API
     * @return bool True se salvata con successo, false altrimenti
     */
    public static function set_google_places_api_key($api_key) {
        return self::update_option('google_places_api_key', $api_key);
    }

    /**
     * Inizializza le opzioni predefinite se necessario
     *
     * @return void
     */
    public static function initialize() {
        // Non impostare default. Le chiavi vengono gestite da wp-config.php o dalla settings page.
        // Manteniamo volutamente vuoto per evitare di committare segreti.
    }
}

// Inizializza le configurazioni
add_action('init', ['Discorg_Config', 'initialize'], 5);
