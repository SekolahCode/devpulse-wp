<?php

namespace DevPulseWP;

class Admin
{
    public static function register(): void
    {
        add_options_page(
            __('DevPulse Settings', 'devpulse'),
            'DevPulse',
            'manage_options',
            'devpulse',
            [self::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('devpulse_settings', 'devpulse_dsn', [
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);

        register_setting('devpulse_settings', 'devpulse_env', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'production',
        ]);

        // absint coerces "1" -> 1 and missing checkbox -> 0
        register_setting('devpulse_settings', 'devpulse_enabled', [
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $dsn     = get_option('devpulse_dsn', '');
        $env     = get_option('devpulse_env', 'production');
        $enabled = (int) get_option('devpulse_enabled', 0);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('devpulse_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="devpulse_dsn"><?php esc_html_e('DSN', 'devpulse'); ?></label>
                        </th>
                        <td>
                            <input type="url" id="devpulse_dsn" name="devpulse_dsn"
                                value="<?php echo esc_attr($dsn); ?>"
                                class="regular-text"
                                placeholder="https://devpulse.example.com/api/ingest/your-key" />
                            <p class="description">
                                <?php esc_html_e('Your DevPulse project ingest URL.', 'devpulse'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="devpulse_env"><?php esc_html_e('Environment', 'devpulse'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="devpulse_env" name="devpulse_env"
                                value="<?php echo esc_attr($env); ?>" class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('e.g. production, staging, development', 'devpulse'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enabled', 'devpulse'); ?></th>
                        <td>
                            <!-- Hidden input ensures the option is cleared when checkbox is unchecked -->
                            <input type="hidden" name="devpulse_enabled" value="0" />
                            <label for="devpulse_enabled">
                                <input type="checkbox" id="devpulse_enabled" name="devpulse_enabled"
                                    value="1" <?php checked($enabled, 1); ?> />
                                <?php esc_html_e('Enable error tracking', 'devpulse'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ($dsn) : ?>
                <hr />
                <h2><?php esc_html_e('Connection Test', 'devpulse'); ?></h2>
                <button id="devpulse-test" class="button button-secondary">
                    <?php esc_html_e('Send Test Event', 'devpulse'); ?>
                </button>
                <span id="devpulse-result" style="margin-left:10px;" aria-live="polite"></span>

                <script>
                (function () {
                    var btn    = document.getElementById('devpulse-test');
                    var result = document.getElementById('devpulse-result');
                    var nonce  = <?php echo wp_json_encode(wp_create_nonce('devpulse_test')); ?>;

                    btn.addEventListener('click', function () {
                        btn.disabled   = true;
                        result.textContent = '<?php echo esc_js(__('Sending\u2026', 'devpulse')); ?>';

                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=devpulse_test&nonce=' + encodeURIComponent(nonce),
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            result.textContent = d.success
                                ? '✓ <?php echo esc_js(__('Event sent!', 'devpulse')); ?>'
                                : '✗ <?php echo esc_js(__('Failed', 'devpulse')); ?>: ' + (d.data || 'unknown error');
                        })
                        .catch(function () {
                            result.textContent = '✗ <?php echo esc_js(__('Network error', 'devpulse')); ?>';
                        })
                        .finally(function () {
                            btn.disabled = false;
                        });
                    });
                }());
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
