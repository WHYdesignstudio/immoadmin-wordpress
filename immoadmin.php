<?php
/**
 * Plugin Name: ImmoAdmin
 * Plugin URI: https://immoadmin.at
 * Description: Synchronisiert Immobilien-Daten von ImmoAdmin und stellt sie als Custom Post Types bereit.
 * Version: 2.0.4
 * Author: WHY Agency
 * Author URI: https://why.dev
 * Text Domain: immoadmin
 * GitHub Plugin URI: WHYdesignstudio/immoadmin-wordpress
 * Primary Branch: main
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Auto-update from GitHub (public repo, no authentication needed)
require_once plugin_dir_path(__FILE__) . 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$immoadminUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/WHYdesignstudio/immoadmin-wordpress/',
    __FILE__,
    'immoadmin'
);

// Set the branch that contains the stable release
$immoadminUpdateChecker->setBranch('main');

// Plugin constants
define('IMMOADMIN_VERSION', '2.0.4');
define('IMMOADMIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IMMOADMIN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IMMOADMIN_DATA_DIR', WP_CONTENT_DIR . '/immoadmin/');
define('IMMOADMIN_MEDIA_DIR', IMMOADMIN_DATA_DIR . 'media/');

// Autoload classes
require_once IMMOADMIN_PLUGIN_DIR . 'includes/class-post-type.php';
require_once IMMOADMIN_PLUGIN_DIR . 'includes/class-sync.php';
require_once IMMOADMIN_PLUGIN_DIR . 'includes/class-admin.php';
require_once IMMOADMIN_PLUGIN_DIR . 'includes/class-webhook.php';

/**
 * Main plugin class
 */
class ImmoAdmin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize components
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Allow our REST API namespace even when plugins block unauthenticated access
        add_filter('rest_authentication_errors', array($this, 'allow_immoadmin_rest'), 99);

        // Run version-based migrations on every load (handles updates without reactivation)
        add_action('admin_init', array($this, 'maybe_run_migrations'));

        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Register Custom Post Type
        ImmoAdmin_Post_Type::register();
    }
    
    public function admin_menu() {
        add_menu_page(
            'ImmoAdmin',
            'ImmoAdmin',
            'manage_options',
            'immoadmin',
            array('ImmoAdmin_Admin', 'render_dashboard'),
            'dashicons-building',
            30
        );
    }
    
    public function register_rest_routes() {
        ImmoAdmin_Webhook::register_routes();
    }

    /**
     * Allow unauthenticated access to our REST endpoints.
     * Many security plugins block the REST API for non-logged-in users.
     * Our endpoints have their own token + HMAC authentication.
     */
    public function allow_immoadmin_rest($result) {
        if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wp-json/immoadmin/') !== false) {
            return true;
        }
        return $result;
    }

    public function activate() {
        // Create data directories
        if (!file_exists(IMMOADMIN_DATA_DIR)) {
            wp_mkdir_p(IMMOADMIN_DATA_DIR);
        }
        if (!file_exists(IMMOADMIN_MEDIA_DIR)) {
            wp_mkdir_p(IMMOADMIN_MEDIA_DIR);
        }

        // Protect data directory (JSON files) from direct web access
        // Media directory is NOT protected - images/PDFs must be publicly accessible
        self::protect_directory(IMMOADMIN_DATA_DIR);
        self::unprotect_directory(IMMOADMIN_MEDIA_DIR);

        // Token is NOT auto-generated - user must enter it from ImmoAdmin

        // Register post type and flush rewrite rules
        ImmoAdmin_Post_Type::register();
        flush_rewrite_rules();
    }

    /**
     * Protect a directory from direct web access
     */
    private static function protect_directory($dir) {
        // .htaccess for Apache
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n", LOCK_EX);
        }

        // index.php as fallback (nginx, misconfigured Apache)
        $index = $dir . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
        }
    }

    /**
     * Ensure a directory is publicly accessible
     * Writes "Allow from all" to override parent directory's "Deny from all"
     */
    private static function unprotect_directory($dir) {
        $htaccess = $dir . '.htaccess';
        // Always write Allow - overrides parent's "Deny from all"
        file_put_contents($htaccess, "Allow from all\nSatisfy any\n", LOCK_EX);
    }
    
    /**
     * Run migrations when plugin version changes (works on update without reactivation)
     */
    public function maybe_run_migrations() {
        $stored_version = get_option('immoadmin_db_version', '0');
        if (version_compare($stored_version, IMMOADMIN_VERSION, '>=')) {
            return;
        }

        // v2.0.4: Write "Allow from all" to media dir (overrides parent "Deny from all")
        if (version_compare($stored_version, '2.0.4', '<')) {
            self::unprotect_directory(IMMOADMIN_MEDIA_DIR);
        }

        // Ensure directories exist
        if (!file_exists(IMMOADMIN_DATA_DIR)) {
            wp_mkdir_p(IMMOADMIN_DATA_DIR);
        }
        if (!file_exists(IMMOADMIN_MEDIA_DIR)) {
            wp_mkdir_p(IMMOADMIN_MEDIA_DIR);
        }

        update_option('immoadmin_db_version', IMMOADMIN_VERSION);
    }

    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize plugin
ImmoAdmin::get_instance();
