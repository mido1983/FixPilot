# WP FixPilot

WP FixPilot is a WordPress and WooCommerce diagnostics, performance profiling, conflict-isolation, and conservative repair plugin. It is designed around a guarded workflow: **detect → explain → back up → repair → validate → rollback on regression**.

> **Status:** `4.0.0-rc1` release candidate. Use on staging first. Production repair features are intentionally conservative and cannot guarantee compatibility with every custom WordPress stack.

## Highlights

- PHP Fatal / Warning / Notice / Deprecated monitoring with plugin/theme/core attribution.
- Site health, plugin risk, runtime-cost, database, autoload, cron, REST/AJAX and WooCommerce diagnostics.
- Signed request profiler for slow SQL, N+1 patterns, outbound HTTP and hook/callback bottlenecks.
- WooCommerce HPOS, Action Scheduler, sessions, catalog/product/cart/checkout profiling and consistency checks.
- Per-browser Safe Mode and plugin Conflict Profiler without disabling plugins for visitors.
- Repair Center with confidence/risk metadata, backups, linting, smoke tests and rollback.
- Auto Repair Workflow that validates after every eligible repair and stops on regression.
- Update Guard, conflict quarantine/restore, configuration repair and guarded performance maintenance.
- Optional AI repair proposals with limited source context and the same backup/lint/smoke-test gate.
- Semantic status colors across the admin UI: red = critical, orange = danger, yellow = attention, green = safe.

## Requirements

- WordPress 6.5+
- PHP 7.4+
- WooCommerce 8.0+ for WooCommerce-specific features
- Tested metadata in this RC targets WordPress 7.1 and WooCommerce 11.1

## Installation

1. Download the repository as ZIP or build a plugin ZIP with `wp-fixpilot` as the plugin directory.
2. In WordPress open **Plugins → Add New → Upload Plugin**.
3. Activate **WP FixPilot**.
4. Open **WP FixPilot** from the main admin menu.
5. Run diagnostics before enabling code repair or Auto Repair.

## Recommended production workflow

1. Take a full external backup.
2. Run a full scan and Request Profiler.
3. Review **Action Center** and **Repair Center**.
4. Test code/config repairs on staging where possible.
5. Start Auto Repair with a small step limit (3–5).
6. Review before/after metrics and rollback history.

## Safety model

WP FixPilot deliberately does **not** promise to repair arbitrary PHP code. Unknown or ambiguous issues remain diagnosis-only. Deterministic code repairs are restricted to known patterns and pass through backup, PHP lint, HTTP/WooCommerce smoke checks, and rollback when validation fails. Destructive database maintenance is batch-limited and kept outside automatic safe repair where appropriate.

## AI Repair

AI repair is opt-in. Normal scans do not send code externally. When enabled, only the selected issue and a limited source-code context are sent to the configured provider. An AI proposal is never applied directly; it must pass exact-match validation and the normal repair safety pipeline.

## Development

```bash
composer install
composer validate
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
```

Release acceptance gates are documented in [`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md).

## License

This repository is **source-available for noncommercial use** under the [PolyForm Noncommercial License 1.0.0](LICENSE). Commercial use requires a separate license from the repository owner.

Because this license restricts commercial use, this GitHub distribution is **not GPL-compatible for WordPress.org submission**. A future WordPress.org edition would need separate GPL-compatible licensing.

## Security

Please do not publish exploitable vulnerabilities in a public issue. See [`SECURITY.md`](SECURITY.md).

## Contributing

Bug reports and pull requests are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md).
