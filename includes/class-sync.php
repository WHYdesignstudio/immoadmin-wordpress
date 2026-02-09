<?php
/**
 * Sync Logic - Reads JSON and updates WordPress posts
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

        // Build a map of building IDs to names
        $buildings = array();
        if (!empty($data['buildings'])) {
            foreach ($data['buildings'] as $building) {
                $buildings[$building['id']] = $building['name'];
            }
        }

        // Get existing posts mapped by ImmoAdmin ID
        $existing_posts = self::get_existing_posts();

        // Process units
        $processed_ids = array();

        if (!empty($data['units'])) {
            foreach ($data['units'] as $unit) {
                $result = self::sync_unit($unit, $buildings, $existing_posts);
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

                // Track media downloads
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

        if (empty($files)) {
            return null;
        }

        // Return first JSON file found (usually there's only one per site)
        return $files[0];
    }

    /**
     * Get existing posts mapped by ImmoAdmin ID
     */
    private static function get_existing_posts() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT pm.meta_value as immoadmin_id, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_immoadmin_id'
             AND p.post_type = 'immoadmin_unit'
             AND p.post_status != 'trash'"
        );

        $map = array();
        foreach ($results as $row) {
            $map[$row->immoadmin_id] = $row->post_id;
        }

        return $map;
    }

    /**
     * Sync a single unit
     */
    private static function sync_unit($unit, $buildings, $existing_posts) {
        $immoadmin_id = $unit['id'];
        $existing_post_id = isset($existing_posts[$immoadmin_id]) ? $existing_posts[$immoadmin_id] : null;

        // Generate content hash for change detection
        $content_hash = md5(json_encode($unit));

        // Check if we need to update
        if ($existing_post_id) {
            $stored_hash = get_post_meta($existing_post_id, '_content_hash', true);
            if ($stored_hash === $content_hash) {
                return array('status' => 'skipped');
            }
        }

        // Prepare post data (sanitize inputs)
        $post_data = array(
            'post_type'    => 'immoadmin_unit',
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

        // Update meta fields
        self::update_meta_fields($post_id, $unit, $buildings);

        // Store content hash
        update_post_meta($post_id, '_content_hash', $content_hash);
        update_post_meta($post_id, '_last_synced', current_time('mysql'));

        // Download media
        $media_downloaded = self::sync_media($post_id, $unit);

        return array(
            'status' => $status,
            'post_id' => $post_id,
            'media_downloaded' => $media_downloaded,
        );
    }

    /**
     * Update all meta fields for a post
     */
    private static function update_meta_fields($post_id, $unit, $buildings) {
        // Store ImmoAdmin ID
        update_post_meta($post_id, '_immoadmin_id', $unit['id']);

        // Building info
        if (!empty($unit['buildingId'])) {
            update_post_meta($post_id, 'building_id', $unit['buildingId']);
            $building_name = isset($buildings[$unit['buildingId']]) ? $buildings[$unit['buildingId']] : '';
            update_post_meta($post_id, 'building_name', $building_name);
        }

        // Field mappings (JSON key => meta key)
        $field_map = array(
            'externalId'            => 'external_id',
            'status'                => 'status',
            'objectType'            => 'object_type',
            'marketingType'         => 'marketing_type',
            'street'                => 'street',
            'houseNumber'           => 'house_number',
            'staircase'             => 'staircase',
            'doorNumber'            => 'door_number',
            'floor'                 => 'floor',
            'postalCode'            => 'postal_code',
            'city'                  => 'city',
            'country'               => 'country',
            'orientation'           => 'orientation',
            'latitude'              => 'latitude',
            'longitude'             => 'longitude',
            'livingArea'            => 'living_area',
            'usableArea'            => 'usable_area',
            'totalArea'             => 'total_area',
            'plotArea'              => 'plot_area',
            'balconyArea'           => 'balcony_area',
            'terraceArea'           => 'terrace_area',
            'roofTerraceArea'       => 'roof_terrace_area',
            'loggiaArea'            => 'loggia_area',
            'gardenArea'            => 'garden_area',
            'basementArea'          => 'basement_area',
            'storageArea'           => 'storage_area',
            'roomCount'             => 'room_count',
            'bedrooms'              => 'bedrooms',
            'bathrooms'             => 'bathrooms',
            'toilets'               => 'toilets',
            'purchasePrice'         => 'purchase_price',
            'purchasePriceInvestor' => 'purchase_price_investor',
            'purchasePricePrivate'  => 'purchase_price_private',
            'rentCold'              => 'rent_cold',
            'rentWarm'              => 'rent_warm',
            'operatingCosts'        => 'operating_costs',
            'deposit'               => 'deposit',
            'commission'            => 'commission',
            'pricePerSqm'           => 'price_per_sqm',
            'constructionYear'      => 'construction_year',
            'renovationYear'        => 'renovation_year',
            'condition'             => 'condition',
            'equipment'             => 'equipment',
            'heatingType'           => 'heating_type',
            'energySource'          => 'energy_source',
            'hwb'                   => 'hwb',
            'hwbClass'              => 'hwb_class',
            'fgee'                  => 'fgee',
            'fgeeClass'             => 'fgee_class',
            'parkingSpaces'         => 'parking_spaces',
            'garageSpaces'          => 'garage_spaces',
            'outdoorSpaces'         => 'outdoor_spaces',
            'carportSpaces'         => 'carport_spaces',
            'parkingPrice'          => 'parking_price',
        );

        // Numeric fields (sanitize with floatval/intval)
        $numeric_fields = array(
            'floor', 'latitude', 'longitude',
            'living_area', 'usable_area', 'total_area', 'plot_area',
            'balcony_area', 'terrace_area', 'roof_terrace_area', 'loggia_area',
            'garden_area', 'basement_area', 'storage_area',
            'room_count', 'bedrooms', 'bathrooms', 'toilets',
            'purchase_price', 'purchase_price_investor', 'purchase_price_private',
            'rent_cold', 'rent_warm', 'operating_costs', 'deposit', 'price_per_sqm',
            'construction_year', 'renovation_year',
            'hwb', 'fgee',
            'parking_spaces', 'garage_spaces', 'outdoor_spaces', 'carport_spaces', 'parking_price',
        );

        foreach ($field_map as $json_key => $meta_key) {
            if (isset($unit[$json_key])) {
                $value = $unit[$json_key];
                // Sanitize: numeric fields get floatval, strings get sanitize_text_field
                if (in_array($meta_key, $numeric_fields, true)) {
                    $value = is_numeric($value) ? floatval($value) : 0;
                } else {
                    $value = sanitize_text_field((string) $value);
                }
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        // Arrays/Objects as JSON (encode ensures no raw HTML)
        if (!empty($unit['features'])) {
            update_post_meta($post_id, 'features', wp_json_encode($unit['features']));
        }
        if (!empty($unit['extras'])) {
            update_post_meta($post_id, 'extras', wp_json_encode($unit['extras']));
        }
    }

    /**
     * Sync media files (download if needed)
     */
    private static function sync_media($post_id, $unit) {
        $downloaded = 0;

        if (empty($unit['media'])) {
            return $downloaded;
        }

        $local_media = array(
            'images' => array(),
            'floor_plans' => array(),
            'documents' => array(),
        );

        foreach (array('images', 'floorPlans', 'documents') as $type) {
            $meta_key = $type === 'floorPlans' ? 'floor_plans' : $type;

            if (empty($unit['media'][$type])) {
                continue;
            }

            foreach ($unit['media'][$type] as $media) {
                $local_path = self::maybe_download_media($media);

                if ($local_path) {
                    $local_media[$meta_key][] = array(
                        'url' => $local_path,
                        'title' => $media['title'] ?? $media['originalFilename'] ?? '',
                        'hash' => $media['contentHash'] ?? '',
                    );

                    if (strpos($local_path, '/immoadmin/media/') !== false) {
                        $downloaded++;
                    }
                }
            }
        }

        // Store local media URLs
        update_post_meta($post_id, 'images', json_encode($local_media['images']));
        update_post_meta($post_id, 'floor_plans', json_encode($local_media['floor_plans']));
        update_post_meta($post_id, 'documents', json_encode($local_media['documents']));

        return $downloaded;
    }

    /**
     * Validate URL is safe to fetch (SSRF protection)
     */
    private static function is_safe_url($url) {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host']) || !isset($parsed['scheme'])) {
            return false;
        }

        // Only allow HTTP(S)
        if (!in_array(strtolower($parsed['scheme']), array('http', 'https'), true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Block localhost
        if (in_array($host, array('localhost', '0.0.0.0', '[::1]'), true)) {
            return false;
        }

        // Resolve hostname to IP and check for private ranges
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false; // DNS resolution failed
        }

        // Block private and reserved IP ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
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
     * Download media file if not exists or hash changed
     */
    private static function maybe_download_media($media) {
        if (empty($media['url'])) {
            return null;
        }

        $url = $media['url'];

        // SSRF protection: validate URL before fetching
        if (!self::is_safe_url($url)) {
            error_log('ImmoAdmin: Blocked unsafe media URL: ' . $url);
            return null;
        }

        $hash = $media['contentHash'] ?? '';
        $filename = $media['originalFilename'] ?? basename(parse_url($url, PHP_URL_PATH));

        // Sanitize filename to prevent path traversal
        $filename = sanitize_file_name($filename);

        // Create unique filename with hash
        if ($hash) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = $name . '-' . substr($hash, 0, 8) . '.' . $ext;
        }

        $local_path = IMMOADMIN_MEDIA_DIR . $filename;
        $local_url = content_url('/immoadmin/media/' . $filename);

        // Check if file exists with same hash
        if (file_exists($local_path)) {
            return $local_url;
        }

        // Download file (SSL verification enabled, 50MB limit)
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'sslverify' => true,
            'limit_response_size' => 50 * 1024 * 1024, // 50MB max
        ));

        if (is_wp_error($response)) {
            error_log('ImmoAdmin: Failed to download media: ' . $response->get_error_message());
            return null;
        }

        // Validate content type (required - reject if missing)
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (empty($content_type)) {
            error_log('ImmoAdmin: Blocked media with missing Content-Type header');
            return null;
        }
        $mime = strtolower(trim(explode(';', $content_type)[0]));
        if (!in_array($mime, self::$allowed_mime_types, true)) {
            error_log('ImmoAdmin: Blocked media with disallowed MIME type: ' . $mime);
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return null;
        }

        // Ensure directory exists
        if (!file_exists(IMMOADMIN_MEDIA_DIR)) {
            wp_mkdir_p(IMMOADMIN_MEDIA_DIR);
        }

        // Save file with exclusive lock
        file_put_contents($local_path, $body, LOCK_EX);

        return $local_url;
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

        // Keep only last 50 entries
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
            'unit_count' => wp_count_posts('immoadmin_unit')->publish ?? 0,
            'building_count' => !empty($json_data['buildings']) ? count($json_data['buildings']) : 0,
            'last_sync' => get_option('immoadmin_last_sync'),
            'last_stats' => get_option('immoadmin_last_sync_stats'),
            'webhook_token' => get_option('immoadmin_webhook_token_masked', ''),
            'webhook_configured' => !empty(get_option('immoadmin_webhook_token_hash')),
        );
    }
}
