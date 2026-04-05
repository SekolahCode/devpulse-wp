# DevPulse for WordPress

WordPress plugin for DevPulse — real-time error tracking and Core Web Vitals monitoring. Self-hosted and free.

Requires a running **DevPulse server v1.0+** and WordPress 6.0+ / PHP 8.1+.

## Installation

1. Copy or clone the `devpulse-wp` directory into `wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Set your DSN (see Configuration below)

## Configuration

### Option A — wp-admin Settings Page

Go to **Settings → DevPulse**, enter your DSN, choose an environment, and check **Enable Error Tracking**.

### Option B — `wp-config.php` Constants

```php
define('DEVPULSE_DSN',          'https://your-devpulse-host/api/ingest/YOUR_API_KEY');
define('DEVPULSE_ENV',          'production');
define('DEVPULSE_ENABLED',      true);
define('DEVPULSE_RELEASE',      '1.4.2');   // optional — version tag on every event
define('DEVPULSE_SAMPLE_RATE',  1.0);       // 0.0–1.0 fraction of events to send
define('DEVPULSE_TRACK_VITALS', true);      // collect frontend Core Web Vitals
```

Constants take precedence over admin settings. Defining `DEVPULSE_DSN` as a constant is recommended — it keeps the API key out of the database and away from database exports.

## What Gets Captured

- **PHP errors** — warnings, notices, and fatal errors
- **Unhandled exceptions** — caught via WordPress exception handler hooks
- **Admin context** — events include whether the error occurred inside wp-admin
- **User context** — authenticated user ID and email attached automatically
- **Release** — version tag from `DEVPULSE_RELEASE` constant or admin setting
- **Core Web Vitals** — LCP, INP, CLS, TTFB, and page load time from real users (requires `DEVPULSE_TRACK_VITALS`)
- **SDK version** — `devpulse-wordpress/2.0.0` attached to every event for version tracking

## Core Web Vitals

When vitals tracking is enabled (default), a lightweight browser script (~4 KB) is injected into every page view. On page hide it sends a single grouped vitals event:

| Metric | Description |
|---|---|
| `lcp` | Largest Contentful Paint (ms) |
| `inp` | Interaction to Next Paint (ms) |
| `cls` | Cumulative Layout Shift (0–1) |
| `ttfb` | Time to First Byte (ms) |
| `page_load` | Total page load time (ms) |

## IP Resolution

The plugin resolves the real client IP safely: `X-Forwarded-For` is only trusted when `REMOTE_ADDR` is a known proxy address. By default, the trusted ranges are RFC-1918 private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) plus loopback (`127.0.0.1`, `::1`).

Override the trusted list via the `devpulse_trusted_proxies` filter:

```php
add_filter('devpulse_trusted_proxies', function (array $cidrs): array {
    $cidrs[] = '203.0.113.0/24';  // your load balancer range
    return $cidrs;
});
```

## Manual Capture

```php
use DevPulseWP\Handler;

// Capture an exception manually
try {
    riskyOperation();
} catch (\Throwable $e) {
    Handler::instance()->capture_exception($e, ['order_id' => $orderId]);
    throw $e;
}
```

## Uninstalling

Deactivating the plugin removes its hooks. Deleting the plugin also removes all settings stored in the WordPress options table via `uninstall.php`.

## License

MIT — see [LICENSE](../../LICENSE)
