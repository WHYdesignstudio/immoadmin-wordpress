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
     * Determine the real client IP, even behind Cloudflare / Coolify reverse proxies.
     *
     * Order of preference:
     *   1. CF-Connecting-IP (Cloudflare, single trusted IP)
     *   2. X-Forwarded-For (first hop = original client)
     *   3. REMOTE_ADDR (direct connection)
     *
     * All values are sanitized and IP-validated. Returns 'unknown' if nothing usable.
     */
    private static function get_client_ip() {
        $candidates = array();

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $xff = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            // XFF can be a comma-separated chain — the first hop is the real client.
            $parts = explode(',', $xff);
            $candidates[] = trim($parts[0]);
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return 'unknown';
    }

    /**
     * Increment a rate-limit bucket. Returns true if request is allowed,
     * false if the bucket is exhausted.
     */
    private static function consume_rate_bucket($key, $limit, $window = 60) {
        $requests = (int) get_transient($key);
        if ($requests >= $limit) {
            return false;
        }
        set_transient($key, $requests + 1, $window);
        return true;
    }

    /**
     * Verify webhook request (token + signature, both required)
     *
     * Rate limiting model:
     *   - Pre-auth check: only verifies the bucket is not already exhausted (no increment).
     *   - Post-auth (on FAILURE): increments two buckets — by-IP and by-token-prefix —
     *     so a flood of bogus requests can't lock out the real backend's token bucket,
     *     and a single misbehaving proxy IP can't either.
     *   - Successful auth does NOT consume budget.
     */
    public static function verify_token($request) {
        $ip = self::get_client_ip();
        $ip_rate_key = 'immoadmin_rate_ip_' . md5($ip);

        // Pre-check: if IP bucket is already full, reject immediately without
        // doing any token work. We DO NOT increment here.
        if ((int) get_transient($ip_rate_key) >= 20) {
            return new WP_Error(
                'rate_limited',
                'Zu viele Anfragen',
                array('status' => 429)
            );
        }

        // Token MUST come from header (never from query params - those leak in logs)
        $token = $request->get_header('X-Auth-Token');
        $signature = $request->get_header('X-Signature');
        $timestamp = $request->get_header('X-Timestamp');

        // Helper to record a failed auth attempt against both IP and token-prefix buckets.
        $record_failure = function ($token_for_bucket) use ($ip_rate_key) {
            // IP bucket: 20 / minute
            $ip_requests = (int) get_transient($ip_rate_key);
            set_transient($ip_rate_key, $ip_requests + 1, 60);

            // Per-token bucket keyed on a short hash prefix of the supplied token.
            // This lets a legit high-traffic backend share a bucket with itself
            // but isolates it from junk tokens.
            if (!empty($token_for_bucket)) {
                $token_bucket_key = 'immoadmin_rate_tok_' . substr(hash('sha256', $token_for_bucket), 0, 16);
                $token_requests = (int) get_transient($token_bucket_key);
                set_transient($token_bucket_key, $token_requests + 1, 60);
            }
        };

        if (empty($token)) {
            $record_failure('');
            return new WP_Error(
                'unauthorized',
                'Auth-Token fehlt',
                array('status' => 401)
            );
        }

        // Trim token early so the bucket key is stable
        $token = trim($token);

        // Per-token rate limit (200 / minute — generous, only meaningful for the real token)
        $token_bucket_key = 'immoadmin_rate_tok_' . substr(hash('sha256', $token), 0, 16);
        if ((int) get_transient($token_bucket_key) >= 200) {
            return new WP_Error(
                'rate_limited',
                'Zu viele Anfragen (Token)',
                array('status' => 429)
            );
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

        // Verify token by comparing hashes
        $received_hash = hash('sha256', $token);

        if (empty($stored_hash) || !hash_equals($stored_hash, $received_hash)) {
            $record_failure($token);
            return new WP_Error(
                'unauthorized',
                'Ungültiger Token',
                array('status' => 401)
            );
        }

        // Signature + Timestamp are REQUIRED (prevents replay attacks)
        if (empty($signature) || empty($timestamp)) {
            $record_failure($token);
            return new WP_Error(
                'unauthorized',
                'Signatur und Timestamp erforderlich',
                array('status' => 401)
            );
        }

        // Check timestamp (max 5 minutes old)
        $request_time = intval($timestamp);
        $current_time = time();

        if (abs($current_time - $request_time) > 300) {
            $record_failure($token);
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
            $record_failure($token);
            return new WP_Error(
                'unauthorized',
                'Ungültige Signatur',
                array('status' => 401)
            );
        }

        // Auth succeeded — do NOT consume any rate-limit budget.
        return true;
    }

    /**
     * Handle sync webhook
     * Saves JSON and schedules background sync (returns immediately)
     */
    public static function handle_sync($request) {
        // Mark connection as verified (token was correct)
        ImmoAdmin_Admin::mark_connection_verified();

        // Check if JSON data was sent directly in the request body
        $json_data = $request->get_body();

        // Reject oversized payloads (max 20MB)
        if (strlen($json_data) > 20 * 1024 * 1024) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payload zu groß (max 20MB)',
            ), 413);
        }

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
                $saved = file_put_contents($json_file, $json_data, LOCK_EX);

                if ($saved === false) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Konnte JSON-Datei nicht speichern',
                    ), 500);
                }

                // Schedule background sync (runs immediately via WP Cron)
                // Clear any previously scheduled sync first
                wp_clear_scheduled_hook('immoadmin_background_sync');
                wp_schedule_single_event(time(), 'immoadmin_background_sync', array($json_file));

                // Try to spawn the cron immediately
                spawn_cron();

                update_option('immoadmin_sync_status', 'running');

                return new WP_REST_Response(array(
                    'success' => true,
                    'message' => 'Sync gestartet (Hintergrund)',
                    'method'  => 'background',
                ), 200);
            }
        }

        // Fallback: Run sync with existing local file (old FTP method)
        wp_clear_scheduled_hook('immoadmin_background_sync');
        wp_schedule_single_event(time(), 'immoadmin_background_sync');
        spawn_cron();

        update_option('immoadmin_sync_status', 'running');

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Sync gestartet (Hintergrund)',
            'method'  => 'background',
        ), 200);
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
