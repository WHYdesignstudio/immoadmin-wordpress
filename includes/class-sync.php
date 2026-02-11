<?php
/**
 * Sync Logic - Reads JSON and updates WordPress posts
 *
 * All business logic (field mapping, computed fields, media numbering)
 * lives in the ImmoAdmin backend. This plugin just writes what it receives.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Sync {

    /**
     * Run sync from JSON file
     */
    public static function run($json_file = null) {
        $start_time = microtime(true);

        // Find JSON file
        if (!$json_file) {
            $json_file = self::find_json_file();
        }

        if (!$json_file || !file_exists($json_file)) {
            return array(
                'success' => false,
                'error' => 'JSON-Datei nicht gefunden',
            );
        }

        // Read and parse JSON
        $json_content = file_get_contents($json_file);
        $data = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'JSON-Parse-Fehler: ' . json_last_error_msg(),
            );
        }

        // Validate format
        if (empty($data['_format']) || $data['_format'] !== 'immoadmin-sync') {
            return array(
                'success' => false,
                'error' => 'Ungültiges JSON-Format',
            );
        }

        $stats = array(
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'media_downloaded' => 0,
            'errors' => array(),
        );

        // Get existing posts mapped by ImmoAdmin ID
        $existing_posts = self::get_existing_posts();

        // Process units
        $processed_ids = array();

        if (!empty($data['units'])) {
            foreach ($data['units'] as $unit) {
                $result = self::sync_unit($unit, $existing_posts, $data['meta']['baseUrl'] ?? '');
                $processed_ids[] = $unit['id'];

                if ($result['status'] === 'created') {
                    $stats['created']++;
                } elseif ($result['status'] === 'updated') {
                    $stats['updated']++;
                } elseif ($result['status'] === 'skipped') {
                    $stats['skipped']++;
                } elseif ($result['status'] === 'error') {
                    $stats['errors'][] = $result['error'];
                }

                if (!empty($result['media_downloaded'])) {
                    $stats['media_downloaded'] += $result['media_downloaded'];
                }
            }
        }

        // Delete posts that are no longer in JSON
        foreach ($existing_posts as $immoadmin_id => $post_id) {
            if (!in_array($immoadmin_id, $processed_ids)) {
                wp_delete_post($post_id, true);
                $stats['deleted']++;
            }
        }

        // Log sync
        $duration = round(microtime(true) - $start_time, 2);
        self::log_sync($stats, $duration);

        // Update last sync time
        update_option('immoadmin_last_sync', current_time('mysql'));
        update_option('immoadmin_last_sync_stats', $stats);

        return array(
            'success' => empty($stats['errors']),
            'stats' => $stats,
            'duration' => $duration,
        );
    }

    /**
     * Find the JSON file in the data directory
     */
    private static function find_json_file() {
        if (!is_dir(IMMOADMIN_DATA_DIR)) {
            return null;
        }

        $files = glob(IMMOADMIN_DATA_DIR . '*.json');
        return !empty($files) ? $files[0] : null;
    }

    /**
     * Get existing posts mapped by ImmoAdmin ID
     */
    private static function get_existing_posts() {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value as immoadmin_id, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
             AND p.post_type = %s
             AND p.post_status != %s",
            '_immoadmin_id',
            'immoadmin_wohnung',
            'trash'
        ));

        $map = array();
        foreach ($results as $row) {
            $map[$row->immoadmin_id] = $row->post_id;
        }

        return $map;
    }

    /**
     * Sync a single unit - just write what the backend sends
     */
    private static function sync_unit($unit, $existing_posts, $base_url) {
        $immoadmin_id = $unit['id'];
        $existing_post_id = isset($existing_posts[$immoadmin_id]) ? $existing_posts[$immoadmin_id] : null;

        // Content hash for change detection
        $content_hash = md5('v3:' . json_encode($unit));

        // Skip if nothing changed
        if ($existing_post_id) {
            $stored_hash = get_post_meta($existing_post_id, '_content_hash', true);
            if ($stored_hash === $content_hash) {
                return array('status' => 'skipped');
            }
        }

        // Create or update post
        $post_data = array(
            'post_type'    => 'immoadmin_wohnung',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($unit['title'] ?? 'Einheit'),
            'post_content' => wp_kses_post($unit['description'] ?? ''),
        );

        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post($post_data, true);
            $status = 'updated';
        } else {
            $post_id = wp_insert_post($post_data, true);
            $status = 'created';
        }

        if (is_wp_error($post_id)) {
            return array(
                'status' => 'error',
                'error' => $unit['title'] . ': ' . $post_id->get_error_message(),
            );
        }

        // Store internal meta (content hash is set AFTER media downloads)
        update_post_meta($post_id, '_immoadmin_id', $unit['id']);
        update_post_meta($post_id, '_last_synced', current_time('mysql'));

        // Clean up old numbered media fields before writing new ones
        self::cleanup_dynamic_meta($post_id);

        // Write ALL metaFields from backend directly - no mapping, no logic
        $media_downloaded = 0;
        $media_failed = false;
        $trusted_host = self::parse_trusted_host($base_url);

        if (!empty($unit['metaFields'])) {
            foreach ($unit['metaFields'] as $key => $value) {
                // Media fields (image_N, floor_plan_N, document_N_url): download locally
                if ($value && is_string($value) && self::is_media_url_field($key)) {
                    $local_url = self::download_media($value, $trusted_host);
                    if ($local_url) {
                        update_post_meta($post_id, $key, $local_url);
                        $media_downloaded++;
                    } else {
                        // Fallback: store remote URL if download fails
                        update_post_meta($post_id, $key, esc_url_raw($value));
                        $media_failed = true;
                    }
                } else {
                    // All other fields: write directly (sanitize strings)
                    if (is_numeric($value)) {
                        update_post_meta($post_id, $key, $value);
                    } elseif (is_string($value)) {
                        update_post_meta($post_id, $key, sanitize_text_field($value));
                    } elseif (is_null($value)) {
                        delete_post_meta($post_id, $key);
                    }
                }
            }
        }

        // Only set content hash if ALL media downloaded successfully.
        // If any media failed, the next sync will retry the downloads.
        if (!$media_failed) {
            update_post_meta($post_id, '_content_hash', $content_hash);
        } else {
            // Clear hash so next sync retries
            delete_post_meta($post_id, '_content_hash');
        }

        return array(
            'status' => $status,
            'post_id' => $post_id,
            'media_downloaded' => $media_downloaded,
        );
    }

    /**
     * Check if a meta key is a media URL field that should be downloaded
     */
    private static function is_media_url_field($key) {
        return (bool) preg_match('/^(image_\d+|floor_plan_\d+|document_\d+_url)$/', $key);
    }

    /**
     * Clean up dynamic/numbered meta fields before re-sync
     */
    private static function cleanup_dynamic_meta($post_id) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta}
             WHERE post_id = %d
             AND (
                 meta_key REGEXP '^image_[0-9]+$'
                 OR meta_key REGEXP '^floor_plan_[0-9]+$'
                 OR meta_key REGEXP '^document_[0-9]+_(url|title)$'
             )",
            $post_id
        ));
    }

    /**
     * Parse trusted host from base URL
     */
    private static function parse_trusted_host($base_url) {
        if (empty($base_url)) return '';
        $parsed = parse_url($base_url);
        return !empty($parsed['host']) ? strtolower($parsed['host']) : '';
    }

    /**
     * Allowed MIME types for media downloads
     */
    private static $allowed_mime_types = array(
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );

    /**
     * Download a media file to local storage
     * Returns local URL or null on failure
     */
    private static function download_media($url, $trusted_host = '') {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // SSRF protection
        if (!self::is_safe_url($url, $trusted_host)) {
            error_log('ImmoAdmin: Blocked unsafe media URL: ' . $url);
            return null;
        }

        // Generate local filename from URL
        $filename = sanitize_file_name(basename(parse_url($url, PHP_URL_PATH)));
        if (empty($filename)) return null;

        $local_path = IMMOADMIN_MEDIA_DIR . $filename;
        $local_url = content_url('/immoadmin/media/' . $filename);

        // Skip if already downloaded (and file is not empty/corrupt)
        if (file_exists($local_path) && filesize($local_path) > 0) {
            return $local_url;
        }

        // Download
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'sslverify' => true,
            'limit_response_size' => 50 * 1024 * 1024,
        ));

        if (is_wp_error($response)) {
            error_log('ImmoAdmin: Failed to download media: ' . $response->get_error_message() . ' URL: ' . $url);
            return null;
        }

        // Validate content type
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (!empty($content_type)) {
            $mime = strtolower(trim(explode(';', $content_type)[0]));
            if (!in_array($mime, self::$allowed_mime_types, true)) {
                error_log('ImmoAdmin: Blocked media with disallowed MIME type: ' . $mime);
                return null;
            }
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) return null;

        // Ensure directory exists
        if (!file_exists(IMMOADMIN_MEDIA_DIR)) {
            wp_mkdir_p(IMMOADMIN_MEDIA_DIR);
        }

        file_put_contents($local_path, $body, LOCK_EX);
        return $local_url;
    }

    /**
     * Validate URL is safe to fetch (SSRF protection)
     */
    private static function is_safe_url($url, $trusted_host = '') {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host']) || !isset($parsed['scheme'])) {
            return false;
        }

        if (!in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Trust the ImmoAdmin server
        if ($trusted_host && $host === $trusted_host) {
            return true;
        }

        // Block localhost
        if (in_array($host, array('localhost', '0.0.0.0', '[::1]'), true)) {
            return false;
        }

        // Block private IP ranges
        $ip = gethostbyname($host);
        if ($ip === $host) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    /**
     * Log sync to database
     */
    private static function log_sync($stats, $duration) {
        $log = get_option('immoadmin_sync_log', array());

        array_unshift($log, array(
            'time' => current_time('mysql'),
            'stats' => $stats,
            'duration' => $duration,
        ));

        $log = array_slice($log, 0, 50);
        update_option('immoadmin_sync_log', $log);
    }

    /**
     * Get sync log
     */
    public static function get_log($limit = 10) {
        $log = get_option('immoadmin_sync_log', array());
        return array_slice($log, 0, $limit);
    }

    /**
     * Get system status
     */
    public static function get_status() {
        $json_file = self::find_json_file();
        $json_exists = $json_file && file_exists($json_file);

        $json_data = null;
        if ($json_exists) {
            $json_data = json_decode(file_get_contents($json_file), true);
        }

        return array(
            'json_exists' => $json_exists,
            'json_file' => $json_file ? basename($json_file) : null,
            'json_meta' => $json_data['meta'] ?? null,
            'media_dir_writable' => is_writable(IMMOADMIN_MEDIA_DIR) || is_writable(IMMOADMIN_DATA_DIR),
            'unit_count' => wp_count_posts('immoadmin_wohnung')->publish ?? 0,
            'building_count' => !empty($json_data['buildings']) ? count($json_data['buildings']) : 0,
            'last_sync' => get_option('immoadmin_last_sync'),
            'last_stats' => get_option('immoadmin_last_sync_stats'),
            'webhook_token' => get_option('immoadmin_webhook_token_masked', ''),
            'webhook_configured' => !empty(get_option('immoadmin_webhook_token_hash')),
        );
    }

    /**
     * Reset content hashes to force a full re-sync
     */
    public static function cleanup_old_meta() {
        global $wpdb;

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != %s",
            'immoadmin_wohnung',
            'trash'
        ));

        if (empty($post_ids)) {
            return array('cleaned' => 0, 'posts' => 0);
        }

        $cleaned = 0;
        foreach ($post_ids as $post_id) {
            // Reset content hash to force re-sync
            delete_post_meta($post_id, '_content_hash');
            $cleaned++;
        }

        return array('cleaned' => $cleaned, 'posts' => count($post_ids));
    }
}
