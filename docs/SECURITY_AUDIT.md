# Security & Anti-Pattern Audit — Smart Domain Mapping System

Target: WordPress 6.4+ / PHP 8.1+, mappings in `wp_sitemeta` (Mercator style).

## Security Triad (skill rules)

1. Sanitize input on the way in
   - Domains: single normalizer `DMS_Mapping_Engine::normalize_domain()`
     (lowercase, strip protocol/www, strict label-pattern regex, ≤253 chars)
     used by REST validate/sanitize callbacks, CLI, migration and storage —
     no divergent copies.
   - IDs: `absint()` everywhere (site IDs, mapping/meta IDs, user IDs).
   - Tables: `sanitize_key()` for enum-ish fields; `sanitize_text_field()` /
     `sanitize_textarea_field()` for free text (logs, errors).
   - REST args declare `sanitize_callback` per arg; AJAX bridge re-sanitizes
     manually because it bypasses the REST server.

2. Escape output on the way out
   - Admin views: `esc_html()` / `esc_html__()` / `esc_attr()` throughout
     (mapping table, settings form, logs table).
   - Redirect URLs pass through `esc_url_raw()` before `wp_redirect()`.
   - Challenge body echoes `esc_html( $token )` (alphanumeric tokens are
     unchanged by entity encoding).

3. Authorize every action
   - REST: every endpoint declares a `permission_callback` (writes and global
     reads: `manage_network`; site-scoped reads/writes: super admin or the
     `dm_allow_site_admin_mapping` filter **plus** `manage_options` on the
     target site — a site admin can never touch another site's mappings).
     Primary-domain promotion and verification are super-admin only.
   - Admin AJAX: `check_ajax_referer( 'dm_nonce_action' )` + `manage_network`.
   - Settings form: `check_admin_referer( 'dm_group-options' )`.
   - Add-mapping fallback form: `check_admin_referer( 'dm_mapping', 'dm_nonce' )`.
   - CLI: enforced when a user context exists (`--user=`); a bare shell with
     no user is treated as trusted (documented in `class-cli.php`).

## Anti-Patterns Check (skill rules)

- No `eval` / `extract` / `create_function` — PASS.
- No raw `$_REQUEST`; `$_POST`/`$_SERVER` routed through `wp_unslash()` +
  sanitize — PASS.
- No hardcoded table prefixes: all custom tables use
  `$wpdb->base_prefix . DMS_TABLE_*` (network-wide, correct for multisite;
  `$wpdb->prefix` is per-site and would break on subsite contexts) — PASS.
- Every SQL value goes through `$wpdb->prepare()` with literal `%d`/`%s`
  formats; identifiers are constants or `base_prefix`-derived — PASS.
  (`list_mappings()` builds its WHERE clause with matching placeholders for
  every parameter — no placeholder-less prepare, no LIKE-without-parameter.)
- No `flush_rewrite_rules()` on load — PASS.
- Nonces on all state-changing forms/AJAX; capabilities checked server-side
  (UI hiding is never the only gate) — PASS.

## Specific protections

- **Token storage**: verification tokens exist only as
  `HMAC-SHA256(token, AUTH_KEY)`; the plaintext is shown once to the caller
  and never stored — a DB read cannot forge a verification.
- **Verification is an external proof**: completion fetches the challenge
  URL (HTTP) or reads the TXT record (DNS) and compares against the hash;
  knowing the token alone does not verify.
- **Scoped meta operations**: get/update/delete by meta ID are constrained
  with `meta_key LIKE 'dm\_domain\_%'` + `site_id`, so an ID from an
  unrelated `wp_sitemeta` row can never be read or mutated.
- **Idempotence/atomicity**: add rolls back the sitemeta row if
  primary-promotion fails; primary swap runs in a transaction and preserves
  the original domain for restore on disable/remove.
- **No sunrise.php / no early-load hacks**: resolution goes through core's
  `wp_blogs.domain` (see the mapping engine class docblock), so there is no
  un-sunsetted pre-plugin interception code to secure.

## Residual notes

- `wp_sitemeta` is network-level: on a network with multiple networks, all
  queries scope `site_id = get_current_network_id()`.
- The plugin stores state under `dm_domain_`, `dm_active_`, `dm_original_`
  key prefixes and `dm_options` / `dm_version` options; `uninstall.php`
  removes all of them plus the three tables.
