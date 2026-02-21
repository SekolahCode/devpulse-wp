<?php
/**
 * Plugin Name:       DevPulse
 * Plugin URI:        https://github.com/yourname/devpulse-wp
 * Description:       Real-time error tracking for WordPress — self-hosted.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.7
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           MIT
 * Text Domain:       devpulse
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

// Load Composer autoloader if present; fall back to manual requires
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/Handler.php';
    require_once __DIR__ . '/src/Admin.php';
}

use DevPulseWP\Handler;
use DevPulseWP\Admin;

// Init on plugins_loaded at priority 1 — runs before most plugins so we catch
// errors thrown during their own initialisation.
add_action('plugins_loaded', function () {
    $dsn     = defined('DEVPULSE_DSN')     ? DEVPULSE_DSN     : get_option('devpulse_dsn', '');
    $env     = defined('DEVPULSE_ENV')     ? DEVPULSE_ENV     : get_option('devpulse_env', 'production');
    $enabled = defined('DEVPULSE_ENABLED') ? DEVPULSE_ENABLED : (bool) get_option('devpulse_enabled', 0);

    if (!$enabled || empty($dsn)) {
        return;
    }

    Handler::init($dsn, $env);
}, 1);

// Admin settings — only load in the admin context
if (is_admin()) {
    add_action('admin_menu', [Admin::class, 'register']);
    add_action('admin_init', [Admin::class, 'registerSettings']);
}
