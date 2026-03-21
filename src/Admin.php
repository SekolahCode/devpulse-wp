<?php

/**
 * DevPulse Admin Settings
 *
 * @package DevPulseWP
 * @since 1.0.0
 */

namespace DevPulseWP;

/**
 * Admin settings class.
 *
 * Handles plugin settings page, assets, and AJAX endpoints.
 *
 * @since 1.0.0
 */
class Admin
{
    /** @var string Settings page hook */
    private const PAGE_HOOK = 'settings_page_devpulse';

    /** @var string Script handle */
    private const SCRIPT_HANDLE = 'devpulse-admin';

    /** @var string Style handle */
    private const STYLE_HANDLE = 'devpulse-admin-style';

    /** @var string Option group */
    private const OPTION_GROUP = 'devpulse_settings';

    /**
     * Register admin menu and settings.
     *
     * @since 1.0.0
     */
    public static function register(): void
    {
        add_options_page(
            __('DevPulse Settings', 'devpulse'),
            __('DevPulse', 'devpulse'),
            'manage_options',
            'devpulse',
            [self::class, 'renderPage']
        );

        // Add plugin action links
        add_filter('plugin_action_links_' . plugin_basename(DEVPULSE_FILE), [self::class, 'pluginActionLinks']);
    }

    /**
     * Add plugin action links.
     *
     * @since 1.0.0
     * @param array $links Existing plugin action links.
     * @return array Modified links.
     */
    public static function pluginActionLinks(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=devpulse')),
            esc_html__('Settings', 'devpulse')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Enqueue admin assets.
     *
     * @since 1.0.0
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public static function enqueueAssets(string $hook_suffix): void
    {
        if ($hook_suffix !== self::PAGE_HOOK) {
            return;
        }

        $base_path = dirname(__DIR__);
        $plugin_file = $base_path . '/devpulse.php';

        // Enqueue script
        $script_relative_path = 'assets/admin.js';
        $script_file = $base_path . '/' . $script_relative_path;
        $script_version = file_exists($script_file) ? (string) filemtime($script_file) : DEVPULSE_VERSION;

        wp_register_script(
            self::SCRIPT_HANDLE,
            plugins_url($script_relative_path, $plugin_file),
            [],
            $script_version,
            ['in_footer' => true, 'strategy' => 'defer']
        );

        /**
         * Filter: Modify admin JavaScript configuration.
         *
         * @since 1.0.0
         * @param array $config JavaScript configuration.
         */
        $config = apply_filters('devpulse_admin_js_config', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('devpulse_test'),
            'action'       => 'devpulse_test',
            'repairNonce'  => wp_create_nonce('devpulse_repair'),
            'repairAction' => 'devpulse_repair',
            'sendingText'  => __('Sending...', 'devpulse'),
            'successText'  => __('Event sent!', 'devpulse'),
            'failedText'   => __('Failed', 'devpulse'),
        ]);

        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.devpulseAdmin = ' . wp_json_encode($config) . ';',
            'before'
        );
        wp_enqueue_script(self::SCRIPT_HANDLE);

