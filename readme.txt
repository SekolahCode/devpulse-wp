=== DevPulse ===
Contributors: sekolahcode
Tags: error-tracking, monitoring, performance, logging, sentry
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Real-time error tracking and performance monitoring for WordPress — self-hosted and free.

== Description ==

DevPulse is a self-hosted error tracking and performance monitoring plugin for WordPress, similar to Sentry but free and running on your own server.

**Features:**

* Captures PHP errors, warnings, notices, and fatal errors
* Captures unhandled exceptions automatically
* Lightweight — zero impact on page load for your visitors
* Configurable via the WordPress admin or `wp-config.php` constants
* Works with any self-hosted DevPulse server

**Privacy:** All error data is sent to your own server. Nothing leaves your infrastructure.

== Installation ==

1. Upload the `devpulse` folder to `wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins** in the WordPress admin
3. Go to **Settings → DevPulse** and enter your server DSN

Alternatively, you can configure the plugin using constants in `wp-config.php` (see Configuration below).

== Configuration ==

You can configure the plugin via **Settings → DevPulse** in the WordPress admin, or by defining constants in `wp-config.php`:

    define( 'DEVPULSE_DSN',     'http://your-server:8000/api/ingest/YOUR_API_KEY' );
    define( 'DEVPULSE_ENV',     'production' );
    define( 'DEVPULSE_ENABLED', true );

Constants take precedence over admin settings.

**Running the DevPulse server:**

    docker compose up -d

See the [DevPulse GitHub repository](https://github.com/SekolahCode/devpulse) for full server setup instructions.

== Frequently Asked Questions ==

= Do I need a DevPulse account? =

No. DevPulse is entirely self-hosted. You run the server yourself using Docker.

= Is it compatible with WordPress Multisite? =

Single-site and network-activated usage is supported. Each site should have its own API key (project) on the DevPulse server.

= What happens when the DevPulse server is unavailable? =

Errors are captured with a short timeout (2 seconds by default) so your site is never slowed down if the server is unreachable.

== Screenshots ==

1. Settings page — enter your DSN and environment name.

== Changelog ==

= 0.1.0 =
* Initial release

== Upgrade Notice ==

= 0.1.0 =
Initial release — no upgrade steps required.
