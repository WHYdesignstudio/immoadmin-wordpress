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
     * Client IP for rate limiting.
     *
     * REMOTE_ADDR only — CF-Connecting-IP / X-Forwarded-For are set by the
     * client and would let an attacker rotate through fake IPs (and dodge or
     * redirect the failure counter). They are honoured only when REMOTE_ADDR
     * is a proxy the site declared as trusted:
     *   define('IMMOADMIN_TRUSTED_PROXIES', '10.0.0.2,10.0.0.3');
     * or the 'immoadmin_trusted_proxies' filter.
     */
    private static function get_client_ip() {
        $proxies = defined('IMMOADMIN_TRUSTED_PROXIES') ? IMMOADMIN_TRUSTED_PROXIES : array();
        if (is_string($proxies)) {
            $proxies = explode(',', $proxies);
        }
        $proxies = (array) apply_filters('immoadmin_trusted_proxies', (array) $proxies);

        return self::resolve_client_ip($_SERVER, $proxies);
    }

    /**
     * Pure helper behind get_client_ip() (unit-tested).
     *
     * Behind a trusted proxy: CF-Connecting-IP, else the right-most
     * X-Forwarded-For entry that is not itself a trusted proxy (entries to
     * the left of it can be forged by the client). Returns 'unknown' if
     * nothing usable.
     */
    public static function resolve_client_ip(array $server, array $trusted_proxies = array()) {
        $remote = isset($server['REMOTE_ADDR']) ? trim((string) $server['REMOTE_ADDR']) : '';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return 'unknown';
        }

        $trusted = array();
        foreach ($trusted_proxies as $proxy) {
            $proxy = trim((string) $proxy);
            if ($proxy !== '') {
                $trusted[$proxy] = true;
            }
        }
        if (!isset($trusted[$remote])) {
            return $remote;
        }

        if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
            $cf = trim((string) $server['HTTP_CF_CONNECTING_IP']);
            if (filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
        }

        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_reverse(array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    break;
                }
                if (!isset($trusted[$hop])) {
                    return $hop;
                }
            }
        }

        return $remote;
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
     *   - Pre-auth check: only verifies the IP bucket is not already exhausted (no increment).
     *   - Every FAILED attempt increments the bucket of the client IP
     *     (20 / minute). Never keyed on the supplied token: the attacker
     *     chooses that value.
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

        // Helper to record a failed auth attempt against the IP bucket.
        $record_failure = function () use ($ip_rate_key) {
            $ip_requests = (int) get_transient($ip_rate_key);
            set_transient($ip_rate_key, $ip_requests + 1, 60);
        };

        if (empty($token)) {
            $record_failure();
            return new WP_Error(
                'unauthorized',
                'Auth-Token fehlt',
                array('status' => 401)
            );
        }

        $token = trim($token);

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
            $record_failure();
            return new WP_Error(
                'unauthorized',
                'Ungültiger Token',
                array('status' => 401)
            );
        }

        // Signature + Timestamp are REQUIRED (prevents replay attacks)
        if (empty($signature) || empty($timestamp)) {
            $record_failure();
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
            $record_failure();
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
            $record_failure();
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
        $json_data = (string) $request->get_body();

        // Reject oversized payloads (max 20MB)
        if (strlen($json_data) > 20 * 1024 * 1024) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Payload zu groß (max 20MB)',
            ), 413);
        }

        $trimmed = trim($json_data);
        if ($trimmed !== '' && $trimmed !== '{}') {
            // Validate it's valid JSON in our format — anything else is a
            // client error, never a silent "success" with the old data.
            $data = json_decode($json_data, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)
                || !isset($data['_format']) || $data['_format'] !== 'immoadmin-sync'
                || (isset($data['units']) && !is_array($data['units']))) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Ungültige Sync-Daten (kein gültiges ImmoAdmin-JSON)',
                ), 400);
            }

            // The body is HMAC-signed by the backend: the only place the
            // trusted media host may come from (first use, see Sync).
            if (!empty($data['meta']['baseUrl']) && is_string($data['meta']['baseUrl'])) {
                ImmoAdmin_Sync::pin_backend_host($data['meta']['baseUrl']);
            }

            // Use project slug for filename if available
            $slug = isset($data['meta']['projectSlug']) && is_scalar($data['meta']['projectSlug'])
                ? (string) $data['meta']['projectSlug']
                : '';

            // Private directory, written atomically (temp file + rename)
            $saved = ImmoAdmin_Sync::write_payload_file($json_data, $slug);

            if ($saved === false) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Konnte JSON-Datei nicht speichern',
                ), 500);
            }
        } else {
            // Fallback: Run sync with existing local file (FTP/SFTP upload
            // into wp-content/immoadmin/ — moved into the private directory
            // right here and again at the start of the sync).
            ImmoAdmin_Sync::ensure_storage();
        }

        // Schedule background sync (runs immediately via WP Cron)
        if (!ImmoAdmin_Sync::schedule()) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sync konnte nicht geplant werden (WP-Cron)',
            ), 500);
        }

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

    /**
     * REST route of the current request, as WordPress dispatches it:
     * the parsed rest_route query var (pretty /wp-json/… permalinks and
     * ?rest_route=… both end up there), else ?rest_route=, else the PATH
     * of REQUEST_URI below the REST prefix. Never the query string.
     */
    public static function current_rest_route() {
        if (isset($GLOBALS['wp']) && is_object($GLOBALS['wp']) && isset($GLOBALS['wp']->query_vars['rest_route'])
            && is_string($GLOBALS['wp']->query_vars['rest_route'])) {
            return $GLOBALS['wp']->query_vars['rest_route'];
        }
        if (isset($_GET['rest_route']) && is_string($_GET['rest_route'])) {
            return wp_unslash($_GET['rest_route']);
        }
        if (empty($_SERVER['REQUEST_URI']) || !function_exists('rest_get_url_prefix')) {
            return '';
        }
        $path = (string) parse_url((string) wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);
        return self::route_from_path($path, rest_get_url_prefix(), $home_path);
    }

    /** "/sub/wp-json/immoadmin/v1/sync" → "/immoadmin/v1/sync" ('' when not a REST path). */
    public static function route_from_path($path, $prefix = 'wp-json', $home_path = '/') {
        $base = rtrim((string) $home_path, '/') . '/' . trim((string) $prefix, '/');
        $path = (string) $path;
        if ($path === $base || $path === $base . '/') {
            return '/';
        }
        if (strpos($path, $base . '/') === 0) {
            return substr($path, strlen($base));
        }
        return '';
    }

    /** True only for our own namespace: "/immoadmin/v1" and below. */
    public static function is_own_route($route) {
        if (!is_string($route) || $route === '') {
            return false;
        }
        $route = '/' . ltrim($route, '/');
        if (strpos($route, '..') !== false || strpos($route, '//') !== false) {
            return false;
        }
        return $route === '/immoadmin/v1' || $route === '/immoadmin/v1/' || strpos($route, '/immoadmin/v1/') === 0;
    }

}
