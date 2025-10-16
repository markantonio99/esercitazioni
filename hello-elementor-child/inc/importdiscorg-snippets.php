<?php
// Questo file è un include del tema (NON plugin separato)
if (!defined('ABSPATH')) exit;

// Evita doppio caricamento
if (defined('DISCORG_SNIPPETS_LOADED')) return;
define('DISCORG_SNIPPETS_LOADED', true);

// Costanti solo se non già definite altrove
if (!defined('DISCORG_POST_TYPE'))    define('DISCORG_POST_TYPE', 'discoteche');
if (!defined('DISCORG_TAX_LOCALITA')) define('DISCORG_TAX_LOCALITA', 'localita');
if (!defined('DISCORG_BRAND'))        define('DISCORG_BRAND', 'Discoteche.org');
if (!defined('DISCORG_DEFAULT_IMAGE')) define('DISCORG_DEFAULT_IMAGE', 'https://discoteche.org/wp-content/uploads/brand/placeholder-discoteca.webp');
if (!defined('DISCORG_GOOGLE_PLACES_API_KEY')) define('DISCORG_GOOGLE_PLACES_API_KEY', '');

/* ====================== ADMIN MENU (2 PAGINE) ====================== */
add_action('admin_menu', function () {
    add_management_page(
        'Import Discoteche',
        'Import Discoteche',
        'manage_options',
        'discorg-import',
        'discorg_import_page_cb'
    );
    add_management_page(
        'Carica immagine discoteca',
        'Carica immagine discoteca',
        'upload_files',
        'discorg-image-uploader',
        'discorg_image_uploader_page'
    );
});

/* =========================== PAGINA IMPORT =========================== */
function discorg_import_page_cb() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap"><h1>Import Discoteche (CSV)</h1>';

    if (!empty($_POST['discorg_import_nonce']) && wp_verify_nonce($_POST['discorg_import_nonce'], 'discorg_import')) {
        if (!empty($_FILES['csv']['tmp_name'])) {
            $res = discorg_handle_csv_upload($_FILES['csv']['tmp_name']);
            echo '<div class="notice notice-success"><p><strong>Import completato.</strong> Creati: '
                . intval($res['created']) . ' — Aggiornati: ' . intval($res['updated']) . ' — Errori: ' . intval($res['errors']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Seleziona un file CSV.</p></div>';
        }
    }

    echo '<p><em>Intestazioni supportate (ordine libero):</em><br><code>title,street,city,region,postcode,country,phone,website,lat,lng,logo_url,image_desktop_url,image_mobile_url,sameas,localita_path,excerpt,content</code></p>';

    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('discorg_import', 'discorg_import_nonce');
    echo '<input type="file" name="csv" accept=".csv" required> ';
    submit_button('Importa CSV');
    echo '</form></div>';
}

