<?php

/**
 * Plugin Name:       DevPulse
 * Plugin URI:        https://github.com/SekolahCode/devpulse-wp
 * Description:       Real-time error tracking for WordPress — self-hosted.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Tested up to:      6.9
 * Author:            SekolahCode
 * Author URI:        https://github.com/SekolahCode
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       devpulse
 * Domain Path:       /languages
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * DevPulse Plugin Version.
 *
 * @since 1.0.0
 */
define('DEVPULSE_VERSION', '1.0.0');

/**
 * DevPulse Plugin File.
 *
 * @since 1.0.0
 */
define('DEVPULSE_FILE', __FILE__);

/**
 * DevPulse Plugin Directory.
 *
 * @since 1.0.0
 */
define('DEVPULSE_DIR', plugin_dir_path(__FILE__));

/**
 * DevPulse Plugin Base Name.
 *
 * @since 1.0.0
 */
define('DEVPULSE_BASENAME', plugin_basename(__FILE__));

/**
 * Load Composer autoloader if present; fall back to manual requires.
 *
 * @since 1.0.0
 */
if (file_exists(DEVPULSE_DIR . 'vendor/autoload.php')) {
    require_once DEVPULSE_DIR . 'vendor/autoload.php';
} else {
    require_once DEVPULSE_DIR . 'src/Handler.php';
    require_once DEVPULSE_DIR . 'src/Admin.php';
}

use DevPulseWP\Handler;
use DevPulseWP\Admin;

/**
 * Plugin activation hook.
 *
 * @since 1.0.0
 */
function devpulse_activate(): void
{
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(DEVPULSE_BASENAME);
        wp_die(
            esc_html__('DevPulse requires PHP 7.4 or higher.', 'devpulse'),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Check WordPress version
    global $wp_version;
    if (version_compare($wp_version, '6.3', '<')) {
        deactivate_plugins(DEVPULSE_BASENAME);
        wp_die(
            esc_html__('DevPulse requires WordPress 6.3 or higher.', 'devpulse'),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Set transient for activation redirect
    set_transient('devpulse_activated', true, 30);
}

register_activation_hook(__FILE__, 'devpulse_activate');

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
function devpulse_deactivate(): void
{
    // Clean up transients
    delete_transient('devpulse_activated');

    /**
     * Action: Fires on plugin deactivation.
     *
     * @since 1.0.0
     */
    do_action('devpulse_deactivated');
}

register_deactivation_hook(__FILE__, 'devpulse_deactivate');

/**
 * Initialize plugin on plugins_loaded.
 *
 * Init on plugins_loaded at priority 1 — runs before most plugins so we catch
 * errors thrown during their own initialization.
 *
 * @since 1.0.0
 */
function devpulse_init(): void
{
    // Allow disabling via constant
    if (defined('DEVPULSE_ENABLED') && !DEVPULSE_ENABLED) {
        return;
    }

    // Get configuration - constants take priority
    $dsn = defined('DEVPULSE_DSN') ? DEVPULSE_DSN : get_option('devpulse_dsn', '');
    $env = defined('DEVPULSE_ENV') ? DEVPULSE_ENV : get_option('devpulse_env', 'production');
    $enabled = defined('DEVPULSE_ENABLED') ? DEVPULSE_ENABLED : (bool) get_option('devpulse_enabled', 0);

    // Skip if not enabled
    if (!$enabled || empty($dsn)) {
        return;
    }

    // Initialize error handler
    Handler::init($dsn, $env);
}

add_action('plugins_loaded', 'devpulse_init', 1);

/**
 * Load admin settings.
 *
 * @since 1.0.0
 */
function devpulse_admin_init(): void
{
    // Only load in admin context
    if (!is_admin()) {
        return;
    }

    // Register admin menu
    add_action('admin_menu', [Admin::class, 'register']);

    // Register settings
    add_action('admin_init', [Admin::class, 'registerSettings']);

    // Enqueue admin assets
    add_action('admin_enqueue_scripts', [Admin::class, 'enqueueAssets']);

    // AJAX: repair database options
    add_action('wp_ajax_devpulse_repair', [Admin::class, 'repairDb']);

    // Handle activation redirect on admin_init (get_current_screen() is not
    // available on 'init'; check $_GET['page'] instead)
    add_action('admin_init', 'devpulse_activation_redirect');
}

/**
 * Redirect to settings page after plugin activation.
 *
 * @since 1.0.0
 */
function devpulse_activation_redirect(): void
{
    if (!get_transient('devpulse_activated')) {
        return;
    }

    delete_transient('devpulse_activated');

    // Don't redirect if already on the settings page
    if (isset($_GET['page']) && $_GET['page'] === 'devpulse') {
        return;
    }

    /**
     * Action: Fires after plugin is activated.
     *
     * @since 1.0.0
     */
    do_action('devpulse_after_activate');

    if (current_user_can('manage_options')) {
        wp_safe_redirect(admin_url('options-general.php?page=devpulse'));
        exit;
    }
}

add_action('init', 'devpulse_admin_init');

/**
 * Get plugin status for external use.
 *
 * @since 1.0.0
 * @return bool Whether plugin is enabled.
 */
function devpulse_is_enabled(): bool
{
    if (defined('DEVPULSE_ENABLED')) {
        return (bool) DEVPULSE_ENABLED;
    }

    return (bool) get_option('devpulse_enabled', 0);
}

/**
 * Get current DSN.
 *
 * @since 1.0.0
 * @return string|null DSN URL or null if not configured.
 */
function devpulse_get_dsn(): ?string
{
    if (defined('DEVPULSE_DSN')) {
        return DEVPULSE_DSN;
    }

    $dsn = get_option('devpulse_dsn', '');

    return !empty($dsn) ? $dsn : null;
}
