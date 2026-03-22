<?php

/**
 * DevPulse Error Handler
 *
 * @package DevPulseWP
 * @since   1.0.0
 */

namespace DevPulseWP;

defined( 'ABSPATH' ) || exit;

/**
 * Captures PHP exceptions, errors and WordPress-specific failures,
 * then ships them to the DevPulse ingest endpoint.
 *
 * @since 1.0.0
 */
class Handler {

	/** @var string Ingest DSN URL. */
	private string $dsn;

	/** @var string Environment name. */
	private string $env;

	/** @var string|null Release identifier (semver, git SHA, etc.). */
	private ?string $release;

	/** @var bool Recursive-send guard. */
	private bool $sending = false;

	/**
	 * Per-request context cache.
	 *
	 * PHP resets all state between HTTP requests — this only de-duplicates
	 * repeated build_context() calls within a single request (e.g., when
	 * multiple errors fire on the same page load). It is NOT a cross-request cache.
	 *
	 * @var array|null
	 */
	private ?array $context_cache = null;

	/** @var int Number of times context_cache has been served in this request. */
	private int $context_cache_hits = 0;

	/** @var int Rebuild context after this many accesses within one request. */
	private const CONTEXT_CACHE_MAX_HITS = 20;

	/** @var int[] PHP error levels to ignore. */
	private array $ignored_levels;

	/** @var string[] FQCN exception classes to skip. */
	private array $ignored_classes;

	/** @var float Event sample rate (0.0 = drop all, 1.0 = send all). */
	private float $sample_rate;

	/** @var int Rate-limit window in seconds (suppress duplicate errors within this window). */
	private const RATE_LIMIT_TTL = 300;

	/**
	 * @param string      $dsn     Ingest URL.
	 * @param string      $env     Environment name.
	 * @param string|null $release Release identifier.
	 */
	public function __construct( string $dsn, string $env = 'production', ?string $release = null ) {
		$this->dsn     = $dsn;
		$this->env     = $env;
		$this->release = $release;

		/**
		 * Filter: PHP error levels that DevPulse should ignore.
		 *
		 * By default, notices, strict notices and deprecation warnings are suppressed
		 * to reduce noise. Pass an empty array to capture everything.
		 *
		 * @since 1.0.0
		 * @param int[] $levels Array of E_* constants.
		 */
		$this->ignored_levels = (array) apply_filters( 'devpulse_ignored_error_levels', [
			E_NOTICE,
			E_STRICT,
			E_DEPRECATED,
			E_USER_DEPRECATED,
		] );

		/**
		 * Filter: Fully-qualified exception class names to suppress.
		 *
		 * Example:
		 *   add_filter( 'devpulse_ignored_exceptions', fn( $c ) =>
		 *     array_merge( $c, [ \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class ] )
		 *   );
		 *
		 * @since 1.0.0
		 * @param string[] $classes
		 */
		$this->ignored_classes = (array) apply_filters( 'devpulse_ignored_exceptions', [] );

		/**
		 * Filter: Fraction of events to send (0.0–1.0).
		 *
		 * Events are dropped silently when the random roll exceeds this value.
		 * Useful on high-traffic sites to reduce ingest volume.
		 *
		 * @since 1.0.0
		 * @param float $rate
		 */
		$this->sample_rate = (float) apply_filters(
			'devpulse_sample_rate',
			(float) get_option( 'devpulse_sample_rate', 1.0 )
		);
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────

	/**
	 * Register all error handlers and WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	public function boot(): void {
		/**
		 * Filter: Allow disabling DevPulse at runtime without changing options.
		 *
		 * @since 1.0.0
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'devpulse_enabled', true ) ) {
			return;
		}

		set_exception_handler( [ $this, 'capture_exception' ] );
		set_error_handler( [ $this, 'capture_error' ] );
		register_shutdown_function( [ $this, 'capture_shutdown' ] );

		add_filter( 'wp_die_handler', [ $this, 'wp_die_handler' ] );
		add_action( 'shutdown',       [ $this, 'capture_db_errors' ] );

		/**
		 * Action: Fires after DevPulse handler is initialised.
		 *
		 * @since 1.0.0
		 */
		do_action( 'devpulse_loaded' );
	}

	// ── Public API ────────────────────────────────────────────────────────

	/** @since 1.0.0 */
	public function get_dsn(): string {
		return $this->dsn;
	}

	/** @since 1.0.0 */
	public function get_env(): string {
		return $this->env;
	}

