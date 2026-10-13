=== Smart Domain Mapping System ===

= 1.2.0-rc1 =
* Feature: add single-site domain-mapping mode alongside existing Multisite core (dual-mode architecture).
* New tables: `dm_mappings` (host/path keyed) and `dm_mapping_values` (global object scopes).
* REST API: `dms/v1` namespace with mappings CRUD + mapping values + batch, mode-aware (multisite delegates to existing engine).
* Core classes: `DMS_Mode`, `DMS_Single_Site_Mappings`, `DMS_Mapping_Values`.
* Admin: single-site mappings page (list/add/delete) with nonce-guarded forms.
* Security: wp_kses allow-list on custom_html; all forms nonce + capability gated.
* Architecture: mode auto-detected via `is_multisite()`; no config switch.
* Tests: migration round-trip, `dms/v1` contract, per-mode CRUD, guard behavior.

= 1.1.1 =
* Restructure: plugin moved to repo root; includes split by feature (core/, verification/, ssl/, admin/, rest/, cli/); class-map autoloader (`dms_autoload`); docs/community files relocated; ZIP built from tracked files only; CI/release/build/bump scripts rewired to new layout.
* Version: 1.1.1; Stable tag: 1.1.1.

= 1.1.0 =
* Audit retention (default 180 days, clamp 0–3650); settings + health-check updates; admin JS i18n (`dmAdmin.i18n`); accessibility improvements (captions, live regions, `:focus-visible`); SSL providers split into interface/none/ACME/providers registry; new settings retention tests.
* CI hardening: PHPCS exclusions with rationale; PHPUnit tests (`tests/test-settings.php`); gitleaks secret scan; release workflow with POT generation (`wp i18n make-pot`).

= 1.0.0 =
* Initial release: domain mapping engine (`DMS_Mapping_Engine`), REST API (`DMS_REST_API`), WP-CLI (`DMS_CLI_Command`), audit logging (`DMS_Logging`), health checks (`DMS_Health_Check`), SSL tracking (`DMS_SSL_Manager` + providers), DNS verification (`DMS_DNS_Verification`), settings/admin (`DMS_Settings`), migration (`DMS_Migration`), DB tables (`DMS_DB_Tables`).
