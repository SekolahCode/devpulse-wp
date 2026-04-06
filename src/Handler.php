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

	/** @var string Original DSN as passed by the caller (used for browser JS init). */
	private string $full_dsn;

	/** @var string Ingest endpoint URL (DSN without the trailing API key segment). */
	private string $dsn;

	/** @var string API key extracted from DSN, sent as X-API-Key header. */
	private string $api_key;

	/** @var string Environment name. */
	private string $env;

	/** @var string|null Release identifier (semver, git SHA, etc.). */
	private ?string $release;

	/** @var bool Whether to inject the JS bundle for frontend vitals. */
	private bool $track_vitals;

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
	 * @param string      $dsn          Ingest URL.
	 * @param string      $env          Environment name.
	 * @param string|null $release      Release identifier.
	 * @param bool        $track_vitals Whether to inject the browser vitals JS bundle.
	 */
	public function __construct( string $dsn, string $env = 'production', ?string $release = null, bool $track_vitals = true ) {
		// Store the original DSN for browser JS init (browser SDK parses it itself).
		$this->full_dsn = $dsn;
		// Extract the API key from the DSN path so it is sent as X-API-Key header
		// rather than embedded in the URL (prevents leakage in server/CDN logs).
		$parts          = explode( '/', rtrim( $dsn, '/' ) );
		$this->api_key  = array_pop( $parts );
		$this->dsn      = implode( '/', $parts );
		$this->env          = $env;
		$this->release      = $release;
		$this->track_vitals = $track_vitals;

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
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- intentional: error tracking plugin.
		set_error_handler( [ $this, 'capture_error' ] );
		register_shutdown_function( [ $this, 'capture_shutdown' ] );

		add_filter( 'wp_die_handler', [ $this, 'wp_die_handler' ] );
		add_action( 'shutdown',       [ $this, 'capture_db_errors' ] );

		if ( $this->track_vitals ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_vitals_script' ] );
		}

		/**
		 * Action: Fires after DevPulse handler is initialised.
		 *
		 * @since 1.0.0
		 */
		do_action( 'devpulse_loaded' );
	}

	// ── Frontend Vitals ───────────────────────────────────────────────────

	/**
	 * Enqueue the DevPulse browser bundle and auto-initialise it.
	 *
	 * Injects the UMD bundle on all public-facing pages and calls
	 * DevPulse.default.init() with the site's DSN, environment, and release
	 * so LCP, INP, CLS, TTFB and page_load are collected automatically —
	 * exactly the same metrics as Lighthouse / Core Web Vitals.
	 *
	 * @since 1.2.0
	 */
	public function enqueue_vitals_script(): void {
		/**
		 * Filter: Disable the browser vitals bundle on specific pages.
		 *
		 * Return false to skip enqueueing (e.g., logged-in admins, maintenance mode).
		 *
		 * @since 1.2.0
		 * @param bool $enqueue
		 */
		if ( ! apply_filters( 'devpulse_enqueue_vitals', true ) ) {
			return;
		}

		$script_path    = 'assets/devpulse.umd.js';
		$script_abs     = DEVPULSE_DIR . $script_path;
		$script_version = file_exists( $script_abs )
			? (string) filemtime( $script_abs )
			: DEVPULSE_VERSION;

		wp_register_script(
			'devpulse-vitals',
			plugins_url( $script_path, DEVPULSE_FILE ),
			[],
			$script_version,
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);

		/**
		 * Filter: Override vitals tracking options passed to DevPulse.init().
		 *
		 * @since 1.2.0
		 * @param array $options
		 */
		$options = apply_filters( 'devpulse_vitals_options', [
			'dsn'         => $this->full_dsn, // browser SDK needs the full DSN (including key segment)
			'environment' => $this->env,
			'release'     => $this->release,
			'trackVitals' => true,
		] );

		// wp_json_encode already escapes for JS string context.
		$init_js = sprintf(
			'(function(w){var dp=w.DevPulse;if(dp&&(dp.default||dp.DevPulse)){(dp.default||dp.DevPulse).init(%s);}})(window);',
			wp_json_encode( $options )
		);

		wp_add_inline_script( 'devpulse-vitals', $init_js, 'after' );
		wp_enqueue_script( 'devpulse-vitals' );
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
	 * Send a blocking test event and return whether the server accepted it.
	 *
	 * Bypasses rate limiting and uses a blocking HTTP request so the caller can
	 * confirm the server actually received and accepted the payload (2xx response).
	 * Use only from the admin connection-test action — never from error handlers.
	 *
	 * @since 1.0.0
	 * @return bool True if the server responded with 2xx, false otherwise.
	 */
	public function send_test(): bool {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return false;
		}

		$payload = [
			'level'     => 'info',
			'message'   => 'DevPulse WordPress connection test',
			'context'   => $this->build_context(),
			'request'   => $this->build_request(),
			'timestamp' => gmdate( 'c' ),
		];

		if ( $this->release !== null ) {
			$payload['release'] = $this->release;
		}

		$json = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( $json === false ) {
			return false;
		}

		$response = wp_remote_post( $this->dsn, [
			'timeout'     => 5,    // longer timeout: user is waiting for feedback
			'blocking'    => true, // must be blocking so we can read the status code
			'headers'     => [ 'Content-Type' => 'application/json', 'X-API-Key' => $this->api_key ],
			'body'        => $json,
			'data_format' => 'body',
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
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

		$payload                = $this->build_from_exception(
			new \ErrorException( $error['message'], 0, $error['type'], $error['file'], $error['line'] )
		);
		$payload['is_fatal']    = true;
		$payload['error_type']  = $type_names[ $error['type'] ];

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
			$msg_str = is_string( $message ) ? $message : '';
			$is_benign = empty( $message ) || $msg_str === '0' || is_int( $message );

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
		if ( apply_filters( 'devpulse_log_db_query', false ) && isset( $wpdb->last_query ) ) {
			$context['last_query'] = wp_unslash( (string) $wpdb->last_query );
		}

		$this->send( [
			'level'   => 'error',
			'message' => 'WordPress DB Error: ' . $wpdb->last_error,
			'context' => $context,
		] );
	}

	// ── Payload Builder ───────────────────────────────────────────────────

	private function build_from_exception( \Throwable $e ): array {
		$stacktrace = $this->build_stacktrace( $e );

		$payload = [
			'level'       => 'error',
			'exception'   => [
				'type'       => get_class( $e ),
				'message'    => $e->getMessage(),
				'stacktrace' => $stacktrace,
			],
			'context'     => $this->build_context(),
			'request'     => $this->build_request(),
			'sdk_version' => 'devpulse-wordpress/2.0.0',
			'timestamp'   => gmdate( 'c' ),
		];

		// Attribute the error to the first plugin/theme-owned frame in the trace.
		foreach ( $stacktrace as $frame ) {
			$p = $frame['plugin'] ?? null;
			if ( $p && in_array( $p['type'], [ 'plugin', 'mu-plugin', 'theme' ], true ) ) {
				$payload['plugin'] = $p;
				break;
			}
		}

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
			'context'  => $this->read_source_context( $e->getFile(), $e->getLine() ),
			'plugin'   => $this->identify_plugin( $e->getFile() ),
		] ];

		foreach ( $e->getTrace() as $frame ) {
			$file = $frame['file'] ?? null;
			$line = $frame['line'] ?? null;
			$frames[] = [
				'file'     => $file,
				'line'     => $line,
				'function' => isset( $frame['class'] )
					? "{$frame['class']}{$frame['type']}{$frame['function']}"
					: ( $frame['function'] ),
				'context'  => $this->read_source_context( $file, $line ),
				'plugin'   => $this->identify_plugin( $file ),
			];
		}

		return $frames;
	}

	/**
	 * Read source code lines around the error location.
	 *
	 * @param string|null $file   Absolute path to the PHP file.
	 * @param int|null    $line   Line number where the error occurred.
	 * @param int         $radius Lines of context to capture above and below.
	 * @return array{start: int, lines: array<int, string>}|null
	 */
	private function read_source_context( ?string $file, ?int $line, int $radius = 5 ): ?array {
		if ( ! $file || ! $line || ! is_readable( $file ) ) {
			return null;
		}

		try {
			$spl   = new \SplFileObject( $file );
			$start = max( 0, $line - $radius - 1 );
			$spl->seek( $start );

			$slice = [];
			for ( $i = $start; $i < $line + $radius && ! $spl->eof(); $i++ ) {
				$raw           = $spl->current();
				$slice[ $i + 1 ] = is_string( $raw ) ? rtrim( $raw ) : '';
				$spl->next();
			}
		} catch ( \RuntimeException $ex ) {
			return null;
		}

		return $slice !== [] ? [ 'start' => $start + 1, 'lines' => $slice ] : null;
	}

	/**
	 * Identify which WordPress plugin, theme, or core file owns a given path.
	 *
	 * @param string|null $file Absolute file path.
	 * @return array{type: string, name?: string}|null
	 */
	private function identify_plugin( ?string $file ): ?array {
		if ( ! $file || ! defined( 'WP_CONTENT_DIR' ) ) {
			return null;
		}

		$file    = str_replace( '\\', '/', $file );
		$content = str_replace( '\\', '/', WP_CONTENT_DIR );

		// Regular plugins
		if ( strpos( $file, $content . '/plugins/' ) === 0 ) {
			$relative = substr( $file, strlen( $content . '/plugins/' ) );
			return [ 'type' => 'plugin', 'name' => explode( '/', $relative )[0] ];
		}

		// Must-use plugins
		if ( strpos( $file, $content . '/mu-plugins/' ) === 0 ) {
			$relative = substr( $file, strlen( $content . '/mu-plugins/' ) );
			return [ 'type' => 'mu-plugin', 'name' => explode( '/', $relative )[0] ];
		}

		// Themes
		if ( strpos( $file, $content . '/themes/' ) === 0 ) {
			$relative = substr( $file, strlen( $content . '/themes/' ) );
			return [ 'type' => 'theme', 'name' => explode( '/', $relative )[0] ];
		}

		// WordPress core
		if ( defined( 'ABSPATH' ) ) {
			$root = str_replace( '\\', '/', rtrim( ABSPATH, '/' ) );
			if ( strpos( $file, $root . '/wp-includes/' ) === 0
				|| strpos( $file, $root . '/wp-admin/' ) === 0 ) {
				return [ 'type' => 'core' ];
			}
		}

		return null;
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
	 * Reads X-Forwarded-For only when REMOTE_ADDR is a known trusted proxy.
	 * This prevents IP spoofing — an attacker cannot fake their IP by injecting
	 * an X-Forwarded-For header unless the request actually arrives via a proxy
	 * whose IP you trust.
	 *
	 * Configure trusted proxy IPs/CIDRs with:
	 *   add_filter( 'devpulse_trusted_proxies', fn() => [ '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16' ] );
	 *
	 * Disable proxy header trust entirely on direct-to-internet servers with:
	 *   add_filter( 'devpulse_trust_proxy_headers', '__return_false' );
	 *
	 * @since 2.0.0
	 * @return string|null
	 */
	private function resolve_client_ip(): ?string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: null;

		/**
		 * Filter: Whether to read X-Forwarded-For / X-Real-IP headers.
		 *
		 * @since 1.0.0
		 * @param bool $trust
		 */
		if ( ! apply_filters( 'devpulse_trust_proxy_headers', true ) ) {
			return $remote_addr;
		}

		/**
		 * Filter: List of trusted proxy IP addresses or CIDR ranges.
		 *
		 * X-Forwarded-For is only trusted when REMOTE_ADDR matches one of these.
		 * Defaults to RFC-1918 private ranges (suitable for most WordPress hosting
		 * behind a load balancer on a private network). Override with your actual
		 * proxy IPs for stricter control.
		 *
		 * @since 2.0.0
		 * @param string[] $proxies IP addresses or CIDR notation ranges.
		 */
		$trusted_proxies = apply_filters( 'devpulse_trusted_proxies', [
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'127.0.0.1',
			'::1',
		] );

		if ( $remote_addr && $this->ip_in_list( $remote_addr, $trusted_proxies ) ) {
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// XFF is a comma-separated list appended left-to-right.
				// Walk right-to-left and return the first IP that is NOT itself
				// a trusted proxy — that is the real client IP.
				$ips = array_reverse( array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				foreach ( $ips as $candidate ) {
					$candidate = sanitize_text_field( $candidate );
					if (
						filter_var( $candidate, FILTER_VALIDATE_IP ) &&
						! $this->ip_in_list( $candidate, $trusted_proxies )
					) {
						return $candidate;
					}
				}
			}

			if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return $remote_addr;
	}

	/**
	 * Check whether an IP address falls within any of the given IPs or CIDR ranges.
	 *
	 * @param string   $ip      IP address to test.
	 * @param string[] $list    IPs or CIDR ranges (e.g. "10.0.0.0/8").
	 * @return bool
	 */
	private function ip_in_list( string $ip, array $list ): bool {
		$ip_long = ip2long( $ip );
		foreach ( $list as $entry ) {
			if ( strpos( $entry, '/' ) !== false ) {
				[ $range, $bits ] = explode( '/', $entry, 2 );
				$mask    = $bits >= 32 ? -1 : ~( ( 1 << ( 32 - (int) $bits ) ) - 1 );
				$network = ip2long( $range ) & $mask;
				if ( $ip_long !== false && ( $ip_long & $mask ) === $network ) {
					return true;
				}
			} elseif ( $ip === $entry ) {
				return true;
			}
		}
		return false;
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
		if ( $this->sample_rate < 1.0 && ( wp_rand() / PHP_INT_MAX ) > $this->sample_rate ) {
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
					'headers'     => [ 'Content-Type' => 'application/json', 'X-API-Key' => $this->api_key ],
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
					'header'        => "Content-Type: application/json\r\nX-API-Key: {$this->api_key}\r\n",
					'content'       => $json,
					'timeout'       => 2,
					'ignore_errors' => true,
				],
			] );
			$stream_error = null;

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- captures stream errors without @ suppression.
		set_error_handler( static function ( int $errno, string $errstr ) use ( &$stream_error ): bool {
				$stream_error = $errstr;
				return true;
			} );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$result = file_get_contents( $this->dsn, false, $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
