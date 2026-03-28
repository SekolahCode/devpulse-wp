<?php

/**
 * DevPulse Admin Settings
 *
 * @package DevPulseWP
 * @since   1.0.0
 */

namespace DevPulseWP;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the plugin settings page and handles admin AJAX.
 *
 * Accepts a Handler instance via the constructor so the connection-test AJAX
 * action works even before the plugin is enabled (first-time setup): if no
 * running handler exists, a temporary one is created just for the test.
 *
 * @since 1.0.0
 */
class Admin {

	/** @var string Settings page hook suffix. */
	private const PAGE_HOOK = 'settings_page_devpulse';

	/** @var string Script handle. */
	private const SCRIPT_HANDLE = 'devpulse-admin';

	/** @var string Style handle. */
	private const STYLE_HANDLE = 'devpulse-admin-style';

	/** @var string Settings option group. */
	private const OPTION_GROUP = 'devpulse_settings';

	/** @var Handler|null Active handler; null when plugin is disabled / unconfigured. */
	private ?Handler $handler;

	/**
	 * @param Handler|null $handler Active error handler, or null when the plugin is disabled.
	 */
	public function __construct( ?Handler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Register all admin hooks.
	 *
	 * AJAX actions are registered unconditionally because admin-ajax.php requests
	 * are is_admin() === true and these actions require manage_options anyway.
	 *
	 * @since 1.0.0
	 */
	public function boot(): void {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_init',            [ $this, 'activation_redirect' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_devpulse_test',   [ $this, 'ajax_test' ] );
		add_action( 'wp_ajax_devpulse_repair', [ $this, 'repair_db' ] );
		add_filter(
			'plugin_action_links_' . plugin_basename( DEVPULSE_FILE ),
			[ $this, 'plugin_action_links' ]
		);
	}

	// ── Menu & Asset Registration ─────────────────────────────────────────

