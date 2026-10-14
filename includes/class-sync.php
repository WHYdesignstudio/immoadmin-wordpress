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

    /** WP-Cron hook of the background sync. */
    const CRON_HOOK = 'immoadmin_background_sync';

    /**
     * Sync lock (option row, created atomically with INSERT IGNORE like
     * WP_Upgrader::create_lock()). The value is a heartbeat timestamp that
     * the running sync refreshes after every unit.
     */
    const LOCK_OPTION = 'immoadmin_sync.lock';

    /** A lock / "running" status without heartbeat for this long is stale. */
    const STALE_AFTER = 1800;

    /**
     * Storage layout version. Bumped whenever ensure_storage() learns
     * something new, so maybe_migrate_storage() re-runs once on every site —
     * independent of the plugin version (no version bump needed).
     *   1 = payload JSON moved to a private, unguessable directory,
     *       .htaccess rules for data / payload / media directories.
     */
    const STORAGE_VERSION = 1;

    /**
     * Run sync in background (called via WP Cron)
     * No time limit, keeps running even if connection drops
     *
     * $json_file is ignored (kept so events scheduled by older versions,
     * which passed the file path as argument, still run).
     */
    public static function run_background($json_file = null) {
        // Remove PHP time limit - we may need to download hundreds of files
        @set_time_limit(0);
        @ignore_user_abort(true);

        // Increase memory limit for large syncs
        @ini_set('memory_limit', '512M');

        // Never run two syncs at once. The webhook may fire again while a
        // long media download is running: remember that and run once more
        // afterwards (the new payload is already on disk). The delayed event
        // is a safety net in case the running sync dies without cleanup.
        if (!self::acquire_lock()) {
            update_option('immoadmin_sync_rerun', 1, false);
            wp_schedule_single_event(time() + 300, self::CRON_HOOK);
            return array(
                'success' => false,
                'error'   => 'Sync läuft bereits',
                'locked'  => true,
            );
        }

        $result = array(
            'success' => false,
            'error'   => 'Sync abgebrochen',
        );

        try {
            // This run reads every payload currently on disk.
            delete_option('immoadmin_sync_rerun');
            update_option('immoadmin_sync_status', 'running');
            update_option('immoadmin_sync_started', time(), false);

            $result = self::run();
        } catch (Throwable $e) {
            error_log('ImmoAdmin: sync aborted: ' . $e->getMessage());
            $result = array(
                'success' => false,
                'error'   => 'Sync-Fehler: ' . $e->getMessage(),
            );
        } finally {
            // Status must never stay at "running" after this point.
            update_option('immoadmin_sync_status', !empty($result['success']) ? 'completed' : 'error');
            update_option('immoadmin_last_sync_result', $result);
            delete_option('immoadmin_sync_progress');
            self::release_lock();
        }

        // The flag is set by another request while we ran: read it past the
        // (persistent) option caches, incl. a stale "notoptions" entry.
        if (self::rerun_requested()) {
            delete_option('immoadmin_sync_rerun');
            self::schedule();
        }

        return $result;
    }

    private static function rerun_requested() {
        global $wpdb;
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete('immoadmin_sync_rerun', 'options');
        }
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_var') && isset($wpdb->options)) {
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
                'immoadmin_sync_rerun'
            ));
            if ($value !== null || empty($wpdb->last_error)) {
                return !empty($value);
            }
        }
        return (bool) get_option('immoadmin_sync_rerun');
    }

    /**
     * Queue a background sync. Status is set BEFORE the event is scheduled,
     * so a cron runner that starts immediately can never be overwritten by
     * a late "running".
     *
     * @return bool false if the event could not be scheduled.
     */
    public static function schedule() {
        update_option('immoadmin_sync_status', 'running');
        update_option('immoadmin_sync_started', time(), false);

        // Removes every pending event of the hook, whatever its args
        // (older versions scheduled it with the JSON path as argument, which
        // wp_clear_scheduled_hook() without args never matched).
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(self::CRON_HOOK);
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK);
        if ($scheduled === false || is_wp_error($scheduled)) {
            update_option('immoadmin_sync_status', 'error');
            update_option('immoadmin_last_sync_result', array(
                'success' => false,
                'error'   => 'Sync konnte nicht geplant werden (WP-Cron)',
            ));
            return false;
        }

        spawn_cron();
        return true;
    }

    /**
     * Current sync status with stale-run detection: a sync that has not
     * shown a heartbeat for STALE_AFTER seconds (PHP fatal, killed worker)
     * is reported — and stored — as "error" instead of "running" forever.
     */
    public static function current_status() {
        $status = get_option('immoadmin_sync_status', 'idle');
        if ($status !== 'running') {
            return $status;
        }

        $beat = max((int) get_option(self::LOCK_OPTION, 0), (int) get_option('immoadmin_sync_started', 0));
        if (self::is_stale($beat, time())) {
            update_option('immoadmin_sync_status', 'error');
            update_option('immoadmin_last_sync_result', array(
                'success' => false,
                'error'   => 'Sync hängt (Zeitüberschreitung) – bitte erneut synchronisieren.',
            ));
            return 'error';
        }

        return $status;
    }

    /** Heartbeat older than STALE_AFTER (or none at all) = stale. */
    public static function is_stale($heartbeat, $now) {
        $heartbeat = (int) $heartbeat;
        return $heartbeat <= 0 || ($now - $heartbeat) > self::STALE_AFTER;
    }

    /**
     * Atomic lock (INSERT IGNORE = only one request can create the row).
     * Same pattern as WP_Upgrader::create_lock().
     */
    public static function acquire_lock($retry = true) {
        global $wpdb;

        $created = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            self::LOCK_OPTION,
            (string) time()
        ));

        if (!$created) {
            $lock = (int) get_option(self::LOCK_OPTION, 0);
            // Row exists but cannot be read: do not steal it.
            if (!$lock) {
                return false;
            }
            if (!self::is_stale($lock, time())) {
                return false;
            }
            // Expired lock of a dead run: clear and take it over once.
            self::release_lock();
            return $retry ? self::acquire_lock(false) : false;
        }

        // Make sure option caches know about the row (see WP_Upgrader).
        update_option(self::LOCK_OPTION, time(), false);
        self::$holding_lock = true;
        return true;
    }

    public static function release_lock() {
        self::$holding_lock = false;
        delete_option(self::LOCK_OPTION);
    }

    /** True while this request owns the sync lock (heartbeats only then). */
    private static $holding_lock = false;

    private static function heartbeat() {
        if (self::$holding_lock) {
            update_option(self::LOCK_OPTION, time(), false);
        }
    }

    // -----------------------------------------------------------------
    // Storage: private payload directory, .htaccess rules, migration
    // -----------------------------------------------------------------

    /**
     * Directory holding the sync payloads (JSON with ALL prices, incl. the
     * ones hidden on the frontend). The name carries a random secret, so it
     * is not reachable on servers that ignore .htaccess (nginx) either.
     */
    public static function payload_dir() {
        $secret = get_option('immoadmin_payload_secret', '');
        if (!is_string($secret) || !preg_match('/^[a-z0-9]{32}$/', $secret)) {
            // add_option() is a no-op when a concurrent first request already
            // stored a secret; re-reading makes both requests share it.
            $candidate = strtolower(wp_generate_password(32, false, false));
            if (is_string($secret) && $secret !== '') {
                update_option('immoadmin_payload_secret', $candidate, false); // invalid value: replace
            } else {
                add_option('immoadmin_payload_secret', $candidate, '', 'no');
            }
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete('immoadmin_payload_secret', 'options');
            }
            $secret = get_option('immoadmin_payload_secret', '');
            if (!is_string($secret) || !preg_match('/^[a-z0-9]{32}$/', $secret)) {
                $secret = $candidate;
            }
        }
        return IMMOADMIN_DATA_DIR . 'payload-' . $secret . '/';
    }

    /**
     * Rules for the data root: no listing, never serve temp files. The
     * *.json files here are the REDACTED public copies (MapSVG scripts on
     * customer sites read wp-content/immoadmin/<slug>.json) and stay public.
     */
    public static function data_htaccess() {
        return "# ImmoAdmin: Sync-Daten nie ausliefern (wird vom Plugin verwaltet)\n"
            . "Options -Indexes\n"
            . "<IfModule mod_rewrite.c>\n"
            . "    RewriteEngine On\n"
            . "    RewriteOptions Inherit\n"
            . "    RewriteRule \\.(tmp|part)$ - [F,L,NC]\n"
            . "</IfModule>\n";
    }

    /** Rules for the payload directory: nothing in it is ever served. */
    public static function payload_htaccess() {
        return "# ImmoAdmin: privater Ordner (wird vom Plugin verwaltet)\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
    }

    /**
     * Rules for the media directory: files stay public (they are image /
     * PDF URLs on the site), but nothing in it is ever executed.
     *
     * Every directive must be safe where AllowOverride is restricted (an
     * invalid .htaccess = HTTP 500 for ALL images):
     *   - "Options -Indexes" is already used by the parent dir on live sites.
     *     NO "-ExecCGI": AllowOverride Options=Indexes rejects it (500).
     *   - php_flag only inside IfModule mod_php* (needs the same Options
     *     override as -Indexes).
     *   - SetHandler / ForceType / Rewrite need FileInfo — the same override
     *     WordPress' own rewrite rules need, so they sit inside
     *     IfModule mod_rewrite.c next to them.
     */
    public static function media_htaccess() {
        $scripts = 'php[0-9]?|pht|phtml|phar|phps|cgi|pl|py|sh|asp|aspx|jsp|shtml?';
        return "# ImmoAdmin: Medien öffentlich, aber nie als Skript ausführen (wird vom Plugin verwaltet)\n"
            . "Options -Indexes\n"
            . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php5.c>\n    php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_rewrite.c>\n"
            . "    <FilesMatch \"\\.(?i:{$scripts})$\">\n"
            . "        SetHandler none\n"
            . "        ForceType text/plain\n"
            . "    </FilesMatch>\n"
            . "    RewriteEngine On\n"
            . "    RewriteOptions Inherit\n"
            . "    RewriteRule \\.({$scripts})$ - [F,L,NC]\n"
            . "</IfModule>\n";
    }

    private static function write_if_changed($path, $content) {
        if (file_exists($path) && @file_get_contents($path) === $content) {
            return true;
        }
        return @file_put_contents($path, $content, LOCK_EX) !== false;
    }

    /**
     * Create directories + protection files and move public payload JSON
     * into the private directory. Idempotent and cheap; runs on activation,
     * once per STORAGE_VERSION (maybe_migrate_storage), on every webhook
     * and at the start of every sync.
     *
     * @return bool true if the payload directory is usable.
     */
    public static function ensure_storage() {
        $payload_dir = self::payload_dir();

        foreach (array(IMMOADMIN_DATA_DIR, IMMOADMIN_MEDIA_DIR, $payload_dir) as $dir) {
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }
        }

        self::write_if_changed(IMMOADMIN_DATA_DIR . '.htaccess', self::data_htaccess());
        self::write_if_changed($payload_dir . '.htaccess', self::payload_htaccess());
        self::write_if_changed(IMMOADMIN_MEDIA_DIR . '.htaccess', self::media_htaccess());

        foreach (array(IMMOADMIN_DATA_DIR, $payload_dir) as $dir) {
            if (is_dir($dir) && !file_exists($dir . 'index.php')) {
                @file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n", LOCK_EX);
            }
        }

        self::migrate_public_payloads($payload_dir);
        self::refresh_public_copies($payload_dir);
        self::remember_public_state();

        return is_dir($payload_dir) && is_writable($payload_dir);
    }

    // -----------------------------------------------------------------
    // Public redacted copy (MapSVG): wp-content/immoadmin/<slug>.json
    // -----------------------------------------------------------------

    /** Top-level flag of the public copy. Such files are never sync input. */
    const REDACTED_FLAG = '_redacted';

    /** Is this decoded JSON (array or object) a redacted public copy? */
    public static function is_redacted_copy($data) {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        return is_array($data) && !empty($data[self::REDACTED_FLAG]);
    }

    /**
     * Redact a payload for the public copy. Same rules as the REST
     * redaction (ImmoAdmin_Visibility): for every unit whose status is not
     * public, every sensitive key (prices incl. *_formatted, rent, costs,
     * documents / document_N_*) is set to null — keys stay, so scripts that
     * read the structure keep working. Public units stay unchanged.
     * Top-level meta keys that look like credentials are dropped.
     *
     * Works on json_decode() objects so "{}" stays "{}".
     *
     * @return object|null null if the rules are unavailable (fail closed:
     *                     then no public copy is written at all)
     */
    public static function redact_payload($data) {
        if (!is_object($data) || !class_exists('ImmoAdmin_Visibility')
            || !method_exists('ImmoAdmin_Visibility', 'is_public_status')
            || !method_exists('ImmoAdmin_Visibility', 'is_sensitive_meta_key')) {
            return null;
        }
        $data = unserialize(serialize($data)); // deep copy

        if (isset($data->meta) && is_object($data->meta)) {
            foreach (array_keys(get_object_vars($data->meta)) as $k) {
                if (preg_match('/(token|secret|password|passwort|signature|credential|api_?key)/i', (string) $k)) {
                    unset($data->meta->{$k});
                }
            }
        }

        // Fail closed: "units" present but not a list of unit objects =
        // unknown structure, nothing safe to publish.
        if (isset($data->units)) {
            if (!is_array($data->units) || array_values($data->units) !== $data->units) {
                return null;
            }
            foreach ($data->units as $unit) {
                if (!is_object($unit)) {
                    return null;
                }
            }
        }

        if (isset($data->units) && is_array($data->units)) {
            foreach ($data->units as $unit) {
                $meta = (isset($unit->metaFields) && is_object($unit->metaFields)) ? $unit->metaFields : null;
                $status = ($meta && isset($meta->status) && is_scalar($meta->status)) ? $meta->status : '';
                if (ImmoAdmin_Visibility::is_public_status($status)) {
                    continue;
                }
                if ($meta) {
                    foreach (array_keys(get_object_vars($meta)) as $k) {
                        if (ImmoAdmin_Visibility::is_sensitive_meta_key($k)) {
                            $meta->{$k} = null;
                        }
                    }
                }
                foreach (array_keys(get_object_vars($unit)) as $k) {
                    if (!in_array($k, array('id', 'title', 'metaFields'), true) && ImmoAdmin_Visibility::is_sensitive_meta_key($k)) {
                        $unit->{$k} = null;
                    }
                }
            }
        }

        $data->{self::REDACTED_FLAG} = true;
        return $data;
    }

    /**
     * Write the redacted public copy of a private payload file to the old
     * public path IMMOADMIN_DATA_DIR/<same name> (atomic, only if changed).
     * If no safe copy can be produced, an existing public file of that name
     * is removed (fail closed).
     */
    public static function write_public_copy($private_file) {
        $public = IMMOADMIN_DATA_DIR . basename($private_file);
        $data = json_decode((string) @file_get_contents($private_file));
        $redacted = (json_last_error() === JSON_ERROR_NONE && is_object($data) && !self::is_redacted_copy($data)
            && ($data->_format ?? '') === 'immoadmin-sync') ? self::redact_payload($data) : null;
        if ($redacted === null) {
            // Fail closed: no safe copy → no public file of that name at all
            if (is_file($public)) {
                @unlink($public);
            }
            return false;
        }

        $json = wp_json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        if (is_file($public) && @file_get_contents($public) === $json) {
            return true;
        }
        return self::atomic_write($public, $json);
    }

    /** Temp file (dotfile, .tmp) in the same directory + rename. */
    private static function atomic_write($target, $content) {
        $tmp = dirname($target) . '/.' . basename($target) . '.' . wp_generate_password(12, false, false) . '.tmp';
        $written = @file_put_contents($tmp, $content, LOCK_EX);
        if ($written === false || $written !== strlen($content)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** (Re)write the public copy of every private payload. */
    public static function refresh_public_copies($payload_dir) {
        foreach (is_dir($payload_dir) ? (glob($payload_dir . '*.json') ?: array()) : array() as $file) {
            self::write_public_copy($file);
        }
    }

    /**
     * Move sync payloads out of the public data root (written there by
     * plugin versions < this one, or uploaded by the backend's FTP/SFTP
     * fallback) and out of payload dirs with an outdated secret.
     *
     * Valid JSON of another format is left alone (not ours), and so are the
     * redacted public copies ("_redacted": true) — they are never sync
     * input. Unparseable JSON is moved too: it is most likely a broken
     * payload, and the sync must see it to report the error and skip the
     * deletion pass. A full payload in the public root is copied into the
     * private dir first and then REPLACED by its redacted copy (rename over
     * it), so the public URL never 404s and never keeps the prices.
     *
     * @return int number of moved files
     */
    public static function migrate_public_payloads($payload_dir) {
        if (!is_dir($payload_dir)) {
            return 0;
        }

        $sources = glob(IMMOADMIN_DATA_DIR . '*.json') ?: array();
        foreach (glob(IMMOADMIN_DATA_DIR . 'payload-*', GLOB_ONLYDIR) ?: array() as $old_dir) {
            if (rtrim($old_dir, '/') . '/' === $payload_dir) {
                continue;
            }
            $sources = array_merge($sources, glob($old_dir . '/*.json') ?: array());
        }

        $moved = 0;
        foreach ($sources as $file) {
            if (!is_file($file)) {
                continue;
            }
            $raw = (string) @file_get_contents($file);
            $data = json_decode($raw, true);
            $valid = json_last_error() === JSON_ERROR_NONE;
            if ($valid && (self::is_redacted_copy($data) || !is_array($data) || ($data['_format'] ?? '') !== 'immoadmin-sync')) {
                continue;
            }
            $target = $payload_dir . basename($file);
            $in_root = dirname($file) . '/' === IMMOADMIN_DATA_DIR || rtrim(dirname($file), '/') . '/' === IMMOADMIN_DATA_DIR;

            if ($valid && $in_root) {
                // Full payload at the public path (FTP upload / old version)
                if (!self::atomic_write($target, $raw)) {
                    error_log('ImmoAdmin: could not copy payload ' . basename($file) . ' to the private directory');
                    continue;
                }
                if (!self::write_public_copy($target)) {
                    @unlink($file); // never leave the full file public
                }
                $moved++;
                continue;
            }

            if (@rename($file, $target)) {
                $moved++;
            } else {
                error_log('ImmoAdmin: could not move payload ' . basename($file) . ' to the private directory');
            }
        }

        return $moved;
    }

    /**
     * Run ensure_storage() once per STORAGE_VERSION on every site, also on
     * sites where nobody opens wp-admin (hooked on init). Failures retry
     * hourly instead of on every request.
     */
    public static function maybe_migrate_storage() {
        if ((int) get_option('immoadmin_storage_version', 0) >= self::STORAGE_VERSION) {
            return;
        }
        if (get_transient('immoadmin_storage_retry')) {
            return;
        }
        if (self::ensure_storage()) {
            update_option('immoadmin_storage_version', self::STORAGE_VERSION);
        } else {
            set_transient('immoadmin_storage_retry', 1, HOUR_IN_SECONDS);
        }
    }

    /**
     * FTP/SFTP fallback guard (hooked on init): the backend uploads the
     * FULL payload to wp-content/immoadmin/<slug>.json before it sends the
     * notify webhook. Detect that on the very next request and replace it
     * with the redacted copy right away.
     *
     * Cost per request: one autoloaded option + one stat of the data dir
     * (new file names) + one stat per known public copy (FTP overwrites an
     * existing file in place, which does not touch the dir mtime) — sites
     * have one or a few projects.
     */
    public static function maybe_secure_public_dir() {
        if ((int) get_option('immoadmin_storage_version', 0) < self::STORAGE_VERSION) {
            return; // maybe_migrate_storage() runs first
        }
        if (!self::public_dir_changed(get_option('immoadmin_public_state', array()))) {
            return;
        }
        self::secure_public_dir();
    }

    /** Pure-ish: compare the stored mtimes with the current ones. */
    public static function public_dir_changed($state) {
        if (!is_array($state) || !isset($state['dir'], $state['files']) || !is_array($state['files'])) {
            return true;
        }
        if ((int) @filemtime(IMMOADMIN_DATA_DIR) !== (int) $state['dir']) {
            return true;
        }
        foreach ($state['files'] as $name => $mtime) {
            if ((int) @filemtime(IMMOADMIN_DATA_DIR . $name) !== (int) $mtime) {
                return true;
            }
        }
        return false;
    }

    /** Move full payloads out of the public dir, refresh copies, remember state. */
    public static function secure_public_dir() {
        $payload_dir = self::payload_dir();
        if (is_dir($payload_dir)) {
            self::migrate_public_payloads($payload_dir);
            self::refresh_public_copies($payload_dir);
        }
        self::remember_public_state();
    }

    private static function remember_public_state() {
        if (!is_dir(IMMOADMIN_DATA_DIR)) {
            return;
        }
        clearstatcache();
        $files = array();
        foreach (glob(IMMOADMIN_DATA_DIR . '*.json') ?: array() as $file) {
            $files[basename($file)] = (int) @filemtime($file);
        }
        $state = array('dir' => (int) @filemtime(IMMOADMIN_DATA_DIR), 'files' => $files);
        if (get_option('immoadmin_public_state') !== $state) {
            update_option('immoadmin_public_state', $state);
        }
    }

    /**
     * File name for a project's payload: same rule as before (projectSlug
     * through sanitize_file_name()), so migrated files are overwritten by
     * the next push instead of being duplicated.
     */
    public static function payload_filename($slug) {
        $base = is_scalar($slug) ? sanitize_file_name((string) $slug) : '';
        if ($base === '' || $base[0] === '.') {
            $base = 'data';
        }
        return $base . '.json';
    }

    /**
     * Write a webhook payload atomically (temp file + rename), so a sync
     * running at the same time never reads a half-written file.
     *
     * @return string|false final path
     */
    public static function write_payload_file($json, $slug = '') {
        if (!self::ensure_storage()) {
            return false;
        }

        $dir = self::payload_dir();
        $target = $dir . self::payload_filename($slug);
        $tmp = $dir . '.' . basename($target) . '.' . wp_generate_password(12, false, false) . '.tmp';

        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }

        // Public redacted copy at the old URL (MapSVG)
        self::write_public_copy($target);
        self::remember_public_state();

        return $target;
    }

    // -----------------------------------------------------------------
    // Trusted backend host (media downloads)
    // -----------------------------------------------------------------

    /**
     * Host the media come from. Set by the site (IMMOADMIN_BACKEND_URL in
     * wp-config.php) or pinned from the first HMAC-signed webhook payload
     * after the token was saved (trust on first use; every token save and
     * disconnecting reset it).
     *
     * Never taken from a payload file on disk: the webhook pins BEFORE it
     * writes the file, so every file written by a signed webhook has already
     * pinned its host. Files from the FTP/SFTP fallback or older plugin
     * versions (not signed) pin nothing — until the next signed webhook,
     * new downloads use the public-IP SSRF check (a backend on a public host
     * works; one in a private network waits for that webhook). Media that
     * already exist locally need no host at all (see download_media()).
     */
    public static function trusted_backend_host() {
        if (defined('IMMOADMIN_BACKEND_URL') && IMMOADMIN_BACKEND_URL) {
            return self::parse_trusted_host(IMMOADMIN_BACKEND_URL);
        }
        $host = get_option('immoadmin_backend_host', '');
        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Pin the backend host from a signed webhook payload (meta.baseUrl).
     * Only when nothing is pinned yet; a different host later is logged and
     * ignored (a leaked token must not be able to redirect the trust).
     *
     * @return bool true if a host was stored
     */
    public static function pin_backend_host($base_url) {
        if (defined('IMMOADMIN_BACKEND_URL') && IMMOADMIN_BACKEND_URL) {
            return false;
        }
        $host = self::parse_trusted_host($base_url);
        if ($host === '') {
            return false;
        }
        $pinned = get_option('immoadmin_backend_host', '');
        if (is_string($pinned) && $pinned !== '') {
            if ($pinned !== $host) {
                error_log('ImmoAdmin: payload baseUrl host ' . $host . ' differs from pinned backend host ' . $pinned . ' (ignored; save the token again in wp-admin to re-pin)');
            }
            return false;
        }
        update_option('immoadmin_backend_host', $host, false);
        return true;
    }

    public static function reset_backend_host() {
        delete_option('immoadmin_backend_host');
    }

    /**
     * Run sync from JSON file
     */
    public static function run($json_file = null) {
        $start_time = microtime(true);

        // Always process EVERY *.json in the payload dir, not just the most
        // recently pushed file. Multi-project sites push one JSON per project
        // (named by projectSlug), and the deletion pass below treats anything
        // NOT in the current payloads as removable — so processing only one
        // file would silently nuke the other projects' posts.
        //
        // The $json_file argument is kept for backwards compatibility only.
        self::ensure_storage();
        $payload_dir = self::payload_dir();
        $json_files = is_dir($payload_dir) ? (glob($payload_dir . '*.json') ?: array()) : array();
        if (empty($json_files)) {
            return array(
                'success' => false,
                'error' => 'JSON-Datei nicht gefunden',
            );
        }

        $stats = array(
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'media_downloaded' => 0,
            'media_deleted' => 0,
            'errors' => array(),
        );

        // Get existing posts mapped by ImmoAdmin ID (once, shared across all files).
        $existing_posts = self::get_existing_posts();
        $processed_ids = array();
        $trusted_host = self::trusted_backend_host();

        // Problems that make the set of units incomplete (broken file, unit
        // without ID). Any of them disables the deletion pass.
        $file_errors = 0;

        // Total unit count across all files for the progress UI.
        $all_units = array();
        foreach ($json_files as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $stats['errors'][] = basename($file) . ': JSON-Parse-Fehler: ' . json_last_error_msg();
                $file_errors++;
                continue;
            }
            if (self::is_redacted_copy($payload)) {
                // Never sync input (prices nulled) — must not get here at all
                $stats['errors'][] = basename($file) . ': redigierte Kopie ignoriert';
                $file_errors++;
                continue;
            }
            if (!is_array($payload) || empty($payload['_format']) || $payload['_format'] !== 'immoadmin-sync') {
                $stats['errors'][] = basename($file) . ': Ungültiges JSON-Format';
                $file_errors++;
                continue;
            }
            $units = isset($payload['units']) ? $payload['units'] : array();
            if (!is_array($units)) {
                $stats['errors'][] = basename($file) . ': Ungültige Einheiten-Liste';
                $file_errors++;
                continue;
            }
            foreach ($units as $unit) {
                if (!is_array($unit) || self::unit_id($unit) === '') {
                    $stats['errors'][] = basename($file) . ': Einheit ohne ID übersprungen';
                    $file_errors++;
                    continue;
                }
                $all_units[] = $unit;
            }
        }

        $total_units = count($all_units);
        $current_unit = 0;

        foreach ($all_units as $unit) {
            $current_unit++;

            // Update progress so the admin dashboard can show it
            update_option('immoadmin_sync_progress', array(
                'current' => $current_unit,
                'total' => $total_units,
                'unit_title' => self::unit_title($unit),
                'stats' => $stats,
            ), false); // false = don't autoload
            self::heartbeat();

            $result = self::sync_unit($unit, $existing_posts, $trusted_host);
            $processed_ids[] = self::unit_id($unit);

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

        // Delete posts that are no longer present in ANY payload — only when
        // every file parsed and every unit had an ID (see deletion_allowed()).
        if (self::deletion_allowed($file_errors, $total_units)) {
            foreach (self::posts_to_delete($existing_posts, $processed_ids) as $post_id) {
                wp_delete_post($post_id, true);
                $stats['deleted']++;
            }
        } elseif ($file_errors > 0) {
            error_log('ImmoAdmin: deletion pass skipped — ' . $file_errors . ' payload problem(s)');
        }

        // Clean up orphaned media files — never after a sync with errors:
        // meta may be incomplete then and the scan would delete live files.
        if (empty($stats['errors'])) {
            $stats['media_deleted'] = self::cleanup_orphan_media();
        }

        // Log sync
        $duration = round(microtime(true) - $start_time, 2);
        self::log_sync($stats, $duration);

        // Update last sync time and clear progress
        update_option('immoadmin_last_sync', current_time('mysql'));
        update_option('immoadmin_last_sync_stats', $stats);
        delete_option('immoadmin_sync_progress');

        return array(
            'success' => empty($stats['errors']),
            'stats' => $stats,
            'duration' => $duration,
        );
    }

    /**
     * The deletion pass may only run when the set of units is complete:
     * no payload failed to parse / had the wrong format / contained a unit
     * without ID, and at least one unit arrived (a transient empty sync
     * must never wipe all posts).
     */
    public static function deletion_allowed($file_errors, $total_units) {
        return (int) $file_errors === 0 && (int) $total_units > 0;
    }

    /** Post IDs whose ImmoAdmin ID is not among the processed IDs. */
    public static function posts_to_delete(array $existing_posts, array $processed_ids) {
        $keep = array();
        foreach ($processed_ids as $id) {
            $keep[(string) $id] = true;
        }
        $delete = array();
        foreach ($existing_posts as $immoadmin_id => $post_id) {
            if (!isset($keep[(string) $immoadmin_id])) {
                $delete[] = $post_id;
            }
        }
        return $delete;
    }

    /** ImmoAdmin ID of a payload unit as string, '' when missing/invalid. */
    public static function unit_id($unit) {
        if (!is_array($unit) || !isset($unit['id']) || !is_scalar($unit['id']) || is_bool($unit['id'])) {
            return '';
        }
        return trim((string) $unit['id']);
    }

    private static function unit_title($unit) {
        if (is_array($unit) && isset($unit['title']) && is_scalar($unit['title']) && trim((string) $unit['title']) !== '') {
            return (string) $unit['title'];
        }
        return 'Einheit';
    }

    /**
     * Find the JSON file in the payload directory
     */
    private static function find_json_file() {
        $dir = self::payload_dir();
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '*.json');
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
        foreach ((array) $results as $row) {
            $map[$row->immoadmin_id] = $row->post_id;
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // Meta keys: whitelist, stale clearing, sanitizing
    // -----------------------------------------------------------------

    /**
     * Keys the current backend sends (apps/api/src/lib/sync-payload.ts)
     * that are not registered as post meta (no REST schema needed for
     * them). Together with the registered fields and the numbered patterns
     * this is the complete whitelist.
     */
    private static $extra_sync_keys = array(
        'flatfinder_url', 'link_virtual_tour_url', 'link_video_url', 'link_website_url',
        'sort_key', 'orientation_label', 'outdoor_area_total',
        'living_area_formatted', 'usable_area_formatted', 'total_area_formatted', 'plot_area_formatted',
        'balcony_area_formatted', 'terrace_area_formatted', 'roof_terrace_area_formatted',
        'loggia_area_formatted', 'garden_area_formatted', 'basement_area_formatted',
        'storage_area_formatted', 'outdoor_area_total_formatted',
        'purchase_price_formatted', 'purchase_price_investor_formatted', 'purchase_price_private_formatted',
        'rent_cold_formatted', 'rent_warm_formatted', 'operating_costs_formatted', 'heating_costs_formatted',
        'deposit_formatted', 'minimum_income_formatted', 'parking_price_formatted', 'price_per_sqm_formatted',
        'images_count', 'floor_plans_count', 'documents_count', 'features_count',
    );

    /** Keys holding JSON documents (written as normalized JSON). */
    private static $json_meta_keys = array('features', 'extras', 'images', 'floor_plans', 'documents');

    /**
     * Fixed (non-numbered) meta keys the sync owns: registered fields
     * without internal "_" keys, plus $extra_sync_keys. Filterable for
     * sites that need an additional backend field before a plugin update.
     */
    public static function static_meta_keys() {
        $keys = array();
        if (class_exists('ImmoAdmin_Post_Type')) {
            $keys = array_keys(ImmoAdmin_Post_Type::get_meta_fields());
        }
        $keys = array_merge($keys, self::$extra_sync_keys);
        if (function_exists('apply_filters')) {
            $keys = (array) apply_filters('immoadmin_allowed_meta_keys', $keys);
        }

        $clean = array();
        foreach ($keys as $key) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                $clean[$key] = true;
            }
        }
        return array_keys($clean);
    }

    /** Numbered keys (image_N, floor_plan_N, document_N_url/_title, feature_N_key/_label). */
    public static function is_dynamic_meta_key($key) {
        return (bool) preg_match('/^(image_\d+|floor_plan_\d+|document_\d+_(url|title)|feature_\d+_(key|label))$/', (string) $key);
    }

    /**
     * May the payload write this meta key? Never internal "_" keys
     * (_wp_page_template, _thumbnail_id, _bricks_page_content_2, our own
     * _content_hash …), only whitelisted fixed keys or numbered patterns.
     */
    public static function is_allowed_meta_key($key, $static_keys = null) {
        if (!is_string($key) || $key === '' || $key[0] === '_') {
            return false;
        }
        if (self::is_dynamic_meta_key($key)) {
            return true;
        }
        if ($static_keys === null) {
            $static_keys = self::static_meta_keys();
        }
        return in_array($key, $static_keys, true);
    }

    /**
     * Sanitize a non-media meta value for storage.
     *
     * @return mixed|null null = do not write (unsupported type)
     */
    public static function sanitize_meta_value($key, $value) {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        if (in_array($key, self::$json_meta_keys, true)) {
            return self::sanitize_json_string($value);
        }
        if (substr($key, -4) === '_url') {
            return esc_url_raw($value);
        }
        if (is_numeric($value)) {
            return $value;
        }
        // Keeps line breaks (equipment, condition …), strips tags.
        return sanitize_textarea_field($value);
    }

    /**
     * Decode, strip tags from every string, re-encode. Invalid JSON → ''.
     * Keeps the document valid JSON (sanitize_text_field on the raw string
     * used to mangle escapes / percent sequences).
     */
    private static function sanitize_json_string($value) {
        if (trim($value) === '') {
            return '';
        }
        // Objects stay objects ("{}" must not turn into "[]").
        $data = json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }
        $clean = self::sanitize_json_data($data);
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    private static function sanitize_json_data($data) {
        if (is_object($data)) {
            $out = new stdClass();
            foreach (get_object_vars($data) as $k => $v) {
                $out->{sanitize_text_field((string) $k)} = self::sanitize_json_data($v);
            }
            return $out;
        }
        if (is_array($data)) {
            $out = array();
            foreach ($data as $k => $v) {
                $out[is_string($k) ? sanitize_text_field($k) : $k] = self::sanitize_json_data($v);
            }
            return $out;
        }
        if (is_string($data)) {
            return sanitize_textarea_field($data);
        }
        return $data;
    }

    /**
     * Sync a single unit - just write what the backend sends
     */
    private static function sync_unit($unit, $existing_posts, $trusted_host = '') {
        $immoadmin_id = self::unit_id($unit);
        if ($immoadmin_id === '') {
            return array(
                'status' => 'error',
                'error' => 'Einheit ohne ID übersprungen',
            );
        }
        $title = self::unit_title($unit);
        $existing_post_id = isset($existing_posts[$immoadmin_id]) ? $existing_posts[$immoadmin_id] : null;

        // Accept a full URL as well (tests / older callers).
        if (is_string($trusted_host) && strpos($trusted_host, '://') !== false) {
            $trusted_host = self::parse_trusted_host($trusted_host);
        }

        // Content hash for change detection. The whole unit (incl. every
        // metaField such as floor_to / pool_area) feeds the hash, so any new
        // backend field propagates automatically. The prefix is bumped when
        // the plugin starts deriving/writing something new from unchanged
        // input (v4: pool_area_formatted; v5: meta whitelist, stale-key
        // clearing, slashing, JSON/URL sanitizing), so units synced by an
        // older plugin get rewritten once. Media is not re-downloaded —
        // download_media() skips files that already exist locally.
        $content_hash = md5('v5:' . json_encode($unit));

        // Skip if nothing changed
        if ($existing_post_id) {
            $stored_hash = get_post_meta($existing_post_id, '_content_hash', true);
            if ($stored_hash === $content_hash) {
                return array('status' => 'skipped');
            }
            // Invalidate BEFORE touching anything: if this sync dies halfway,
            // the next one must not skip the half-written unit.
            delete_post_meta($existing_post_id, '_content_hash');
        }

        // Create or update post (wp_insert_post / wp_update_post expect
        // slashed data and unslash it again — without wp_slash() every
        // backslash in title/description would be lost).
        $post_data = array(
            'post_type'    => 'immoadmin_wohnung',
            'post_status'  => 'publish',
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_kses_post(isset($unit['description']) && is_string($unit['description']) ? $unit['description'] : ''),
        );

        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post(wp_slash($post_data), true);
            $status = 'updated';
        } else {
            $post_id = wp_insert_post(wp_slash($post_data), true);
            $status = 'created';
        }

        if (is_wp_error($post_id)) {
            return array(
                'status' => 'error',
                'error' => $title . ': ' . $post_id->get_error_message(),
            );
        }

        // Store internal meta (content hash is set AFTER media downloads)
        update_post_meta($post_id, '_immoadmin_id', wp_slash($immoadmin_id));
        update_post_meta($post_id, '_last_synced', current_time('mysql'));

        // Clean up old numbered media/feature fields before writing new ones
        self::cleanup_dynamic_meta($post_id);

        // Write the whitelisted metaFields from backend directly - no mapping, no logic
        $media_downloaded = 0;
        $media_failed = false;

        $meta_fields = (!empty($unit['metaFields']) && is_array($unit['metaFields']))
            ? $unit['metaFields']
            : array();

        // Derived companions (e.g. pool_area_formatted) — guarded so a
        // partially delivered update can never break the sync.
        if (!empty($meta_fields) && class_exists('ImmoAdmin_Unit_Fields')) {
            $meta_fields = ImmoAdmin_Unit_Fields::augment_meta_fields($meta_fields);
        }

        $static_keys = self::static_meta_keys();
        $rejected = array();

        foreach ($meta_fields as $key => $value) {
            $key = (string) $key;
            if (!self::is_allowed_meta_key($key, $static_keys)) {
                $rejected[] = $key;
                continue;
            }

            // Media fields (image_N, floor_plan_N, document_N_url): download locally
            if (self::is_media_url_field($key)) {
                if (!is_string($value) || $value === '') {
                    continue; // removed by cleanup_dynamic_meta() already
                }
                // File types we never store locally (SVG, HTML …): keep the
                // remote URL, but do NOT count it as a failed download —
                // otherwise the unit never gets its hash and is rewritten on
                // every sync. Local reuse stays blocked for these types too.
                if (self::media_extension(sanitize_file_name(basename((string) parse_url($value, PHP_URL_PATH)))) === '') {
                    update_post_meta($post_id, $key, wp_slash(esc_url_raw($value)));
                    self::log_once('ImmoAdmin: media type not downloaded, remote URL kept: ' . $value);
                    continue;
                }
                $local_url = self::download_media($value, $trusted_host);
                if ($local_url) {
                    update_post_meta($post_id, $key, wp_slash($local_url));
                    $media_downloaded++;
                } else {
                    // Fallback: store remote URL if download fails
                    update_post_meta($post_id, $key, wp_slash(esc_url_raw($value)));
                    $media_failed = true;
                }
                continue;
            }

            $clean = self::sanitize_meta_value($key, $value);
            if ($clean !== null) {
                update_post_meta($post_id, $key, wp_slash($clean));
            }
        }

        // Stale meta: a fixed key the payload no longer carries (e.g.
        // floor_to after a maisonette became a single floor, or status) is
        // removed instead of keeping an outdated value. Skipped when the
        // unit arrived without metaFields at all (broken payload).
        if (!empty($meta_fields)) {
            foreach ($static_keys as $key) {
                if (!array_key_exists($key, $meta_fields)) {
                    delete_post_meta($post_id, $key);
                }
            }
        }

        if (!empty($rejected)) {
            error_log('ImmoAdmin: ignored meta keys for unit ' . $immoadmin_id . ': ' . implode(', ', array_slice($rejected, 0, 20)));
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

    /** error_log() each message only once per request (sync run). */
    private static $logged = array();

    private static function log_once($message) {
        if (!isset(self::$logged[$message])) {
            self::$logged[$message] = true;
            error_log($message);
        }
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
                 OR meta_key REGEXP '^feature_[0-9]+_(key|label)$'
             )",
            $post_id
        ));
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($post_id, 'post_meta');
        }
    }

    /**
     * Delete orphaned media files - files in IMMOADMIN_MEDIA_DIR that are
     * no longer referenced by any unit's image_N / floor_plan_N / document_N_url meta.
     *
     * Defensive: any failure is logged but never breaks the sync. Aborts
     * (deletes nothing) when the reference query failed or returned nothing
     * although units exist. Returns deleted count.
     */
    private static function cleanup_orphan_media() {
        $deleted = 0;

        try {
            global $wpdb;

            // Build set of currently referenced media URLs across all units
            $referenced_urls = $wpdb->get_col("
                SELECT DISTINCT pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.post_type = 'immoadmin_wohnung'
                  AND p.post_status != 'trash'
                  AND (
                      pm.meta_key REGEXP '^image_[0-9]+$'
                      OR pm.meta_key REGEXP '^floor_plan_[0-9]+$'
                      OR pm.meta_key REGEXP '^document_[0-9]+_url$'
                  )
                  AND pm.meta_value != ''
            ");

            if (!empty($wpdb->last_error) || !is_array($referenced_urls)) {
                error_log('ImmoAdmin: cleanup_orphan_media aborted (query failed): ' . $wpdb->last_error);
                return 0;
            }

            if (empty($referenced_urls)) {
                $unit_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'immoadmin_wohnung' AND post_status != 'trash'"
                );
                if (!empty($wpdb->last_error) || $unit_count > 0) {
                    error_log('ImmoAdmin: cleanup_orphan_media aborted (no media references although units exist)');
                    return 0;
                }
            }

            // Index by basename for O(1) lookups during the file scan.
            //
            // CRITICAL: must apply the EXACT same transformation that download_media()
            // uses to derive the on-disk filename — otherwise URLs containing umlauts,
            // spaces, or other characters changed by sanitize_file_name() will not
            // match the actual file on disk, and we'd wrongly classify it as orphan
            // and delete legitimate cached media.
            $referenced = array();
            foreach ($referenced_urls as $url) {
                // 1. Remote URLs — apply sanitize_file_name() to match the downloader.
                // 2. Already-local URLs (content_url('/immoadmin/media/...')) — basename
                //    is already the sanitized on-disk name, but applying
                //    sanitize_file_name() again is idempotent so this is safe.
                $raw_basename = basename((string) parse_url($url, PHP_URL_PATH));
                $filename = sanitize_file_name($raw_basename);
                if (!empty($filename)) {
                    $referenced[$filename] = true;
                }
                // Belt-and-suspenders: also index the un-sanitized basename in
                // case any legacy meta predates the sanitizer change.
                if (!empty($raw_basename) && $raw_basename !== $filename) {
                    $referenced[$raw_basename] = true;
                }
            }

            if (!is_dir(IMMOADMIN_MEDIA_DIR)) {
                return 0;
            }

            $files = glob(IMMOADMIN_MEDIA_DIR . '*');
            if ($files === false || empty($files)) {
                return 0;
            }

            foreach ($files as $path) {
                if (!is_file($path)) {
                    continue;
                }

                $filename = basename($path);

                // Skip system/hidden files (.htaccess, .DS_Store, ...)
                if (strpos($filename, '.') === 0) {
                    continue;
                }

                if (!isset($referenced[$filename])) {
                    if (@unlink($path)) {
                        $deleted++;
                        error_log('ImmoAdmin: Deleted orphan media file: ' . $filename);
                    } else {
                        error_log('ImmoAdmin: Failed to delete orphan media file: ' . $filename);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ImmoAdmin: cleanup_orphan_media error: ' . $e->getMessage());
        }

        return (int) $deleted;
    }

    /**
     * Parse trusted host from base URL ("host" or "host:port", lowercase)
     */
    public static function parse_trusted_host($base_url) {
        if (empty($base_url) || !is_string($base_url)) return '';
        $parsed = parse_url($base_url);
        if (empty($parsed['host'])) return '';
        $host = strtolower($parsed['host']);
        return !empty($parsed['port']) ? $host . ':' . (int) $parsed['port'] : $host;
    }

    /** Does $url point at the trusted host (and port, if the trust names one)? */
    public static function url_matches_trusted_host($url, $trusted_host) {
        if (!is_string($trusted_host) || $trusted_host === '') {
            return false;
        }
        $parsed = parse_url((string) $url);
        if (empty($parsed['host'])) {
            return false;
        }
        $host = strtolower($parsed['host']);
        if (strpos($trusted_host, ':') !== false && strpos($trusted_host, ']') === false) {
            $port = !empty($parsed['port']) ? (int) $parsed['port'] : null;
            return $port !== null && $host . ':' . $port === $trusted_host;
        }
        return $host === $trusted_host;
    }

    /**
     * Allowed media types per (lowercase) extension: Content-Type values the
     * server may send, and what content sniffing (finfo) may report. Images,
     * PDF and the office formats the backend can serve. Never SVG/HTML/JS.
     */
    private static $media_types = array(
        'jpg'  => array('ct' => array('image/jpeg', 'image/pjpeg'), 'sniff' => array('image/jpeg')),
        'jpeg' => array('ct' => array('image/jpeg', 'image/pjpeg'), 'sniff' => array('image/jpeg')),
        'jpe'  => array('ct' => array('image/jpeg', 'image/pjpeg'), 'sniff' => array('image/jpeg')),
        'png'  => array('ct' => array('image/png'), 'sniff' => array('image/png')),
        'gif'  => array('ct' => array('image/gif'), 'sniff' => array('image/gif')),
        'webp' => array('ct' => array('image/webp'), 'sniff' => array('image/webp')),
        'pdf'  => array('ct' => array('application/pdf', 'application/x-pdf'), 'sniff' => array('application/pdf', 'application/x-pdf')),
        'doc'  => array(
            'ct'    => array('application/msword', 'application/octet-stream'),
            'sniff' => array('application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'),
        ),
        'docx' => array(
            'ct'    => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'),
            'sniff' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'),
        ),
        'xls'  => array(
            'ct'    => array('application/vnd.ms-excel', 'application/octet-stream'),
            'sniff' => array('application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2'),
        ),
        'xlsx' => array(
            'ct'    => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'),
            'sniff' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'),
        ),
    );

    /** Lowercase extension if it is on the media allowlist, else ''. */
    public static function media_extension($filename) {
        $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
        return isset(self::$media_types[$ext]) ? $ext : '';
    }

    /**
     * Pure check: allowlisted extension, a Content-Type header that matches
     * it, and (when sniffing was possible) sniffed content that matches too.
     *
     * @param string      $filename     sanitized local file name
     * @param string      $content_type raw Content-Type header (may carry "; charset")
     * @param string|null $sniffed      finfo result, null = sniffing unavailable
     */
    public static function is_allowed_media($filename, $content_type, $sniffed = null) {
        $ext = self::media_extension($filename);
        if ($ext === '') {
            return false;
        }
        $types = self::$media_types[$ext];

        $ct = strtolower(trim(explode(';', (string) $content_type)[0]));
        if ($ct === '' || !in_array($ct, $types['ct'], true)) {
            return false;
        }

        if ($sniffed !== null) {
            $sniffed = strtolower(trim(explode(';', (string) $sniffed)[0]));
            if (!in_array($sniffed, $types['sniff'], true)) {
                return false;
            }
        }

        return true;
    }

    /** MIME type of the downloaded bytes, null when no sniffer is available. */
    private static function sniff_mime($body) {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($body);
            return is_string($mime) && $mime !== '' ? $mime : null;
        }
        return null;
    }

    /**
     * Download a media file to local storage
     * Returns local URL or null on failure
     */
    private static function download_media($url, $trusted_host = '') {
        if (empty($url) || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Generate local filename from URL — extension must be on the allowlist
        $filename = sanitize_file_name(basename((string) parse_url($url, PHP_URL_PATH)));
        if (empty($filename) || $filename[0] === '.' || self::media_extension($filename) === '') {
            error_log('ImmoAdmin: Blocked media with disallowed file type: ' . $url);
            return null;
        }

        $local_path = IMMOADMIN_MEDIA_DIR . $filename;
        $local_url = content_url('/immoadmin/media/' . $filename);

        // Skip if already downloaded (and file is not empty/corrupt). Before
        // the SSRF check: no request is made, and media downloaded before a
        // backend host was pinned keep working.
        if (file_exists($local_path) && filesize($local_path) > 0) {
            return $local_url;
        }

        // SSRF protection
        if (!self::is_safe_url($url, $trusted_host)) {
            error_log('ImmoAdmin: Blocked unsafe media URL: ' . $url);
            return null;
        }

        $max_bytes = 50 * 1024 * 1024;
        $is_trusted = self::url_matches_trusted_host($url, $trusted_host);

        // wp_safe_remote_get() rejects private/loopback targets — also after
        // redirects. The configured backend may live in a private network
        // (Docker / Coolify): allow exactly that host (and its port).
        $allow_host = function ($external, $host) use ($trusted_host) {
            $plain = preg_replace('/:\d+$/', '', (string) $trusted_host);
            return strtolower((string) $host) === $plain ? true : $external;
        };
        $trusted_port = (int) parse_url('http://' . $trusted_host, PHP_URL_PORT);
        $allow_port = function ($ports) use ($trusted_port) {
            if ($trusted_port) {
                $ports[] = $trusted_port;
            }
            return $ports;
        };
        if ($is_trusted) {
            add_filter('http_request_host_is_external', $allow_host, 10, 2);
            add_filter('http_allowed_safe_ports', $allow_port);
        }

        // Download with retry on rate limit (429)
        $max_retries = 3;
        $response = null;

        try {
            for ($attempt = 0; $attempt < $max_retries; $attempt++) {
                self::heartbeat(); // long downloads must not look like a dead sync
                $response = wp_safe_remote_get($url, array(
                    'timeout' => 60,
                    'redirection' => 2,
                    'sslverify' => true,
                    'limit_response_size' => $max_bytes,
                ));

                if (is_wp_error($response)) {
                    error_log('ImmoAdmin: Failed to download media: ' . $response->get_error_message() . ' URL: ' . $url);
                    return null;
                }

                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 429) {
                    // Rate limited - wait and retry
                    $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                    $wait = min(60, max($retry_after, 2 * ($attempt + 1))); // At least 2s, increasing
                    error_log("ImmoAdmin: Rate limited (429), waiting {$wait}s before retry (attempt " . ($attempt + 1) . ")");
                    sleep($wait);
                    continue;
                }

                break; // Success or non-retryable error
            }
        } finally {
            if ($is_trusted) {
                remove_filter('http_request_host_is_external', $allow_host, 10);
                remove_filter('http_allowed_safe_ports', $allow_port);
            }
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            error_log('ImmoAdmin: Media download failed with status ' . wp_remote_retrieve_response_code($response) . ' URL: ' . $url);
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        $size = strlen($body);
        if ($size === 0 || $size >= $max_bytes) {
            error_log('ImmoAdmin: Media empty or too large (' . $size . ' bytes) URL: ' . $url);
            return null;
        }

        // Truncated transfer? (Content-Length is the encoded size, so only
        // comparable when the body was not compressed.)
        $content_length = wp_remote_retrieve_header($response, 'content-length');
        $encoding = strtolower(trim((string) wp_remote_retrieve_header($response, 'content-encoding')));
        if ($content_length !== '' && is_numeric($content_length) && ($encoding === '' || $encoding === 'identity')
            && (int) $content_length !== $size) {
            error_log('ImmoAdmin: Media size mismatch (' . $size . ' of ' . $content_length . ' bytes) URL: ' . $url);
            return null;
        }

        // Type: extension, Content-Type header and the actual bytes must agree
        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $sniffed = self::sniff_mime($body);
        if (!self::is_allowed_media($filename, $content_type, $sniffed)) {
            error_log('ImmoAdmin: Blocked media (type mismatch: ' . $content_type . ' / ' . (string) $sniffed . ') URL: ' . $url);
            return null;
        }

        // Ensure directory exists
        if (!file_exists(IMMOADMIN_MEDIA_DIR)) {
            wp_mkdir_p(IMMOADMIN_MEDIA_DIR);
        }

        // Write to a temp file (dotfile: skipped by orphan cleanup, not
        // served as media) and rename, so no half-written file is ever live.
        $tmp = IMMOADMIN_MEDIA_DIR . '.' . $filename . '.' . md5(uniqid((string) mt_rand(), true)) . '.part';
        $written = @file_put_contents($tmp, $body, LOCK_EX);
        if ($written !== $size) {
            @unlink($tmp);
            error_log('ImmoAdmin: Could not write media file ' . $filename);
            return null;
        }
        if (!@rename($tmp, $local_path)) {
            @unlink($tmp);
            error_log('ImmoAdmin: Could not move media file into place: ' . $filename);
            return null;
        }
        @chmod($local_path, 0644);

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

        // Trust the configured ImmoAdmin server (see trusted_backend_host())
        if (self::url_matches_trusted_host($url, $trusted_host)) {
            return true;
        }

        $host = strtolower($parsed['host']);

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
        if (!is_array($log)) {
            $log = array();
        }

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
        return array_slice(is_array($log) ? $log : array(), 0, $limit);
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
            'sync_status' => self::current_status(),
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
