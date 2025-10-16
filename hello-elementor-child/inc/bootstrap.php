<?php
/**
 * Discoteche.org - Bootstrap generale
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

// Percorso base del tema child
$base = get_stylesheet_directory();

// ===== 1. Utility e costanti =====
require_once $base . '/inc/modules/utils/constants.php';
require_once $base . '/inc/modules/utils/config.php';
require_once $base . '/inc/modules/utils/acf-helpers.php';
// ===== 1b. ACF Local Fields ===== (safe include)
if (file_exists($base . '/inc/modules/acf/venue-content-fields.php')) {
    require_once $base . '/inc/modules/acf/venue-content-fields.php';
}

// ===== 2. Moduli immagini =====
require_once $base . '/inc/modules/images/image-processor.php';
require_once $base . '/inc/modules/images/image-backfill.php';
require_once $base . '/inc/modules/images/background-removal.php';

// ===== 3. Moduli importazione =====
require_once $base . '/inc/modules/import/csv-importer.php';
require_once $base . '/inc/modules/import/google-places-importer.php';
require_once $base . '/inc/modules/import/acf-mapper.php';
// ===== 3b. Moduli AI ===== (safe include)
if (file_exists($base . '/inc/modules/ai/ai-config.php')) {
    require_once $base . '/inc/modules/ai/ai-config.php';
}
if (file_exists($base . '/inc/modules/ai/ai-venue-generator.php')) {
    require_once $base . '/inc/modules/ai/ai-venue-generator.php';
}

// ===== 4. Pagine admin =====
if (is_admin()) {
    require_once $base . '/inc/modules/admin/admin-pages.php';
    require_once $base . '/inc/modules/admin/settings-page.php';
    require_once $base . '/inc/modules/admin/import-page.php';
    require_once $base . '/inc/modules/admin/image-uploader-page.php';
    require_once $base . '/inc/modules/admin/import-page-manual.php';
    // Metabox AI (safe include)
    if (file_exists($base . '/inc/modules/admin/ai-venue-metabox.php')) {
        require_once $base . '/inc/modules/admin/ai-venue-metabox.php';
    }
    // ACF inline AI controls (server-side)
    if (file_exists($base . '/inc/modules/admin/acf-inline-ai.php')) {
        require_once $base . '/inc/modules/admin/acf-inline-ai.php';
    }
    // Admin-post fallback (server-side, senza JS)
    if (file_exists($base . '/inc/modules/admin/ai-adminpost.php')) {
        require_once $base . '/inc/modules/admin/ai-adminpost.php';
    }
    
    // Funzioni admin aggiuntive: AJAX, ecc.
}

// ===== 5. Supporto retrocompatibilità =====

// Carica il file di compatibilità che fornisce il ponte tra il vecchio e il nuovo sistema
require_once $base . '/inc/importdiscorg-snippets-compat.php';

// ===== 6. Includi Custom Post Type e tassonomie =====
require_once $base . '/inc/discorg-snippets.php';

// ===== 7. Azioni di inizializzazione =====

/**
 * Carica script e stili per l'admin
 *
 * @param string $hook Hook della pagina corrente
 */
