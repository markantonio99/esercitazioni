<?php
/**
 * Discoteche.org - Definizioni costanti
 *
 * @package Discoteche.org
 * @since 1.3.0
 */

if (!defined('ABSPATH')) exit;

// Evita doppio caricamento
if (defined('DISCORG_CONSTANTS_LOADED')) return;
define('DISCORG_CONSTANTS_LOADED', true);

// Versione del tema child
if (!defined('DISCORG_VERSION'))       define('DISCORG_VERSION', '1.3.0');

// Costanti per i post type e tassonomie
if (!defined('DISCORG_POST_TYPE'))     define('DISCORG_POST_TYPE', 'discoteche');
if (!defined('DISCORG_TAX_LOCALITA'))  define('DISCORG_TAX_LOCALITA', 'localita');
if (!defined('DISCORG_BRAND'))         define('DISCORG_BRAND', 'Discoteche.org');
if (!defined('DISCORG_DEFAULT_IMAGE')) define('DISCORG_DEFAULT_IMAGE', 'https://discoteche.org/wp-content/uploads/brand/placeholder-discoteca.webp');

// Costanti API - possono essere sovrascritte in wp-config.php se necessario
/**
 * Nota: definire DISCORG_GOOGLE_PLACES_API_KEY in wp-config.php (non nel tema).
 * Esempio:
 *   define('DISCORG_GOOGLE_PLACES_API_KEY', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxx');
 */

// Non definiamo qui DISCORG_REMOVEBG_API_KEY perché verrà gestita tramite option
