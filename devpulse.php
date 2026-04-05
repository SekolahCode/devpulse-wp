<?php

/**
 * Plugin Name:       DevPulse
 * Plugin URI:        https://github.com/SekolahCode/devpulse-wp
 * Description:       Real-time error tracking for WordPress — self-hosted.
 * Version:           2.0.0
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
defined( 'ABSPATH' ) || exit;

/**
 * DevPulse Plugin Version.
 *
 * @since 1.0.0
 */
define( 'DEVPULSE_VERSION', '2.0.0' );

/**
 * Absolute path to the main plugin file.
 *
 * @since 1.0.0
 */
define( 'DEVPULSE_FILE', __FILE__ );

/**
 * Plugin root directory (with trailing slash).
 *
 * @since 1.0.0
 */
define( 'DEVPULSE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin basename (e.g. devpulse/devpulse.php).
 *
 * @since 1.0.0
 */
define( 'DEVPULSE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load Composer autoloader if present; fall back to manual requires.
 *
 * @since 1.0.0
 */
if ( file_exists( DEVPULSE_DIR . 'vendor/autoload.php' ) ) {
	require_once DEVPULSE_DIR . 'vendor/autoload.php';
} else {
	require_once DEVPULSE_DIR . 'src/Plugin.php';
	require_once DEVPULSE_DIR . 'src/Handler.php';
	require_once DEVPULSE_DIR . 'src/Admin.php';
}

use DevPulseWP\Plugin;

// ── Activation / Deactivation ─────────────────────────────────────────────

/**
 * Plugin activation hook.
 *
 * @since 1.0.0
 */
function devpulse_activate(): void {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( DEVPULSE_BASENAME );
		wp_die(
			esc_html__( 'DevPulse requires PHP 7.4 or higher.', 'devpulse' ),
			'Plugin Activation Error',
			[ 'back_link' => true ]
		);
	}

	global $wp_version;
	if ( version_compare( $wp_version, '6.3', '<' ) ) {
		deactivate_plugins( DEVPULSE_BASENAME );
		wp_die(
			esc_html__( 'DevPulse requires WordPress 6.3 or higher.', 'devpulse' ),
			'Plugin Activation Error',
			[ 'back_link' => true ]
		);
	}

	set_transient( 'devpulse_activated', true, 30 );
}

register_activation_hook( __FILE__, 'devpulse_activate' );

/**
 * Plugin deactivation hook.
 *
 * @since 1.0.0
 */
function devpulse_deactivate(): void {
	delete_transient( 'devpulse_activated' );

	/**
	 * Action: Fires on plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	do_action( 'devpulse_deactivated' );
}

register_deactivation_hook( __FILE__, 'devpulse_deactivate' );

// ── Public API ────────────────────────────────────────────────────────────

/**
 * Return the Plugin singleton.
 *
 * Use devpulse()->handler() to access the active Handler from external code.
 *
 * @since 1.0.0
 * @return Plugin
 */
function devpulse(): Plugin {
	return Plugin::instance();
}

/**
 * Check whether DevPulse is currently enabled.
 *
 * @since 1.0.0
 * @return bool
 */
function devpulse_is_enabled(): bool {
	if ( defined( 'DEVPULSE_ENABLED' ) ) {
		return (bool) DEVPULSE_ENABLED;
	}

	return (bool) get_option( 'devpulse_enabled', 0 );
}

/**
 * Return the active DSN, or null if not configured.
 *
 * @since 1.0.0
 * @return string|null
 */
function devpulse_get_dsn(): ?string {
	if ( defined( 'DEVPULSE_DSN' ) ) {
		return DEVPULSE_DSN;
	}

	$dsn = get_option( 'devpulse_dsn', '' );

	return ! empty( $dsn ) ? $dsn : null;
}

// ── Bootstrap ─────────────────────────────────────────────────────────────

Plugin::instance()->boot();
