<?php
/**
 * Discoteche.org – Snippets Unificati
 * v1.2 – 2025-10-07
 *
 * Include:
 * - [SETUP] (opz.) CPT discoteche/eventi + tassonomia "localita" gerarchica
 * - [SYNC]  Città term (foglia) ↔ ACF venue_address.city su Discoteche
 * - [NOINDEX] Archivi localita vuoti
 * - [RANK MATH VARS] %citta% e %smart_city%
 * - [CLEANUP] Rimuovi Organization locale dalle singole Discoteche
 * - [SCHEMA] NightClub per singole Discoteche
 * - [SCHEMA] Event ALL-IN-ONE per singoli Eventi (validFrom auto + description)
 */

if ( ! defined('ABSPATH') ) exit;

/* ===== Switch rapidi ===== */
if ( ! defined('DISCORG_ENABLE_SETUP') )            define('DISCORG_ENABLE_SETUP',            true);  // CPT + tassonomia
if ( ! defined('DISCORG_ENABLE_SYNC') )             define('DISCORG_ENABLE_SYNC',             true);  // Sync città
if ( ! defined('DISCORG_ENABLE_NOINDEX_EMPTY') )    define('DISCORG_ENABLE_NOINDEX_EMPTY',    true);  // Noindex archivi localita vuoti
if ( ! defined('DISCORG_ENABLE_RM_VARS') )          define('DISCORG_ENABLE_RM_VARS',          true);  // %citta% %smart_city%
if ( ! defined('DISCORG_ENABLE_CLEANUP_ORG') )      define('DISCORG_ENABLE_CLEANUP_ORG',      true);  // pulizia Organization
if ( ! defined('DISCORG_ENABLE_SCHEMA_NIGHTCLUB') ) define('DISCORG_ENABLE_SCHEMA_NIGHTCLUB', true);  // schema discoteche
if ( ! defined('DISCORG_ENABLE_SCHEMA_EVENT') )     define('DISCORG_ENABLE_SCHEMA_EVENT',     true);  // schema eventi

/* ---------------------------------------------------------
 * [SETUP] CPT opzionali + tassonomia "localita" (gerarchica)
 * --------------------------------------------------------- */
if ( DISCORG_ENABLE_SETUP ) {
  add_action('init', function () {
    // CPT Discoteche (solo se non già registrato)
    if ( ! post_type_exists('discoteche') ) {
      register_post_type('discoteche', [
        'label' => 'Discoteche',
        'public' => true,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-location',
        'supports' => ['title','editor','thumbnail','excerpt'],
        'has_archive' => true,
        'rewrite' => ['slug' => 'discoteche'],
      ]);
    }
    // CPT Eventi (solo se non già registrato)
    if ( ! post_type_exists('eventi') ) {
      register_post_type('eventi', [
        'label' => 'Eventi',
        'public' => true,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-calendar-alt',
        'supports' => ['title','editor','thumbnail','excerpt'],
        'has_archive' => false,
        'rewrite' => ['slug' => 'eventi'],
      ]);
    }
  }, 9);

  // Tassonomia Località (Regione → Città)
  add_action('init', function () {
    if ( ! taxonomy_exists('localita') ) {
      register_taxonomy('localita', ['discoteche','eventi'], [
        'labels' => ['name'=>'Località','singular_name'=>'Località','menu_name'=>'Località'],
        'hierarchical' => true,
        'public' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug'=>'localita','hierarchical'=>true,'with_front'=>false],
      ]);
    }
  }, 9);
}

/* ---------------------------------------------
 * Helper città dal termine "localita" più profondo
 * --------------------------------------------- */
if ( ! function_exists('discorg_get_city_from_localita') ) {
  function discorg_get_city_from_localita( $post_id = 0 ){
    $post_id = $post_id ?: get_the_ID();
    if ( ! $post_id || ! taxonomy_exists('localita') ) return '';
    $terms = wp_get_post_terms( $post_id, 'localita', [ 'orderby'=>'term_id', 'order'=>'ASC' ] );
    if ( is_wp_error($terms) || empty($terms) ) return '';
    // prendo il termine più "figlio"
    $deepest = $terms[0];
    foreach ( $terms as $t ) {
      if ( $t->parent && ( ! $deepest->parent || term_is_ancestor_of( $deepest->term_id, $t->term_id, 'localita' ) ) ) {
        $deepest = $t;
      }
    }
    return trim($deepest->name);
  }
}

