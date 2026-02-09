<?php
/**
 * Webhook REST API Endpoint
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Webhook {

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        register_rest_route('immoadmin/v1', '/sync', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_sync'),
            'permission_callback' => array(__CLASS__, 'verify_token'),
        ));

        register_rest_route('immoadmin/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_status'),
            'permission_callback' => array(__CLASS__, 'verify_token'),
        ));

        // Verify endpoint - just checks if token is valid
        register_rest_route('immoadmin/v1', '/verify', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_verify'),
            'permission_callback' => array(__CLASS__, 'verify_token'),
        ));

        // Debug endpoint removed for security - token info should not be publicly accessible
    }

    /**
     * Verify webhook request (token + signature)
     */
    public static function verify_token($request) {
        $token = $request->get_header('X-Auth-Token');
        $signature = $request->get_header('X-Signature');
        $timestamp = $request->get_header('X-Timestamp');

        if (empty($token)) {
            $token = $request->get_param('token');
        }

        $stored_hash = get_option('immoadmin_webhook_token_hash');

        // Fallback: Check for old plain-text token (migration)
        if (empty($stored_hash)) {
            $old_token = get_option('immoadmin_webhook_token');
            if (!empty($old_token)) {
                // Migrate to hashed storage
                $stored_hash = hash('sha256', trim($old_token));
                update_option('immoadmin_webhook_token_hash', $stored_hash);
                delete_option('immoadmin_webhook_token');
            }
        }

        // Trim token
        $token = trim($token);

        // Verify token by comparing hashes
        $received_hash = hash('sha256', $token);

        if (empty($stored_hash) || !hash_equals($stored_hash, $received_hash)) {
            return new WP_Error(
                'unauthorized',
                'Ungültiger Token',
                array('status' => 401)
            );
        }

        // If signature is provided, verify it (enhanced security)
        if (!empty($signature) && !empty($timestamp)) {
            // Check timestamp (max 5 minutes old)
            $request_time = intval($timestamp);
            $current_time = time();

            if (abs($current_time - $request_time) > 300) {
                return new WP_Error(
                    'unauthorized',
                    'Request abgelaufen (Timestamp zu alt)',
                    array('status' => 401)
                );
            }

            // Verify HMAC signature
            $body = $request->get_body();
            $expected_signature = hash_hmac('sha256', $timestamp . $body, $token);

            if (!hash_equals($expected_signature, $signature)) {
                return new WP_Error(
                    'unauthorized',
                    'Ungültige Signatur',
                    array('status' => 401)
                );
            }
        }

        return true;
    }

    /**
     * Handle sync webhook
     */
    public static function handle_sync($request) {
        // Mark connection as verified (token was correct)
        ImmoAdmin_Admin::mark_connection_verified();

        // If a GitHub token is sent from ImmoAdmin, store it for auto-updates
        $github_token = $request->get_header('X-GitHub-Token');
        if (!empty($github_token)) {
            update_option('immoadmin_github_token', sanitize_text_field($github_token));
        }

        // Check if JSON data was sent directly in the request body
        $json_data = $request->get_body();

        if (!empty($json_data) && $json_data !== '{}') {
            // Validate it's valid JSON
            $data = json_decode($json_data, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['_format']) && $data['_format'] === 'immoadmin-sync') {
                // Save JSON to local file
                $data_dir = WP_CONTENT_DIR . '/immoadmin/';
                if (!file_exists($data_dir)) {
                    wp_mkdir_p($data_dir);
                }

                // Use project slug for filename if available
                $filename = 'data.json';
                if (!empty($data['meta']['projectSlug'])) {
                    $filename = sanitize_file_name($data['meta']['projectSlug']) . '.json';
                }

                $json_file = $data_dir . $filename;
                $saved = file_put_contents($json_file, $json_data);

                if ($saved === false) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Konnte JSON-Datei nicht speichern',
                    ), 500);
                }

                // Now run sync with the saved file
                $result = ImmoAdmin_Sync::run($json_file);

                return new WP_REST_Response(array(
                    'success' => $result['success'],
                    'message' => $result['success'] ? 'Sync erfolgreich' : ($result['error'] ?? 'Sync fehlgeschlagen'),
                    'stats'   => $result['stats'] ?? null,
                    'duration' => $result['duration'] ?? null,
                    'method'  => 'direct',
                ), $result['success'] ? 200 : 500);
            }
        }

        // Fallback: Run sync with existing local file (old FTP method)
        $result = ImmoAdmin_Sync::run();

        return new WP_REST_Response(array(
            'success' => $result['success'],
            'message' => $result['success'] ? 'Sync erfolgreich' : ($result['error'] ?? 'Sync fehlgeschlagen'),
            'stats'   => $result['stats'] ?? null,
            'duration' => $result['duration'] ?? null,
            'method'  => 'file',
        ), $result['success'] ? 200 : 500);
    }

    /**
     * Handle verify request - just confirms token is valid
     */
    public static function handle_verify($request) {
        // Mark connection as verified
        ImmoAdmin_Admin::mark_connection_verified();

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Token gültig',
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
        ), 200);
    }

    /**
     * Handle status request
     */
    public static function handle_status($request) {
        $status = ImmoAdmin_Sync::get_status();

        return new WP_REST_Response(array(
            'success' => true,
            'status'  => $status,
        ), 200);
    }

}
