<?php
/**
 * Admin Dashboard UI
 */

if (!defined('ABSPATH')) {
    exit;
}

class ImmoAdmin_Admin {

    /**
     * Render the main dashboard page
     */
    public static function render_dashboard() {
        // Handle token save (always process this first)
        if (isset($_POST['immoadmin_save_token']) && wp_verify_nonce($_POST['_wpnonce'], 'immoadmin_save_token')) {
            $new_token = trim(sanitize_text_field($_POST['immoadmin_token']));
            // Store hash of token, not the token itself (security)
            $token_hash = hash('sha256', $new_token);
            update_option('immoadmin_webhook_token_hash', $token_hash);
            // Store masked version for display only
            $masked = substr($new_token, 0, 4) . str_repeat('•', 24) . substr($new_token, -4);
            update_option('immoadmin_webhook_token_masked', $masked);
            // Remove old plain text token if exists
            delete_option('immoadmin_webhook_token');
            $token_message = $new_token ? 'Token gespeichert! Verbindung hergestellt.' : 'Token entfernt!';
            $token_success = !empty($new_token);
        }

        // Handle GitHub token save
        if (isset($_POST['immoadmin_save_github_token']) && wp_verify_nonce($_POST['_wpnonce'], 'immoadmin_save_github_token')) {
            $gh_token = trim(sanitize_text_field($_POST['immoadmin_github_token']));
            if (!empty($gh_token)) {
                ImmoAdmin::encrypt_option('immoadmin_github_token', $gh_token);
                $token_message = 'GitHub Token gespeichert! Auto-Updates aktiviert.';
                $token_success = true;
            } else {
                delete_option('immoadmin_github_token');
                $token_message = 'GitHub Token entfernt.';
                $token_success = false;
            }
        }

        // Handle disconnect
        if (isset($_POST['immoadmin_disconnect']) && wp_verify_nonce($_POST['_wpnonce'], 'immoadmin_disconnect')) {
            delete_option('immoadmin_webhook_token');
            delete_option('immoadmin_webhook_token_hash');
            delete_option('immoadmin_webhook_token_masked');
            self::reset_connection_verification();
            // Show setup screen immediately after disconnect
            self::render_setup_screen('Verbindung getrennt.', false);
            return;
        }

        $webhook_token_hash = get_option('immoadmin_webhook_token_hash', '');
        $webhook_token_masked = get_option('immoadmin_webhook_token_masked', '');

        // If no token, show only the setup screen
        if (empty($webhook_token_hash)) {
            self::render_setup_screen($token_message ?? null, $token_success ?? false);
            return;
        }

        // If token exists but not verified yet, show waiting screen
        if (!self::is_connection_verified()) {
            self::render_waiting_screen($webhook_token_masked, $token_message ?? null);
            return;
        }

        // Handle manual sync
        if (isset($_POST['immoadmin_sync']) && wp_verify_nonce($_POST['_wpnonce'], 'immoadmin_sync')) {
            $result = ImmoAdmin_Sync::run();
            $sync_message = $result['success']
                ? 'Sync erfolgreich! ' . $result['stats']['created'] . ' erstellt, ' . $result['stats']['updated'] . ' aktualisiert.'
                : 'Sync fehlgeschlagen: ' . ($result['error'] ?? 'Unbekannter Fehler');
            $sync_success = $result['success'];
        }

        $status = ImmoAdmin_Sync::get_status();
        $log = ImmoAdmin_Sync::get_log(10);

        ?>
        <div class="wrap immoadmin-dashboard">
            <?php self::render_styles(); ?>

            <div class="immoadmin-header">
                <div class="immoadmin-logo">
                    <span class="dashicons dashicons-building"></span>
                    <div>
                        <h1>ImmoAdmin</h1>
                        <span class="subtitle">Immobilien-Synchronisation</span>
                    </div>
                </div>
                <form method="post" style="margin: 0;">
                    <?php wp_nonce_field('immoadmin_sync'); ?>
                    <button type="submit" name="immoadmin_sync" class="immoadmin-btn immoadmin-btn-primary">
                        <span class="dashicons dashicons-update"></span>
                        Jetzt synchronisieren
                    </button>
                </form>
            </div>

            <?php if (isset($sync_message)): ?>
                <div class="immoadmin-notice <?php echo $sync_success ? 'success' : 'error'; ?>">
                    <span class="dashicons <?php echo $sync_success ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php echo esc_html($sync_message); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($token_message)): ?>
                <div class="immoadmin-notice <?php echo $token_success ? 'success' : 'error'; ?>">
                    <span class="dashicons <?php echo $token_success ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <?php echo esc_html($token_message); ?>
                </div>
            <?php endif; ?>

            <!-- Status Cards -->
            <div class="immoadmin-cards">
                <div class="immoadmin-card">
                    <h3>Status</h3>
                    <div class="value <?php echo $status['json_exists'] ? 'success' : 'error'; ?>">
                        <?php echo $status['json_exists'] ? '✓ Verbunden' : '✗ Keine Daten'; ?>
                    </div>
                </div>
                <div class="immoadmin-card">
                    <h3>Einheiten</h3>
                    <div class="value"><?php echo esc_html($status['unit_count']); ?></div>
                </div>
                <div class="immoadmin-card">
                    <h3>Gebäude</h3>
                    <div class="value"><?php echo esc_html($status['building_count']); ?></div>
                </div>
                <div class="immoadmin-card">
                    <h3>Letzter Sync</h3>
                    <div class="value" style="font-size: 16px;">
                        <?php
                        if ($status['last_sync']) {
                            $diff = human_time_diff(strtotime($status['last_sync']), current_time('timestamp'));
                            echo 'vor ' . $diff;
                        } else {
                            echo 'Noch nie';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- System Check -->
            <div class="immoadmin-section">
                <h2><span class="dashicons dashicons-yes-alt"></span> System Check</h2>
                <div class="immoadmin-check">
                    <span class="dashicons <?php echo $status['json_exists'] ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>"></span>
                    <span>JSON-Datei <?php echo $status['json_exists'] ? 'vorhanden: <code>' . esc_html($status['json_file']) . '</code>' : 'nicht gefunden'; ?></span>
                </div>
                <div class="immoadmin-check">
                    <span class="dashicons <?php echo $status['media_dir_writable'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <span>Media-Ordner <?php echo $status['media_dir_writable'] ? 'beschreibbar' : 'nicht beschreibbar'; ?></span>
                </div>
                <div class="immoadmin-check">
                    <span class="dashicons <?php echo $status['unit_count'] > 0 ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <span><strong><?php echo $status['unit_count']; ?></strong> Einheiten synchronisiert</span>
                </div>
                <?php if ($status['json_meta']): ?>
                <div class="immoadmin-check">
                    <span class="dashicons dashicons-info"></span>
                    <span>Projekt: <strong><?php echo esc_html($status['json_meta']['projectName'] ?? 'Unbekannt'); ?></strong></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sync Log -->
            <div class="immoadmin-section">
                <h2><span class="dashicons dashicons-backup"></span> Sync Log</h2>
                <?php if (empty($log)): ?>
                    <p style="color: #64748b;">Noch keine Syncs durchgeführt.</p>
                <?php else: ?>
                    <div class="immoadmin-log">
                        <?php foreach ($log as $entry): ?>
                            <div class="immoadmin-log-entry">
                                <div class="time"><?php echo esc_html($entry['time']); ?> · <?php echo esc_html($entry['duration']); ?>s</div>
                                <div class="stats">
                                    <span class="created">+<?php echo esc_html($entry['stats']['created']); ?> erstellt</span>
                                    <span class="updated">↻<?php echo esc_html($entry['stats']['updated']); ?> aktualisiert</span>
                                    <?php if ($entry['stats']['deleted'] > 0): ?>
                                        <span class="deleted">-<?php echo esc_html($entry['stats']['deleted']); ?> gelöscht</span>
                                    <?php endif; ?>
                                    <?php if ($entry['stats']['media_downloaded'] > 0): ?>
                                        <span class="media">📷 <?php echo esc_html($entry['stats']['media_downloaded']); ?> Medien</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($entry['stats']['errors'])): ?>
                                    <div style="color: #dc2626; margin-top: 8px; font-size: 12px;">
                                        ⚠️ <?php echo esc_html(implode(', ', $entry['stats']['errors'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Settings -->
            <div class="immoadmin-section">
                <h2><span class="dashicons dashicons-admin-settings"></span> Einstellungen</h2>

                <h4>Webhook Token</h4>
                <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">
                    Verbunden mit ImmoAdmin. Token kann bei Bedarf geändert werden.
                </p>
                <form method="post">
                    <?php wp_nonce_field('immoadmin_save_token'); ?>
                    <div class="immoadmin-token">
                        <input
                            type="text"
                            name="immoadmin_token"
                            id="webhook-token"
                            value="<?php echo esc_attr($status['webhook_token']); ?>"
                            placeholder="Token von ImmoAdmin einfügen..."
                        />
                        <button type="submit" name="immoadmin_save_token" class="immoadmin-btn immoadmin-btn-secondary">
                            Aktualisieren
                        </button>
                    </div>
                </form>

                <h4 style="margin-top: 24px;">Webhook URL</h4>
                <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">Diese URL wird automatisch von ImmoAdmin aufgerufen.</p>
                <code class="immoadmin-code"><?php echo esc_html(rest_url('immoadmin/v1/sync')); ?></code>

                <h4 style="margin-top: 24px;">Auto-Updates</h4>
                <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">
                    GitHub Token für automatische Plugin-Updates vom privaten Repository.
                </p>
                <form method="post">
                    <?php wp_nonce_field('immoadmin_save_github_token'); ?>
                    <div class="immoadmin-token">
                        <?php $gh_token = ImmoAdmin::decrypt_option('immoadmin_github_token'); ?>
                        <input
                            type="text"
                            name="immoadmin_github_token"
                            value="<?php echo esc_attr($gh_token); ?>"
                            placeholder="ghp_..."
                        />
                        <button type="submit" name="immoadmin_save_github_token" class="immoadmin-btn immoadmin-btn-secondary">
                            Speichern
                        </button>
                    </div>
                    <?php if (!empty($gh_token)): ?>
                        <p style="color: #16a34a; font-size: 12px; margin-top: 8px;">
                            <span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px;"></span>
                            Token konfiguriert – Auto-Updates aktiv
                        </p>
                    <?php endif; ?>
                </form>

                <h4 style="margin-top: 24px;">Verbindung trennen</h4>
                <p style="color: #64748b; font-size: 13px; margin-bottom: 12px;">Token entfernen und Plugin zurücksetzen.</p>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('immoadmin_disconnect'); ?>
                    <button type="submit" name="immoadmin_disconnect" class="immoadmin-btn immoadmin-btn-danger" onclick="return confirm('Verbindung wirklich trennen?');">
                        <span class="dashicons dashicons-dismiss"></span>
                        Verbindung trennen
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Check if connection is verified (first successful sync happened)
     */
    private static function is_connection_verified() {
        return get_option('immoadmin_connection_verified', false);
    }

    /**
     * Mark connection as verified
     */
    public static function mark_connection_verified() {
        update_option('immoadmin_connection_verified', true);
    }

    /**
     * Reset connection verification
     */
    public static function reset_connection_verification() {
        delete_option('immoadmin_connection_verified');
    }

    /**
     * Render the waiting screen (token entered, waiting for verification from ImmoAdmin)
     */
    private static function render_waiting_screen($token, $message = null) {
        ?>
        <div class="wrap immoadmin-dashboard">
            <?php self::render_styles(); ?>

            <div class="immoadmin-setup">
                <div class="immoadmin-setup-box">
                    <div class="icon warning">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <h1>Warte auf Verifizierung</h1>
                    <p>
                        Token wurde gespeichert. Jetzt in ImmoAdmin:<br>
                        <strong>Projekt → Sync-Button klicken</strong>
                    </p>

                    <?php if ($message): ?>
                        <div class="immoadmin-notice success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html($message); ?>
                        </div>
                    <?php endif; ?>

                    <div class="token-display">
                        <label>Gespeicherter Token</label>
                        <code><?php echo esc_html($token); ?></code>
                    </div>

                    <div class="btn-group">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=immoadmin')); ?>" class="immoadmin-btn immoadmin-btn-primary">
                            <span class="dashicons dashicons-update"></span>
                            Seite neu laden
                        </a>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field('immoadmin_disconnect'); ?>
                            <button type="submit" name="immoadmin_disconnect" class="immoadmin-btn immoadmin-btn-secondary">
                                Token ändern
                            </button>
                        </form>
                    </div>

                    <p class="hint">
                        Nach dem ersten erfolgreichen Sync wird das Dashboard freigeschaltet.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the setup screen (shown when no token is configured)
     */
    private static function render_setup_screen($message = null, $success = false) {
        ?>
        <div class="wrap immoadmin-dashboard">
            <?php self::render_styles(); ?>

            <div class="immoadmin-setup">
                <div class="immoadmin-setup-box">
                    <div class="icon">
                        <span class="dashicons dashicons-building"></span>
                    </div>
                    <h1>ImmoAdmin verbinden</h1>
                    <p>
                        Kopieren Sie den Token aus ImmoAdmin<br>
                        <strong>(Projekt → Einstellungen → Sync)</strong>
                    </p>

                    <?php if ($message): ?>
                        <div class="immoadmin-notice <?php echo $success ? 'success' : 'error'; ?>">
                            <span class="dashicons <?php echo $success ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                            <?php echo esc_html($message); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?php wp_nonce_field('immoadmin_save_token'); ?>
                        <div class="input-group">
                            <input
                                type="text"
                                name="immoadmin_token"
                                placeholder="Token hier einfügen..."
                                required
                            />
                            <button type="submit" name="immoadmin_save_token" class="immoadmin-btn immoadmin-btn-primary">
                                Verbinden
                            </button>
                        </div>
                    </form>

                    <p class="hint">
                        Noch kein ImmoAdmin? <a href="https://immoadmin.at" target="_blank">Mehr erfahren →</a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render shared styles - Modern design matching ImmoAdmin
     */
    private static function render_styles() {
        ?>
        <style>
            /* Reset & Base */
            .immoadmin-dashboard {
                max-width: 1200px;
                font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', Roboto, sans-serif;
                color: #0f172a;
                font-size: 14px;
                line-height: 1.5;
            }
            .immoadmin-dashboard * { box-sizing: border-box; }

            /* Header */
            .immoadmin-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 24px;
                padding: 24px;
                background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
                border-radius: 16px;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            }
            .immoadmin-logo { display: flex; align-items: center; gap: 16px; }
            .immoadmin-logo h1 { margin: 0; font-size: 24px; font-weight: 700; color: #fff; letter-spacing: -0.5px; }
            .immoadmin-logo .subtitle { color: #94a3b8; font-size: 13px; font-weight: 400; }
            .immoadmin-logo .dashicons {
                font-size: 36px;
                width: 48px;
                height: 48px;
                background: rgba(255,255,255,0.1);
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #60a5fa;
            }

            /* Buttons */
            .immoadmin-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 20px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.15s ease;
                border: none;
                text-decoration: none;
            }
            .immoadmin-btn-primary {
                background: #3b82f6;
                color: #fff;
                box-shadow: 0 1px 2px rgba(59,130,246,0.3);
            }
            .immoadmin-btn-primary:hover { background: #2563eb; color: #fff; }
            .immoadmin-btn-secondary {
                background: #f1f5f9;
                color: #475569;
                border: 1px solid #e2e8f0;
            }
            .immoadmin-btn-secondary:hover { background: #e2e8f0; color: #334155; }
            .immoadmin-btn-danger {
                background: #fee2e2;
                color: #dc2626;
                border: 1px solid #fecaca;
            }
            .immoadmin-btn-danger:hover { background: #fecaca; }
            .immoadmin-btn .dashicons { font-size: 18px; width: 18px; height: 18px; }

            /* Cards Grid */
            .immoadmin-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                margin-bottom: 24px;
            }
            .immoadmin-card {
                background: #fff;
                padding: 24px;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                transition: all 0.15s ease;
            }
            .immoadmin-card:hover {
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
                border-color: #cbd5e1;
            }
            .immoadmin-card h3 {
                margin: 0 0 8px;
                font-size: 12px;
                font-weight: 500;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .immoadmin-card .value {
                font-size: 36px;
                font-weight: 700;
                color: #0f172a;
                letter-spacing: -1px;
            }
            .immoadmin-card .value.success { color: #16a34a; }
            .immoadmin-card .value.warning { color: #ca8a04; }
            .immoadmin-card .value.error { color: #dc2626; }

            /* Sections */
            .immoadmin-section {
                background: #fff;
                padding: 24px;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                margin-bottom: 20px;
            }
            .immoadmin-section h2 {
                margin: 0 0 16px;
                font-size: 16px;
                font-weight: 600;
                color: #0f172a;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .immoadmin-section h2 .dashicons { color: #64748b; }
            .immoadmin-section h4 {
                margin: 0 0 8px;
                font-size: 14px;
                font-weight: 600;
                color: #334155;
            }

            /* System Checks */
            .immoadmin-check {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                background: #f8fafc;
                border-radius: 8px;
                margin-bottom: 8px;
            }
            .immoadmin-check:last-child { margin-bottom: 0; }
            .immoadmin-check .dashicons {
                width: 20px;
                height: 20px;
                font-size: 20px;
            }
            .immoadmin-check .dashicons-yes-alt { color: #16a34a; }
            .immoadmin-check .dashicons-warning { color: #ca8a04; }
            .immoadmin-check .dashicons-dismiss { color: #dc2626; }
            .immoadmin-check .dashicons-info { color: #3b82f6; }

            /* Sync Log */
            .immoadmin-log {
                max-height: 320px;
                overflow-y: auto;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
            }
            .immoadmin-log-entry {
                padding: 16px;
                border-bottom: 1px solid #f1f5f9;
                transition: background 0.1s ease;
            }
            .immoadmin-log-entry:hover { background: #f8fafc; }
            .immoadmin-log-entry:last-child { border-bottom: none; }
            .immoadmin-log-entry .time {
                color: #64748b;
                font-size: 12px;
                font-weight: 500;
            }
            .immoadmin-log-entry .stats {
                margin-top: 8px;
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
            }
            .immoadmin-log-entry .stats span {
                font-size: 13px;
                font-weight: 500;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .immoadmin-log-entry .stats .created { color: #16a34a; }
            .immoadmin-log-entry .stats .updated { color: #3b82f6; }
            .immoadmin-log-entry .stats .deleted { color: #dc2626; }
            .immoadmin-log-entry .stats .media { color: #8b5cf6; }

            /* Token Input */
            .immoadmin-token {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
            }
            .immoadmin-token input[type="text"] {
                flex: 1;
                min-width: 300px;
                padding: 12px 16px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-family: 'SF Mono', 'Consolas', monospace;
                font-size: 13px;
                background: #f8fafc;
                transition: all 0.15s ease;
            }
            .immoadmin-token input[type="text"]:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
                background: #fff;
            }

            /* Code blocks */
            .immoadmin-code {
                display: block;
                padding: 12px 16px;
                background: #f1f5f9;
                border-radius: 8px;
                font-family: 'SF Mono', 'Consolas', monospace;
                font-size: 13px;
                color: #334155;
                word-break: break-all;
            }

            /* Notices */
            .immoadmin-notice {
                padding: 16px 20px;
                border-radius: 8px;
                margin-bottom: 16px;
                display: flex;
                align-items: center;
                gap: 12px;
                font-weight: 500;
            }
            .immoadmin-notice .dashicons { font-size: 20px; width: 20px; height: 20px; }
            .immoadmin-notice.success {
                background: #dcfce7;
                color: #166534;
                border: 1px solid #bbf7d0;
            }
            .immoadmin-notice.error {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            .immoadmin-notice.info {
                background: #dbeafe;
                color: #1e40af;
                border: 1px solid #bfdbfe;
            }

            /* Setup Screen */
            .immoadmin-setup {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 70vh;
                padding: 40px 20px;
            }
            .immoadmin-setup-box {
                background: #fff;
                padding: 48px;
                border-radius: 20px;
                box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
                text-align: center;
                max-width: 520px;
                width: 100%;
                border: 1px solid #e2e8f0;
            }
            .immoadmin-setup-box .icon {
                width: 80px;
                height: 80px;
                background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
                border-radius: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 24px;
                box-shadow: 0 10px 15px -3px rgba(59,130,246,0.3);
            }
            .immoadmin-setup-box .icon .dashicons {
                font-size: 40px;
                width: 40px;
                height: 40px;
                color: #fff;
            }
            .immoadmin-setup-box .icon.warning {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                box-shadow: 0 10px 15px -3px rgba(245,158,11,0.3);
            }
            .immoadmin-setup-box h1 {
                margin: 0 0 12px;
                font-size: 28px;
                font-weight: 700;
                color: #0f172a;
                letter-spacing: -0.5px;
            }
            .immoadmin-setup-box p {
                color: #64748b;
                font-size: 15px;
                line-height: 1.6;
                margin: 0 0 24px;
            }
            .immoadmin-setup-box .token-display {
                background: #f1f5f9;
                padding: 16px;
                border-radius: 12px;
                margin-bottom: 24px;
            }
            .immoadmin-setup-box .token-display label {
                display: block;
                font-size: 11px;
                font-weight: 600;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 8px;
            }
            .immoadmin-setup-box .token-display code {
                font-family: 'SF Mono', 'Consolas', monospace;
                font-size: 13px;
                color: #334155;
                word-break: break-all;
            }
            .immoadmin-setup-box .input-group {
                display: flex;
                gap: 12px;
                max-width: 100%;
            }
            .immoadmin-setup-box input[type="text"] {
                flex: 1;
                padding: 14px 18px;
                border: 2px solid #e2e8f0;
                border-radius: 10px;
                font-family: 'SF Mono', 'Consolas', monospace;
                font-size: 14px;
                transition: all 0.15s ease;
            }
            .immoadmin-setup-box input[type="text"]:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 4px rgba(59,130,246,0.1);
            }
            .immoadmin-setup-box .btn-group {
                display: flex;
                gap: 12px;
                justify-content: center;
                margin-top: 24px;
            }
            .immoadmin-setup-box .hint {
                color: #94a3b8;
                font-size: 13px;
                margin-top: 32px;
            }
            .immoadmin-setup-box .hint a { color: #3b82f6; }

            /* Responsive */
            @media (max-width: 768px) {
                .immoadmin-header { flex-direction: column; gap: 16px; text-align: center; }
                .immoadmin-setup-box { padding: 32px 24px; }
                .immoadmin-setup-box .input-group { flex-direction: column; }
            }
        </style>
        <?php
    }
}