/* ------------------------------------------------
 * [SYNC] Città ACF ↔ termine localita su Discoteche
 * ------------------------------------------------ */
if ( DISCORG_ENABLE_SYNC ) {
  add_action('save_post_discoteche', function( $post_id, $post, $update ){
    if ( wp_is_post_revision($post_id) || ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) ) return;

    // Città dalla tassonomia (term foglia)
    $city_term_name = '';
    if ( taxonomy_exists('localita') ) {
      $terms = wp_get_post_terms($post_id,'localita',['fields'=>'all']);
      if ( ! is_wp_error($terms) && $terms ){
        $by_id=[]; $is_child=[];
        foreach ($terms as $t){ $by_id[$t->term_id]=$t; $is_child[$t->parent]=true; }
        $city = null; foreach ($terms as $t){ if ( empty($is_child[$t->term_id]) ){ $city=$t; break; } }
        if ( ! $city ) $city=$terms[0];
        $city_term_name=$city->name;
      }
    }

    // Città da ACF
    $city_acf='';
    if ( function_exists('get_field') ) {
      $addr=get_field('venue_address',$post_id);
      if ( is_array($addr) && !empty($addr['city']) ) $city_acf=(string)$addr['city'];
      if ( ! $city_acf ){
        $raw=get_post_meta($post_id,'venue_address_city',true);
        if ( is_string($raw) && $raw!=='' ) $city_acf=$raw;
      }
    }

    // Sync verso ACF se city_term presente
    if ( $city_term_name ){
      if ( get_post_meta($post_id,'venue_address_city',true)!==$city_term_name ){
        update_post_meta($post_id,'venue_address_city',$city_term_name);
      }
      return;
    }

    // Oppure crea/imposta termine dal valore ACF
    if ( $city_acf && taxonomy_exists('localita') ){
      if ( ! term_exists($city_acf,'localita') ){
        wp_insert_term($city_acf,'localita',['slug'=>sanitize_title($city_acf)]);
      }
      wp_set_post_terms($post_id,[$city_acf],'localita',true);
    }
  }, 20, 3);
}

/* ------------------------------------------------
 * [NOINDEX] archivi localita senza contenuti
 * ------------------------------------------------ */
if ( DISCORG_ENABLE_NOINDEX_EMPTY ) {
  add_filter('rank_math/frontend/robots', function($robots){
    if ( ! is_tax('localita') ) return $robots;
    $term=get_queried_object(); if(!$term) return $robots;
    $q=new WP_Query([
      'post_type'=>['discoteche','eventi'],
      'tax_query'=>[[ 'taxonomy'=>'localita','terms'=>$term->term_id ]],
      'posts_per_page'=>1,'no_found_rows'=>true,'fields'=>'ids',
    ]);
    if ( ! $q->have_posts() ) $robots='noindex,follow';
    wp_reset_postdata();
    return $robots;
  });
}

/* ---------------------------------------------
 * [RANK MATH VARS] %citta% e %smart_city%
 * --------------------------------------------- */
if ( DISCORG_ENABLE_RM_VARS ) {
  if ( ! function_exists('discorg_var_citta') ) { function discorg_var_citta(){ return discorg_get_city_from_localita(); } }
  if ( ! function_exists('discorg_var_smart_city') ) {
    function discorg_var_smart_city(){
      $city  = discorg_get_city_from_localita();
      if ( $city === '' ) return '';
      $title = get_the_ID() ? get_the_title() : '';
      $needle = wp_strip_all_tags(strtolower($city));
      $hay    = wp_strip_all_tags(strtolower((string)$title));
      return ( $needle && strpos($hay, $needle) !== false ) ? '' : $city;
    }
  }
  add_action('init', function(){
    if ( function_exists('rank_math_register_var_replacement') ) {
      rank_math_register_var_replacement('citta', [
        'name'=>'Città (tassonomia localita)',
        'description'=>'Termine più profondo assegnato (di solito la città).',
        'variable'=>'citta','example'=>'Milano',
      ], 'discorg_var_citta');
      rank_math_register_var_replacement('smart_city', [
        'name'=>'Città (solo se assente nel titolo)',
        'description'=>'Evita duplicazioni nel title.',
        'variable'=>'smart_city','example'=>'Milano',
      ], 'discorg_var_smart_city');
    }
  }, 11);
}