        // Enqueue styles
        $style_relative_path = 'assets/admin.css';
        $style_file = $base_path . '/' . $style_relative_path;
        $style_version = file_exists($style_file) ? (string) filemtime($style_file) : DEVPULSE_VERSION;

        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url($style_relative_path, $plugin_file),
            [],
            $style_version
        );
        wp_enqueue_style(self::STYLE_HANDLE);

        // Add admin styles inline
        $custom_css = self::getAdminStyles();
        if (!empty($custom_css)) {
            wp_add_inline_style(self::STYLE_HANDLE, $custom_css);
        }
    }

    /**
     * Get admin CSS styles.
     *
     * @since 1.0.0
     * @return string CSS styles.
     */
    private static function getAdminStyles(): string
    {
        return '
            .devpulse-result {
                margin-left: 10px;
                font-weight: 500;
            }
            .devpulse-result.devpulse-success {
                color: #46b450;
            }
            .devpulse-result.devpulse-error {
                color: #dc3232;
            }
            .devpulse-result.devpulse-loading {
                color: #82878c;
            }
            #devpulse-test[disabled] {
                opacity: 0.6;
                cursor: not-allowed;
            }
        ';
    }

    /**
     * Register settings using WordPress Settings API.
     *
     * @since 1.0.0
     */
    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, 'devpulse_dsn', [
            'sanitize_callback' => [self::class, 'sanitizeDsn'],
            'default'           => '',
            'type'              => 'string',
            'show_in_rest'      => false,
        ]);

        register_setting(self::OPTION_GROUP, 'devpulse_env', [
            'sanitize_callback' => [self::class, 'sanitizeEnv'],
            'default'           => 'production',
            'type'              => 'string',
            'show_in_rest'      => false,
        ]);

        register_setting(self::OPTION_GROUP, 'devpulse_enabled', [
            'sanitize_callback' => 'absint',
            'default'           => 0,
            'type'              => 'boolean',
            'show_in_rest'      => false,
        ]);
    }

    /**
     * Sanitize DSN option.
     *
     * @since 1.0.0
     * @param mixed $value Raw DSN value.
     * @return string Sanitized DSN.
     */
    public static function sanitizeDsn($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if (empty($value)) {
            return '';
        }

        // Validate URL format
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            add_settings_error(
                self::OPTION_GROUP,
                'devpulse_dsn_invalid',
                __('Please enter a valid DSN URL.', 'devpulse'),
                'error'
            );
            return '';
        }

        return esc_url_raw($value);
    }

    /**
     * Sanitize environment option.
     *
     * @since 1.0.0
     * @param mixed $value Raw environment value.
     * @return string Sanitized environment.
     */
    public static function sanitizeEnv($value): string
    {
        if (!is_string($value)) {
            return 'production';
        }

        $allowed = ['production', 'staging', 'development', 'local', 'test'];

        $value = sanitize_key(trim($value));

        if (!in_array($value, $allowed, true)) {
            // Still allow custom environments but sanitize
            $value = sanitize_title($value);
        }

        return $value;
    }

    /**
     * Render the settings page.
     *
     * @since 1.0.0
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'devpulse'));
        }

        $dsn     = get_option('devpulse_dsn', '');
        $env     = get_option('devpulse_env', 'production');
        $enabled = (int) get_option('devpulse_enabled', 0);
?>
        <div class="wrap devpulse-settings-wrap">
            <h1 class="wp-heading-inline">
                <?php echo esc_html(get_admin_page_title()); ?>
            </h1>

            <hr class="wp-header-end" />

            <form method="post" action="options.php" class="devpulse-settings-form">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr scope="row">
                            <th scope="row">
                                <label for="devpulse_enabled">
                                    <?php esc_html_e('Enable Error Tracking', 'devpulse'); ?>
                                </label>
                            </th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text">
                                        <?php esc_html_e('Enable Error Tracking', 'devpulse'); ?>
                                    </legend>
                                    <label for="devpulse_enabled">
                                        <input
                                            type="checkbox"
                                            id="devpulse_enabled"
                                            name="devpulse_enabled"
                                            value="1"
                                            <?php checked($enabled, 1); ?> />
                                        <?php esc_html_e('Enable error and performance monitoring', 'devpulse'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>

                        <tr scope="row">
                            <th scope="row">
                                <label for="devpulse_dsn">
                                    <?php esc_html_e('DSN (Data Source Name)', 'devpulse'); ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="url"
                                    id="devpulse_dsn"
                                    name="devpulse_dsn"
                                    value="<?php echo esc_attr($dsn); ?>"
                                    class="regular-text"
                                    placeholder="https://devpulse.example.com/api/ingest/your-api-key"
                                    <?php echo $enabled ? '' : 'disabled'; ?> />
                                <p class="description">
                                    <?php esc_html_e('Your DevPulse project ingest URL. Get this from your DevPulse dashboard.', 'devpulse'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr scope="row">
                            <th scope="row">
                                <label for="devpulse_env">
                                    <?php esc_html_e('Environment', 'devpulse'); ?>
                                </label>
                            </th>
                            <td>
                                <select
                                    id="devpulse_env"
                                    name="devpulse_env"
                                    class="regular-text"
                                    <?php echo $enabled ? '' : 'disabled'; ?>>
                                    <option value="production" <?php selected($env, 'production'); ?>>
                                        <?php esc_html_e('Production', 'devpulse'); ?>
                                    </option>
                                    <option value="staging" <?php selected($env, 'staging'); ?>>
                                        <?php esc_html_e('Staging', 'devpulse'); ?>
                                    </option>
                                    <option value="development" <?php selected($env, 'development'); ?>>
                                        <?php esc_html_e('Development', 'devpulse'); ?>
                                    </option>
                                    <option value="local" <?php selected($env, 'local'); ?>>
                                        <?php esc_html_e('Local', 'devpulse'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Select the environment where this site is running.', 'devpulse'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save Changes', 'devpulse')); ?>
            </form>

            <?php if ($enabled && !empty($dsn)) : ?>
                <hr />

                <h2><?php esc_html_e('Connection Test', 'devpulse'); ?></h2>
                <p>
                    <?php esc_html_e('Click the button below to send a test event to verify your setup is working correctly.', 'devpulse'); ?>
                </p>
                <p>
                    <button type="button" id="devpulse-test" class="button button-secondary">
                        <?php esc_html_e('Send Test Event', 'devpulse'); ?>
                    </button>
                    <span id="devpulse-result" class="devpulse-result" aria-live="polite"></span>
                </p>
            <?php endif; ?>

            <hr />

            <h2><?php esc_html_e('Repair Database', 'devpulse'); ?></h2>
            <p>
                <?php esc_html_e('Restore missing options to their defaults, validate stored values, and clear stale transients.', 'devpulse'); ?>
            </p>
            <p>
                <button type="button" id="devpulse-repair" class="button button-secondary">
                    <?php esc_html_e('Repair Database', 'devpulse'); ?>
                </button>
                <span id="devpulse-repair-result" class="devpulse-result" aria-live="polite"></span>
            </p>
            <div id="devpulse-repair-details" style="display:none; margin-top:10px;">
                <ul id="devpulse-repair-list" class="ul-disc" style="margin-left:2em;"></ul>
            </div>

            <hr />

            <h2><?php esc_html_e('Documentation', 'devpulse'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: DevPulse documentation URL */
                    esc_html__('For more information on how to use DevPulse, please %s.', 'devpulse'),
                    '<a href="https://github.com/SekolahCode/devpulse-wp" target="_blank" rel="noopener noreferrer">' . esc_html__('read the documentation', 'devpulse') . '</a>'
                );
                ?>
            </p>
        </div>
