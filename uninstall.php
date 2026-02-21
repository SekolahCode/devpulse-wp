<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 * Removes all options created by DevPulse.
 */

// WordPress sets this constant before loading uninstall.php; bail if called directly.
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('devpulse_dsn');
delete_option('devpulse_env');
delete_option('devpulse_enabled');