	/**
	 * Manually capture a free-form message.
	 *
	 * @since 1.0.0
	 * @param string $message
	 * @param string $level  One of: debug, info, warning, error.
	 * @return bool True on success or on intentional drop; false on send failure.
	 */
	public function capture_message( string $message, string $level = 'info' ): bool {
		$payload = [
			'level'     => $level,
			'message'   => $message,
			'context'   => $this->build_context(),
			'request'   => $this->build_request(),
			'timestamp' => gmdate( 'c' ),
		];

		if ( $this->release !== null ) {
			$payload['release'] = $this->release;
		}

		return $this->send( $payload );
	}

	// ── Exception Handler ─────────────────────────────────────────────────

	/**
	 * Handle uncaught exceptions.
	 *
	 * @since 1.0.0
	 * @param \Throwable $e
	 */
	public function capture_exception( \Throwable $e ): void {
		/**
		 * Filter: Allow suppressing individual exceptions before capture.
		 *
		 * @since 1.0.0
		 * @param bool       $capture Whether to capture.
		 * @param \Throwable $e       The exception.
		 */
		if ( ! apply_filters( 'devpulse_capture_exception', true, $e ) ) {
			return;
		}

		if ( $this->is_class_ignored( $e ) ) {
			return;
		}

		$this->send( $this->build_from_exception( $e ) );
	}

	// ── PHP Error Handler ─────────────────────────────────────────────────

	/**
	 * Handle PHP errors.
	 *
	 * Returning false lets PHP continue its default error handling for the same error.
	 *
	 * @since 1.0.0
	 * @param int    $severity
	 * @param string $message
	 * @param string $file
	 * @param int    $line
	 * @return bool
	 */
	public function capture_error( int $severity, string $message, string $file, int $line ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}

		if ( in_array( $severity, $this->ignored_levels, true ) ) {
			return false;
		}

		$this->capture_exception( new \ErrorException( $message, 0, $severity, $file, $line ) );