/* =========================== CORE IMPORT =========================== */
function discorg_handle_csv_upload($tmp_path){
    // Togli BOM se presente
    $raw = file_get_contents($tmp_path);
    if (substr($raw,0,3) === "\xEF\xBB\xBF") file_put_contents($tmp_path, substr($raw,3));

    $fh = fopen($tmp_path, 'r');
    if (!$fh) return ['created'=>0,'updated'=>0,'errors'=>1];

    $headers = fgetcsv($fh, 0, ','); if (!$headers) return ['created'=>0,'updated'=>0,'errors'=>1];
    $headers = array_map(fn($h)=>trim(mb_strtolower($h)), $headers);

    $created=0; $updated=0; $errors=0;

    while (($row = fgetcsv($fh, 0, ',')) !== false) {
        if (count($row) !== count($headers)) continue;
        $data = array_combine($headers, $row);
        $get = function($k,$def='') use ($data){ return isset($data[$k]) ? trim($data[$k]) : $def; };

        $title = $get('title'); $city = $get('city');
        if ($title==='') { $errors++; continue; }

        // Dedup: Titolo + City
        $post = get_page_by_title($title, OBJECT, DISCORG_POST_TYPE);
        if ($post && $city && get_post_meta($post->ID, 'city', true) !== $city) $post = null;

        $postarr = ['post_type'=>DISCORG_POST_TYPE,'post_title'=>$title,'post_status'=>'publish'];
        if (($ex = $get('excerpt'))!=='') $postarr['post_excerpt'] = $ex;
        if (($co = $get('content'))!=='') $postarr['post_content'] = $co;

        if ($post) { $postarr['ID']=$post->ID; $post_id = wp_update_post($postarr, true); $updated++; }
        else       { $post_id = wp_insert_post($postarr, true); $created++; }
        if (is_wp_error($post_id)) { $errors++; continue; }

        // meta base
        foreach (['street','city','region','postcode','country','phone','website','lat','lng','logo_url','image_desktop_url','image_mobile_url','sameas'] as $k){
            $v=$get($k); if ($v!=='') update_post_meta($post_id,$k,$v);
        }

        // tassonomia localita: "Regione|Città" (filtra anomalie numeriche, es. "44")
        $loc_path = $get('localita_path');
        if ($loc_path && taxonomy_exists(DISCORG_TAX_LOCALITA)) {
            $raw = array_map('trim', explode('|', $loc_path));
            $parts = [];
            foreach ($raw as $p) {
                if ($p==='' || preg_match('/^\d+$/', $p) || mb_strlen($p) < 3) continue;
                $p = preg_replace('/\s+/', ' ', $p);
                $parts[] = $p;
            }
            $parent=0; $term_ids=[];
            foreach ($parts as $p){
                $exists = term_exists($p, DISCORG_TAX_LOCALITA, $parent);
                if (!$exists) $exists = wp_insert_term($p, DISCORG_TAX_LOCALITA, ['parent'=>$parent]);
                if (!is_wp_error($exists)) { $parent=$exists['term_id']; $term_ids[]=$exists['term_id']; }
            }
            if ($term_ids) wp_set_object_terms($post_id, $term_ids, DISCORG_TAX_LOCALITA);
        }

        // ACF + immagini
        discorg_populate_acf_and_images($post_id);
    }

    fclose($fh);
    return ['created'=>$created,'updated'=>$updated,'errors'=>$errors];
}

/* ==================== ACF + IMMAGINI PRO (con fallback) ==================== */
function discorg_populate_acf_and_images($post_id){
    // ACF testuali
    if (function_exists('update_field')) {
        $phone   = get_post_meta($post_id,'phone',true);
        $website = get_post_meta($post_id,'website',true);
        if ($phone)   update_field('venue_phone',$phone,$post_id);
        if ($website) update_field('venue_website',$website,$post_id);
        $addr=[]; foreach (['street','city','region','postcode','country'] as $k){ $v=get_post_meta($post_id,$k,true); if ($v!=='') $addr[$k]=$v; }
        if ($addr) update_field('venue_address',$addr,$post_id);
        $lat=get_post_meta($post_id,'lat',true); if ($lat!=='') update_field('venue_geo_lat',$lat,$post_id);
        $lng=get_post_meta($post_id,'lng',true); if ($lng!=='') update_field('venue_geo_lng',$lng,$post_id);
        $sameas=get_post_meta($post_id,'sameas',true);
        if ($sameas){ $urls=array_filter(array_map('trim',explode('|',$sameas))); $rows=[]; foreach($urls as $u){ $rows[]=['url'=>esc_url_raw($u)]; } if($rows) update_field('venue_sameas',$rows,$post_id); }
    }

    // ALT SEO
    $title=get_the_title($post_id);
    $city=get_post_meta($post_id,'city',true);
    $region=get_post_meta($post_id,'region',true);
    $alt=trim($title.($city?' — '.$city:'').($region?', '.$region:'').' | '.DISCORG_BRAND);

    // backfill immagine se manca (og → fb → places → default)
    discorg_backfill_logo_url_if_missing($post_id);

    $logo_url=get_post_meta($post_id,'logo_url',true);
    $desk_url=get_post_meta($post_id,'image_desktop_url',true);
    $mob_url =get_post_meta($post_id,'image_mobile_url',true);

    $logo_id=discorg_attach_from_url($post_id,$logo_url,'logo',$alt);
    $desk_id=discorg_attach_from_url($post_id,$desk_url,'cover-desktop',$alt);
    $mob_id =discorg_attach_from_url($post_id,$mob_url,'cover-mobile',$alt);

    if (function_exists('update_field')) {
        if ($logo_id) update_field('venue_logo',$logo_id,$post_id);
        if ($desk_id) update_field('immagine_desktop',$desk_id,$post_id);
        if ($mob_id)  update_field('immagine_mobile',$mob_id,$post_id);
    }

    if (!has_post_thumbnail($post_id)) {
        $thumb_id = $desk_id ?: ($logo_id ?: $mob_id);
        if ($thumb_id) set_post_thumbnail($post_id,$thumb_id);
    }
}