function discorg_enqueue_admin_assets($hook) {
    if ($hook === 'tools_page_discorg-image-uploader' || $hook === 'tools_page_discorg-import') {
        // Crea directory per JavaScript se non esiste
        $js_dir = get_stylesheet_directory() . '/assets/js/admin';
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        
        // Crea file JS se non esiste
        $js_file = $js_dir . '/image-uploader.js';
        if (!file_exists($js_file)) {
            file_put_contents($js_file, '// Discoteche.org admin scripts');
        }
        
        // Registra e carica gli script
        wp_enqueue_script(
            'discorg-admin',
            get_stylesheet_directory_uri() . '/assets/js/admin/image-uploader.js',
            ['jquery'],
            DISCORG_VERSION,
            true
        );
    }

    // Enqueue pulsanti inline AI solo su editor del CPT "discoteche"
    if ($hook === 'post.php' || $hook === 'post-new.php') {
        $pt = '';
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && !empty($screen->post_type)) {
                $pt = $screen->post_type;
            }
        }
        if (!$pt) {
            // Fallback su querystring
            $pt = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';
            if (!$pt && isset($_GET['post'])) {
                $pt = get_post_type((int) $_GET['post']);
            }
        }
        $target_pt = defined('DISCORG_POST_TYPE') ? DISCORG_POST_TYPE : 'discoteche';
        if ($pt === $target_pt) {
            $js_dir = get_stylesheet_directory() . '/assets/js/admin';
            if (!file_exists($js_dir)) {
                wp_mkdir_p($js_dir);
            }
            $acf_inline_js = get_stylesheet_directory() . '/assets/js/admin/acf-inline-ai.js';
            if (!file_exists($acf_inline_js)) {
                // Crea file placeholder se manca
                file_put_contents($acf_inline_js, '/* acf inline ai */');
            }
            $version2 = (file_exists($acf_inline_js) ? filemtime($acf_inline_js) : (defined('DISCORG_VERSION') ? DISCORG_VERSION : time()));
            wp_enqueue_script(
                'discorg-admin-acf-inline-ai',
                get_stylesheet_directory_uri() . '/assets/js/admin/acf-inline-ai.js',
                array('jquery'),
                $version2,
                true
            );
            // Fallback globale per URL admin-ajax in caso 'ajaxurl' non sia definito
            wp_add_inline_script(
                'discorg-admin-acf-inline-ai',
                'window.discorgAjaxURL = window.discorgAjaxURL || "' . admin_url('admin-ajax.php') . '";',
                'before'
            );
            // Debug overlay attivabile via query ?discorg_ai_debug=1
            $dbg = isset($_GET['discorg_ai_debug']) ? strtolower(trim((string) $_GET['discorg_ai_debug'])) : '';
            $dbg_on = in_array($dbg, array('1','true','on','yes'), true);
            if ($dbg_on) {
                // Definisci flag PRIMA del main script (sicuro per i check condizionali)
                wp_add_inline_script('discorg-admin-acf-inline-ai', 'window.DISCORG_AI_DEBUG = true;', 'before');
                // Enqueue script overlay debug
                $acf_dbg_js = get_stylesheet_directory() . '/assets/js/admin/acf-inline-ai-debug.js';
                if (!file_exists($acf_dbg_js)) {
                    file_put_contents($acf_dbg_js, '/* acf inline ai debug */');
                }
                $dbg_ver = file_exists($acf_dbg_js) ? filemtime($acf_dbg_js) : time();
                wp_enqueue_script(
                    'discorg-admin-acf-inline-ai-debug',
                    get_stylesheet_directory_uri() . '/assets/js/admin/acf-inline-ai-debug.js',
                    array('jquery'),
                    $dbg_ver,
                    true
                );
            }
        }
    }
}
add_action('admin_enqueue_scripts', 'discorg_enqueue_admin_assets');

/**
 * Tenta di determinare il post_type corrente in modo robusto (admin/editor)
 */
if (!function_exists('discorg_current_post_type_guess')) {
    function discorg_current_post_type_guess() {
        $pt = '';
        if (function_exists('get_current_screen')) {
            $scr = get_current_screen();
            if ($scr && !empty($scr->post_type)) {
                $pt = $scr->post_type;
            }
        }
        if (!$pt && isset($_GET['post'])) { $pt = get_post_type((int) $_GET['post']); }
        if (!$pt && isset($_GET['post_type'])) { $pt = sanitize_text_field($_GET['post_type']); }
        return $pt;
    }
}

/**
 * Enqueue degli asset AI inline anche nello scope del Block Editor (iframe)
 */
