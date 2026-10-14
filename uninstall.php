<?php
/**
 * ImmoAdmin Uninstall
 *
 * Runs when the plugin is deleted (not just deactivated).
 * Cleans up all data created by the plugin.
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Stop pending background syncs
if (function_exists('wp_unschedule_hook')) {
    wp_unschedule_hook('immoadmin_background_sync');
} else {
    wp_clear_scheduled_hook('immoadmin_background_sync');
}

// Delete all immoadmin posts (both old and new post type)
$posts = get_posts(array(
    'post_type' => array('immoadmin_wohnung', 'immoadmin_unit'),
    'numberposts' => -1,
    'post_status' => 'any',
));

foreach ($posts as $post) {
    // Delete post and all its meta
    wp_delete_post($post->ID, true); // true = force delete, bypass trash
}

// Delete all plugin options
foreach (array(
    'immoadmin_webhook_token',
    'immoadmin_webhook_token_hash',
    'immoadmin_webhook_token_masked',
    'immoadmin_connection_verified',
    'immoadmin_last_sync',
    'immoadmin_last_sync_stats',
    'immoadmin_last_sync_result',
    'immoadmin_sync_log',
    'immoadmin_sync_status',
    'immoadmin_sync_progress',
    'immoadmin_sync_started',
    'immoadmin_sync_rerun',
    'immoadmin_sync.lock',
    'immoadmin_db_version',
    'immoadmin_storage_version',
    'immoadmin_payload_secret',
    'immoadmin_backend_host',
    'immoadmin_public_state',
    'immoadmin_bricks_enabled',
) as $immoadmin_option) {
    delete_option($immoadmin_option);
}

// Clear transients (rate-limit buckets immoadmin_rate_*, filter row cache
// immoadmin_frows_*, immoadmin_storage_retry …). Stored as options unless an
// object cache is active — there they expire on their own.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like('_transient_immoadmin_') . '%',
    $wpdb->esc_like('_transient_timeout_immoadmin_') . '%'
));
delete_transient('immoadmin_cache');

/**
 * Delete a directory tree incl. dotfiles (.htaccess, temp files).
 * Only ever called for the plugin's own data directory.
 */
function immoadmin_uninstall_rmdir($dir) {
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) ?: array() as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            immoadmin_uninstall_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

// Delete data directory (payloads, media, .htaccess files)
immoadmin_uninstall_rmdir(WP_CONTENT_DIR . '/immoadmin');

// Flush rewrite rules
flush_rewrite_rules();