/* --------- Fallback immagini legali --------- */
function discorg_fetch_og_image($url){
    if (empty($url)) return '';
    $resp = wp_remote_get($url, ['timeout'=>10,'redirection'=>5,'user-agent'=>'Mozilla/5.0']);
    if (is_wp_error($resp)) return '';
    $html = wp_remote_retrieve_body($resp); if (!$html) return '';
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html,$m)) return esc_url_raw($m[1]);
    if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html,$m)) return esc_url_raw($m[1]);
    return '';
}
function discorg_fb_page_picture($page_url){
    if (empty($page_url)) return '';
    if (!preg_match('#facebook\.com/([^/?]+)#i',$page_url,$m)) return '';
    return 'https://graph.facebook.com/'.$m[1].'/picture?type=large';
}
function discorg_google_places_photo($query){
    if (!defined('DISCORG_GOOGLE_PLACES_API_KEY') || !DISCORG_GOOGLE_PLACES_API_KEY) return ['', ''];
    $key = DISCORG_GOOGLE_PLACES_API_KEY;
    $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query='.rawurlencode($query).'&key='.$key;
    $r = wp_remote_get($url, ['timeout'=>10]); if (is_wp_error($r)) return ['', ''];
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (empty($j['results'][0]['photos'][0]['photo_reference'])) return ['', ''];
    $ref  = $j['results'][0]['photos'][0]['photo_reference'];
    $attr = !empty($j['results'][0]['photos'][0]['html_attributions'][0]) ? $j['results'][0]['photos'][0]['html_attributions'][0] : '';
    $photo_url = 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=1600&photoreference='.$ref.'&key='.$key;
    return [$photo_url, $attr];
}
function discorg_backfill_logo_url_if_missing($post_id){
    $logo = get_post_meta($post_id,'logo_url',true);
    if (!empty($logo)) return;
    $site = get_post_meta($post_id,'website',true);
    $og   = discorg_fetch_og_image($site);
    if ($og) { update_post_meta($post_id,'logo_url',$og); return; }
    $sameas = get_post_meta($post_id,'sameas',true);
    if ($sameas) {
        foreach (array_filter(array_map('trim', explode('|',$sameas))) as $u) {
            if (stripos($u,'facebook.com') !== false) {
                $fb = discorg_fb_page_picture($u);
                if ($fb) { update_post_meta($post_id,'logo_url',$fb); return; }
            }
        }
    }
    $q = get_the_title($post_id).' '.get_post_meta($post_id,'city',true).' discoteca';
    list($gurl,$attr) = discorg_google_places_photo($q);
    if ($gurl) {
        update_post_meta($post_id,'logo_url',$gurl);
        if ($attr) update_post_meta($post_id,'photo_attribution_html',$attr);
        return;
    }
    if (defined('DISCORG_DEFAULT_IMAGE') && DISCORG_DEFAULT_IMAGE) update_post_meta($post_id,'logo_url', DISCORG_DEFAULT_IMAGE);
}

/* --------- Download + ottimizza e crea attachment pulito (per URL import) --------- */
function discorg_attach_from_url($post_id, $img_url, $suffix, $alt){
    if (empty($img_url)) return 0;
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/image.php';

    $tmp_id = media_sideload_image($img_url, $post_id, null, 'id');
    if (is_wp_error($tmp_id)) return 0;

    $src = get_attached_file($tmp_id);
    $ed  = wp_get_image_editor($src);
    if (is_wp_error($ed)) return $tmp_id;

    if (class_exists('Imagick')) { try { $im = new Imagick($src); @$im->stripImage(); $im->writeImage($src); $im->destroy(); } catch(Exception $e){} }

    $ed->resize(1600,1600,false);

    $uploads = wp_upload_dir();
    $slug  = sanitize_title(get_the_title($post_id));
    $base  = 'discoteca-'.$slug.'-'.$suffix;
    $dest  = trailingslashit($uploads['path']).$base.'.webp';

    if (method_exists($ed,'set_mime_type')) $ed->set_mime_type('image/webp');
    $res = $ed->save($dest,'image/webp');
    if (is_wp_error($res)) {
        $ed = wp_get_image_editor($src);
        if (!is_wp_error($ed)) {
            $ed->resize(1600,1600,false);
            $dest = trailingslashit($uploads['path']).$base.'.jpg';
            $res = $ed->save($dest,'image/jpeg');
        }
    }
    if (is_wp_error($res)) { wp_delete_attachment($tmp_id,true); return 0; }

    $type = wp_check_filetype($dest);
    $att = ['post_mime_type'=>$type['type'],'post_title'=>get_the_title($post_id).' – '.$suffix,'post_content'=>'','post_status'=>'inherit'];
    $new_id = wp_insert_attachment($att, $dest, $post_id);
    if (is_wp_error($new_id)) { wp_delete_attachment($tmp_id,true); return 0; }

    $meta = wp_generate_attachment_metadata($new_id,$dest);
    wp_update_attachment_metadata($new_id,$meta);
    update_post_meta($new_id,'_wp_attachment_image_alt',$alt);

    wp_delete_attachment($tmp_id,true);
    return $new_id;
}

