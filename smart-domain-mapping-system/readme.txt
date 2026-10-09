=== Smart Domain Mapping System ===
Contributors: CITGROUP
Tags: multisite, domain mapping, ssl, dns
Requires at least: 6.4
Requires PHP: 8.1
Tested up to: 6.6
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Multisite domain mapping via wp_sitemeta with DNS verification, SSL lifecycle
tracking, and audit logging.

Mappings are stored in `wp_sitemeta` (`dm_domain_{domain}` keys, Mercator
style); verification, certificate and audit data live in three custom tables
(`dm_verifications`, `dm_certificates`, `dm_audit_log`).

**How mapped domains resolve.** WordPress resolves the request domain before
plugins load (during `wp-includes/ms-settings.php`), and this plugin
deliberately does not use `sunrise.php`. Instead, promoting a mapping to
*primary* writes the domain into `wp_blogs.domain` via `wp_update_site()` —
the same mechanism as the Network Admin "Site Address" field — so core
resolves it natively. Additional (non-primary) mappings are managed and
verified data; serving them from the web server layer (parked domain /
rewrite to the primary domain) is left to the hosting environment.

Site administrators can be granted management of their own site's mappings
via the `dm_allow_site_admin_mapping` filter (off by default).

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/.
2. Network activate the plugin.
3. Visit Network Admin > Domain Mapping to add and manage mappings.

== Frequently Asked Questions ==

= Do I need sunrise.php? =
No. Primary mappings are synced to `wp_blogs.domain`, which core resolves
without any early-loading hacks.

= What about alias (non-primary) domains? =
WordPress only resolves one domain per site (`wp_blogs.domain`). Alias
mappings are stored, verified and audited here; point them at the primary
domain at the hosting/DNS layer.

= WP-CLI =
`wp dm mapping add|list|enable|disable|remove|verify|set-primary`,
`wp dm cert issue|renew`, `wp dm audit tail`, `wp dm migrate`.
Run `wp help dm` for details.

== Developer notes ==
Unit tests use the standard WordPress test suite: set `WP_TESTS_DIR` (and
run with `WP_MULTISITE=1`), then `vendor/bin/phpunit -c phpunit.xml.dist`.
Static checks run in CI (`php -l` on PHP 8.1-8.3 plus `node --check`).

== Changelog ==
= 1.0.0 =
* Initial release. Core mapping engine using wp_sitemeta, 3 custom tables for verifications, SSL, and logs, REST API (domain-mapping/v1), WP-CLI `dm` commands, Network Admin UI, audit logging, migrations and uninstall cleanup.