<?php
    }

    /**
     * AJAX handler: repair plugin database options.
     *
     * Restores missing options to defaults, validates stored values,
     * and flushes stale transients.
     *
     * @since 1.0.0
     */
    public static function repairDb(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'devpulse'), 403);
            return;
        }

        if (!check_ajax_referer('devpulse_repair', 'nonce', false)) {
            wp_send_json_error(__('Invalid nonce.', 'devpulse'), 403);
            return;
        }

        $repairs = [];

        // 1. Restore missing options with defaults
        $defaults = [
            'devpulse_dsn'     => '',
            'devpulse_env'     => 'production',
            'devpulse_enabled' => 0,
        ];

        foreach ($defaults as $option => $default) {
            if (get_option($option) === false) {
                add_option($option, $default);
                /* translators: %s: option name */
                $repairs[] = sprintf(__('Restored missing option: %s', 'devpulse'), $option);
            }
        }

        // 2. Validate DSN — must be a valid URL or empty
        $dsn = get_option('devpulse_dsn', '');
        if (!empty($dsn) && !filter_var($dsn, FILTER_VALIDATE_URL)) {
            update_option('devpulse_dsn', '');
            $repairs[] = __('Cleared invalid DSN value.', 'devpulse');
        }

        // 3. Validate environment — fall back to production if unrecognised
        $env     = get_option('devpulse_env', 'production');
        $allowed = ['production', 'staging', 'development', 'local', 'test'];
        if (!in_array($env, $allowed, true) && !preg_match('/^[a-z0-9_\-]+$/', $env)) {
            update_option('devpulse_env', 'production');
            $repairs[] = __('Reset invalid environment to "production".', 'devpulse');
        }

        // 4. Flush stale transients
        delete_transient('devpulse_activated');

        // 5. Multisite — repair each sub-site
        if (is_multisite()) {
            global $wpdb;
            $blog_ids = $wpdb->get_col(
                $wpdb->prepare('SELECT blog_id FROM %i', $wpdb->blogs)
            );

            foreach ($blog_ids as $blog_id) {
                switch_to_blog((int) $blog_id);

                foreach ($defaults as $option => $default) {
                    if (get_option($option) === false) {
                        add_option($option, $default);
                        /* translators: 1: option name, 2: blog ID */
                        $repairs[] = sprintf(
                            __('Restored missing option %1$s on site %2$d.', 'devpulse'),
                            $option,
                            (int) $blog_id
                        );
                    }
                }

                delete_transient('devpulse_activated');
                restore_current_blog();
            }
        }

        $message = empty($repairs)
            ? __('Database is healthy — no repairs needed.', 'devpulse')
            : __('Repair completed successfully.', 'devpulse');

        wp_send_json_success([
            'message' => $message,
            'repairs' => $repairs,
        ]);
    }
}