/* =================== JSON-LD (Rank Math) → forza image =================== */
add_filter('rank_math/json_ld', function($data){
    if (is_admin() || !is_singular(DISCORG_POST_TYPE)) return $data;
    $post_id = get_the_ID();
    $candidates = [];
    $feat = get_the_post_thumbnail_url($post_id,'full'); if ($feat) $candidates[] = $feat;
    if (function_exists('get_field')) {
        foreach (['immagine_desktop','venue_logo','immagine_mobile'] as $k) {
            $id = get_field($k,$post_id);
            if ($id) { $url = wp_get_attachment_image_url($id,'full'); if ($url) $candidates[] = $url; }
        }
    }
    $final = reset($candidates);
    foreach ($data as &$g) {
        if (empty($g['@type'])) continue;
        $types = (array) $g['@type'];
        if (array_intersect($types,['LocalBusiness','NightClub'])) {
            if (empty($g['image']) && $final) $g['image'] = $final;
        }
    }
    return $data;
}, 20);

/* ==================== PAGINA: CARICA IMMAGINE DISCOTECA ==================== */
function discorg_image_uploader_page(){
    if (!current_user_can('upload_files')) return;

    if (function_exists('discorg_image_uploader_before_form')) discorg_image_uploader_before_form();

    if (!empty($_POST['discorg_img_nonce']) && wp_verify_nonce($_POST['discorg_img_nonce'],'discorg_img_upload')) {
        echo discorg_handle_image_upload();
    }
    ?>
    <div class="wrap">
      <h1>Carica immagine discoteca</h1>
      <p>Seleziona la discoteca, carica l'immagine e scegli l'uso. Verranno creati i formati ottimizzati e aggiornati i campi ACF.</p>

      <form method="post" enctype="multipart/form-data" id="discorg-img-form">
        <?php wp_nonce_field('discorg_img_upload','discorg_img_nonce'); ?>

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="discorg-search">Discoteca</label></th>
            <td>
              <input type="hidden" name="post_id" id="discorg-post-id" required>
              <input type="text" id="discorg-search" class="regular-text" placeholder="Inizia a digitare il nome…" autocomplete="off">
              <p class="description">Cerca per titolo nel CPT <code>discoteche</code>.</p>
              <ul id="discorg-suggest" style="margin:.5em 0 0;max-height:220px;overflow:auto;border:1px solid #ddd;display:none;background:#fff;"></ul>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="discorg-file">Immagine</label></th>
            <td>
              <input type="file" name="discorg_file" id="discorg-file" accept="image/*" required>
              <p class="description">JPG/PNG/WebP consigliati.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Tipo utilizzo</th>
            <td>
              <fieldset>
                <label><input type="radio" name="discorg_type" value="cover" checked> Cover (aggiorna ACF <code>immagine_desktop</code> + <code>immagine_mobile</code>)</label><br>
                <label><input type="radio" name="discorg_type" value="logo"> Logo (aggiorna ACF <code>venue_logo</code>)</label>
              </fieldset>
            </td>
          </tr>

          <tr id="discorg-auto-bg-row" style="display:none">
            <th scope="row">Logo: scontorno automatico</th>
            <td>
              <label>
                <input type="checkbox" name="discorg_auto_bgremove" value="1">
                Prova a rimuovere uno sfondo uniforme (bianco/nero) e salva PNG con trasparenza
              </label>
              <p class="description">Funziona bene con sfondo piatto e logo a contrasto. Per sfondi complessi usa un editor.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Opzioni</th>
            <td>
              <label><input type="checkbox" name="discorg_set_featured" value="1" checked> Imposta come Immagine in evidenza (se <em>cover</em>)</label><br>
              <label><input type="checkbox" name="discorg_use_title_caption" value="1" checked> Scrivi Title/Caption/Descrizione media</label>
            </td>
          </tr>
        </table>

        <?php submit_button('Carica e ottimizza immagine'); ?>
      </form>
    </div>
    <style>
      #discorg-suggest li{padding:6px 10px;cursor:pointer}
      #discorg-suggest li:hover{background:#f1f1f1}
      #discorg-suggest li.discorg-sel{background:#e9f5ff}
    </style>
    <?php
}

