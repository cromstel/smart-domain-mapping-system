=== Smart Domain Mapping System ===
Contributors: CITGROUP
Tags: multisite, domain mapping, ssl, dns, single-site, rewrite, cli
Requires at least: 6.6
Requires PHP: 8.1
Tested up to: 6.6
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Multisite **and single-site** domain mapping with DNS verification, SSL lifecycle
tracking, audit logging, per-domain customization, global mapping scopes,
URL/query rewriting, and a modern REST API.

**Modes**
* **Multisite (Network)** — Domain mapping via `wp_sitemeta` (`dm_domain_{domain}` keys, Mercator
  style); verification, certificate and audit data in three custom tables
  (`dm_verifications`, `dm_certificates`, `dm_audit_log`).
* **Single-site** — Host/path mappings in `dm_mappings`, global object scopes in
  `dm_mapping_values`, per-domain customization (custom HTML, favicon, menu,
  language, 404), URL/query rewriting, alias/subdomain auth.

**How mapped domains resolve.** WordPress resolves the request domain before
plugins load (during `wp-includes/ms-settings.php`), and this plugin
deliberately does not use `sunrise.php`. In Multisite, promoting a mapping to
*primary* writes the domain into `wp_blogs.domain` via `wp_update_site()` —
the same mechanism as the Network Admin "Site Address" field — so core
resolves it natively. In single-site mode, a `mu-plugins` drop-in handles
request-time rewriting/redirects.

Site administrators can be granted management of their own site's mappings
via the `dm_allow_site_admin_mapping` filter (off by default).

**Key features**
* **Per-domain customization** — Custom head/body code (sanitized), favicon,
  menu, language/locale, custom 404 page.
* **Global mapping scopes** — Map archives, categories, parent pages,
  WooCommerce products to domains via Mapping Values.
* **URL rewriting & query strings** — Regex/wildcard path rewrites,
  query string add/remove/replace/set.
* **Alias & subdomain auth** — Cross-domain session sync, shared subdomain cookies.
* **Modern REST API** — `dms/v1` (single-site + unified) with pagination,
  batch ops, per-domain fields; `domain-mapping/v1` (multisite) unchanged.
* **WP-CLI** — Full CRUD, verification, SSL, audit, migration with `--dry-run`.
* **Granular capabilities** — `manage_dms_mappings`, `manage_dms_settings`,
  `manage_dms_rewrites`, `view_dms_logs`; filterable via `dms_capabilities`.
* **Integrations** — Yoast SEO, W3TC/WP Rocket/WP Super Cache, Polylang/WPML,
  Borlabs Cookie, MDM conflict detection (all runtime-guarded).
* **Audit logging** — Correlation IDs, JSON context, log rotation by age/size,
  export for compliance.
* **Rewrite engine** — `mu-plugins` drop-in, path rewrites (regex/wildcard),
  query string add/remove/replace/set, priority sorting, cache invalidation.

== Installation ==

**Multisite (Network)**
1. Upload the plugin folder to `/wp-content/plugins/`.
2. **Network activate** the plugin.
3. Visit **Network Admin > Domain Mapping** to add and manage mappings.

**Single-site**
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin (single-site auto-detected).
3. Visit **Settings > Domain Mappings** to add and manage mappings.

== Frequently Asked Questions ==

= Do I need sunrise.php? =
No. Primary mappings are synced to `wp_blogs.domain` (Multisite) or handled by
the `mu-plugins` rewrite engine (single-site), so core resolves them without
any early-loading hacks.

= What about alias (non-primary) domains? =
WordPress only resolves one domain per site (`wp_blogs.domain`). Alias
mappings are stored, verified and audited here; point them at the primary
domain at the hosting/DNS layer.

= Single-site mode? =
Activate on a standard (non-multisite) WordPress install. A new "Domain
Mappings" menu appears under Settings. Map extra domains to your site with
full per-domain customization and global scopes.

= WP-CLI =
`wp dm mapping add|list|enable|disable|remove|verify|set-primary`,
`wp dm cert issue|renew`, `wp dm audit tail`, `wp dm migrate`.
All mapping commands support `--dry-run` for safe preview.
Run `wp help dm` for details.

