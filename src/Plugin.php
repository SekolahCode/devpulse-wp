<?php

/**
 * DevPulse Plugin Bootstrapper
 *
 * @package DevPulseWP
 * @since   1.0.0
 */

namespace DevPulseWP;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin entry point.
 *
 * Owns the Handler and Admin lifecycles and acts as a minimal service container.
 * Use devpulse()->handler() to access the active error handler from external code.
 *
 * @since 1.0.0
 */
final class Plugin {

	/** @var self|null */
	private static ?self $instance = null;

	/** @var Handler|null Active error handler; null when the plugin is disabled / unconfigured. */
	private ?Handler $handler = null;

	/** @var Admin|null Admin instance; null on non-admin requests. */
	private ?Admin $admin = null;

	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @since 1.0.0
	 * @return self
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register bootstrap hooks.
	 *
	 * @since 1.0.0
	 */
	public function boot(): void {
		// Initialise error handler at priority 1 so we catch errors thrown by other plugins.
		add_action( 'plugins_loaded', [ $this, 'init_handler' ], 1 );

		// Admin UI — only needed on admin / admin-ajax.php requests.
		add_action( 'init', [ $this, 'init_admin' ] );
	}

	/**
	 * Initialise the error handler.
	 *
	 * @since 1.0.0
	 */
	public function init_handler(): void {
		// Hard-disabled via constant.
		if ( defined( 'DEVPULSE_ENABLED' ) && ! DEVPULSE_ENABLED ) {
			return;
		}

		$dsn     = defined( 'DEVPULSE_DSN' )     ? DEVPULSE_DSN     : get_option( 'devpulse_dsn', '' );
		$env     = defined( 'DEVPULSE_ENV' )     ? DEVPULSE_ENV     : get_option( 'devpulse_env', 'production' );
		$enabled = defined( 'DEVPULSE_ENABLED' ) ? (bool) DEVPULSE_ENABLED : (bool) get_option( 'devpulse_enabled', 0 );
		$release = defined( 'DEVPULSE_RELEASE' ) ? DEVPULSE_RELEASE : get_option( 'devpulse_release', '' );

		if ( ! $enabled || empty( $dsn ) ) {
			return;
		}

		$this->handler = new Handler( $dsn, $env, $release ?: null );
		$this->handler->boot();
	}

	/**
	 * Initialise admin UI (admin and admin-ajax.php requests only).
	 *
	 * @since 1.0.0
	 */
	public function init_admin(): void {
		if ( ! is_admin() ) {
			return;
		}

		$this->admin = new Admin( $this->handler );
		$this->admin->boot();
	}

	/**
	 * Return the active Handler, or null when the plugin is disabled / unconfigured.
	 *
	 * @since 1.0.0
	 * @return Handler|null
	 */
	public function handler(): ?Handler {
		return $this->handler;
	}

	/**
	 * Return the Admin instance (only available on admin requests).
	 *
	 * @since 1.0.0
	 * @return Admin|null
	 */
	public function admin(): ?Admin {
		return $this->admin;
	}
}