/* === JS admin: toggle + autocomplete === */
add_action('admin_enqueue_scripts', function($hook){
    if ($hook !== 'tools_page_discorg-image-uploader') return;
    wp_enqueue_script('jquery');
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
    wp_add_inline_script('jquery', $discorg_inline_js);
});

/* === Autocomplete AJAX === */
add_action('wp_ajax_discorg_search_discoteche', function(){
    if (!current_user_can('upload_files')) wp_send_json_error();
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
            'city'   => get_post_meta($pid,'city',true),
            'region' => get_post_meta($pid,'region',true),
            'edit'   => get_edit_post_link($pid),
        ];
    }
    wp_send_json_success($out);
});

function discorg_handle_image_upload(){
    if (empty($_POST['post_id']) || empty($_FILES['discorg_file'])) {
        return '<div class="notice notice-error"><p>Seleziona discoteca e file.</p></div>';
    }
    $post_id      = (int) $_POST['post_id'];
    $type         = ($_POST['discorg_type'] ?? 'cover') === 'logo' ? 'logo' : 'cover';
    $set_featured = !empty($_POST['discorg_set_featured']);
    $write_meta   = !empty($_POST['discorg_use_title_caption']);
    $want_auto    = !empty($_POST['discorg_auto_bgremove']); // ⬅️ checkbox “scontorna logo”

    if (get_post_type($post_id) !== DISCORG_POST_TYPE) {
        return '<div class="notice notice-error"><p>ID non valido.</p></div>';
    }

    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';

    $attach_id = media_handle_upload('discorg_file', $post_id);
    if (is_wp_error($attach_id)) {
        return '<div class="notice notice-error"><p>Upload fallito: '.esc_html($attach_id->get_error_message()).'</p></div>';
    }

    // ⬇️ Passiamo $want_auto alla funzione di processing
    $res = discorg_process_and_attach($post_id, $attach_id, $type, $set_featured, $write_meta, $want_auto);

    // rimuove il file grezzo caricato (usiamo le varianti generate)
    wp_delete_attachment($attach_id, true);

    // se abbiamo creato un PNG scontornato temporaneo, prova a cancellarlo
    if (!empty($res['tmp']) && file_exists($res['tmp'])) {
        @unlink($res['tmp']);
    }

    if (empty($res['ok'])) {
        return '<div class="notice notice-error"><p>Elaborazione immagine non riuscita.</p></div>';
    }

    $html = '<div class="notice notice-success"><p><strong>Fatto!</strong> ';
    $html .= ($type==='cover')
        ? 'Aggiornati ACF <code>immagine_desktop</code> e <code>immagine_mobile</code>'.($set_featured?' + Featured impostata':'').'.'
        : 'Aggiornato ACF <code>venue_logo</code>'.($want_auto?' (scontorno automatico attivo)':'').'.';
    $html .= '</p></div>';
    return $html;
}



