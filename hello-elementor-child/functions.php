<?php
/** [A02] Bootstrap Discorg (child theme) */
if (!defined('ABSPATH')) exit;

// Carica lo stile del tema padre
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'hello-elementor-parent',
        get_template_directory_uri() . '/style.css'
    );
}, 20);

// Includi il bootstrap dei moduli Discorg
require_once get_stylesheet_directory() . '/inc/bootstrap.php';