if (!function_exists('discorg_enqueue_block_editor_ai_assets')) {
    function discorg_enqueue_block_editor_ai_assets() {
        $target_pt = defined('DISCORG_POST_TYPE') ? DISCORG_POST_TYPE : 'discoteche';
        $pt = discorg_current_post_type_guess();
        if ($pt !== $target_pt) return;

        $base_uri = get_stylesheet_directory_uri() . '/assets/js/admin';
        $base_dir = get_stylesheet_directory() . '/assets/js/admin';

        // Main AI inline (Block Editor)
        $main = $base_dir . '/acf-inline-ai.js';
        $ver  = file_exists($main) ? filemtime($main) : ( defined('DISCORG_VERSION') ? DISCORG_VERSION : time() );
        wp_enqueue_script('discorg-admin-acf-inline-ai-be', $base_uri . '/acf-inline-ai.js', array('jquery'), $ver, true);

        // Fallback admin-ajax + log di scope
        wp_add_inline_script(
            'discorg-admin-acf-inline-ai-be',
            'window.discorgAjaxURL = window.discorgAjaxURL || "' . admin_url('admin-ajax.php') . '"; console.log("[discorg] scope:block-editor");',
            'before'
        );

        // Debug overlay in Block Editor, se attivato
        $dbg = isset($_GET['discorg_ai_debug']) ? strtolower(trim((string) $_GET['discorg_ai_debug'])) : '';
        $dbg_on = in_array($dbg, array('1','true','on','yes'), true);
        if ($dbg_on) {
            wp_add_inline_script('discorg-admin-acf-inline-ai-be', 'window.DISCORG_AI_DEBUG = true;', 'before');
            $dbg_file = $base_dir . '/acf-inline-ai-debug.js';
            $dbg_ver  = file_exists($dbg_file) ? filemtime($dbg_file) : time();
            wp_enqueue_script('discorg-admin-acf-inline-ai-debug-be', $base_uri . '/acf-inline-ai-debug.js', array('jquery'), $dbg_ver, true);
        }
    }
}
add_action('enqueue_block_editor_assets', 'discorg_enqueue_block_editor_ai_assets');

/**
 * Stampa una nonce globale nascosta per le azioni AI inline
 * - Solo in editor del CPT "discoteche"
 */
if (!function_exists('discorg_ai_print_global_nonce')) {
    function discorg_ai_print_global_nonce() {
        if (!function_exists('get_current_screen')) return;
        $screen = get_current_screen();
        if (!$screen || empty($screen->post_type)) return;
        $target_pt = defined('DISCORG_POST_TYPE') ? DISCORG_POST_TYPE : 'discoteche';
        if ($screen->post_type !== $target_pt) return;
        // Nonce globale; il client la invierà come 'discorg_ai_nonce'
        $nonce = wp_create_nonce('discorg_ai_metabox');
        echo '<input type="hidden" id="discorg_ai_nonce_global" value="' . esc_attr($nonce) . '">';
    }
}
add_action('admin_footer-post.php', 'discorg_ai_print_global_nonce');
add_action('admin_footer-post-new.php', 'discorg_ai_print_global_nonce');

/**
 * Inizializza il tema e i suoi moduli
 */
function discorg_theme_init() {
    // Inizializzazione plugin
}
add_action('init', 'discorg_theme_init', 15);

/**
 * Registra hook di attivazione/disattivazione tema
 */
if (is_admin()) {
    // Hook di attivazione (per creare directory, ecc.)
    add_action('after_switch_theme', 'discorg_theme_activation');
}

/**
 * Eseguito all'attivazione del tema
 */
function discorg_theme_activation() {
    // Crea le directory degli asset se non esistono
    $dirs = [
        get_stylesheet_directory() . '/assets',
        get_stylesheet_directory() . '/assets/js',
        get_stylesheet_directory() . '/assets/js/admin',
        get_stylesheet_directory() . '/assets/css',
        get_stylesheet_directory() . '/assets/css/admin',
    ];
    
    foreach ($dirs as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
    }
    
    // Imposta le opzioni di default
    if (class_exists('Discorg_Config')) {
        Discorg_Config::initialize();
    }
}
