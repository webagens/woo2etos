<?php
/**
 * Plugin Name: WP2Etos – Aggregatore Etos
 * Description: Crea un attributo aggregatore "Taglia (WC)" (slug: taglia-wc) che unifica tutti gli attributi taglia provenienti da Etos e li collega ai prodotti senza toccare le varianti. Sicuro per ambienti solo wp-admin: nessuna azione automatica finché non attivi i toggle nella pagina strumenti.
 * Version: 1.0.0
 * Author: Simone Cansella
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: wp2etos
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', true );
}
if ( ! defined( 'WP_DEBUG_LOG' ) ) {
    define( 'WP_DEBUG_LOG', true );
}

define( 'WP2ETOS_AT_VERSION', '1.0.0' );
define( 'WP2ETOS_AT_SLUG', 'wp2etos' );
define( 'WP2ETOS_AT_OPTION', 'wp2etos_at_options' );
define( 'WP2ETOS_AT_TAX_SLUG', 'taglia-wc' );
define( 'WP2ETOS_AT_TAX', 'pa_' . WP2ETOS_AT_TAX_SLUG );
define( 'WP2ETOS_AT_META_HASH', '_wp2etos_taglia_hash' );

// Safe init after plugins loaded
add_action( 'plugins_loaded', function(){
    // Load only if WooCommerce exists
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function(){
            echo '<div class="notice notice-warning"><p><strong>WP2Etos – Aggregatore Taglie</strong>: WooCommerce non è attivo. Il plugin resta inattivo.</p></div>';
        });
        return;
    }
    require_once __DIR__ . '/includes/class-wp2etos.php';
    WP2ETOS::instance();
});

// No automatic actions on activation: safety-first
function wp2etos_uninstall_cleanup() {
    // cleanup only options/transients, not taxonomy terms
    delete_option( WP2ETOS_AT_OPTION );
}
register_uninstall_hook( __FILE__, 'wp2etos_uninstall_cleanup' );
