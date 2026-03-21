<?php

/**
 * DevPulse Error Handler
 *
 * @package DevPulseWP
 * @since 1.0.0
 */

namespace DevPulseWP;

use WP_Error;

defined('ABSPATH') || exit;

class Handler
{
    /** @var string DSN URL for sending events */
    private static string $dsn;

    /** @var string Current environment */
    private static string $env;

    /** @var bool Whether handler is initialized */
    private static bool $booted = false;

    /** @var bool Prevent recursive error capture */
    private static bool $sending = false;

    /** @var array Cached context data */
    private static ?array $context_cache = null;

    /** @var int Cache timestamp */
    private static int $context_cache_time = 0;

    /** @var int Context cache TTL in seconds (5 minutes) */
    private const CONTEXT_CACHE_TTL = 300;

    /**
     * Initialize the error handler.
     *
     * @since 1.0.0
     * @param string $dsn DSN URL for sending events.
     * @param string $env Environment name.
     */
    public static function init(string $dsn, string $env = 'production'): void
    {
        if (self::$booted) {
            return;
        }

        /**
         * Filter: Allow disabling DevPulse before initialization.
         *
         * @since 1.0.0
         * @param bool $enabled Whether to enable DevPulse.
         */
        if (!apply_filters('devpulse_enabled', true)) {
            return;
        }

        self::$dsn    = $dsn;
        self::$env    = $env;
        self::$booted = true;

        // 1. Native PHP error handlers
        set_exception_handler([self::class, 'captureException']);
        set_error_handler([self::class, 'captureError']);
        register_shutdown_function([self::class, 'captureShutdown']);

        // 2. WordPress-specific hooks
        add_filter('wp_die_handler', [self::class, 'wpDieHandler']);
        add_action('shutdown', [self::class, 'captureDbErrors']);

        // Test endpoint — logged-in users only (nopriv intentionally omitted)
        add_action('wp_ajax_devpulse_test', [self::class, 'ajaxTest']);

        /**
         * Action: Fires after DevPulse handler is initialized.
         *
         * @since 1.0.0
         */
        do_action('devpulse_loaded');
    }

    /**
     * Check if handler is initialized.
     *
     * @since 1.0.0
     * @return bool
     */
    public static function is_loaded(): bool
    {
        return self::$booted;
    }

    /**
     * Get the current DSN.
     *
     * @since 1.0.0
     * @return string
     */
    public static function get_dsn(): string
    {
        return self::$dsn;
    }

    /**
     * Get the current environment.
     *
     * @since 1.0.0
     * @return string
     */
    public static function get_env(): string
    {
        return self::$env;
    }

    // ── Exception Handler ───────────────────────────────────────────────────
    /**
     * Capture an exception.
     *
     * @since 1.0.0
     * @param \Throwable $e The exception to capture.
     */
    public static function captureException(\Throwable $e): void
    {
        /**
         * Filter: Allow filtering exceptions before capture.
         *
         * @since 1.0.0
         * @param bool   $capture Whether to capture the exception.
         * @param object $e       The exception object.
         */
        if (!apply_filters('devpulse_capture_exception', true, $e)) {
            return;
        }

        self::send(self::buildFromException($e));
    }

