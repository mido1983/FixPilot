=== WP FixPilot ===
Contributors: wpfixpilot
Tags: diagnostics, woocommerce, performance, deprecated, errors
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.0.0-rc1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WP FixPilot diagnoses WordPress and WooCommerce problems and applies only conservative, reversible automatic repairs.

== Description ==

WP FixPilot 3.0 adds an Auto Repair Workflow that applies eligible repairs one at a time, validates each step, stops on regression, and rolls back the last reversible change when possible.

Version 2.9 adds Safe Config Repair: reversible production error-display protection, Heartbeat throttling, WooCommerce session-cleanup fallback, runtime configuration health and config rollback history.

WP FixPilot 2.6 adds an actionable Repair Center with safe batch repair, explicit risk/confidence metadata, code backup/validation/rollback, and guarded database maintenance. Critical, danger, attention and safe states use consistent semantic color labels across the admin UI.
WP FixPilot provides:

* Actionable Repair Center with Safe Repair All.
* Safe Config Repair for production runtime settings with rollback history.
* Production error-display guard that keeps internal diagnostics while suppressing raw PHP errors from visitor responses.
* Reversible WordPress Heartbeat interval control using the native heartbeat_settings filter.
* WooCommerce daily session-cleanup fallback only when no native cleanup event is scheduled.
* High-confidence PHP code repairs with backup, lint, smoke-test and rollback.
* Guarded orphan metadata, transient, revision, cron and WooCommerce maintenance actions.

* PHP Fatal, Warning, Notice and Deprecated monitoring.
* Source attribution to plugins, themes, WordPress Core or custom/server code.
* Plugin risk scoring from updates, compatibility, runtime errors and overlapping capabilities.
* Autoload, transients, cron, object-cache and database diagnostics.
* WooCommerce HPOS, Action Scheduler and session diagnostics.
* Loopback performance benchmark for home/shop/cart/checkout.
* Signed deep Request Profiler for home, REST, AJAX, shop, cart and checkout.
* Slow SQL inventory, SQL-time measurement and repeated-query/N+1 heuristics.
* Runtime attribution for slow SQL and outbound HTTP to plugin/theme/core/custom code.
* Per-plugin measured runtime-cost score from attributed SQL and HTTP work.
* WooCommerce catalog/product/category/cart/checkout bottleneck analysis and variation hot-spot inventory.
* Outbound HTTP timing, request peak-memory metrics and REST/AJAX registry inventory.
* Evidence-based prioritized optimization plan generated from measured bottlenecks.
* Per-browser Safe Mode for plugin conflict isolation without disabling plugins for visitors.
* Conflict Profiler that measures page impact by excluding one plugin at a time only inside private loopback probes.
* Update Guard that records a pre-update baseline and compares performance/errors after standard single-plugin updates.
* Backup browser with explicit manual rollback.
* High-confidence PHP repair rules for PHP 8.2 dynamic properties, legacy ReturnTypeWillChange notices, and guarded optional-before-required signatures.
* File backup, Crash Guard validation and automatic rollback after code repairs.
* JSON report export and repair history.

WP FixPilot does not claim to repair arbitrary PHP errors. Unknown or ambiguous issues remain diagnosis-only rather than being modified unsafely.

== Installation ==
1. Upload the plugin ZIP in Plugins > Add New > Upload Plugin.
2. Activate WP FixPilot.
3. Open WP FixPilot from the main admin menu.
4. Run Full Scan.
5. Enable Code Repair Engine in Settings only if you want high-confidence code repairs.

== Frequently Asked Questions ==
= Does WP FixPilot edit WordPress Core? =
No. Automatic code repair is restricted to writable plugin and theme PHP files.

= Can a repair break the site? =
Every supported code repair creates a backup first. FixPilot then runs loopback checks and restores the original file automatically if validation fails. No tool can guarantee compatibility with every custom stack, so code repair is disabled by default.

= Does Safe Mode affect visitors? =
No. Safe Mode uses a random browser cookie plus an MU loader and filters active plugins only for that diagnostic browser session.

= Does it support WooCommerce HPOS? =
Yes. The plugin declares HPOS compatibility and includes HPOS-aware diagnostics.

= Repair capabilities in 2.7.0 =

* Adds a deterministic PHP repair for deprecated `${var}` string interpolation.
* Detects autoloaded WordPress transient options and can switch only those allowlisted cache options to non-autoload without changing the stored value. Autoload policy changes are reversible from Repair Center.
* Detects Action Scheduler due backlog and old completed/canceled history. Queue execution uses Action Scheduler's queue hook; historical cleanup is capped at 1,000 rows per run.
* Can queue WooCommerce product lookup table regeneration through WooCommerce's own API.
* Destructive database operations remain outside Safe Repair All.

== Changelog ==

= 4.0.0-rc1 =
Release candidate: production safety, expanded repair rules, performance repair, conflict resolution, Update Guard Pro, opt-in AI Repair, release-readiness UI and CI/test/publication scaffolding.

= 3.6.0 =
AI Repair Assistant: explicit opt-in provider configuration, limited source context, JSON replacement proposals, exact-match validation and guarded apply through the normal backup/lint/smoke pipeline.

= 3.5.0 =
Update Guard Pro: pre-update plugin/theme filesystem snapshots, post-update regression checks, persistent history and manual rollback.

= 3.4.0 =
Conflict Resolution: evidence-backed culprit plans, reversible plugin quarantine through native WordPress activation APIs, and quarantine history.

= 3.3.0 =
Performance Repair: conservative duplicate/stale cron cleanup, runtime repair plan and explicit dependency-safe handling of cart fragments/object cache.

= 3.2.0 =
Expanded deterministic repair rules for nullable count/foreach/string operations and safe nullable array-offset reads.

= 3.1.0 =
Production Safety: repair locks/watchdog, disk/filesystem preflight, multisite/self-protection and emergency MU recovery bridge.

= 3.0.0 =
* Added Safe Config Repair and runtime configuration health.
* Added reversible production error-display guard.
* Added reversible Heartbeat throttling.
* Added WooCommerce session cleanup fallback and config rollback history.

= 2.4.0 =
* Added signed loopback hook/callback profiler with plugin/theme/core source attribution.
* Added before/after request-profile comparison with regression classification.
* Added Recommendation Engine: leave, review, update then profile, configure/patch, replace/disable.
* Added semantic traffic-light metric labels across FixPilot admin screens.
* Added hook/callback and comparison data to profiler output and JSON export.
= 2.3.0 =
* Runtime source attribution, per-plugin measured cost scoring, product/category profiling and WooCommerce catalog/variation bottleneck analysis.

= 2.2.0 =
* Deep Request Profiler, SQL/N+1 analysis, outbound HTTP timing, AJAX/REST inventory and evidence-based optimization plan.

= 2.1.0 =
* Conflict Profiler, Update Guard, two additional deterministic repair rules and manual rollback.

= 2.0.0 =
* Production repair engine, rollback, Safe Mode, benchmark and expanded WooCommerce/database diagnostics.


== External services ==

AI Repair is disabled by default. If the administrator explicitly enables it and supplies an API endpoint/key, only the selected PHP issue metadata and a limited source-code context window are sent to that configured provider to generate a repair proposal. WP FixPilot does not send source code for ordinary diagnostics or deterministic repairs.