/* ------------------------------------------------
 * [CLEANUP] Organization duplicata su Discoteche
 * ------------------------------------------------ */
if ( DISCORG_ENABLE_CLEANUP_ORG ) {
  add_filter('rank_math/json_ld', function ($data) {
    if ( is_singular('discoteche') ) {
      foreach ($data as $k => $node) {
        if ( isset($node['@type']) && $node['@type'] === 'Organization' ) {
          $id = isset($node['@id']) ? (string)$node['@id'] : '';
          if ( strpos($id, '#organization') === false ) unset($data[$k]);
        }
      }
    }
    return $data;
  }, 99999, 2);
}

/* ------------------------------------------------
 * [SCHEMA] NightClub (Discoteche)
 * ------------------------------------------------ */
if ( DISCORG_ENABLE_SCHEMA_NIGHTCLUB ) {
  add_filter('rank_math/json_ld', function ($data) {
    if ( ! is_singular('discoteche') ) return $data;
    if ( ! function_exists('get_field') ) return $data;

    $post_id = get_the_ID(); if ( ! $post_id ) return $data;

    $phone   = trim((string) get_field('venue_phone',   $post_id));
    $website = trim((string) get_field('venue_website', $post_id));
    $addr    = (array) get_field('venue_address', $post_id);
    $street   = isset($addr['street'])   ? trim($addr['street'])   : '';
    $city     = isset($addr['city'])     ? trim($addr['city'])     : '';
    $region   = isset($addr['region'])   ? trim($addr['region'])   : '';
    $postcode = isset($addr['postcode']) ? trim($addr['postcode']) : '';
    $country  = isset($addr['country'])  ? trim($addr['country'])  : 'IT';

    $lat  = get_field('venue_geo_lat', $post_id);
    $lng  = get_field('venue_geo_lng', $post_id);

    $sameAs = [];
    $sameas_rows = get_field('venue_sameas', $post_id);
    if (is_array($sameas_rows)) {
      foreach ($sameas_rows as $row) {
        if (!empty($row['url'])) $sameAs[] = esc_url($row['url']);
      }
    }

    $logo_field = get_field('venue_logo', $post_id);
    $logo_url = '';
    if (is_numeric($logo_field)) {
      $logo_url = wp_get_attachment_image_url((int)$logo_field, 'full');
    } elseif (is_array($logo_field) && !empty($logo_field['url'])) {
      $logo_url = $logo_field['url'];
    } elseif (is_string($logo_field)) {
      $logo_url = $logo_field;
    }
    if (!$logo_url) $logo_url = get_the_post_thumbnail_url($post_id, 'full');

    $address = array_filter([
      '@type'           => 'PostalAddress',
      'streetAddress'   => $street,
      'addressLocality' => $city,
      'addressRegion'   => $region,
      'postalCode'      => $postcode,
      'addressCountry'  => $country ?: 'IT',
    ]);

    $geo = [];
    if ($lat !== '' && $lng !== '') {
      $geo = ['@type'=>'GeoCoordinates','latitude'=>(float)$lat,'longitude'=>(float)$lng];
    }

    $permalink = get_permalink($post_id);
    $nightclub = [
      '@type' => 'NightClub',
      '@id'   => trailingslashit($permalink) . '#venue',
      'name'  => get_the_title($post_id),
      'url'   => $website ?: $permalink,
      'priceRange' => '€€', // opzionale
    ];
    if ($logo_url) $nightclub['image']     = $logo_url;
    if ($phone)    $nightclub['telephone'] = $phone;
    if ($address)  $nightclub['address']   = $address;
    if ($geo)      $nightclub['geo']       = $geo;
    if ($sameAs)   $nightclub['sameAs']    = $sameAs;

    $data['NightClub'] = $nightclub;
    return $data;
  }, 9999, 2);
}

/* ------------------------------------------------
 * [SCHEMA] Event ALL-IN-ONE (Eventi)
 * ------------------------------------------------ */