		return false;
	}

	// ── Fatal Error Handler ───────────────────────────────────────────────

	/**
	 * Capture fatal errors that PHP cannot throw as exceptions.
	 *
	 * @since 1.0.0
	 */
	public function capture_shutdown(): void {
		$error = error_get_last();

		if ( ! $error ) {
			return;
		}

		$fatals = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ];

		if ( ! in_array( $error['type'], $fatals, true ) ) {
			return;
		}

		$type_names = [
			E_ERROR         => 'E_ERROR',
			E_PARSE         => 'E_PARSE',
			E_CORE_ERROR    => 'E_CORE_ERROR',
			E_COMPILE_ERROR => 'E_COMPILE_ERROR',
		];

		$payload               = $this->build_from_exception(
			new \ErrorException( $error['message'], 0, $error['type'], $error['file'], $error['line'] )
		);
		$payload['is_fatal']   = true;
		$payload['error_type'] = $type_names[ $error['type'] ] ?? 'UNKNOWN';

		$this->send( $payload );
	}

	// ── wp_die() Handler ──────────────────────────────────────────────────

	/**
	 * Return a wp_die handler that captures meaningful die calls.
	 *
	 * Skips benign AJAX termination patterns (empty string, '0', integer) which
	 * are normal WordPress behaviour, not errors.
	 *
	 * @since 1.0.0
	 * @return callable
	 */
	public function wp_die_handler(): callable {
		return function ( $message, $title = '', $args = [] ) {
			// Skip common AJAX termination patterns — these are expected, not errors.
			$is_benign = empty( $message ) || $message === '0' || is_int( $message );

			/**
			 * Filter: Skip additional wp_die calls that should not be reported.
			 *
			 * Return true to suppress capture and pass through to the default handler.
			 *
			 * @since 1.0.0
			 * @param bool  $skip    True to skip capture.
			 * @param mixed $message wp_die message.
			 * @param mixed $title   wp_die title.
			 */
			if ( $is_benign || apply_filters( 'devpulse_skip_wp_die', false, $message, $title ) ) {
				_default_wp_die_handler( $message, $title, $args );
				return;
			}

			$msg = is_wp_error( $message )
				? $message->get_error_message()
				: ( is_string( $message ) ? $message : 'wp_die called' );

			$this->send( [
				'level'   => 'error',
				'message' => 'wp_die: ' . $msg,
				'context' => array_merge( $this->build_context(), [
					'wp_die_title' => is_string( $title ) ? $title : '',
				] ),
			] );

			_default_wp_die_handler( $message, $title, $args );
		};
	}

	// ── $wpdb Error Capture ───────────────────────────────────────────────

	/**
	 * Capture the last $wpdb error at shutdown.
	 *
	 * @since 1.0.0
	 */
	public function capture_db_errors(): void {
		global $wpdb;

		if ( empty( $wpdb->last_error ) ) {
			return;
		}

		$context             = $this->build_context();
		$context['db_error'] = $wpdb->last_error;

		/**
		 * Filter: Enable DB query logging alongside the error.
		 *
		 * Disabled by default — queries can contain plaintext secrets (passwords,
		 * tokens, etc. in INSERT/UPDATE statements). Only enable after confirming
		 * your queries never include sensitive data.
		 *
		 * @since 1.0.0
		 * @param bool $log_query
		 */
		if ( apply_filters( 'devpulse_log_db_query', false ) ) {
			$context['last_query'] = wp_unslash( $wpdb->last_query );
		}

		$this->send( [
			'level'   => 'error',
			'message' => 'WordPress DB Error: ' . $wpdb->last_error,
			'context' => $context,
		] );
	}

	// ── Payload Builder ───────────────────────────────────────────────────

	private function build_from_exception( \Throwable $e ): array {
		$payload = [
			'level'     => 'error',
			'exception' => [
				'type'       => get_class( $e ),
				'message'    => $e->getMessage(),
				'stacktrace' => $this->build_stacktrace( $e ),
			],
			'context'   => $this->build_context(),
			'request'   => $this->build_request(),
			'timestamp' => gmdate( 'c' ),
		];

		if ( $this->release !== null ) {
			$payload['release'] = $this->release;
		}

		return $payload;
	}

	private function build_stacktrace( \Throwable $e ): array {
		$frames = [ [
			'file'     => $e->getFile(),
			'line'     => $e->getLine(),
			'function' => null,
		] ];

		foreach ( $e->getTrace() as $frame ) {
			$frames[] = [
				'file'     => $frame['file'] ?? null,
				'line'     => $frame['line'] ?? null,
				'function' => isset( $frame['class'] )
					? "{$frame['class']}{$frame['type']}{$frame['function']}"
					: ( $frame['function'] ?? null ),
			];
		}

		return $frames;
	}

	private function build_context(): array {
		// Per-request cache: avoids repeated WP API calls when multiple errors
		// fire in the same page load. Invalidated after CONTEXT_CACHE_MAX_HITS
		// accesses so extremely long-running requests eventually refresh the data.
		if ( $this->context_cache !== null && $this->context_cache_hits < self::CONTEXT_CACHE_MAX_HITS ) {
			++$this->context_cache_hits;
			return $this->context_cache;
		}

		$active_plugins = get_option( 'active_plugins', [] );
		$plugin_count   = count( $active_plugins );

		if ( $plugin_count > 20 ) {
			$active_plugins   = array_slice( $active_plugins, 0, 20 );
			$active_plugins[] = '... truncated';
		}

		$context = [
			'php'            => PHP_VERSION,
			'platform'       => 'wordpress',
			'wordpress'      => get_bloginfo( 'version' ),
			'active_plugins' => $active_plugins,
			'plugin_count'   => $plugin_count,
			'theme'          => get_template(),
			'theme_parent'   => get_stylesheet(),
			'environment'    => $this->env,
			'memory'         => memory_get_peak_usage( true ),
			'is_admin'       => is_admin(),
			'multisite'      => is_multisite(),
			'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'   => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
		];

		// Attach the authenticated user — only when logged in.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			/**
			 * Filter: Customise the user context payload.
			 *
			 * Return an empty array to suppress user data entirely (e.g., GDPR).
			 *
			 * @since 1.0.0
			 * @param array    $user_context Default user fields.
			 * @param \WP_User $user         Current user object.
			 */
			$user_context = apply_filters( 'devpulse_user_context', [
				'id'    => $user->ID,
				'email' => $user->user_email,
				'login' => $user->user_login,
				'roles' => $user->roles,
			], $user );

			if ( ! empty( $user_context ) ) {
				$context['user'] = $user_context;
			}
		}

		$this->context_cache      = $context;
		$this->context_cache_hits = 0;

		return $context;
	}

	private function build_request(): ?array {
		if ( PHP_SAPI === 'cli' ) {
			return null;
		}

		$uri    = isset( $_SERVER['REQUEST_URI'] )
			? filter_var( wp_unslash( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL )
			: '/';
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: 'GET';

		return [
			'url'    => home_url( $uri ),
			'method' => $method,
			'ip'     => $this->resolve_client_ip(),
		];
	}

	/**
	 * Resolve the real client IP, accounting for reverse proxies and CDNs.
	 *
	 * Checks X-Forwarded-For → X-Real-IP → REMOTE_ADDR in order.
	 * Disable proxy header trust on direct-to-internet servers with:
	 *   add_filter( 'devpulse_trust_proxy_headers', '__return_false' );
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	private function resolve_client_ip(): ?string {
		/**
		 * Filter: Whether to read X-Forwarded-For / X-Real-IP headers.
		 *
		 * Disable on servers where proxy headers are not sanitised upstream.
		 *
		 * @since 1.0.0
		 * @param bool $trust
		 */
		if ( apply_filters( 'devpulse_trust_proxy_headers', true ) ) {
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// XFF may be a comma-separated list; the first entry is the original client.
				$ip = trim( explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return sanitize_text_field( $ip );
				}
			}

			if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: null;
	}

	// ── HTTP Transport ────────────────────────────────────────────────────

	/**
	 * Encode and ship a payload to the ingest endpoint.
	 *
	 * Applies sample rate and per-fingerprint rate limiting before sending.
	 *
	 * @since 1.0.0
	 * @param array $payload
	 * @return bool True on success or on intentional drop; false on send failure.
	 */
	private function send( array $payload ): bool {
		if ( $this->sending ) {
			return false;
		}

		// Sample rate: silently drop a configured fraction of events.
		if ( $this->sample_rate < 1.0 && ( mt_rand() / mt_getrandmax() ) > $this->sample_rate ) {
			return true;
		}

		// Rate limiting: suppress duplicate reports of the same error within the TTL.
		$rate_key = 'devpulse_rl_' . $this->fingerprint( $payload );
		if ( get_transient( $rate_key ) ) {
			return true;
		}
		set_transient( $rate_key, 1, self::RATE_LIMIT_TTL );

		$this->sending = true;

		try {
			$json = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( $json === false ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'DevPulse: json_encode failed — ' . json_last_error_msg() );
				return false;
			}

			// Prefer the WordPress HTTP API (non-blocking: fire-and-forget).
			if ( function_exists( 'wp_remote_post' ) ) {
				$response = wp_remote_post( $this->dsn, [
					'timeout'     => 2,
					'blocking'    => false,
					'headers'     => [ 'Content-Type' => 'application/json' ],
					'body'        => $json,
					'data_format' => 'body',
				] );

				if ( is_wp_error( $response ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'DevPulse: ' . $response->get_error_message() );
					return false;
				}

				return true;
			}

			// Fallback for shutdown / early-boot contexts (synchronous stream request).
			if ( ! ini_get( 'allow_url_fopen' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'DevPulse: allow_url_fopen is disabled; cannot send via fallback transport.' );
				return false;
			}

			$context      = stream_context_create( [
				'http' => [
					'method'        => 'POST',
					'header'        => "Content-Type: application/json\r\n",
					'content'       => $json,
					'timeout'       => 2,
					'ignore_errors' => true,
				],
			] );
			$stream_error = null;

			set_error_handler( static function ( int $errno, string $errstr ) use ( &$stream_error ): bool {
				$stream_error = $errstr;
				return true;
			} );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$result = file_get_contents( $this->dsn, false, $context );
			restore_error_handler();

			if ( $stream_error !== null ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'DevPulse: stream error — ' . $stream_error );
			}

			return $result !== false;

		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'DevPulse send() failed: ' . $e->getMessage() );
			return false;
		} finally {
			$this->sending = false;
		}
	}

	/**
	 * Compute a short fingerprint for rate-limit transient keys.
	 *
	 * @param array $payload
	 * @return string 16-character hex string.
	 */
	private function fingerprint( array $payload ): string {
		if ( isset( $payload['exception'] ) ) {
			$key = ( $payload['exception']['type'] ?? '' ) . ':' . ( $payload['exception']['message'] ?? '' );
		} else {
			$key = $payload['message'] ?? serialize( $payload );
		}

		return substr( md5( $key ), 0, 16 );
	}

	/**
	 * Check whether an exception's class is on the ignore list.
	 *
	 * @param \Throwable $e
	 * @return bool
	 */
	private function is_class_ignored( \Throwable $e ): bool {
		foreach ( $this->ignored_classes as $class ) {
			if ( $e instanceof $class ) {
				return true;
			}
		}

		return false;
	}
}