	/**
	 * Register the DevPulse settings page under Settings.
	 *
	 * @since 1.0.0
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'DevPulse Settings', 'devpulse' ),
			__( 'DevPulse', 'devpulse' ),
			'manage_options',
			'devpulse',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Add "Settings" action link on the Plugins list page.
	 *
	 * @since 1.0.0
	 * @param array $links
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		array_unshift( $links, sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=devpulse' ) ),
			esc_html__( 'Settings', 'devpulse' )
		) );

		return $links;
	}

	/**
	 * Enqueue admin JS and CSS — only on the DevPulse settings page.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== self::PAGE_HOOK ) {
			return;
		}

		$script_path    = 'assets/admin.js';
		$script_abs     = DEVPULSE_DIR . $script_path;
		$script_version = file_exists( $script_abs ) ? (string) filemtime( $script_abs ) : DEVPULSE_VERSION;

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( $script_path, DEVPULSE_FILE ),
			[],
			$script_version,
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);

		/**
		 * Filter: Modify the admin JS configuration object.
		 *
		 * @since 1.0.0
		 * @param array $config
		 */
		$config = apply_filters( 'devpulse_admin_js_config', [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'devpulse_test' ),
			'action'       => 'devpulse_test',
			'repairNonce'  => wp_create_nonce( 'devpulse_repair' ),
			'repairAction' => 'devpulse_repair',
			'sendingText'  => __( 'Sending...', 'devpulse' ),
			'successText'  => __( 'Event sent!', 'devpulse' ),
			'failedText'   => __( 'Failed', 'devpulse' ),
		] );

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.devpulseAdmin = ' . wp_json_encode( $config ) . ';',
			'before'
		);
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$style_path    = 'assets/admin.css';
		$style_abs     = DEVPULSE_DIR . $style_path;
		$style_version = file_exists( $style_abs ) ? (string) filemtime( $style_abs ) : DEVPULSE_VERSION;

		wp_register_style( self::STYLE_HANDLE, plugins_url( $style_path, DEVPULSE_FILE ), [], $style_version );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $this->get_admin_styles() );
	}

	// ── Settings API ──────────────────────────────────────────────────────

	/**
	 * Register settings using the WordPress Settings API.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, 'devpulse_dsn', [
			'sanitize_callback' => [ $this, 'sanitize_dsn' ],
			'default'           => '',
			'type'              => 'string',
			'show_in_rest'      => false,
		] );

		register_setting( self::OPTION_GROUP, 'devpulse_env', [
			'sanitize_callback' => [ $this, 'sanitize_env' ],
			'default'           => 'production',
			'type'              => 'string',
			'show_in_rest'      => false,
		] );

		register_setting( self::OPTION_GROUP, 'devpulse_enabled', [
			'sanitize_callback' => 'absint',
			'default'           => 0,
			'type'              => 'boolean',
			'show_in_rest'      => false,
		] );

		register_setting( self::OPTION_GROUP, 'devpulse_sample_rate', [
			'sanitize_callback' => [ $this, 'sanitize_sample_rate' ],
			'default'           => 1.0,
			'type'              => 'number',
			'show_in_rest'      => false,
		] );

		register_setting( self::OPTION_GROUP, 'devpulse_release', [
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
			'type'              => 'string',
			'show_in_rest'      => false,
		] );
	}

	/**
	 * Sanitize DSN: must be a valid URL or empty.
	 *
	 * @since 1.0.0
	 * @param mixed $value
	 * @return string
	 */
	public function sanitize_dsn( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( empty( $value ) ) {
			return '';
		}

		if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			add_settings_error(
				self::OPTION_GROUP,
				'devpulse_dsn_invalid',
				__( 'Please enter a valid DSN URL.', 'devpulse' ),
				'error'
			);
			return '';
		}

		return esc_url_raw( $value );
	}

	/**
	 * Sanitize environment: allow predefined slugs or any lowercase alphanumeric slug.
	 *
	 * @since 1.0.0
	 * @param mixed $value
	 * @return string
	 */
	public function sanitize_env( $value ): string {
		if ( ! is_string( $value ) ) {
			return 'production';
		}

		$allowed = [ 'production', 'staging', 'development', 'local', 'test' ];
		$value   = sanitize_key( trim( $value ) );

		if ( ! in_array( $value, $allowed, true ) ) {
			$value = sanitize_title( $value );
		}

		return $value ?: 'production';
	}

	/**
	 * Sanitize sample rate: clamp to 0.0–1.0.
	 *
	 * @since 1.0.0
	 * @param mixed $value
	 * @return float
	 */
	public function sanitize_sample_rate( $value ): float {
		return max( 0.0, min( 1.0, (float) $value ) );
	}

	// ── Settings Page Render ──────────────────────────────────────────────

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'devpulse' ) );
		}

		$dsn         = get_option( 'devpulse_dsn', '' );
		$env         = get_option( 'devpulse_env', 'production' );
		$enabled     = (int) get_option( 'devpulse_enabled', 0 );
		$sample_rate = (float) get_option( 'devpulse_sample_rate', 1.0 );
		$release     = get_option( 'devpulse_release', '' );

		$dsn_via_constant  = defined( 'DEVPULSE_DSN' );
		// Show an advisory when the DSN (which contains an API key) is stored in
		// the database rather than defined as a wp-config.php constant.
		$show_dsn_advisory = ! $dsn_via_constant && ! empty( $dsn );
		?>
		<div class="wrap devpulse-settings-wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html( get_admin_page_title() ); ?>
			</h1>
			<hr class="wp-header-end" />

			<?php if ( $show_dsn_advisory ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e(
							'Your DSN is stored in the database. For better security, define DEVPULSE_DSN in wp-config.php — constants are not exposed to plugin code or visible in database exports.',
							'devpulse'
						); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" class="devpulse-settings-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row">
								<label for="devpulse_enabled">
									<?php esc_html_e( 'Enable Error Tracking', 'devpulse' ); ?>
								</label>
							</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text">
										<?php esc_html_e( 'Enable Error Tracking', 'devpulse' ); ?>
									</legend>
									<label for="devpulse_enabled">
										<input
											type="checkbox"
											id="devpulse_enabled"
											name="devpulse_enabled"
											value="1"
											<?php checked( $enabled, 1 ); ?> />
										<?php esc_html_e( 'Enable error and performance monitoring', 'devpulse' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="devpulse_dsn">
									<?php esc_html_e( 'DSN (Data Source Name)', 'devpulse' ); ?>
								</label>
							</th>
							<td>
								<?php if ( $dsn_via_constant ) : ?>
									<code><?php echo esc_html( DEVPULSE_DSN ); ?></code>
									<p class="description">
										<?php esc_html_e( 'Set via DEVPULSE_DSN constant — cannot be changed here.', 'devpulse' ); ?>
									</p>
								<?php else : ?>
									<input
										type="url"
										id="devpulse_dsn"
										name="devpulse_dsn"
										value="<?php echo esc_attr( $dsn ); ?>"
										class="regular-text"
										placeholder="https://devpulse.example.com/api/ingest/your-api-key" />
									<p class="description">
										<?php esc_html_e( 'Your DevPulse project ingest URL.', 'devpulse' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="devpulse_env">
									<?php esc_html_e( 'Environment', 'devpulse' ); ?>
								</label>
							</th>
							<td>
								<select id="devpulse_env" name="devpulse_env" class="regular-text">
									<?php foreach ( [ 'production', 'staging', 'development', 'local' ] as $e_val ) : ?>
										<option value="<?php echo esc_attr( $e_val ); ?>" <?php selected( $env, $e_val ); ?>>
											<?php echo esc_html( ucfirst( $e_val ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'The environment this site is running in.', 'devpulse' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="devpulse_sample_rate">
									<?php esc_html_e( 'Sample Rate', 'devpulse' ); ?>
								</label>
							</th>
							<td>
								<input
									type="number"
									id="devpulse_sample_rate"
									name="devpulse_sample_rate"
									value="<?php echo esc_attr( $sample_rate ); ?>"
									min="0"
									max="1"
									step="0.01"
									class="small-text" />
								<p class="description">
									<?php esc_html_e( 'Fraction of events to send (0.0 = none, 1.0 = all). Lower this on high-traffic sites to reduce ingest volume.', 'devpulse' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="devpulse_release">
									<?php esc_html_e( 'Release', 'devpulse' ); ?>
								</label>
							</th>
							<td>
								<input
									type="text"
									id="devpulse_release"
									name="devpulse_release"
									value="<?php echo esc_attr( $release ); ?>"
									class="regular-text"
									placeholder="1.2.3 or git SHA" />
								<p class="description">
									<?php esc_html_e( 'Optional version or deploy identifier attached to every event. Override with DEVPULSE_RELEASE in wp-config.php.', 'devpulse' ); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button( __( 'Save Changes', 'devpulse' ) ); ?>
			</form>

			<?php if ( $enabled && ( ! empty( $dsn ) || $dsn_via_constant ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Connection Test', 'devpulse' ); ?></h2>
				<p><?php esc_html_e( 'Send a test event to verify your setup is working correctly.', 'devpulse' ); ?></p>
				<p>
					<button type="button" id="devpulse-test" class="button button-secondary">
						<?php esc_html_e( 'Send Test Event', 'devpulse' ); ?>
					</button>
					<span id="devpulse-result" class="devpulse-result" aria-live="polite"></span>
				</p>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Repair Database', 'devpulse' ); ?></h2>
			<p><?php esc_html_e( 'Restore missing options to defaults, validate stored values, and flush stale transients.', 'devpulse' ); ?></p>
			<p>
				<button type="button" id="devpulse-repair" class="button button-secondary">
					<?php esc_html_e( 'Repair Database', 'devpulse' ); ?>
				</button>
				<span id="devpulse-repair-result" class="devpulse-result" aria-live="polite"></span>
			</p>
			<div id="devpulse-repair-details" style="display:none; margin-top:10px;">
				<ul id="devpulse-repair-list" class="ul-disc" style="margin-left:2em;"></ul>
			</div>

			<hr />
			<h2><?php esc_html_e( 'Documentation', 'devpulse' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: link to documentation */
					esc_html__( 'For full setup instructions, %s.', 'devpulse' ),
					'<a href="https://github.com/SekolahCode/devpulse-wp" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'read the documentation', 'devpulse' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── AJAX Handlers ─────────────────────────────────────────────────────

	/**
	 * AJAX: Send a test event to verify the DSN is reachable.
	 *
	 * Registered unconditionally so it works even before the plugin is enabled
	 * (first-time setup flow). If no running handler exists, a temporary one is
	 * created just for this request.
	 *
	 * @since 1.0.0
	 */
	public function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'devpulse' ), 403 );
			return;
		}

		if ( ! check_ajax_referer( 'devpulse_test', 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid nonce.', 'devpulse' ), 403 );
			return;
		}

		$dsn = defined( 'DEVPULSE_DSN' ) ? DEVPULSE_DSN : get_option( 'devpulse_dsn', '' );
		$env = defined( 'DEVPULSE_ENV' ) ? DEVPULSE_ENV : get_option( 'devpulse_env', 'production' );

		if ( empty( $dsn ) ) {
			wp_send_json_error( __( 'DSN is not configured.', 'devpulse' ) );
			return;
		}

		// Reuse the running handler; if the plugin is currently disabled,
		// spin up a temporary handler just for this test request.
		$handler = $this->handler ?? new Handler( $dsn, $env );
		$sent    = $handler->send_test();

		if ( $sent ) {
			wp_send_json_success( __( 'Event sent!', 'devpulse' ) );
		} else {
			wp_send_json_error( __( 'Could not connect to DevPulse server — check DSN and network.', 'devpulse' ) );
		}
	}

	/**
	 * AJAX: Repair database options.
	 *
	 * Restores missing options to defaults, validates stored values,
	 * and flushes stale transients. Handles multisite.
	 *
	 * @since 1.0.0
	 */
	public function repair_db(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'devpulse' ), 403 );
			return;
		}

		if ( ! check_ajax_referer( 'devpulse_repair', 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid nonce.', 'devpulse' ), 403 );
			return;
		}

		$repairs  = [];
		$defaults = [
			'devpulse_dsn'         => '',
			'devpulse_env'         => 'production',
			'devpulse_enabled'     => 0,
			'devpulse_sample_rate' => 1.0,
			'devpulse_release'     => '',
		];

		// 1. Restore any missing options.
		foreach ( $defaults as $option => $default ) {
			if ( get_option( $option ) === false ) {
				add_option( $option, $default );
				/* translators: %s: option name */
				$repairs[] = sprintf( __( 'Restored missing option: %s', 'devpulse' ), $option );
			}
		}

		// 2. Validate DSN — must be a valid URL or empty.
		$dsn = get_option( 'devpulse_dsn', '' );
		if ( ! empty( $dsn ) && ! filter_var( $dsn, FILTER_VALIDATE_URL ) ) {
			update_option( 'devpulse_dsn', '' );
			$repairs[] = __( 'Cleared invalid DSN value.', 'devpulse' );
		}

		// 3. Validate environment — fall back to production if unrecognised.
		$env     = get_option( 'devpulse_env', 'production' );
		$allowed = [ 'production', 'staging', 'development', 'local', 'test' ];
		if ( ! in_array( $env, $allowed, true ) && ! preg_match( '/^[a-z0-9_\-]+$/', $env ) ) {
			update_option( 'devpulse_env', 'production' );
			$repairs[] = __( 'Reset invalid environment to "production".', 'devpulse' );
		}

		// 4. Validate sample rate — must be a number between 0.0 and 1.0.
		$rate = get_option( 'devpulse_sample_rate', 1.0 );
		if ( ! is_numeric( $rate ) || (float) $rate < 0.0 || (float) $rate > 1.0 ) {
			update_option( 'devpulse_sample_rate', 1.0 );
			$repairs[] = __( 'Reset invalid sample rate to 1.0.', 'devpulse' );
		}

		// 5. Flush stale transients.
		delete_transient( 'devpulse_activated' );

		// 6. Multisite — repair each sub-site.
		if ( is_multisite() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- repair tool, one-time admin action.
			$blog_ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT blog_id FROM %i', $wpdb->blogs )
			);

			foreach ( $blog_ids as $blog_id ) {
				switch_to_blog( (int) $blog_id );

				foreach ( $defaults as $option => $default ) {
					if ( get_option( $option ) === false ) {
						add_option( $option, $default );
						$repairs[] = sprintf(
							/* translators: 1: option name 2: site ID */
							__( 'Restored missing option %1$s on site %2$d.', 'devpulse' ),
							$option,
							(int) $blog_id
						);
					}
				}

				delete_transient( 'devpulse_activated' );
				restore_current_blog();
			}
		}

		$message = empty( $repairs )
			? __( 'Database is healthy — no repairs needed.', 'devpulse' )
			: __( 'Repair completed successfully.', 'devpulse' );

		wp_send_json_success( [
			'message' => $message,
			'repairs' => $repairs,
		] );
	}

	// ── Activation Redirect ───────────────────────────────────────────────

	/**
	 * Redirect to the settings page after first activation.
	 *
	 * @since 1.0.0
	 */
	public function activation_redirect(): void {
		if ( ! get_transient( 'devpulse_activated' ) ) {
			return;
		}

		delete_transient( 'devpulse_activated' );

		// Don't redirect if already on the settings page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'devpulse' ) {
			return;
		}

		/**
		 * Action: Fires after the plugin is activated and before the redirect.
		 *
		 * @since 1.0.0
		 */
		do_action( 'devpulse_after_activate' );

		if ( current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=devpulse' ) );
			exit;
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function get_admin_styles(): string {
		return '
			.devpulse-result                  { margin-left: 10px; font-weight: 500; }
			.devpulse-result.devpulse-success { color: #46b450; }
			.devpulse-result.devpulse-error   { color: #dc3232; }
			.devpulse-result.devpulse-loading { color: #82878c; }
			#devpulse-test[disabled]          { opacity: 0.6; cursor: not-allowed; }
		';
	}
}
