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

// Delete all immoadmin_unit posts
$posts = get_posts(array(
    'post_type' => 'immoadmin_unit',
    'numberposts' => -1,
    'post_status' => 'any',
));

foreach ($posts as $post) {
    // Delete post and all its meta
    wp_delete_post($post->ID, true); // true = force delete, bypass trash
}

// Delete all plugin options
delete_option('immoadmin_webhook_token');
delete_option('immoadmin_webhook_token_hash');
delete_option('immoadmin_webhook_token_masked');
delete_option('immoadmin_connection_verified');
delete_option('immoadmin_last_sync');
delete_option('immoadmin_last_sync_stats');
delete_option('immoadmin_sync_log');

// Delete data directory and all files
$data_dir = WP_CONTENT_DIR . '/immoadmin/';
if (is_dir($data_dir)) {
    // Delete all files in directory
    $files = glob($data_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    // Delete media subdirectory
    $media_dir = $data_dir . 'media/';
    if (is_dir($media_dir)) {
        $media_files = glob($media_dir . '*');
        foreach ($media_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($media_dir);
    }

    // Delete main directory
    rmdir($data_dir);
}

// Clear any transients
delete_transient('immoadmin_cache');

// Flush rewrite rules
flush_rewrite_rules();