if ( DISCORG_ENABLE_SCHEMA_EVENT ) {
  add_filter('rank_math/json_ld', function( $data, $jsonld ){

    if ( is_admin() || ( defined('REST_REQUEST') && REST_REQUEST ) || wp_doing_ajax() || wp_doing_cron() ) return $data;
    if ( ! is_singular('eventi') || ! function_exists('get_field') ) return $data;

    $post_id = get_the_ID();
    $iso = function( $v ){ if ( empty($v) ) return null; $ts = is_numeric($v)? (int)$v : strtotime((string)$v); return $ts? date('c',$ts) : null; };

    // trova nodi Event
    $event_keys = [];
    foreach ( $data as $key => $node ) {
      if ( isset($node['@type']) ) {
        $t = $node['@type'];
        if ( ( is_string($t) && strtolower($t)==='event' ) || ( is_array($t) && in_array('Event',$t,true) ) || $key==='Event' ) {
          $event_keys[] = $key;
        }
      }
    }
    if ( empty($event_keys) ) return $data;

    $start_raw  = get_field('event_start', $post_id);
    $end_raw    = get_field('event_end',   $post_id);
    $venue_post = get_field('event_venue', $post_id);

    /* DESCRIPTION dinamica: ACF -> excerpt -> titolo */
    $desc = '';
    $acf_desc = get_field('event_description', $post_id);
    if ( is_string($acf_desc) && $acf_desc !== '' ) {
      $desc = wp_strip_all_tags( $acf_desc );
    } elseif ( has_excerpt($post_id) ) {
      $desc = wp_strip_all_tags( get_the_excerpt($post_id) );
    } else {
      $desc = wp_strip_all_tags( get_the_title($post_id) );
    }

    /* OFFERS con validFrom auto (-7gg) se mancante */
    $offers = [];
    if ( have_rows('offers',$post_id) ) {
      while ( have_rows('offers',$post_id) ) {
        the_row();
        $valid_from = get_sub_field('offer_valid_from');
        if ( empty($valid_from) ) {
          $base_ts = $start_raw ? ( is_numeric($start_raw) ? (int)$start_raw : strtotime((string)$start_raw) ) : time();
          $dt = new DateTime('@' . ($base_ts - 7 * DAY_IN_SECONDS));
          $dt->setTimezone( wp_timezone() );
          $valid_from = $dt->format( DATE_ATOM );
        } else {
          $valid_from = $iso($valid_from);
        }

        $offers[] = array_filter([
          '@type'         => 'Offer',
          'name'          => trim((string)get_sub_field('offer_name')) ?: null,
          'price'         => get_sub_field('offer_price'),
          'priceCurrency' => get_sub_field('offer_currency') ?: 'EUR',
          'validFrom'     => $valid_from,
          'availability'  => get_sub_field('offer_availability') ?: 'https://schema.org/InStock',
          'url'           => get_sub_field('offer_url') ?: get_permalink($post_id),
        ]);
      }
    }

    /* PERFORMER */
    $performers = [];
    if ( have_rows('performer',$post_id) ) {
      while ( have_rows('performer',$post_id) ) {
        the_row();
        $type = get_sub_field('performer_type');
        $performers[] = array_filter([
          '@type'  => in_array($type,['Person','MusicGroup'],true) ? $type : 'Person',
          'name'   => get_sub_field('performer_name'),
          'sameAs' => get_sub_field('performer_url'),
        ]);
      }
    }

    /* ORGANIZER */
    $organizer = null;
$org_name  = get_field('organizer_name',$post_id);
$org_url   = trim((string) get_field('organizer_url',$post_id));

// fallback: se manca organizer_url, usa l'home del sito
if ( ! $org_url ) {
    $org_url = home_url('/');
}

if ( $org_name || $org_url ) {
    $organizer = array_filter([
        '@type' => 'Organization',
        'name'  => $org_name ?: get_bloginfo('name'), // fallback al nome sito
        'url'   => $org_url,
    ]);
}

    /* LOCATION dalla discoteca collegata */
    $location = null;
    if ( $venue_post ) {
      $venue_id   = is_object($venue_post) ? (int)$venue_post->ID : (int)$venue_post;
      $venue_name = get_the_title($venue_id);
      $venue_url  = get_field('venue_website',$venue_id) ?: get_permalink($venue_id);

      $addr_group = get_field('venue_address',$venue_id);
      $street   = is_array($addr_group)&&!empty($addr_group['street'])   ? $addr_group['street']   : '';
      $city     = is_array($addr_group)&&!empty($addr_group['city'])     ? $addr_group['city']     : discorg_get_city_from_localita($venue_id);
      $region   = is_array($addr_group)&&!empty($addr_group['region'])   ? $addr_group['region']   : '';
      $postcode = is_array($addr_group)&&!empty($addr_group['postcode']) ? $addr_group['postcode'] : '';
      $country  = is_array($addr_group)&&!empty($addr_group['country'])  ? $addr_group['country']  : '';

      $postal = array_filter([
        '@type'           => 'PostalAddress',
        'streetAddress'   => $street ?: null,
        'addressLocality' => $city   ?: null,
        'addressRegion'   => $region ?: null,
        'postalCode'      => $postcode ?: null,
        'addressCountry'  => $country ?: null,
      ]);

      $lat = get_field('venue_geo_lat',$venue_id);
      $lng = get_field('venue_geo_lng',$venue_id);
      $geo = ( $lat && $lng ) ? ['@type'=>'GeoCoordinates','latitude'=>$lat,'longitude'=>$lng] : null;

      $logo = get_field('venue_logo',$venue_id);
      $image_url = is_array($logo) && !empty($logo['url']) ? $logo['url'] : ( is_string($logo) ? $logo : null );

      $sameas = [];
      if ( have_rows('venue_sameas',$venue_id) ) {
        while ( have_rows('venue_sameas',$venue_id) ) { the_row(); $u = get_sub_field('url'); if ( $u ) $sameas[] = esc_url_raw($u); }
      } else {
        $raw = get_field('venue_sameas',$venue_id);
        if ( is_array($raw) ) foreach ( $raw as $u ) if ( $u ) $sameas[] = esc_url_raw($u);
      }
      $sameas = array_values(array_filter(array_unique($sameas)));

      $location = array_filter([
        '@type'  => 'Place',
        'name'   => $venue_name,
        'url'    => $venue_url,
        'image'  => $image_url ?: null,
        'address'=> $postal ?: null,
        'geo'    => $geo ?: null,
        'sameAs' => $sameas ?: null,
      ]);
    }

    $event_patch = array_filter([
      '@type'              => 'Event',
      'name'               => get_the_title($post_id),
      'description'        => $desc,
      'startDate'          => $iso($start_raw),
      'endDate'            => $iso($end_raw),
      'location'           => $location,
      'organizer'          => $organizer,
      'offers'             => !empty($offers) ? $offers : null,
      'performer'          => !empty($performers) ? $performers : null,
      'eventAttendanceMode'=> 'https://schema.org/OfflineEventAttendanceMode',
      'eventStatus'        => 'https://schema.org/EventScheduled',
    ]);

            // ---- Normalizzazioni qualità schema (https + timezone) ----
        $https_map = [
            'http://schema.org/EventScheduled'             => 'https://schema.org/EventScheduled',
            'http://schema.org/EventCancelled'             => 'https://schema.org/EventCancelled',
            'http://schema.org/EventPostponed'             => 'https://schema.org/EventPostponed',
            'http://schema.org/EventRescheduled'           => 'https://schema.org/EventRescheduled',
            'http://schema.org/OfflineEventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'http://schema.org/OnlineEventAttendanceMode'  => 'https://schema.org/OnlineEventAttendanceMode',
            'http://schema.org/MixedEventAttendanceMode'   => 'https://schema.org/MixedEventAttendanceMode',
            'http://schema.org/InStock'                    => 'https://schema.org/InStock',
            'http://schema.org/SoldOut'                    => 'https://schema.org/SoldOut',
            'http://schema.org/PreOrder'                   => 'https://schema.org/PreOrder',
        ];

        $fmt_iso_tz = function($iso){
            if (empty($iso)) return $iso;
            try {
                $dt = new DateTime($iso);
            } catch (Exception $e) { return $iso; }
            $dt->setTimezone( wp_timezone() );
            return $dt->format(DATE_ATOM); // es. 2025-10-10T22:00:00+02:00
        };

        $fix_event = function (&$ev) use ($https_map, $fmt_iso_tz) {
            if (empty($ev) || !is_array($ev)) return;

            // http -> https
            foreach (['eventStatus','eventAttendanceMode'] as $k) {
                if (!empty($ev[$k]) && isset($https_map[$ev[$k]])) {
                    $ev[$k] = $https_map[$ev[$k]];
                }
            }

            // date -> timezone WP
            foreach (['startDate','endDate'] as $k) {
                if (!empty($ev[$k])) $ev[$k] = $fmt_iso_tz($ev[$k]);
            }

            // offers: array-normalize + availability https + validFrom timezone
            if (!empty($ev['offers'])) {
                $offers = $ev['offers'];
                if (isset($offers['@type'])) $offers = [ $offers ];
                foreach ($offers as &$offer) {
                    if (!is_array($offer)) continue;
                    if (!empty($offer['availability']) && isset($https_map[$offer['availability']])) {
                        $offer['availability'] = $https_map[$offer['availability']];
                    }
                    if (!empty($offer['validFrom'])) {
                        $offer['validFrom'] = $fmt_iso_tz($offer['validFrom']);
                    }
                }
                $ev['offers'] = $offers;
            }
        };

        // Applica il fix al/ai nodo/i Event presenti in $data
        foreach ($event_keys as $key) {
            if (isset($data[$key])) {
                // Singolo Event
                if (isset($data[$key]['@type'])) {
                    $fix_event($data[$key]);
                }
                // Collezione di Event
                elseif (is_array($data[$key])) {
                    foreach ($data[$key] as &$maybe_ev) { $fix_event($maybe_ev); }
                }
            }
        }

// --- Fail-safe finale: forza HTTPS e timezone sui campi schema.org all'interno degli Event ---
$force_https_keys = ['eventStatus','eventAttendanceMode','availability'];

$force_fix = function (&$item) use ($force_https_keys, $fmt_iso_tz) {
    if (!is_array($item)) return;
    $types = $item['@type'] ?? null;
    $is_event = (is_string($types) && $types==='Event') || (is_array($types) && in_array('Event',$types,true));
    if (!$is_event) return;

    // 1) HTTPS per i campi schema.org
    foreach ($force_https_keys as $k) {
        if (!empty($item[$k]) && is_string($item[$k])) {
            $item[$k] = str_replace('http://schema.org/','https://schema.org/',$item[$k]);
        }
    }
    if (!empty($item['offers'])) {
        $offers = isset($item['offers']['@type']) ? [ $item['offers'] ] : $item['offers'];
        foreach ($offers as &$offer) {
            if (!is_array($offer)) continue;
            if (!empty($offer['availability']) && is_string($offer['availability'])) {
                $offer['availability'] = str_replace('http://schema.org/','https://schema.org/',$offer['availability']);
            }
            if (!empty($offer['validFrom'])) $offer['validFrom'] = $fmt_iso_tz($offer['validFrom']);
        }
        $item['offers'] = $offers;
    }

    // 2) Timezone su start/end
    if (!empty($item['startDate'])) $item['startDate'] = $fmt_iso_tz($item['startDate']);
    if (!empty($item['endDate']))   $item['endDate']   = $fmt_iso_tz($item['endDate']);
};

// Applica il fail-safe a tutti i nodi top-level
foreach ($data as $k => &$node) {
    $force_fix($node);
}
unset($node);



    foreach ( $event_keys as $key ) {
      if ( isset($data[$key]) && is_array($data[$key]) ) {
        $data[$key] = array_merge($data[$key], $event_patch);
      }
    }
    return $data;
  }, 999999, 2);
}
// --- GLOBAL SCHEMA SANITIZER (ultimissimo) ---
// Forza https://schema.org/ in TUTTO lo stack JSON-LD, a prova di qualsiasi override.
add_filter('rank_math/json_ld', function($data, $jsonld){
    $fix = function (&$node) use (&$fix) {
        if (is_array($node)) {
            foreach ($node as $k => &$v) {
                if (is_string($v) && strpos($v, 'http://schema.org/') === 0) {
                    $v = 'https://' . substr($v, strlen('http://'));
                } elseif (is_array($v)) {
                    $fix($v);
                }
            }
            unset($v);
        }
    };
    $fix($data);
    return $data;
}, 1000000, 2);

// --- ULTIMO PASSO: forza https su schema.org nella stringa JSON-LD finale di Rank Math ---
add_filter('rank_math/json_ld/encoded', function($json){
    if ( ! is_string($json) || $json === '' ) return $json;
    return str_replace('http://schema.org/', 'https://schema.org/', $json);
}, 1000001);

// --- SANITIZER UNIVERSALE: corregge http->https in TUTTI gli script JSON-LD del front-end ---
add_action('wp_head', function() {
    ob_start(function($buffer) {
        if (strpos($buffer, 'http://schema.org/') !== false) {
            $buffer = str_replace('http://schema.org/', 'https://schema.org/', $buffer);
        }
        return $buffer;
    });
}, 0);

add_action('shutdown', function() {
    if (ob_get_length()) ob_end_flush();
}, PHP_INT_MAX);
