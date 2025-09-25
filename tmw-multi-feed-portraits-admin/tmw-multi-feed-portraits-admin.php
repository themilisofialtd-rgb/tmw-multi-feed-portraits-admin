<?php
/*
Plugin Name: TMW Multi-Feed Portraits Admin
Plugin URI: https://top-models.webcam
Description: Flipbox / multi-feed portraits admin plugin for Top-Models.webcam. 
             Provides migration helpers and admin tools for syncing model data.
Version: 1.0.0
Author: Top-Models.webcam
Author URI: https://top-models.webcam
License: GPL2
Text Domain: tmw-multi-feed-portraits-admin
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define constants
 */
define( 'TMW_MFPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMW_MFPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Include core files
 * Add your migration/admin logic here
 */
require_once TMW_MFPA_PLUGIN_DIR . 'migration.php';

/**
 * Plugin activation hook
 */
function tmw_mfpa_activate() {
    // Run migration logic if needed on activation
    if ( function_exists( 'tmw147d_migrate_terms_to_cpt' ) ) {
        tmw147d_migrate_terms_to_cpt();
    }
}
register_activation_hook( __FILE__, 'tmw_mfpa_activate' );

/**
 * Admin notice (example)
 */
function tmw_mfpa_admin_notice() {
    if ( current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-success is-dismissible">
            <p><strong>TMW Multi-Feed Portraits Admin</strong> is active.</p>
        </div>';
    }
}
add_action( 'admin_notices', 'tmw_mfpa_admin_notice' );
