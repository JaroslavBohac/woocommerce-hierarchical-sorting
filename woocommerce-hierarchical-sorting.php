<?php
/**
 * Plugin Name: WooCommerce Hierarchical Sorting
 * Description: Custom product ordering by category → size → manufacturer → price.
 * Version: 1.2.0
 * Author: Copilot
 * Text Domain: wc-hierarchical-sorting
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load main class
require_once plugin_dir_path( __FILE__ ) . 'includes/class-main.php';

// Register activation/deactivation hooks
register_activation_hook( __FILE__, array( 'WC_Hierarchical_Sorting', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'WC_Hierarchical_Sorting', 'on_deactivation' ) );

// Init on plugins_loaded
add_action( 'plugins_loaded', array( 'WC_Hierarchical_Sorting', 'init' ) );

// Public API functions
if ( ! function_exists( 'wc_hs_build_product_sort_key' ) ) {
    function wc_hs_build_product_sort_key( $product_id ) {
        return WC_Hierarchical_Sorting::build_sort_key( $product_id );
    }
}

if ( ! function_exists( 'wc_hs_schedule_migration' ) ) {
    function wc_hs_schedule_migration() {
        return WC_Hierarchical_Sorting::schedule_migration();
    }
}

if ( ! function_exists( 'wc_hs_get_migration_status' ) ) {
    function wc_hs_get_migration_status() {
        return WC_Hierarchical_Sorting::get_migration_status();
    }
}