// Scontorno sfondo uniforme (angoli + flood fill), molto conservativo.
// Ritorna path PNG con alpha oppure '' (se fallisce → usa originale).
if (!function_exists('discorg_auto_remove_bg_uniform')) {
function discorg_auto_remove_bg_uniform($src_path, $fuzz_percent = 6, $corner_ratio = 0.03){
    if (!class_exists('Imagick')) return '';
    try {
        $im = new Imagick();
        $im->readImage($src_path);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        if ($w < 8 || $h < 8) { $im->clear(); $im->destroy(); return ''; }

        // --- 1) Stima colore sfondo dai 4 angoli (solo 1 pixel per angolo, opz. piccola media) ---
        $pad = max(1, (int) round(min($w,$h) * max(0.01, min(0.08, $corner_ratio))));
        $pts = [
            [$pad,           $pad],
            [$w - $pad - 1,  $pad],
            [$pad,           $h - $pad - 1],
            [$w - $pad - 1,  $h - $pad - 1],
        ];
        $acc = ['r'=>0,'g'=>0,'b'=>0];
        foreach ($pts as $xy){
            $c = $im->getImagePixelColor($xy[0], $xy[1])->getColor(); // 0..255
            $acc['r'] += $c['r']; $acc['g'] += $c['g']; $acc['b'] += $c['b'];
        }
        $cnt = count($pts);
        $br = (int) round($acc['r'] / $cnt);
        $bg = (int) round($acc['g'] / $cnt);
        $bb = (int) round($acc['b'] / $cnt);
        $bg_color = sprintf('rgb(%d,%d,%d)', $br,$bg,$bb);

        // --- 2) Flood fill dal perimetro su quel colore (con fuzz) ---
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
        $fill   = new ImagickPixel('transparent');
        $target = new ImagickPixel($bg_color);

        // fuzz in Quantum
        $qr   = Imagick::getQuantumRange();
        $quant= is_array($qr) ? ($qr['quantumRangeLong'] ?? 65535) : (int)$qr;
        $fuzzQ= (int) max(0, min(100, $fuzz_percent)) * $quant / 100;

        // semi-raster dal bordo; passo stretto per non lasciare bave
        $stepX = max(1, (int) round($w * 0.02));
        $stepY = max(1, (int) round($h * 0.02));
        for ($x = 0; $x < $w; $x += $stepX) {
            @$im->floodFillPaintImage($fill, $fuzzQ, $target, $x, 0,     false);
            @$im->floodFillPaintImage($fill, $fuzzQ, $target, $x, $h-1,  false);
        }
        for ($y = 0; $y < $h; $y += $stepY) {
            @$im->floodFillPaintImage($fill, $fuzzQ, $target, 0,    $y, false);
            @$im->floodFillPaintImage($fill, $fuzzQ, $target, $w-1, $y, false);
        }

        // --- 3) Fail-safe: area residua ragionevole (tra 10% e 95% dell’originale) ---
        $origArea = $w * $h;
        $chk = clone $im;
        @$chk->trimImage(0);
        $nw = $chk->getImageWidth();
        $nh = $chk->getImageHeight();
        $areaRatio = ($nw * $nh) / max(1, $origArea);
        $chk->clear(); $chk->destroy();

        if ($areaRatio < 0.10 || $areaRatio > 0.95) {
            // troppo aggressivo o praticamente nulla → abort
            $im->clear(); $im->destroy();
            return '';
        }

        // --- 4) Piccola rifinitura + trim finale ---
        if (class_exists('ImagickKernel')) {
            $kernel = ImagickKernel::fromBuiltIn(Imagick::KERNEL_DISK, "1.0");
            @$im->morphology(Imagick::MORPHOLOGY_OPEN, 1, $kernel);
            @$im->morphology(Imagick::MORPHOLOGY_CLOSE,1, $kernel);
        }
        @$im->trimImage(0);
        $im->setImagePage(0,0,0,0);

        // --- 5) Salva PNG con alpha ---
        $uploads = wp_upload_dir();
        $dest = trailingslashit($uploads['path']) . 'discorg-autoalpha-' . wp_generate_uuid4() . '.png';
        $im->setImageFormat('png');
        $im->writeImage($dest);
        $im->clear(); $im->destroy();

        return $dest;
    } catch (Exception $e) {
        return '';
    }
}}







