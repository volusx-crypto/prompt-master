=== Plugin Harmony — هماهنگ‌ساز افزونه‌ها ===
Contributors: plugin-harmony
Tags: plugin settings, site health, seo, cache, configuration, persian
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Analyzes the site, detects installed plugins, and aligns their settings with each other and with what the site actually needs. When a new plugin is activated it is reviewed and configured to match the rest of the stack.

== Description ==

WordPress has no standard API that exposes "the settings of every plugin", and every plugin stores its options in its own shape. Plugin Harmony therefore works in three layers:

1. **Automatic writing** for plugins whose option schema is part of the built-in knowledge base (WordPress core, Yoast SEO, WooCommerce, UpdraftPlus, Smush, WP Rocket, Autoptimize) — and only for option keys that already exist on the site.
2. **Manual advice** for plugins where a wrong write is dangerous or schema-unstable (Wordfence, Solid Security, LiteSpeed Cache, WPML, Polylang, SMTP plugins, page builders, analytics).
3. **Custom rules** so any other plugin can be covered from the admin screen without writing code.

### What it actually does

* Builds a site profile: WordPress and PHP version, locale, HTTPS, pretty permalinks, object cache, server stack, WooCommerce presence, content volume.
* Detects active plugins by plugin basename **and** by class / constant / option, so renamed folders do not break detection.
* Maps capabilities (sitemap, page cache, image optimization, firewall, backup, 2FA, redirects, SMTP, analytics, multilingual, maintenance, page builder) and picks one primary owner per capability.
* Reports real conflicts: two SEO plugins, two cache plugins, two firewalls, duplicated analytics tags, two page builders.
* Produces a change plan, applies it safely and keeps a snapshot of every touched option so one click rolls the batch back.
* On `activated_plugin`, re-scans on the next admin request and aligns the new plugin with the rest of the stack.
* Daily cron scan repairs drift introduced by plugin updates.

### Safety model

* Only options that already exist can be written; unknown keys are skipped, never created.
* A hard block list protects `active_plugins`, `siteurl`, `home`, `permalink_structure`, `users_can_register`, `default_role`, SMTP credentials and similar.
* Every batch stores the previous value of each whole option before writing, and verifies the stored value afterwards. If a plugin's sanitizer rejects the value, the log says "rejected by plugin" instead of pretending success.
* High-risk rules are never applied automatically — not even in the most permissive mode.
* Default mode is **suggest only**: nothing is written until you press the button.

== Installation ==

Install it like any other plugin — there is nothing to configure by hand.

= From the Plugins screen (recommended) =
1. Go to **Plugins → Add New Plugin → Upload Plugin**.
2. Choose `plugin-harmony.zip` and press **Install Now**.
3. Press **Activate**. The menu item **هماهنگ‌ساز افزونه‌ها** appears in the sidebar.
4. Open it, review the plan, then either apply selected items or switch the mode to automatic.

= Manually =
1. Upload the `plugin-harmony` folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.

= WP-CLI =
`wp plugin install plugin-harmony.zip --activate`

== Performance ==

The plugin is built to stay invisible on the front end:

* **Zero cost for visitors.** On a front-end request the plugin registers no hooks, runs no query and loads only two small files. It returns before any planning, detection or registry code is even loaded.
* **Lazy class loading.** Every class is autoloaded on demand — the planner, the admin UI, the recipe library and the applier are never parsed unless they are used.
* **Plan is built once per request** and memoised; a second call in the same request costs nothing.
* **Cached reads.** The site profile is cached for 5 minutes, plugin display names for a day.
* **No autoloaded bloat.** The change log, the snapshots and the scan bookkeeping are stored with `autoload = no`, so they are never read on normal page loads.
* **Bounded storage.** 100 log batches, at most 300 rows per batch, and the newest 20 snapshots.
* **No front-end assets.** CSS/JS are enqueued only on the plugin's own admin screen.
* **One cron event per day.** Nothing else is scheduled.

== Frequently Asked Questions ==

= Does it disable or deactivate other plugins? =
No. It reports overlaps and can turn off a duplicated capability inside a plugin's own settings (when that key is safe to write). Deactivating plugins stays a human decision.

= Can it configure a plugin it does not know? =
Yes, through the "قواعد سفارشی" tab: pick the plugin, enter the option name, the key path and the value. The rule only fires if the option and key already exist.

= What happens on rollback? =
The stored snapshot of every option in that batch is written back, and the batch is flagged as undone in the history.

== Changelog ==

= 1.1.0 =
* Performance: front-end requests now register zero hooks and run zero queries; classes are autoloaded on demand, the plan is memoised per request, and the profile / plugin-name lookups are cached.
* Performance: bookkeeping options are stored with `autoload = no` and the log is bounded (100 batches × 300 rows).
* Packaging: `build.sh` now verifies the zip (single folder, valid header, no BOM, no dev files) so the package always installs from **Plugins → Add New → Upload Plugin**.
* Guarded against PHP < 7.4 instead of throwing a fatal error.

= 1.0.0 =
* First release: profiling, detection, capability map, conflict report, safe applier with snapshots and rollback, activation hook, daily scan, WP-CLI, custom rules.