    // ── PHP Error Handler ───────────────────────────────────────────────────
    public static function captureError(int $severity, string $message, string $file, int $line): bool
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
        if (!(error_reporting() & $severity)) return false;
        self::captureException(new \ErrorException($message, 0, $severity, $file, $line));
        return false;
    }

    // ── Fatal Error Handler ─────────────────────────────────────────────────
    public static function captureShutdown(): void
    {
        $error = error_get_last();
        if (!$error) return;

        $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatals, true)) return;

        self::captureException(
            new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
        );
    }

    // ── wp_die() Handler ────────────────────────────────────────────────────
    public static function wpDieHandler(): callable
    {
        return function ($message, $title = '', $args = []) {
            $msg = is_wp_error($message)
                ? $message->get_error_message()
                : (is_string($message) ? $message : 'wp_die called');

            self::send([
                'level'   => 'error',
                'message' => 'wp_die: ' . $msg,
                'context' => array_merge(self::buildContext(), [
                    'wp_die_title' => is_string($title) ? $title : '',
                ]),
            ]);

            // Call default WordPress die handler
            _default_wp_die_handler($message, $title, $args);
        };
    }

    // ── $wpdb Error Capture ─────────────────────────────────────────────────
    public static function captureDbErrors(): void
    {
        global $wpdb;
        if (empty($wpdb->last_error)) return;

        self::send([
            'level'   => 'error',
            'message' => 'WordPress DB Error: ' . $wpdb->last_error,
            'context' => array_merge(self::buildContext(), [
                'last_query' => $wpdb->last_query,
                'db_error'   => $wpdb->last_error,
            ]),
        ]);
    }

    // ── Payload Builder ─────────────────────────────────────────────────────
    private static function buildFromException(\Throwable $e): array
    {
        return [
            'level'     => 'error',
            'exception' => [
                'type'       => get_class($e),
                'message'    => $e->getMessage(),
                'stacktrace' => self::buildStacktrace($e),
            ],
            'context'   => self::buildContext(),
            'request'   => self::buildRequest(),
            'timestamp' => gmdate('c'),
        ];
    }

    private static function buildStacktrace(\Throwable $e): array
    {
        $frames = [[
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'function' => null,
        ]];

        foreach ($e->getTrace() as $frame) {
            $frames[] = [
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'function' => isset($frame['class'])
                    ? "{$frame['class']}{$frame['type']}{$frame['function']}"
                    : ($frame['function'] ?? null),
            ];
        }

        return $frames;
    }

    /**
     * Build context data with caching for better performance.
     *
     * Uses transient caching to avoid repeated calls to WordPress APIs.
     *
     * @since 1.0.0
     * @return array Context data.
     */
    private static function buildContext(): array
    {
        $now = time();

        // Return cached context if still valid
        if (
            self::$context_cache !== null
            && ($now - self::$context_cache_time) < self::CONTEXT_CACHE_TTL
        ) {
            return self::$context_cache;
        }

        // Get active plugins - limit to first 20 to prevent huge payloads
        $active_plugins = get_option('active_plugins', []);
        if (count($active_plugins) > 20) {
            $active_plugins = array_slice($active_plugins, 0, 20);
            $active_plugins[] = '... truncated';
        }

        self::$context_cache = [
            'php'             => PHP_VERSION,
            'platform'        => 'wordpress',
            'wordpress'       => get_bloginfo('version'),
            'active_plugins'  => $active_plugins,
            'theme'           => get_template(),
            'theme_parent'    => get_stylesheet(),
            'environment'     => self::$env,
            'memory'          => memory_get_peak_usage(true),
            'is_admin'        => is_admin(),
            'multisite'       => is_multisite(),
            'wp_debug'        => (defined('WP_DEBUG') && WP_DEBUG),
            'wp_debug_log'    => (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG),
        ];

        self::$context_cache_time = $now;

        return self::$context_cache;
    }

    private static function buildRequest(): ?array
    {
        if (PHP_SAPI === 'cli') return null;

        // Sanitize server vars — they come from the web server and are untrusted.
        $uri    = isset($_SERVER['REQUEST_URI'])
            ? filter_var(wp_unslash($_SERVER['REQUEST_URI']), FILTER_SANITIZE_URL)
            : '/';
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))
            : 'GET';
        $ip     = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : null;

        return [
            'url'    => home_url($uri),
            'method' => $method,
            'ip'     => $ip,
        ];
    }

    // ── HTTP Transport ───────────────────────────────────────────────────────

    private static function send(array $payload): bool
    {
        if (self::$sending) return false;
        self::$sending = true;

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('DevPulse: json_encode failed — ' . json_last_error_msg());
                return false;
            }

            // Use WordPress HTTP API when available (non-blocking: fire-and-forget)
            if (function_exists('wp_remote_post')) {
                $response = wp_remote_post(self::$dsn, [
                    'timeout'     => 2,
                    'blocking'    => false,
                    'headers'     => ['Content-Type' => 'application/json'],
                    'body'        => $json,
                    'data_format' => 'body',
                ]);

                if (is_wp_error($response)) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('DevPulse: ' . $response->get_error_message());
                    return false;
                }

                return true;
            }

            // Fallback for shutdown/early-boot contexts — synchronous stream request
            if (!ini_get('allow_url_fopen')) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('DevPulse: allow_url_fopen is disabled; cannot send event via fallback transport.');
                return false;
            }

            $context = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/json\r\n",
                    'content'       => $json,
                    'timeout'       => 2,
                    'ignore_errors' => true,
                ],
            ]);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $streamError = null;
            set_error_handler(static function (int $errno, string $errstr) use (&$streamError): bool {
                $streamError = $errstr;
                return true;
            });
            $result = file_get_contents(self::$dsn, false, $context);
            restore_error_handler();
            if ($streamError !== null) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('DevPulse: stream error — ' . $streamError);
            }
            return $result !== false;
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('DevPulse send() failed: ' . $e->getMessage());
            return false;
        } finally {
            self::$sending = false;
        }
    }

    // ── AJAX: Connection Test (admin only) ───────────────────────────────────
    public static function ajaxTest(): void
    {
        // Verify the caller is a logged-in admin
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions', 403);
            return;
        }

        if (!check_ajax_referer('devpulse_test', 'nonce', false)) {
            wp_send_json_error('Invalid nonce', 403);
            return;
        }

        $dsn = defined('DEVPULSE_DSN') ? DEVPULSE_DSN : get_option('devpulse_dsn', '');

        if (empty($dsn)) {
            wp_send_json_error('DSN is not configured');
            return;
        }

        $sent = self::send([
            'level'   => 'info',
            'message' => 'DevPulse WordPress connection test',
            'context' => self::buildContext(),
        ]);

        if ($sent) {
            wp_send_json_success('Event sent!');
        } else {
            wp_send_json_error('Could not connect to DevPulse server — check DSN and network');
        }
    }
}