/* === Core processing upload (cover/logo) === */
function discorg_process_and_attach($post_id, $tmp_attach_id, $type, $set_featured, $write_meta, $want_auto = false){
    $file = get_attached_file($tmp_attach_id);
    if (!$file || !file_exists($file)) return ['ok'=>false];

    $title  = get_the_title($post_id);
    $city   = get_post_meta($post_id,'city',true);
    $region = get_post_meta($post_id,'region',true);
    $slug   = sanitize_title($title);

    // Token versione condiviso (anti-cache)
    $version = gmdate('YmdHis');

    $alt     = trim($title . ($city ? ' — '.$city : '') . ($region ? ', '.$region : '') . ' | ' . DISCORG_BRAND);
    $caption = ($type==='logo') ? ($title.' — Logo ufficiale') : ($title.' — Cover ufficiale');
    $desc    = ($type==='logo') ? ('Logo della discoteca '.$title.($city?' a '.$city:'')) 
                                : ('Immagine di copertina della discoteca '.$title.($city?' a '.$city:''));

    // ▼ Sorgente per l’editor (eventualmente scontornata)
    $src_for_edit = $file;
    $tmp_auto = '';
    if ($want_auto && $type === 'logo') {
        // fuzz 12%, banda bordo 6% (tarabili)
        $maybe = discorg_auto_remove_bg_uniform($file, 12, 0.06);
        if ($maybe && file_exists($maybe)) { 
            $src_for_edit = $maybe; 
            $tmp_auto = $maybe;          // utile se vuoi poi cancellare il temporaneo
        }
    }

    // Helper: crea variante, salva e ritorna [attachment_id, path]
    $make_variant = function($max_w, $suffix) use ($src_for_edit, $post_id, $slug, $alt, $caption, $desc, $write_meta, $version){
        $ed = wp_get_image_editor($src_for_edit);
        if (is_wp_error($ed)) return [0,''];

        // Strip EXIF se disponibile
        if (class_exists('Imagick')) {
            try {
                $im = new Imagick($src_for_edit);
                @$im->stripImage();
                $im->writeImage($src_for_edit);
                $im->clear(); $im->destroy();
            } catch (Exception $e) {}
        }

        $ed->resize($max_w, $max_w, false);

        $uploads = wp_upload_dir();
        $is_logo = (strpos($suffix, 'logo') !== false);

        // Nome file versionato
        $base = 'discoteca-'.$slug.'-'.$suffix.'-v'.$version;

        if ($is_logo) {
            // LOGO → PNG con alpha
            $dest = trailingslashit($uploads['path']).$base.'.png';
            if (method_exists($ed,'set_mime_type')) $ed->set_mime_type('image/png');
            $saved = $ed->save($dest, 'image/png');
            if (is_wp_error($saved)) return [0,''];
        } else {
            // COVER → WebP (fallback JPG)
            $dest = trailingslashit($uploads['path']).$base.'.webp';
            if (method_exists($ed,'set_mime_type')) $ed->set_mime_type('image/webp');
            $saved = $ed->save($dest, 'image/webp');
            if (is_wp_error($saved)) {
                $ed = wp_get_image_editor($src_for_edit);
                if (is_wp_error($ed)) return [0,''];
                $ed->resize($max_w, $max_w, false);
                $dest = trailingslashit($uploads['path']).$base.'.jpg';
                $saved = $ed->save($dest, 'image/jpeg');
                if (is_wp_error($saved)) return [0,''];
            }
        }

        $ft  = wp_check_filetype($dest);
        $att = [
            'post_mime_type' => $ft['type'],
            'post_title'     => wp_strip_all_tags(get_the_title($post_id).' – '.$suffix),
            'post_content'   => $write_meta ? $desc    : '',
            'post_excerpt'   => $write_meta ? $caption : '',
            'post_status'    => 'inherit',
        ];
        $new_id = wp_insert_attachment($att, $dest, $post_id);
        if (is_wp_error($new_id)) return [0,''];

        $meta = wp_generate_attachment_metadata($new_id, $dest);
        wp_update_attachment_metadata($new_id, $meta);
        update_post_meta($new_id, '_wp_attachment_image_alt', $alt);

        return [$new_id, $dest];
    };

    if ($type==='logo') {
        // opz: elimina il precedente logo
        if (function_exists('get_field')) {
            $old = get_field('venue_logo', $post_id);
            if ($old) wp_delete_attachment((int)$old, true);
        }
        list($logo_id,) = $make_variant(800, 'logo');
        if ($logo_id && function_exists('update_field')) update_field('venue_logo',$logo_id,$post_id);
    } else {
        // opz: elimina precedenti cover
        if (function_exists('get_field')) {
            $old_d = get_field('immagine_desktop', $post_id);
            $old_m = get_field('immagine_mobile',  $post_id);
            if ($old_d) wp_delete_attachment((int)$old_d, true);
            if ($old_m) wp_delete_attachment((int)$old_m, true);
        }
        list($desk_id,) = $make_variant(1600, 'cover-desktop');
        list($mob_id ,) = $make_variant(800 , 'cover-mobile');
        if (function_exists('update_field')) {
            if ($desk_id) update_field('immagine_desktop',$desk_id,$post_id);
            if ($mob_id ) update_field('immagine_mobile', $mob_id, $post_id);
        }
        if ($desk_id && $set_featured) set_post_thumbnail($post_id, $desk_id);
    }

    return ['ok'=>true, 'tmp'=>$tmp_auto];
}