= Per-domain customization? =
Each mapped domain can have:
* Custom head/body HTML (sanitized via strict allow-list)
* Favicon (from Media Library)
* Custom nav menu
* Locale/language (Polylang/WPML integration)
* Custom 404 page (Post ID or URL)
* URL rewrite rules (regex/wildcard, redirect or internal)
* Query string rules (add/remove/replace/set)

= Global mapping scopes? =
Map content to domains via Mapping Values:
* Archives (post type archives)
* Categories / Tags
* Parent pages (incl. all children)
* WooCommerce products
* Custom post types

= URL rewriting & query strings? =
Rewrite rules support regex/wildcard with redirect or internal rewrite.
Query string rules: add/remove/replace/set. Priority ordering prevents conflicts.

= Alias / Subdomain auth? =
* `dms_subdomain_auth_enabled` — opt-in: share one auth cookie across a shared
  parent domain (e.g. `.example.com`).
* `dms_alias_auth_enabled` — **retired in 1.2.0**; flipping it now only raises a
  `_doing_it_wrong()` notice. It never worked (a browser rejects any cookie whose
  `Domain` is not a suffix of the host serving the response), and its accept path
  was deleted rather than repaired because making the signed payload
  self-consistent would have created a session-forgery primitive. To stay signed
  in across related domains, use `dms_subdomain_auth_enabled` with a shared parent
  domain.

= WP-CLI --dry-run? =
All mapping commands support `--dry-run` to preview changes safely:
`wp dm mapping add example.org --site=3 --dry-run`

= Integrations? =
Runtime-guarded (no hard deps):
* Yoast SEO — canonical/OG/Twitter URLs, sitemap inclusion
* Caching — W3TC, WP Rocket, WP Super Cache purge on mapping changes
* Polylang/WPML — language per domain, hreflang
* Borlabs Cookie — cookie domain & allowed domains
* MDM — detects other domain mapping plugins, defers gracefully

= WP-CLI =
`wp dm mapping add|list|enable|disable|remove|verify|set-primary` (all with `--dry-run`),
`wp dm cert issue|renew`, `wp dm audit tail`, `wp dm migrate`.
Run `wp help dm` for details.

= What are the minimum requirements? =
WordPress 6.6+, PHP 8.1+. Multisite or single-site.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. **Multisite:** Network activate. **Single-site:** Activate normally.
3. **Multisite:** Network Admin > Domain Mapping. **Single-site:** Settings > Domain Mappings.

== Frequently Asked Questions ==

= Do I need sunrise.php? =
No. Primary mappings are synced to `wp_blogs.domain` (Multisite) or handled by
the `mu-plugins` rewrite engine (single-site), so core resolves them without
any early-loading hacks.

= What about alias (non-primary) domains? =
WordPress only resolves one domain per site (`wp_blogs.domain`). Alias
mappings are stored, verified and audited here; point them at the primary
domain at the hosting/DNS layer.

= WP-CLI =
`wp dm mapping add|list|enable|disable|remove|verify|set-primary` (all with `--dry-run`),
`wp dm cert issue|renew`, `wp dm audit tail`, `wp dm migrate`.
Run `wp help dm` for details.

= Privacy =
All plugin data stays in your own database and is never transmitted to CITGROUP
or any third party:

* Domain mappings: `wp_sitemeta` (`dm_domain_{domain}` keys, Multisite) or `dm_mappings` (single-site).
* Mapping values (global scopes): `dm_mapping_values`.
* Verifications, certificates and audit entries: the `dm_verifications`,
  `dm_certificates` and `dm_audit_log` tables.

Audit entries are pruned automatically according to the retention window set in
*Network Admin > Domain Mapping* (default 180 days; `0` keeps them forever).

The only outbound network requests the plugin makes are the DNS/HTTP lookups
used to verify a domain you explicitly asked it to verify, plus any certificate
issuance you trigger through a configured SSL provider.

== Developer notes ==
Unit tests use the standard WordPress test suite: set `WP_TESTS_DIR` (and
run with `WP_MULTISITE=1`), then `vendor/bin/phpunit -c phpunit.xml.dist`.
`bin/install-wp-tests.sh` fetches the library in CI.

