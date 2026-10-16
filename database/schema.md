# DATABASE SCHEMA — smart-domain-mapping-system

Tables created by `DMS_DB_Tables::create_tables()` (`includes/core/class-db-tables.php`) via `dbDelta()`.

## `dm_verifications`
- `id` bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
- `blog_id` bigint(20) unsigned NOT NULL
- `domain` varchar(255) NOT NULL
- `method` varchar(20) DEFAULT 'dns' (dns | http)
- `token_hash` varchar(128) NOT NULL
- `challenge_url` text DEFAULT NULL
- `challenge_content` text DEFAULT NULL
- `status` varchar(20) DEFAULT 'pending' (pending | verified | failed)
- `verified_at` datetime DEFAULT NULL
- `created_at` datetime DEFAULT CURRENT_TIMESTAMP
- `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
- `expires_at` datetime DEFAULT NULL
- `attempts` int DEFAULT 0
- `last_error` text DEFAULT NULL
- INDEX: `blog_id` (blog_id), `domain` (domain), `status` (status)

## `dm_certificates`
- `id` bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
- `blog_id` bigint(20) unsigned NOT NULL
- `domain` varchar(255) NOT NULL
- `provider` varchar(20) DEFAULT 'none' (none | acme)
- `status` varchar(20) DEFAULT 'none' (none | issued | expired | revoked)
- `issued_at` datetime DEFAULT NULL
- `expires_at` datetime DEFAULT NULL
- `last_renewal_attempt` datetime DEFAULT NULL
- `last_renewal_result` varchar(20) DEFAULT NULL
- `last_error` text DEFAULT NULL
- `created_at` datetime DEFAULT CURRENT_TIMESTAMP
- `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
- INDEX: `blog_id` (blog_id), `domain` (domain), `provider` (provider), `status` (status)

## `dm_audit_log`
- `id` bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
- `blog_id` bigint(20) unsigned DEFAULT NULL
- `user_id` bigint(20) unsigned DEFAULT NULL
- `domain` varchar(255) DEFAULT NULL
- `action` varchar(64) NOT NULL (mapping_create | mapping_update | mapping_delete | mapping_enable | mapping_disable | mapping_set_primary | verify_request | verify_success | verify_failure | cert_issue_request | cert_renew_request | cert_renew_success | cert_renew_failure | audit_purged | dms.mapping.create | dms.mapping.update | dms.mapping.delete)
- `context` text DEFAULT NULL (JSON string of relevant state)
- `correlation_id` varchar(36) DEFAULT NULL (UUID v4 joining the rows of one request/operation; added in migration 5)
- `created_at` datetime DEFAULT CURRENT_TIMESTAMP
- INDEX: `blog_id` (blog_id), `domain` (domain), `user_id` (user_id), `correlation_id` (correlation_id)

## `dm_mappings` *(added in migration 2 — single-site mode / `dms/v1`; extended in migration 3 — Phase 2 per-domain meta)*
- `id` bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
- `host` varchar(255) NOT NULL (normalized lower-case domain)
- `path` varchar(255) NOT NULL DEFAULT '' (subdirectory; '' = root)
- `attachment_id` bigint(20) unsigned DEFAULT NULL (per-domain favicon / reference image)
- `custom_html` longtext NULL (per-domain head/body code, wp_kses allow-listed — scripts are NOT stored)
- `menu_id` bigint(20) unsigned DEFAULT NULL (per-domain nav menu; `wp_get_nav_menu_object()`)
- `language` varchar(10) DEFAULT NULL (ISO 639-1 or locale, e.g., `en`, `en_US`)
- `redirect_404` text DEFAULT NULL (post ID or absolute URL for custom 404 target)
- `rewrite_rules` json DEFAULT NULL (array of rewrite rule objects: `{source, target, regex?, redirect?}`)
- `query_string_rules` json DEFAULT NULL (array of query string rule objects: `{action, key, value?}`)
- `active` tinyint(1) NOT NULL DEFAULT 1
- `verified` tinyint(1) NOT NULL DEFAULT 0
- `primary` tinyint(1) NOT NULL DEFAULT 0 (backticked: reserved word)
- `created_at` datetime DEFAULT CURRENT_TIMESTAMP
- `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
- UNIQUE KEY: `host_path` (host, path); INDEX: `active` (active), `verified` (verified)

## `dm_mapping_values` *(added in migration 2 — global object scopes)*
- `id` bigint(20) unsigned AUTO_INCREMENT PRIMARY KEY
- `mapping_id` bigint(20) unsigned NOT NULL (FK to `dm_mappings.id`, enforced in code)
- `object_type` varchar(32) NOT NULL (post | page | category | tag | archive | product | cpt)
- `object_id` bigint(20) unsigned NOT NULL
- `primary` tinyint(1) NOT NULL DEFAULT 0 (backticked: reserved word)
- `created_at` datetime DEFAULT CURRENT_TIMESTAMP
- UNIQUE KEY: `mapping_object` (mapping_id, object_type, object_id); INDEX: `object` (object_type, object_id), `mapping_id` (mapping_id)

## Schema rules
- All tables use `$wpdb->base_prefix` (multisite-aware).
- `DMS_TABLE_VERIFICATIONS`, `DMS_TABLE_SSL`, `DMS_TABLE_LOGS`, `DMS_TABLE_MAPPINGS`, `DMS_TABLE_VALUES` constants define table names.
- The schema version is tracked independently of the release version: `version_compare( get_site_option('dm_db_version'), DMS_DB_VERSION )` gates `dbDelta()` runs; `dm_version` remains the plugin-version marker.
- Uninstall (`uninstall.php`) drops tables and deletes `dm_*` metadata from `wp_sitemeta`.
