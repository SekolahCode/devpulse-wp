<?php

defined('ABSPATH') || exit;

namespace DevPulseWP;

class Handler
{
    private static string $dsn;
    private static string $env;
    private static bool   $booted = false;

    public static function init(string $dsn, string $env = 'production'): void
    {
        if (self::$booted) return;

        self::$dsn    = $dsn;
        self::$env    = $env;
        self::$booted = true;

        // 1. Native PHP error handlers
        set_exception_handler([self::class, 'captureException']);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        set_error_handler([self::class, 'captureError']);
        register_shutdown_function([self::class, 'captureShutdown']);

        // 2. WordPress-specific hooks
        add_filter('wp_die_handler', [self::class, 'wpDieHandler']);
        add_action('shutdown',       [self::class, 'captureDbErrors']);

        // Test endpoint — logged-in users only (nopriv intentionally omitted)
        add_action('wp_ajax_devpulse_test', [self::class, 'ajaxTest']);
    }

    // ── Exception Handler ───────────────────────────────────────────────────
    public static function captureException(\Throwable $e): void
    {
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

    private static function buildContext(): array
    {
        return [
            'php'            => PHP_VERSION,
            'platform'       => 'wordpress',
            'wordpress'      => get_bloginfo('version'),
            'active_plugins' => get_option('active_plugins', []),
            'theme'          => get_template(),
            'environment'    => self::$env,
            'memory'         => memory_get_peak_usage(true),
            'is_admin'       => is_admin(),
            'multisite'      => is_multisite(),
        ];
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
    private static bool $sending = false;

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
            $result = @file_get_contents(self::$dsn, false, $context);
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
