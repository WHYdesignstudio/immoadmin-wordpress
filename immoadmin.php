<?php
/**
 * Plugin Name: ImmoAdmin
 * Plugin URI: https://immoadmin.at
 * Description: Synchronisiert Immobilien-Daten von ImmoAdmin und stellt sie als Custom Post Types bereit.
 * Version: 1.2.0
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

// Auto-update from GitHub
require_once plugin_dir_path(__FILE__) . 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$immoadminUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/WHYdesignstudio/immoadmin-wordpress/',
    __FILE__,
    'immoadmin'
);

// Set the branch that contains the stable release
$immoadminUpdateChecker->setBranch('main');

// Authentication for private repo (from DB option or wp-config.php)
$github_token = defined('IMMOADMIN_GITHUB_TOKEN') ? IMMOADMIN_GITHUB_TOKEN : ImmoAdmin::decrypt_option('immoadmin_github_token');
if (!empty($github_token)) {
    $immoadminUpdateChecker->setAuthentication($github_token);
}

// Plugin constants
define('IMMOADMIN_VERSION', '1.2.0');
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
    
    public function activate() {
        // Create data directories
        if (!file_exists(IMMOADMIN_DATA_DIR)) {
            wp_mkdir_p(IMMOADMIN_DATA_DIR);
        }
        if (!file_exists(IMMOADMIN_MEDIA_DIR)) {
            wp_mkdir_p(IMMOADMIN_MEDIA_DIR);
        }

        // Protect data directories from direct web access
        self::protect_directory(IMMOADMIN_DATA_DIR);
        self::protect_directory(IMMOADMIN_MEDIA_DIR);

        // Token is NOT auto-generated - user must enter it from ImmoAdmin

        // Register post type and flush rewrite rules
        ImmoAdmin_Post_Type::register();
        flush_rewrite_rules();
    }

    /**
     * Encrypt a value and store it as a WP option
     */
    public static function encrypt_option($option_name, $value) {
        if (empty($value)) {
            delete_option($option_name);
            return;
        }
        $key = hash('sha256', wp_salt('auth'), true); // Proper 32-byte key
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);
        update_option($option_name, base64_encode($iv . '::' . $encrypted));
    }

    /**
     * Decrypt a WP option value
     */
    public static function decrypt_option($option_name) {
        $stored = get_option($option_name, '');
        if (empty($stored)) {
            return '';
        }
        // Migration: if value doesn't look encrypted (starts with ghp_), encrypt it
        if (strpos($stored, 'ghp_') === 0 || strpos($stored, 'github_pat_') === 0) {
            self::encrypt_option($option_name, $stored);
            return $stored;
        }
        $decoded = base64_decode($stored);
        if ($decoded === false || strpos($decoded, '::') === false) {
            return '';
        }
        $parts = explode('::', $decoded, 2);
        $key = hash('sha256', wp_salt('auth'), true); // Proper 32-byte key
        $decrypted = openssl_decrypt($parts[1], 'aes-256-cbc', $key, 0, $parts[0]);
        return $decrypted !== false ? $decrypted : '';
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
    
    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize plugin
ImmoAdmin::get_instance();