/* =================== LISTA "MANCANO IMMAGINI" + AZIONI RAPIDE =================== */
function discorg_get_incomplete_discos($limit = 500){
    $q = new WP_Query([
        'post_type'      => DISCORG_POST_TYPE,
        'posts_per_page' => $limit,
        'post_status'    => ['publish','pending','draft','future','private'],
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    $rows=[];
    foreach ($q->posts as $pid){
        $desk_id = function_exists('get_field') ? get_field('immagine_desktop', $pid) : (int)get_post_meta($pid,'immagine_desktop',true);
        $mob_id  = function_exists('get_field') ? get_field('immagine_mobile',  $pid) : (int)get_post_meta($pid,'immagine_mobile',true);
        $logo_id = function_exists('get_field') ? get_field('venue_logo',      $pid) : (int)get_post_meta($pid,'venue_logo',true);
        $feat_id = has_post_thumbnail($pid) ? get_post_thumbnail_id($pid) : 0;

        $missing = ['desktop'=>empty($desk_id),'mobile'=>empty($mob_id),'logo'=>empty($logo_id),'featured'=>empty($feat_id)];
        if ($missing['desktop'] || $missing['mobile'] || $missing['logo']){
            $rows[] = [
                'ID'=>$pid,
                'title'=>get_the_title($pid),
                'city'=>get_post_meta($pid,'city',true),
                'region'=>get_post_meta($pid,'region',true),
                'missing'=>$missing,
                'feat'=> $feat_id ? wp_get_attachment_image($feat_id,[80,80],false, ['style'=>'border-radius:6px']) : '',
                'edit'=>get_edit_post_link($pid),
                'view'=>get_permalink($pid),
            ];
        }
    }
    return $rows;
}

add_action('load-tools_page_discorg-image-uploader', function(){
    add_action('discorg_image_uploader_before_form', function(){
        $rows = discorg_get_incomplete_discos();
        echo '<h2 style="margin-top:20px">Discoteche senza immagini complete</h2>';
        if (empty($rows)) { echo '<p>🎉 Tutte le discoteche hanno logo + cover desktop + cover mobile.</p>'; return; }

        echo '<p class="description">Restano qui finché non hanno tutte le immagini. Usa le azioni per caricarle al volo.</p>';
        echo '<table class="widefat striped" style="margin-top:10px"><thead><tr>
                <th>Titolo</th><th>Località</th>
                <th style="text-align:center">Desktop</th>
                <th style="text-align:center">Mobile</th>
                <th style="text-align:center">Logo</th>
                <th style="text-align:center">Featured</th>
                <th>Anteprima</th><th style="width:220px">Azioni</th>
              </tr></thead><tbody>';
        $st = function($ok){ return $ok ? '<span style="color:#3c763d;font-weight:600">✓</span>' : '<span style="color:#a00;font-weight:600">—</span>'; };
        foreach ($rows as $r){
            $lab = esc_attr($r['title'].($r['city']?' — '.$r['city']:'').($r['region']?', '.$r['region']:''));
            echo '<tr>
                <td><strong>'.esc_html($r['title']).'</strong></td>
                <td>'.esc_html(trim(($r['city']?:'').($r['region']?', '.$r['region']:''))).'</td>
                <td style="text-align:center">'.$st(!$r['missing']['desktop']).'</td>
                <td style="text-align:center">'.$st(!$r['missing']['mobile']).'</td>
                <td style="text-align:center">'.$st(!$r['missing']['logo']).'</td>
                <td style="text-align:center">'.$st(!$r['missing']['featured']).'</td>
                <td>'.$r['feat'].'</td>
                <td>
                    <a href="#" class="button button-primary discorg-pick" data-post="'.$r['ID'].'" data-type="cover" data-title="'.$lab.'">Carica cover</a>
                    <a href="#" class="button discorg-pick" style="margin-left:6px" data-post="'.$r['ID'].'" data-type="logo" data-title="'.$lab.'">Carica logo</a>
                    <a href="'.esc_url($r['edit']).'" class="button-link" style="margin-left:8px">Modifica</a>
                    <a href="'.esc_url($r['view']).'" class="button-link" target="_blank" style="margin-left:6px">Vedi</a>
                </td></tr>';
        }
        echo '</tbody></table><hr style="margin:18px 0 6px">';
    });
});

if (!function_exists('discorg_image_uploader_before_form')) {
    function discorg_image_uploader_before_form(){ do_action('discorg_image_uploader_before_form'); }
}