Static checks: `composer lint` (PHP_CodeSniffer with WordPress and
PHPCompatibility rulesets), `composer static-analysis` (PHPStan Level 5 +
Psalm Level 3 with WordPress plugins), `php -l`. CI runs the coding standards,
the PHPUnit suite (MySQL), `php -l` on PHP 8.1-8.3, `node --check`, CodeQL and
a secret scan on every push.

== Changelog ==

= 1.2.0 =
* **Feature:** Single-site domain mapping mode alongside existing Multisite core (dual-mode architecture).
* **Feature:** Per-domain customization — custom head/body code (XSS-safe), favicon, menu, language, 404 handling. Single-site only: the multisite engine stays authoritative for its own mappings.
* **Feature:** Global mapping scopes — archives, categories, parent pages, WooCommerce products via Mapping Values.
* **Feature:** URL rewriting & query string rules — regex/wildcard path rewrites, query string add/remove/replace/set, priority sorting, preloading on save, incremental cache invalidation.
* **Feature:** Subdomain authentication — one shared auth cookie across a parent domain, opt-in via `dms_subdomain_auth_enabled`.
* **Feature:** Modern REST API — the `dms/v1` namespace (mappings, mapping values, batch, pagination, per-domain fields) alongside `domain-mapping/v1`.
* **Feature:** Granular capabilities — `manage_dms_mappings`, `manage_dms_settings`, `manage_dms_rewrites`, `view_dms_logs`; filterable via `dms_capabilities`.
* **Feature:** Integrations — Yoast SEO, W3TC/WP Rocket/WP Super Cache, Polylang/WPML, Borlabs Cookie, MDM (all runtime-guarded, each disableable via `dms_integration_{slug}_enabled`).
* **Feature:** CLI `--dry-run` flag for all mapping commands (`add`, `enable`, `disable`, `remove`, `set-primary`).
* **Feature:** Structured audit logging — correlation IDs, JSON context, log rotation by age/size, export.
* **Enhancement:** Denormalized `host` column in `dm_mapping_values` with backfill, plus composite indexes `(host, active)` and `(host, verified)` on `dm_mappings` (Migration 4).
* **Enhancement:** WordPress 6.6 minimum; PHP 8.1 minimum.
* **Removal:** Alias auth (`dms_alias_auth_enabled`). Its cross-domain cookie could never be stored by a browser, and repairing the signed payload would have turned the reader into a session-forgery primitive, so the accept path was deleted rather than patched. Use `dms_subdomain_auth_enabled` with a shared parent domain.
* **Fixed:** Alias-domain redirects no longer loop (structural cycle detection) or fall back to `wp-admin` on an off-site target; per-domain REST fields refuse multisite instead of writing the single-site table; the `dms.` audit-key namespace is consistent at every call site (rows written before this release keep their older keys); and log rotation now honours the configured retention window, which previously only the manual purge respected.
* **Docs:** Hooks reference for filters and lifecycle actions (see `README.md`).
* **Testing:** PHPStan Level 5 + Psalm Level 3 with WordPress extensions in CI; `composer static-analysis` + `composer audit`.
* **Packaging:** Full GPL-2.0 license text, generated `.pot` translation template.

= 1.1.1 =
* Restructure: plugin source moved to repo root; `includes/` split by feature; new class-map autoloader; docs/community files relocated; build script uses tracked-file packaging.
* Version: 1.1.1; Stable tag: 1.1.1.

= 1.1.0 =
* Feature: configurable audit-log retention (default 180 days), pruned by the daily health check; `0` keeps entries forever.
* Accessibility: table captions and column `scope` attributes, and a polite live region for status messages.
* Developer: all admin JavaScript strings are now translatable (passed to the script as `dmAdmin.i18n`).
* Developer: PHP_CodeSniffer (WordPress + PHPCompatibility) and the PHPUnit suite now run in CI, alongside CodeQL and secret scanning.
* Packaging: ships the full GPL-2.0 license text and a generated `.pot` translation template.

= 1.0.0 =
* Initial release. Core mapping engine using wp_sitemeta, 3 custom tables for verifications, SSL, and logs, REST API (domain-mapping/v1), WP-CLI `dm` commands, Network Admin UI, audit logging, migrations and uninstall cleanup.