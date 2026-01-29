<?php
/**
 * Plugin Name: ImmoAdmin
 * Plugin URI: https://immoadmin.at
 * Description: Synchronisiert Immobilien-Daten von ImmoAdmin und stellt sie als Custom Post Types bereit.
 * Version: 1.0.0
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

// Optional: If the repo is private, add authentication
// $immoadminUpdateChecker->setAuthentication('your-github-token');

// Plugin constants
define('IMMOADMIN_VERSION', '1.0.0');
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

        // Token is NOT auto-generated - user must enter it from ImmoAdmin

        // Register post type and flush rewrite rules
        ImmoAdmin_Post_Type::register();
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize plugin
ImmoAdmin::get_instance();
