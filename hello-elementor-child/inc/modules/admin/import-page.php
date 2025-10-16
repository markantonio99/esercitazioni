<?php
/**
 * Discoteche.org - Pagina importazione CSV
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Mostra la pagina di importazione CSV
 */
function discorg_import_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    require_once dirname(dirname(__FILE__)) . '/import/csv-importer.php';
    
    echo '<div class="wrap"><h1>Import Discoteche (CSV)</h1>';
    
    if (!empty($_POST['discorg_import_nonce']) && wp_verify_nonce($_POST['discorg_import_nonce'], 'discorg_import')) {
        if (!empty($_FILES['csv']['tmp_name'])) {
            $importer = new Discorg_CSV_Importer();
            $res = $importer->handle_csv_upload($_FILES['csv']['tmp_name']);
            
            echo '<div class="notice notice-success"><p><strong>Import completato.</strong> Creati: '
                 . intval($res['created']) . ' — Aggiornati: ' . intval($res['updated']) 
                 . ' — Errori: ' . intval($res['errors']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Seleziona un file CSV.</p></div>';
        }
    }
    
    // Istruzioni
    echo '<div class="notice notice-info inline"><p>';
    echo '<strong>Intestazioni supportate</strong> (ordine libero):<br>';
    echo '<code>title,street,city,region,postcode,country,phone,website,lat,lng,logo_url,image_desktop_url,image_mobile_url,sameas,localita_path,excerpt,content</code>';
    echo '</p></div>';
    
    // Formato file
    echo '<h2>Formato del file CSV</h2>';
    echo '<ul class="ul-disc">';
    echo '<li>Il file deve avere una riga di intestazione con i nomi delle colonne</li>';
    echo '<li>I campi devono essere separati da virgole (<code>,</code>)</li>';
    echo '<li>I testi con virgole devono essere racchiusi tra virgolette (<code>"</code>)</li>';
    echo '<li>Il campo <code>title</code> è obbligatorio per ogni riga</li>';
    echo '<li>Il campo <code>localita_path</code> deve contenere il percorso gerarchico della località, separato da pipe (<code>|</code>), es: <code>Lombardia|Milano</code></li>';
    echo '<li>Il campo <code>sameas</code> può contenere più URL separati da pipe (<code>|</code>)</li>';
    echo '</ul>';
    
    // Form di upload
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('discorg_import', 'discorg_import_nonce');
    echo '<input type="file" name="csv" accept=".csv" required> ';
    submit_button('Importa CSV');
    echo '</form>';
    
    // Esempio di file CSV
    echo '<h2>Esempio di riga CSV</h2>';
    echo '<pre style="background:#f0f0f0;padding:10px;overflow:auto;max-height:150px;font-size:12px;">';
    echo 'title,street,city,region,postcode,country,phone,website,lat,lng,logo_url,image_desktop_url,image_mobile_url,sameas,localita_path,excerpt,content' . "\n";
    echo '"Discoteca Esempio","Via Roma 123","Milano","Lombardia","20100","IT","+39 02 12345678","https://esempio.it","45.4654219","9.1859243","https://esempio.it/logo.jpg","https://esempio.it/cover.jpg","https://esempio.it/cover-mobile.jpg","https://facebook.com/esempio|https://instagram.com/esempio","Lombardia|Milano","Breve descrizione della discoteca.","Contenuto completo con <strong>HTML</strong>."';
    echo '</pre>';
    
    echo '</div>';
}
