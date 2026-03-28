<?php

/**
 * Uninstall DevPulse Plugin.
 *
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all options and transients created by DevPulse.
 *
 * @since 1.0.0
 */

// WordPress sets this constant before loading uninstall.php; bail if called directly.
defined('WP_UNINSTALL_PLUGIN') || exit;

// Option names to delete
$devpulse_options = [
    'devpulse_dsn',
    'devpulse_env',
    'devpulse_enabled',
    'devpulse_sample_rate',
    'devpulse_release',
];

// Delete single site options
foreach ($devpulse_options as $devpulse_option) {
    delete_option($devpulse_option);
}

// Delete multisite options if in multisite context
if (is_multisite()) {
    global $wpdb;

    $devpulse_blog_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: no caching needed.
        $wpdb->prepare("SELECT blog_id FROM %i", $wpdb->blogs)
    );

    foreach ($devpulse_blog_ids as $blog_id) {
        switch_to_blog($blog_id);

        foreach ($devpulse_options as $devpulse_option) {
            delete_option($devpulse_option);
        }

        delete_transient( 'devpulse_activated' );
        restore_current_blog();
    }
}

// Delete transients (single-site)
delete_transient('devpulse_activated');
