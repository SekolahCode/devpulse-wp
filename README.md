# DevPulse for WordPress

WordPress plugin for DevPulse — real-time error tracking and performance monitoring for WordPress sites.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A running DevPulse server

## Installation

1. Copy or clone the `devpulse-wp` directory into `wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Configure your DSN (see below)

## Configuration

### Option A — wp-admin Settings Page

Go to **Settings → DevPulse** and enter your DSN and environment.

### Option B — `wp-config.php` Constants

```php
define('DEVPULSE_DSN',     'http://localhost:8000/api/ingest/YOUR_API_KEY');
define('DEVPULSE_ENV',     'production');
define('DEVPULSE_ENABLED', true);
```

Constants take precedence over the admin settings.

## What Gets Captured

- **PHP errors** — warnings, notices, fatal errors
- **Unhandled exceptions** — via WordPress exception handler hooks
- **Admin context** — captures include whether the error occurred in wp-admin

## Uninstalling

Deactivating the plugin removes its hooks. Deleting the plugin also removes the settings stored in the WordPress options table via `uninstall.php`.

## License

MIT — see [LICENSE](../../LICENSE)
