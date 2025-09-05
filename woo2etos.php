<?php
/**
 * Plugin Name: Woo2Etos – Aggregatore Etos per WooCommerce
 * Description: Aggrega più attributi "TAGLIA" in un unico "Taglia" (slug: taglia-wc) e li collega ai prodotti.
 * Version: 1.0.0
 * Author: Simone Cansella
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: woo2etos
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', true );
}
if ( ! defined( 'WP_DEBUG_LOG' ) ) {
    define( 'WP_DEBUG_LOG', true );
}

define( 'WOO2ETOS_AT_VERSION', '1.0.0' );
define( 'WOO2ETOS_AT_SLUG', 'woo2etos' );
define( 'WOO2ETOS_AT_OPTION', 'woo2etos_at_options' );
define( 'WOO2ETOS_AT_TAX_SLUG', 'taglia-w2e' );
define( 'WOO2ETOS_AT_TAX', 'pa_' . WOO2ETOS_AT_TAX_SLUG );
define( 'WOO2ETOS_AT_META_HASH', '_woo2etos_taglia_hash' );

// Safe init after plugins loaded
add_action( 'plugins_loaded', function(){
    // Load only if WooCommerce exists
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function(){
            echo '<div class="notice notice-warning"><p><strong>Woo2Etos</strong>: WooCommerce non è attivo. Il plugin resta inattivo.</p></div>';
        });
        return;
    }
    require_once __DIR__ . '/includes/class-woo2etos.php';
    Woo2Etos::instance();
});

// No automatic actions on activation: safety-first
function woo2etos_uninstall_cleanup() {
    // cleanup only options/transients, not taxonomy terms
    delete_option( WOO2ETOS_AT_OPTION );
}
register_uninstall_hook( __FILE__, 'woo2etos_uninstall_cleanup' );
