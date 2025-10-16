<?php
/**
 * Discoteche.org - Registrazione pagine admin
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Classe per la gestione delle pagine di amministrazione
 */
class Discorg_Admin_Pages {
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Ottiene o crea l'istanza singleton
     *
     * @return Discorg_Admin_Pages
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
        // Registra le pagine di amministrazione
        add_action('admin_menu', [$this, 'register_admin_pages']);
        
        // Inizializza gli script e gli stili admin
        add_action('admin_enqueue_scripts', [$this, 'register_admin_scripts']);
    }
    
    /**
     * Registra le pagine di amministrazione
     */
    public function register_admin_pages() {
        // Pagina di importazione CSV
        add_management_page(
            'Import Discoteche',
            'Import Discoteche',
            'manage_options',
            'discorg-import',
            [$this, 'import_page_callback']
        );
        
        // Pagina di caricamento immagini
        add_management_page(
            'Carica immagine discoteca',
            'Carica immagine discoteca',
            'upload_files',
            'discorg-image-uploader',
            [$this, 'image_uploader_page_callback']
        );
        
        // Pagina impostazioni
        add_options_page(
            'Discoteche.org Settings',
            'Discoteche.org',
            'manage_options',
            'discorg-settings',
            [$this, 'settings_page_callback']
        );
    }
    
    /**
     * Callback pagina importazione CSV
     */
    public function import_page_callback() {
        require_once dirname(__FILE__) . '/import-page.php';
        discorg_import_page();
    }
    
    /**
     * Callback pagina caricamento immagini
     */
    public function image_uploader_page_callback() {
        require_once dirname(__FILE__) . '/image-uploader-page.php';
        discorg_image_uploader_page();
    }
    
    /**
     * Callback pagina impostazioni
     */
    public function settings_page_callback() {
        require_once dirname(__FILE__) . '/settings-page.php';
        discorg_settings_page();
    }
    
    /**
     * Registra script e stili per l'admin
     *
     * @param string $hook Hook della pagina corrente
     */
    public function register_admin_scripts($hook) {
        // Script solo per pagine specifiche
        if ($hook !== 'tools_page_discorg-image-uploader' && $hook !== 'tools_page_discorg-import') {
            return;
        }
        
        // Registra e carica gli script
        wp_enqueue_script(
            'discorg-admin',
            get_stylesheet_directory_uri() . '/assets/js/admin/image-uploader.js',
            ['jquery'],
            DISCORG_VERSION,
            true
        );
        
        // Registra e carica gli stili
        wp_enqueue_style(
            'discorg-admin',
            get_stylesheet_directory_uri() . '/assets/css/admin/image-uploader.css',
            [],
            DISCORG_VERSION
        );
        
        // Aggiungi script inline per l'autocomplete
        $this->add_inline_scripts();
    }
    
    /**
     * Aggiunge script inline
     */
    private function add_inline_scripts() {
        $discorg_inline_js = <<<'JS'
jQuery(function($){
  // Bottoni di scelta rapida nella tabella
  $(document).on('click','.discorg-pick', function(e){
    e.preventDefault();
    $('#discorg-post-id').val($(this).data('post'));
    $('#discorg-search').val($(this).data('title'));
    $('input[name="discorg_type"][value="'+$(this).data('type')+'"]').prop('checked',true).trigger('change');
    $('html,body').animate({scrollTop: $('#discorg-img-form').offset().top - 40}, 250);
  });

  function toggleBgRow(){
    var v = $('input[name="discorg_type"]:checked').val();
    $('#discorg-auto-bg-row').toggle(v === 'logo');
  }
  $('input[name="discorg_type"]').on('change', toggleBgRow);
  toggleBgRow();

  // Autocomplete
  var timer=null, $s=$('#discorg-search'), $list=$('#discorg-suggest'), $hid=$('#discorg-post-id'), selCls='discorg-sel';
  function render(items){
    if(!items || !items.length){ $list.html('<li>Nessun risultato</li>').show(); return; }
    var html='';
    items.forEach(function(r){
      var loc=(r.city?' — '+r.city:'')+(r.region?', '+r.region:'');
      html+='<li tabindex="0" data-id="'+r.id+'"><strong>'+r.title+'</strong>'+loc+'</li>';
    });
    $list.html(html).show();
  }
  function query(v){
    $.get(ajaxurl, {action:'discorg_search_discoteche', s:v}, function(resp){
      if(!resp || !resp.success){ $list.hide(); return; }
      render(resp.data);
    });
  }
  $s.on('input', function(){
    clearTimeout(timer);
    var v=$(this).val();
    if(v.length<2){ $list.hide(); return; }
    timer=setTimeout(function(){ query(v); }, 250);
  });
  $list.on('click','li', function(){
    $hid.val($(this).data('id'));
    $s.val($(this).text());
    $list.hide();
  });
  $s.on('keydown', function(e){
    if(!$list.is(':visible')) return;
    var $items=$list.find('li'); if(!$items.length) return;
    var $cur=$items.filter('.'+selCls).first(); var idx=$items.index($cur);
    if(e.key==='ArrowDown'){ e.preventDefault(); idx=(idx+1)%$items.length; $items.removeClass(selCls).eq(idx).addClass(selCls).focus(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); idx=(idx<=0?$items.length-1:idx-1); $items.removeClass(selCls).eq(idx).addClass(selCls).focus(); }
    else if(e.key==='Enter'){ e.preventDefault(); if(idx>=0){ var $it=$items.eq(idx); $hid.val($it.data('id')); $s.val($it.text()); $list.hide(); } }
    else if(e.key==='Escape'){ $list.hide(); }
  });
  $(document).on('click', function(e){
    if(!$(e.target).closest('#discorg-suggest,#discorg-search').length){ $list.hide(); }
  });
});
JS;
        wp_add_inline_script('discorg-admin', $discorg_inline_js);
        
        // Aggiungi stili inline
        $discorg_inline_css = <<<'CSS'
#discorg-suggest li { padding:6px 10px; cursor:pointer; }
#discorg-suggest li:hover { background:#f1f1f1; }
#discorg-suggest li.discorg-sel { background:#e9f5ff; }
CSS;
        wp_add_inline_style('discorg-admin', $discorg_inline_css);
    }
}

/**
 * Helper function per accedere all'istanza
 *
 * @return Discorg_Admin_Pages
 */
function discorg_admin_pages() {
    return Discorg_Admin_Pages::get_instance();
}

// Inizializza la classe
add_action('init', 'discorg_admin_pages', 5);

/**
 * Handler AJAX per la ricerca discoteche
 */
function discorg_search_discoteche_ajax() {
    if (!current_user_can('upload_files')) {
        wp_send_json_error();
    }
    
    $s = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $q = new WP_Query([
        'post_type'      => DISCORG_POST_TYPE,
        's'              => $s,
        'posts_per_page' => 20,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);
    
    $out = [];
    foreach ($q->posts as $pid) {
        $out[] = [
            'id'     => $pid,
            'title'  => html_entity_decode(get_the_title($pid)),
            'city'   => get_post_meta($pid, 'city', true),
            'region' => get_post_meta($pid, 'region', true),
            'edit'   => get_edit_post_link($pid),
        ];
    }
    
    wp_send_json_success($out);
}
add_action('wp_ajax_discorg_search_discoteche', 'discorg_search_discoteche_ajax');
