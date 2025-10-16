<?php
/**
 * Discoteche.org - Pagina impostazioni
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Visualizza la pagina impostazioni
 */
function discorg_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Salva le impostazioni
    if (isset($_POST['discorg_settings_nonce']) && wp_verify_nonce($_POST['discorg_settings_nonce'], 'discorg_save_settings')) {
        $saved = false;

        if (isset($_POST['discorg_removebg_api_key'])) {
            $api_key = sanitize_text_field($_POST['discorg_removebg_api_key']);
            Discorg_Config::set_removebg_api_key($api_key);
            $saved = true;
        }

        // Salva Google Places API Key solo se NON definita come const in wp-config.php
        if (!defined('DISCORG_GOOGLE_PLACES_API_KEY') && isset($_POST['discorg_google_places_api_key'])) {
            $gkey = sanitize_text_field($_POST['discorg_google_places_api_key']);
            Discorg_Config::set_google_places_api_key($gkey);
            $saved = true;
        }

        // OpenAI: salva key se non forzata da costante
        if (!defined('DISCORG_OPENAI_API_KEY') && isset($_POST['discorg_openai_api_key'])) {
            update_option('discorg_openai_api_key', sanitize_text_field($_POST['discorg_openai_api_key']));
            $saved = true;
        }
        // OpenAI: salva modello
        if (isset($_POST['discorg_openai_model'])) {
            $m = sanitize_text_field($_POST['discorg_openai_model']);
            update_option('discorg_openai_model', $m);
            if ($m === 'custom' && isset($_POST['discorg_openai_model_custom'])) {
                update_option('discorg_openai_model_custom', sanitize_text_field($_POST['discorg_openai_model_custom']));
            }
            $saved = true;
        }

        // Test connessione OpenAI (opzionale)
        if (!empty($_POST['discorg_openai_test'])) {
            if (!function_exists('discorg_openai_chat_complete')) {
                require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
            }
            $test = discorg_openai_chat_complete(
                array(
                    array('role'=>'system','content'=>'You are a health check.'),
                    array('role'=>'user','content'=>'ping')
                ),
                null,
                0.0
            );
            if (is_wp_error($test)) {
                echo '<div class="notice notice-error"><p>OpenAI Test: KO — ' . esc_html($test->get_error_message()) . '</p></div>';
            } else {
                // prova a leggere un x-request-id facendo una richiesta list models (senza loggare chiave)
                $headers = wp_remote_retrieve_headers(wp_remote_get('https://api.openai.com/v1/models', array(
                    'headers' => array('Authorization' => 'Bearer ' . (defined('DISCORG_OPENAI_API_KEY') ? DISCORG_OPENAI_API_KEY : get_option('discorg_openai_api_key', '')))
                )));
                $rid = '';
                if (is_array($headers) && isset($headers['x-request-id'])) $rid = $headers['x-request-id'];
                echo '<div class="notice notice-success"><p>OpenAI Test: OK' . ($rid ? ' — request-id: '.esc_html($rid) : '') . '</p></div>';
            }
        }

        if ($saved) {
            echo '<div class="notice notice-success"><p>Impostazioni salvate!</p></div>';
        }
    }
    
    // Recupera le impostazioni attuali
    $remove_bg_key = Discorg_Config::get_removebg_api_key();
    $google_places_key = defined('DISCORG_GOOGLE_PLACES_API_KEY') ? DISCORG_GOOGLE_PLACES_API_KEY : Discorg_Config::get_google_places_api_key();
    
    // Service status
    $bg_removal_available = discorg_background_removal()->is_service_available();

    // Stato OpenAI
    if (!function_exists('discorg_openai_api_key')) {
        @require_once get_stylesheet_directory() . '/inc/modules/ai/ai-config.php';
    }
    $openai_key_defined = defined('DISCORG_OPENAI_API_KEY');
    $openai_key_value   = $openai_key_defined ? DISCORG_OPENAI_API_KEY : get_option('discorg_openai_api_key', '');
    $openai_model       = get_option('discorg_openai_model', 'gpt-4.1-mini');
    $openai_model_custom= get_option('discorg_openai_model_custom', '');
    $openai_connected   = function_exists('discorg_openai_is_connected') ? discorg_openai_is_connected() : false;
    
    ?>
    <div class="wrap">
        <h1>Impostazioni Discoteche.org</h1>
        
        <form method="post">
            <?php wp_nonce_field('discorg_save_settings', 'discorg_settings_nonce'); ?>
            
            <h2>API Keys</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="discorg_removebg_api_key">Remove.bg API Key</label></th>
                    <td>
                        <input type="text" name="discorg_removebg_api_key" id="discorg_removebg_api_key" 
                               value="<?php echo esc_attr($remove_bg_key); ?>" class="regular-text">
                        <p class="description">
                            <?php if ($bg_removal_available): ?>
                                <span style="color: green;">✓</span> Servizio attivo e configurato
                            <?php else: ?>
                                <span style="color: red;">✗</span> Servizio non configurato. 
                                <a href="https://www.remove.bg/api" target="_blank">Ottieni una chiave API</a>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="discorg_google_places_api_key">Google Places API Key</label></th>
                    <td>
                        <?php if (defined('DISCORG_GOOGLE_PLACES_API_KEY')): ?>
                            <p><code><?php echo esc_html(substr($google_places_key, 0, 5) . '...' . substr($google_places_key, -5)); ?></code></p>
                            <p class="description">Configurata tramite costante <code>DISCORG_GOOGLE_PLACES_API_KEY</code> nel file wp-config.php</p>
                        <?php else: ?>
                            <input type="text" name="discorg_google_places_api_key" id="discorg_google_places_api_key"
                                   value="<?php echo esc_attr($google_places_key); ?>" class="regular-text" placeholder="AIza...">
                            <p class="description">Inserisci la tua Google Places API Key. In alternativa, puoi definirla in wp-config.php con <code>define('DISCORG_GOOGLE_PLACES_API_KEY','...');</code></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2>OpenAI</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="discorg_openai_api_key">OpenAI API Key</label></th>
                    <td>
                        <?php if ($openai_key_defined): ?>
                            <input type="password" id="discorg_openai_api_key" value="********" class="regular-text" disabled>
                            <p class="description">Chiave forzata da costante <code>DISCORG_OPENAI_API_KEY</code> in wp-config.php</p>
                        <?php else: ?>
                            <input type="password" name="discorg_openai_api_key" id="discorg_openai_api_key"
                                   value="<?php echo esc_attr($openai_key_value); ?>" class="regular-text" autocomplete="off">
                        <?php endif; ?>
                        <p class="description">
                            Stato: <?php echo $openai_connected ? '<span style="color:green">Connessa</span>' : '<span style="color:red">Non connessa</span>'; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="discorg_openai_model">Modello</label></th>
                    <td>
                        <select name="discorg_openai_model" id="discorg_openai_model">
                            <?php
                            $opts = array('gpt-4.1-mini','gpt-4.1','gpt-4o-mini','o4-mini','custom');
                            foreach ($opts as $opt) {
                                printf('<option value="%s"%s>%s</option>', esc_attr($opt), selected($openai_model, $opt, false), esc_html($opt));
                            }
                            ?>
                        </select>
                        <input type="text" name="discorg_openai_model_custom" id="discorg_openai_model_custom"
                               value="<?php echo esc_attr($openai_model_custom); ?>" class="regular-text" placeholder="es. my-org/my-model">
                        <p class="description">Se scegli “custom”, specifica il nome modello personalizzato.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Test connessione</th>
                    <td>
                        <button class="button" name="discorg_openai_test" value="1">Esegui test</button>
                    </td>
                </tr>
            </table>
            
            <h2>Rimozione Sfondo Automatica</h2>
            <p>
                La rimozione automatica dello sfondo dei loghi utilizza <a href="https://www.remove.bg/" target="_blank">Remove.bg</a>, 
                un servizio basato su intelligenza artificiale che garantisce risultati professionali e di alta qualità.
            </p>
            
            <?php if ($bg_removal_available): ?>
            <div class="notice notice-info inline">
                <p>
                    <strong>Come funziona:</strong> Quando carichi un logo con l'opzione "Scontorno automatico" attivata, 
                    l'immagine viene inviata in modo sicuro al servizio Remove.bg, che rimuove lo sfondo e restituisce 
                    un'immagine PNG con trasparenza. Questo processo è completamente automatico e garantisce risultati 
                    professionali anche con loghi su sfondi complessi.
                </p>
            </div>
            <?php else: ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong>Servizio non attivo:</strong> Per utilizzare la rimozione automatica dello sfondo, 
                    è necessario inserire una chiave API valida di Remove.bg. La vecchia funzione di scontorno automatico 
                    è stata sostituita perché non funzionava correttamente.
                </p>
            </div>
            <?php endif; ?>
            
            <?php submit_button('Salva impostazioni'); ?>
        </form>
        
        <hr>
        
        <h2>Funzioni di Compatibilità</h2>
        <p>
            Questo tema include funzioni di compatibilità che permettono l'utilizzo continuo del codice esistente con la nuova architettura modulare.
            Le vecchie funzioni continuano a funzionare e puntano alla nuova implementazione migliorata.
        </p>
        
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Vecchia funzione</th>
                    <th>Nuova implementazione</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>discorg_process_and_attach()</code></td>
                    <td><code>Discorg_Image_Processor::process_and_attach()</code></td>
                </tr>
                <tr>
                    <td><code>discorg_attach_from_url()</code></td>
                    <td><code>Discorg_Image_Processor::attach_from_url()</code></td>
                </tr>
                <tr>
                    <td><code>discorg_handle_image_upload()</code></td>
                    <td><code>Discorg_Image_Processor::handle_image_upload()</code></td>
                </tr>
                <tr>
                    <td><code>discorg_auto_remove_bg_uniform()</code> (non funzionante)</td>
                    <td><code>Discorg_Background_Removal::remove_background()</code> (integrazione con Remove.bg)</td>
                </tr>
                <tr>
                    <td><code>discorg_populate_acf_and_images()</code></td>
                    <td><code>Discorg_Image_Backfill::populate_acf_and_images()</code></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}
