# Contributing

Thanks for your interest in Smart Domain Mapping System. This project is a
WordPress **multisite** plugin (WordPress 6.4+, PHP 8.1+) — read
[`docs/API.md`](docs/API.md) before changing the REST API or WP-CLI
surface.

## Ground rules

- Every change must keep the plugin working on **PHP 8.1+** and **WordPress 6.4+**.
- The plugin source lives at the repository root; documentation lives in `docs/`
  and the changelog lives in `readme.txt`.
- Security first: capability checks, nonces and sanitization/escaping on every
  path. See [`SECURITY.md`](SECURITY.md).

## Repository layout

```
domain-mapping-system.php     plugin header + class autoloader
uninstall.php                 uninstall handler
readme.txt                    WordPress.org readme (changelog lives here)
LICENSE.txt                   GPL-2.0
includes/                     PHP classes, grouped by feature
  core/                       bootstrap (DMS_Plugin), DB, engine, logging, migration
  verification/               DNS verification, health check
  ssl/                        SSL manager + provider implementations
  admin/                      network-admin settings screen
  rest/                       REST API controller
  cli/                        WP-CLI (`wp dm`) commands
admin/                        admin UI assets (css/, js/, views/)
public/                       front-end redirect helpers
languages/                    translation template (.pot, generated in CI)
tests/                        PHPUnit suite
bin/                          build/version/asset scripts
docs/                         API + security-audit documentation
assets/                       WordPress.org banner + icon art
```

## Local setup

```bash
git clone https://github.com/cromstel/smart-domain-mapping-system.git
cd smart-domain-mapping-system

composer install        # PHP_CodeSniffer (WPCS/PHPCompatibility) + PHPUnit
```

### Static analysis

```bash
composer lint           # PHP_CodeSniffer: WordPress-Extra + PHPCompatibilityWP
composer lint:fix       # auto-fix what can be fixed
find includes -name '*.php' -print0 | xargs -0 -n1 php -l
node --check admin/js/admin.js
```

### Tests

The PHPUnit suite runs against the official WordPress test library
(`WP_TESTS_DIR`) with multisite enabled:

```bash
# One-time: fetch the WP test library (see wp-cli scaffold or wordpress-develop).
export WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit
export WP_MULTISITE=1

composer test           # == vendor/bin/phpunit -c phpunit.xml.dist
```

CI runs the same suite on MySQL (`ci.yml`) so you get a second opinion on every
push.

## Coding standards

- WordPress Coding Standards, enforced by the ruleset in `.phpcs.xml.dist`.
- All user-facing strings are translatable with the `domain-mapping-system`
  text domain; JS strings are passed from PHP via `dmAdmin.i18n`.
- Prefix everything: PHP classes `DMS_`, functions/options/actions `dm_`,
  tables `dm_`.
- New classes go in the matching `includes/` feature folder and are registered in
  the class map in `domain-mapping-system.php` (the self-registering WP-CLI
  command is loaded eagerly instead).

## Packaging

```bash
pwsh bin/build-zip.ps1      # -> smart-domain-mapping-system-<version>.zip
```

The ZIP root is the plugin slug; the plugin source sits at the repo root and
dev-only files (`.github/`, `bin/`, `docs/`, `tests/`, `assets/`, `composer.*`,
`phpunit.xml.dist`, `.phpcs.xml.dist` and the Markdown docs) are excluded. CI
uses the same script.

## Releasing

Version numbers are single-sourced from the plugin header, then propagated:

```bash
pwsh bin/bump-version.ps1 1.2.0   # header, DMS_VERSION, readme Stable tag, changelog
# edit the new changelog entry in readme.txt
git commit -am "chore: release 1.2.0"
git tag -a v1.2.0 -m "Smart Domain Mapping System 1.2.0"
git push origin main --follow-tags
```

Pushing the `v*` tag triggers `.github/workflows/release.yml`, which lints,
builds the production ZIP (and regenerates the `.pot`), and attaches both to a
GitHub Release.

## Pull requests

1. Branch from `main`.
2. Keep commits focused; use [Conventional Commits](https://www.conventionalcommits.org/)
   (`feat:`, `fix:`, `chore:`, `ci:`, `docs:` …).
3. Make sure `composer lint` and `composer test` pass locally and CI is green.
4. Describe the *why*, not just the *what*.

## License

By contributing you agree that your contributions are licensed under
GPL-2.0-or-later (see `LICENSE.txt`).
