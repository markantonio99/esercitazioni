<?php
/**
 * Discoteche.org - Pagina importazione manuale da Google Places
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Registra la pagina di amministrazione per l'importazione manuale
 */
function discorg_register_import_manual_page() {
    // Pagina importazione manuale
    add_submenu_page(
        'edit.php?post_type=' . DISCORG_POST_TYPE,
        'Import Manuale da Google',
        'Import Manuale da Google',
        'manage_options',
        'discorg-import-manual',
        'discorg_import_manual_page'
    );
}
add_action('admin_menu', 'discorg_register_import_manual_page');

/**
 * Pagina di amministrazione per l'importazione manuale
 */
function discorg_import_manual_page() {
    // Verifica permessi
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Carica script e stili
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    wp_enqueue_script('jquery-ui-progressbar');
    wp_enqueue_script('jquery-ui-dialog'); // Aggiungi jquery-ui-dialog
    wp_enqueue_style('wp-jquery-ui-dialog');
    wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css'); // CSS esterno per UI completo
    
    // Ottieni la mappa delle regioni e città italiane
    $importer = discorg_google_places_importer();
    $regions_cities = $importer->get_regions_cities_map();
    
    // Aggiungi markup HTML diretto per le città (evita problemi di codifica JSON)
    $city_options_html = [];
    foreach ($regions_cities as $region => $cities) {
        $region_slug = sanitize_title($region);
        $city_options_html[$region_slug] = '';
        foreach ($cities as $city) {
            $city_options_html[$region_slug] .= '<option value="' . esc_attr($city) . '">' . esc_html($city) . '</option>';
        }
    }
    
    // Genera nonce
    $search_nonce = wp_create_nonce('discorg_google_places_search');
    $details_nonce = wp_create_nonce('discorg_google_places_details');
    $import_nonce = wp_create_nonce('discorg_google_places_import');
    
    // Verifica se API key è configurata (costante o opzione tema)
    $has_api_key = ( defined('DISCORG_GOOGLE_PLACES_API_KEY') && !empty(DISCORG_GOOGLE_PLACES_API_KEY) )
        || ( !empty(Discorg_Config::get_google_places_api_key()) );
    $has_remove_bg = discorg_background_removal()->is_service_available();
    
    // Interfaccia
    ?>
    <div class="wrap">
        <h1>Import Manuale Discoteche da Google Places</h1>
        
        <?php if (!$has_api_key): ?>
        <div class="notice notice-error">
            <p><strong>API Key di Google Places non configurata!</strong> L'importazione non funzionerà correttamente. 
            Configura la costante <code>DISCORG_GOOGLE_PLACES_API_KEY</code> nel file wp-config.php.</p>
        </div>
        <?php endif; ?>
        
        <?php if (!$has_remove_bg): ?>
        <div class="notice notice-warning">
            <p><strong>API Key di Remove.bg non configurata!</strong> Lo scontorno automatico dei loghi non sarà disponibile.
            <a href="<?php echo admin_url('options-general.php?page=discorg-settings'); ?>">Configurala qui</a>.</p>
        </div>
        <?php endif; ?>
        
        <div id="discorg-import-tabs">
            <ul>
                <li><a href="#tab-step1">Step 1: Selezione Geografica</a></li>
                <li><a href="#tab-step2">Step 2: Ricerca e Anteprima</a></li>
                <li><a href="#tab-step3">Step 3: Selezione Manuale</a></li>
                <li><a href="#tab-step4">Step 4: Import</a></li>
            </ul>
            
            <!-- STEP 1: Selezione Geografica -->
            <div id="tab-step1">
                <h2>Selezione Geografica</h2>
                <p>Seleziona la regione e la città per cui vuoi importare le discoteche:</p>
                
                <div class="form-field">
                    <label for="region-select">Regione:</label>
                    <select id="region-select" name="region">
                        <option value="">-- Seleziona Regione --</option>
                        <?php foreach (array_keys($regions_cities) as $region): ?>
                        <option value="<?php echo esc_attr($region); ?>"><?php echo esc_html($region); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-field">
                    <label for="city-select">Città:</label>
                    <select id="city-select" name="city" disabled>
                        <option value="">-- Seleziona prima una regione --</option>
                    </select>
                </div>
                
                <p class="submit">
                    <button id="go-to-step2" class="button button-primary" disabled>Avanti</button>
                </p>
            </div>
            
            <!-- STEP 2: Ricerca e Anteprima -->
            <div id="tab-step2">
                <h2>Ricerca e Anteprima</h2>
                <p>Ricerca discoteche nella località selezionata:</p>
                
                <div class="form-field">
                    <strong>Località selezionata:</strong> <span id="selected-location">Nessuna selezione</span>
                </div>
                
                <p class="submit">
                    <button id="search-places" class="button button-primary">Cerca Discoteche</button>
                </p>
                
                <div id="search-results" style="display: none;">
                    <h3>Risultati della ricerca</h3>
                    <div id="search-results-loading" style="display: none;">
                        <p><img src="<?php echo admin_url('images/spinner.gif'); ?>" /> Ricerca in corso...</p>
                    </div>
                    
                    <div id="search-results-container">
                        <p>Risultati trovati: <span id="results-count">0</span></p>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all-results"></th>
                                    <th>Nome</th>
                                    <th>Indirizzo</th>
                                    <th>Rating</th>
                                    <th>Stato Logo</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody id="results-table">
                                <!-- I risultati saranno aggiunti qui dinamicamente -->
                            </tbody>
                        </table>
                        
                        <p class="submit">
                            <button id="go-to-step3" class="button button-primary" disabled>Continua con la Selezione</button>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- STEP 3: Selezione Manuale -->
            <div id="tab-step3">
                <h2>Selezione Manuale</h2>
                <p>Seleziona le discoteche da importare:</p>
                
                <div class="selection-tools">
                    <button id="select-all-places" class="button">Seleziona Tutti</button>
                    <button id="deselect-all-places" class="button">Deseleziona Tutti</button>
                </div>
                
                <div id="cost-estimate" class="notice notice-info inline">
                    <h3>Costo Stimato</h3>
                    <ul>
                        <li><strong>Loghi trasparenti:</strong> <span id="transparent-logos-count">0</span> (gratis)</li>
                        <li><strong>Loghi da scontornare:</strong> <span id="logos-to-process-count">0</span> ($<span id="logos-cost">0.00</span> totale)</li>
                        <li><strong>Totale costo stimato:</strong> $<span id="total-cost">0.00</span></li>
                    </ul>
                </div>
                
                <div id="selected-places-container">
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select-all-selected"></th>
                                <th>Nome</th>
                                <th>Indirizzo</th>
                                <th>Rating</th>
                                <th>Stato Logo</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="selected-places-table">
                            <!-- Le discoteche selezionate saranno aggiunte qui dinamicamente -->
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <button id="go-to-step4" class="button button-primary" disabled>Procedi con l'Import</button>
                    </p>
                </div>
            </div>
            
            <!-- STEP 4: Import -->
            <div id="tab-step4">
                <h2>Import con Mapping ACF Completo</h2>
                <p>Procedi con l'importazione delle discoteche selezionate:</p>
                
                <div id="import-progress">
                    <div class="import-status">
                        <p>Importazione <span id="current-import">0</span> di <span id="total-import">0</span> discoteche...</p>
                        <p>Discoteca corrente: <strong id="current-venue">-</strong></p>
                    </div>
                    
                    <div id="progress-bar"></div>
                    
                    <div id="import-log" style="max-height: 200px; overflow-y: scroll; margin-top: 20px; border: 1px solid #ddd; padding: 10px; display: none;">
                        <h4>Log di importazione:</h4>
                        <ul id="import-log-items">
                            <!-- Log di importazione -->
                        </ul>
                    </div>
                </div>
                
                <div id="import-results" style="display: none;">
                    <h3>Importazione Completata</h3>
                    
                    <div class="notice notice-success inline">
                        <h4>Risultati:</h4>
                        <ul>
                            <li><strong>Discoteche importate con successo:</strong> <span id="imported-count">0</span></li>
                            <li><strong>Duplicate saltate:</strong> <span id="duplicates-count">0</span></li>
                            <li><strong>Errori:</strong> <span id="errors-count">0</span></li>
                        </ul>
                        
                        <h4>Utilizzo API:</h4>
                        <ul>
                            <li><strong>Loghi trasparenti usati:</strong> <span id="final-transparent-logos">0</span> (risparmiati $<span id="saved-cost">0.00</span>)</li>
                            <li><strong>Loghi scontornati:</strong> <span id="final-removed-bg-logos">0</span> (costo $<span id="final-cost">0.00</span>)</li>
                        </ul>
                    </div>
                    
                    <div class="submit">
                        <button id="start-new-import" class="button button-primary">Inizia Nuova Importazione</button>
                    </div>
                </div>
                
                <div id="import-start" class="submit">
                    <button id="start-import" class="button button-primary">Inizia Importazione</button>
                </div>
            </div>
        </div>
        
        <!-- Modal per i dettagli del posto -->
        <div id="place-details-modal" style="display: none;">
            <div id="place-details-content">
                <!-- Contenuto del modal -->
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Variabili globali
        var selectedRegion = '';
        var selectedCity = '';
        var searchResults = [];
        var selectedPlaces = [];
        var importQueue = [];
        var currentImportIndex = 0;
        
        // Inizializza tabs
        $('#discorg-import-tabs').tabs({
            disabled: [1, 2, 3],
            activate: function(event, ui) {
                // Aggiorna UI quando si cambia tab
                if (ui.newPanel.attr('id') === 'tab-step2') {
                    $('#selected-location').text(selectedRegion + ' › ' + selectedCity);
                }
                else if (ui.newPanel.attr('id') === 'tab-step3') {
                    updateCostEstimate();
                }
            }
        });
        
        // Inizializza dialog per i dettagli
        $('#place-details-modal').dialog({
            autoOpen: false,
            modal: true,
            width: 600,
            height: 500,
            title: 'Dettagli Discoteca',
            buttons: {
                Chiudi: function() {
                    $(this).dialog('close');
                }
            }
        });
        
        // Inizializza progress bar
        $('#progress-bar').progressbar({ value: 0 });
        
        // Event handler per cambio regione
        $('#region-select').on('change', function() {
            selectedRegion = $(this).val();
            
            // Reset città
            $('#city-select').empty().append('<option value="">-- Seleziona Città --</option>');
            
            if (selectedRegion) {
                // Ottieni lo slug della regione per cercare nelle options pregenerate
                var regionSlug = selectedRegion.toLowerCase()
                    .replace(/\s+/g, '-')           // Sostituisci spazi con trattini
                    .replace(/[^\w\-]+/g, '')       // Rimuovi caratteri speciali
                    .replace(/\-\-+/g, '-')         // Rimuovi trattini multipli
                    .replace(/^-+/, '')             // Rimuovi trattini iniziali
                    .replace(/-+$/, '');            // Rimuovi trattini finali
                
                // Usa l'HTML precompilato per le città di questa regione
                var cityOptionsHTML = <?php echo json_encode($city_options_html, JSON_UNESCAPED_UNICODE); ?>[regionSlug] || '';
                $('#city-select').append(cityOptionsHTML);
                
                // Logging per debug
                console.log("Regione selezionata:", selectedRegion);
                console.log("Slug regione:", regionSlug);
                console.log("HTML città:", cityOptionsHTML.substring(0, 100) + "...");
                
                // Abilita select città
                $('#city-select').prop('disabled', false);
                
                // Se c'è solo una città, selezionala automaticamente
                if ($('#city-select option').length === 2) { // Default + 1 città
                    $('#city-select option:last').prop('selected', true);
                    $('#city-select').trigger('change');
                }
            } else {
                // Disabilita select città
                $('#city-select').prop('disabled', true);
                $('#go-to-step2').prop('disabled', true);
            }
        });
        
        // Event handler per cambio città
        $('#city-select').on('change', function() {
            selectedCity = $(this).val();
            $('#go-to-step2').prop('disabled', !selectedCity);
        });
        
        // Event handler per passare allo step 2
        $('#go-to-step2').on('click', function() {
            $('#discorg-import-tabs').tabs('enable', 1).tabs('option', 'active', 1);
        });
        
        // Event handler per la ricerca
        $('#search-places').on('click', function() {
            // Mostra loader
            $('#search-results-loading').show();
            $('#search-results').show();
            
            // Reset tabella
            $('#results-table').empty();
            $('#results-count').text('0');
            
            // Esegui ricerca AJAX
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'discorg_search_google_places',
                    nonce: '<?php echo $search_nonce; ?>',
                    city: selectedCity,
                    region: selectedRegion
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        searchResults = response.data.places;
                        $('#results-count').text(searchResults.length);
                        
                        // Popola tabella risultati
                        $.each(searchResults, function(i, place) {
                            var logoStatus = place.has_transparent_logo ?
                                '<span style="color:green;font-weight:bold">✅ Trasparente</span>' :
                                '<span style="color:orange;font-weight:bold">⚠️ Da scontornare ($0.20)</span>';
                            
                            var row = '<tr data-place-id="' + place.place_id + '">' +
                                '<td><input type="checkbox" class="select-place" data-place-id="' + place.place_id + '" data-transparent="' + (place.has_transparent_logo ? '1' : '0') + '"></td>' +
                                '<td>' + place.name + '</td>' +
                                '<td>' + place.formatted_address + '</td>' +
                                '<td>' + (place.rating ? place.rating + ' (' + place.user_ratings_total + ')' : '-') + '</td>' +
                                '<td>' + logoStatus + '</td>' +
                                '<td>' +
                                    '<button class="button preview-place" data-place-id="' + place.place_id + '">Anteprima</button> ' +
                                    '<button class="button details-place" data-place-id="' + place.place_id + '">Dettagli</button>' +
                                '</td>' +
                            '</tr>';
                            
                            $('#results-table').append(row);
                        });
                        
                        // Abilita pulsante per step 3
                        $('#go-to-step3').prop('disabled', false);
                    } else {
                        alert('Errore nella ricerca: ' + response.data.message);
                    }
                    
                    // Nascondi loader
                    $('#search-results-loading').hide();
                },
                error: function() {
                    alert('Errore nella connessione al server');
                    $('#search-results-loading').hide();
                }
            });
        });
        
        // Event handler per selezionare tutti i risultati
        $('#select-all-results').on('change', function() {
            $('.select-place').prop('checked', $(this).is(':checked'));
        });
        
        // Event handler per l'anteprima
        $(document).on('click', '.preview-place', function() {
            var placeId = $(this).data('place-id');
            var place = searchResults.find(function(p) { return p.place_id === placeId; });
            
            if (place) {
                var content = '<div class="place-preview">' +
                    '<h2>' + place.name + '</h2>' +
                    '<p><strong>Indirizzo:</strong> ' + place.formatted_address + '</p>' +
                    '<p><strong>Rating:</strong> ' + (place.rating || '-') + '</p>' +
                        (place.photos && place.photos.length ? 
                        '<img src="https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference=' + place.photos[0].photo_reference + '&key=<?php echo esc_attr(discorg_google_places_api_key()); ?>" alt="Preview">' : 
                        '<p>Nessuna foto disponibile</p>') +
                    '</div>';
                
                $('#place-details-content').html(content);
                $('#place-details-modal').dialog('option', 'title', 'Anteprima: ' + place.name).dialog('open');
            }
        });
        
        // Event handler per i dettagli
        $(document).on('click', '.details-place', function() {
            var placeId = $(this).data('place-id');
            
            $('#place-details-content').html('<p><img src="<?php echo admin_url('images/spinner.gif'); ?>" /> Caricamento dettagli...</p>');
            $('#place-details-modal').dialog('option', 'title', 'Dettagli...').dialog('open');
            
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'discorg_get_place_details',
                    nonce: '<?php echo $details_nonce; ?>',
                    place_id: placeId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var place = response.data.place;
                        var content = '<div class="place-details">' +
                            '<h2>' + place.name + '</h2>' +
                            '<p><strong>Indirizzo:</strong> ' + place.formatted_address + '</p>' +
                            '<p><strong>Telefono:</strong> ' + (place.formatted_phone_number || '-') + '</p>' +
                            '<p><strong>Website:</strong> ' + (place.website ? '<a href="' + place.website + '" target="_blank">' + place.website + '</a>' : '-') + '</p>' +
                            '<p><strong>Rating:</strong> ' + (place.rating ? place.rating + ' (' + place.user_ratings_total + ' recensioni)' : '-') + '</p>';
                            
                        if (place.opening_hours && place.opening_hours.weekday_text) {
                            content += '<p><strong>Orari di apertura:</strong></p><ul>';
                            $.each(place.opening_hours.weekday_text, function(i, day) {
                                content += '<li>' + day + '</li>';
                            });
                            content += '</ul>';
                        }
                        
                        if (place.photos && place.photos.length) {
                            content += '<p><strong>Foto:</strong></p>';
                            content += '<img src="https://maps.googleapis.com/maps/api/place/photo?maxwidth=500&photoreference=' + place.photos[0].photo_reference + '&key=<?php echo esc_attr(discorg_google_places_api_key()); ?>" alt="Photo">';
                        }
                        
                        content += '</div>';
                        
                        $('#place-details-content').html(content);
                        $('#place-details-modal').dialog('option', 'title', 'Dettagli: ' + place.name);
                    } else {
                        $('#place-details-content').html('<p>Errore nel caricamento dei dettagli: ' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    $('#place-details-content').html('<p>Errore nella connessione al server</p>');
                }
            });
        });
        
        // Event handler per passare allo step 3
        $('#go-to-step3').on('click', function() {
            // Ottieni le discoteche selezionate
            selectedPlaces = [];
            
            $('.select-place:checked').each(function() {
                var placeId = $(this).data('place-id');
                var isTransparent = $(this).data('transparent') == 1;
                
                var place = searchResults.find(function(p) { return p.place_id === placeId; });
                if (place) {
                    place.is_selected = true;
                    place.has_transparent_logo = isTransparent;
                    selectedPlaces.push(place);
                }
            });
            
            // Popola tabella discoteche selezionate
            $('#selected-places-table').empty();
            
            $.each(selectedPlaces, function(i, place) {
                var logoStatus = place.has_transparent_logo ?
                    '<span style="color:green;font-weight:bold">✅ Trasparente</span>' :
                    '<span style="color:orange;font-weight:bold">⚠️ Da scontornare ($0.20)</span>';
                
                var row = '<tr data-place-id="' + place.place_id + '">' +
                    '<td><input type="checkbox" class="selected-place" checked data-place-id="' + place.place_id + '" data-transparent="' + (place.has_transparent_logo ? '1' : '0') + '"></td>' +
                    '<td>' + place.name + '</td>' +
                    '<td>' + place.formatted_address + '</td>' +
                    '<td>' + (place.rating ? place.rating + ' (' + place.user_ratings_total + ')' : '-') + '</td>' +
                    '<td>' + logoStatus + '</td>' +
                    '<td>' +
                        '<button class="button preview-place" data-place-id="' + place.place_id + '">Anteprima</button> ' +
                        '<button class="button details-place" data-place-id="' + place.place_id + '">Dettagli</button>' +
                    '</td>' +
                '</tr>';
                
                $('#selected-places-table').append(row);
            });
            
            // Aggiorna conteggio costi
            updateCostEstimate();
            
            // Abilita pulsante per step 4 se ci sono discoteche selezionate
            $('#go-to-step4').prop('disabled', selectedPlaces.length === 0);
            
            // Abilita e vai al tab successivo
            $('#discorg-import-tabs').tabs('enable', 2).tabs('option', 'active', 2);
        });
        
        // Funzione per aggiornare il costo stimato
        function updateCostEstimate() {
            var transparentCount = 0;
            var needRemoveBgCount = 0;
            
            selectedPlaces.forEach(function(place) {
                if (place.has_transparent_logo) {
                    transparentCount++;
                } else {
                    needRemoveBgCount++;
                }
            });
            
            var logosCost = needRemoveBgCount * 0.20;
            var totalCost = logosCost.toFixed(2);
            
            $('#transparent-logos-count').text(transparentCount);
            $('#logos-to-process-count').text(needRemoveBgCount);
            $('#logos-cost').text(logosCost.toFixed(2));
            $('#total-cost').text(totalCost);
        }
        
        // Event handler per selezionare/deselezionare tutto
        $('#select-all-places').on('click', function() {
            $('.selected-place').prop('checked', true);
        });
        
        $('#deselect-all-places').on('click', function() {
            $('.selected-place').prop('checked', false);
        });
        
        // Event handler per il cambio di selezione
        $(document).on('change', '.selected-place', function() {
            // Abilita pulsante per step 4 se c'è almeno una discoteca selezionata
            var hasSelected = $('.selected-place:checked').length > 0;
            $('#go-to-step4').prop('disabled', !hasSelected);
        });
        
        // Event handler per passare allo step 4
        $('#go-to-step4').on('click', function() {
            // Prepara la coda di importazione
            importQueue = [];
            
            $('.selected-place:checked').each(function() {
                var placeId = $(this).data('place-id');
                importQueue.push(placeId);
            });
            
            // Aggiorna UI per step 4
            $('#total-import').text(importQueue.length);
            $('#current-import').text('0');
            $('#current-venue').text('-');
            
            // Reset progress bar
            $('#progress-bar').progressbar('value', 0);
            
            // Mostra log e nascondi risultati
            $('#import-log').show();
            $('#import-results').hide();
            $('#import-start').show();
            
            // Abilita e vai al tab successivo
            $('#discorg-import-tabs').tabs('enable', 3).tabs('option', 'active', 3);
        });
        
        // Event handler per iniziare l'importazione
        $('#start-import').on('click', function() {
            // Nascondi pulsante start
            $('#import-start').hide();
            
            // Reset log
            $('#import-log-items').empty();
            
            // Reset contatori
            currentImportIndex = 0;
            
            // Inizia importazione
            importNext();
        });
        
        // Funzione per importare il posto successivo
        function importNext() {
            if (currentImportIndex >= importQueue.length) {
                // Importazione completata
                importCompleted();
                return;
            }
            
            // Aggiorna UI
            var placeId = importQueue[currentImportIndex];
            var place = selectedPlaces.find(function(p) { return p.place_id === placeId; });
            
            $('#current-import').text(currentImportIndex + 1);
            $('#current-venue').text(place ? place.name : placeId);
            
            // Aggiorna progress bar
            var progress = Math.floor((currentImportIndex / importQueue.length) * 100);
            $('#progress-bar').progressbar('value', progress);
            
            // Aggiungi al log
            $('#import-log-items').append('<li>Importazione <strong>' + (place ? place.name : placeId) + '</strong>...</li>');
            
            // Importa il posto
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'discorg_import_selected_places',
                    nonce: '<?php echo $import_nonce; ?>',
                    places: JSON.stringify([placeId]),
                    city: selectedCity,
                    region: selectedRegion
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Aggiorna log
                        var result = response.data;
                        var lastItem = $('#import-log-items li:last');
                        // DEBUG A SCHERMO: mostra esito caricamento immagini per il place corrente
                        try {
                            if (result && result.details && result.details.length) {
                                var det = result.details[result.details.length - 1];
                                if (det && det.image_results) {
                                    var ir = det.image_results;
                                    var parts = [];
                                    parts.push('logo=' + (ir.logo_processed ? 'OK' : 'NO'));
                                    parts.push('desktop=' + (ir.desktop_processed ? 'OK' : 'NO'));
                                    parts.push('mobile=' + (ir.mobile_processed ? 'OK' : 'NO'));
                                    if (ir.used_remove_bg) parts.push('remove.bg=YES');
                                    if (ir.errors && ir.errors.length) parts.push('errors=' + ir.errors.join(' | '));
                                    $('#import-log-items').append('<li style="color:#555">[DEBUG IMG] ' + parts.join(' · ') + '</li>');
                                    if (Array.isArray(ir.debug) && ir.debug.length) {
                                        ir.debug.forEach(function(line) {
                                            $('#import-log-items').append('<li style="color:#777">[DBG] ' + String(line) + '</li>');
                                        });
                                    }
                                }
                            }
                        } catch(e) {
                            $('#import-log-items').append('<li style="color:#b00">[DEBUG IMG] Eccezione nel parsing debug: ' + e.message + '</li>');
                        }
                        
                        if (result.imported > 0) {
                            lastItem.html(lastItem.html() + ' <span style="color:green">✓</span>');
                        } else if (result.duplicates > 0) {
                            lastItem.html(lastItem.html() + ' <span style="color:orange">⚠️ Duplicato</span>');
                        } else {
                            lastItem.html(lastItem.html() + ' <span style="color:red">❌ Errore</span>');
                        }
                        
                        // Incrementa indice e continua
                        currentImportIndex++;
                        setTimeout(importNext, 1000);
                    } else {
                        // Errore
                        $('#import-log-items').append('<li><span style="color:red">❌ Errore: ' + response.data.message + '</span></li>');
                        
                        // Incrementa indice e continua comunque
                        currentImportIndex++;
                        setTimeout(importNext, 1000);
                    }
                },
                error: function() {
                    // Errore connessione
                    $('#import-log-items').append('<li><span style="color:red">❌ Errore di connessione al server</span></li>');
                    
                    // Incrementa indice e continua comunque
                    currentImportIndex++;
                    setTimeout(importNext, 1000);
                }
            });
        }
        
        // Funzione per completare l'importazione
        function importCompleted() {
            // Aggiorna progress bar al 100%
            $('#progress-bar').progressbar('value', 100);
            
            // Ottieni risultati finali
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'discorg_import_selected_places',
                    nonce: '<?php echo $import_nonce; ?>',
                    places: JSON.stringify([]), // Empty array to get just the summary
                    city: selectedCity,
                    region: selectedRegion
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var result = response.data;
                        
                        // Aggiorna contatori
                        $('#imported-count').text(result.imported);
                        $('#duplicates-count').text(result.duplicates);
                        $('#errors-count').text(result.errors);
                        
                        // Aggiorna costi
                        $('#final-transparent-logos').text(result.transparent_logos);
                        $('#final-removed-bg-logos').text(result.removed_bg_logos);
                        $('#final-cost').text(result.cost.toFixed(2));
                        $('#saved-cost').text(result.saved.toFixed(2));
                        
                        // Mostra risultati
                        $('#import-results').show();
                    }
                }
            });
        }
        
        // Event handler per ricominciare da capo
        $('#start-new-import').on('click', function() {
            // Reset tutti i dati
            selectedRegion = '';
            selectedCity = '';
            searchResults = [];
            selectedPlaces = [];
            importQueue = [];
            currentImportIndex = 0;
            
            // Reset UI
            $('#region-select').val('').trigger('change');
            $('#city-select').empty().append('<option value="">-- Seleziona prima una regione --</option>').prop('disabled', true);
            $('#go-to-step2').prop('disabled', true);
            
            // Torna al primo tab
            $('#discorg-import-tabs').tabs('option', 'disabled', [1, 2, 3]).tabs('option', 'active', 0);
        });
    });
    </script>
    
    <style>
    .form-field {
        margin: 15px 0;
    }
    
    .form-field label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    
    #discorg-import-tabs {
        margin-top: 20px;
    }
    
    #place-details-content img {
        max-width: 100%;
        height: auto;
    }
    
    .selection-tools {
        margin: 15px 0;
    }
    
    #cost-estimate {
        margin: 15px 0;
        padding: 10px 15px;
    }
    
    #progress-bar {
        margin: 15px 0;
        height: 25px;
    }
    
    .ui-progressbar-value {
        background-color: #0073aa;
    }
    
    #import-log {
        margin: 15px 0;
    }
    
    #import-log-items {
        margin: 0;
    }
    
    #import-log-items li {
        margin-bottom: 5px;
    }
    </style>
    <?php
}
