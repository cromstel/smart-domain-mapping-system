domain-mapping-system/
├── domain-mapping-system.php   # header + constants + requires + activation/deactivation + bootstrap
├── uninstall.php               # drops tables, dm_domain_*/dm_active_*/dm_original_* sitemeta, options
├── readme.txt
├── SECURITY_AUDIT.md
├── phpunit.xml.dist            # PHPUnit config (WP test suite, WP_MULTISITE=1)
├── .github/workflows/ci.yml    # php -l (PHP 8.1–8.3) + node --check lint matrix
├── includes/
│   ├── class-domain-mapping-system.php   # DMS_Plugin singleton (wires engine + settings on init)
│   ├── class-db-tables.php               # dbDelta for 3 tables (base_prefix)
│   ├── class-mapping-engine.php          # service layer: sitemeta CRUD, wp_blogs sync, state, cache, wildcard
│   ├── class-dns-verification.php        # challenge generation + external proof verification
│   ├── class-ssl-manager.php             # certificate metadata CRUD
│   ├── class-ssl-provider.php            # provider interface + none/ACME + dm_ssl_provider factory
│   ├── class-logging.php                 # dm_audit_log writer
│   ├── class-rest-api.php                # domain-mapping/v1 routes + admin-ajax bridge
│   ├── class-cli.php                     # wp dm commands (spec contract)
│   ├── class-settings.php                # network menu, settings, no-JS form handlers
│   ├── class-migration.php               # legacy table + Mercator migrations
│   └── class-health-check.php            # daily DNS/cert health cron (dm_daily_health_check)
├── admin/
│   ├── css/
│   ├── js/
│   │   └── admin.js                      # AJAX add/delete/toggle/set-primary/verify
│   └── views/
│       ├── network-mappings.php          # mappings screen (status, primary, actions)
│       ├── settings.php                  # settings screen form
│       └── logs.php                      # audit log table
├── public/
│   └── redirect-helpers.php              # canonical redirect to primary mapped domain (template_redirect)
└── tests/
    ├── bootstrap.php                     # loads plugin into WP test suite
    ├── test-sample.php                   # normalization + state-machine tests
    └── test-db-tables.php                # schema tests (base_prefix)
