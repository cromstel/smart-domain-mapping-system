# Contributing

Thanks for your interest in Smart Domain Mapping System. This project is a
WordPress **multisite** plugin (WordPress 6.4+, PHP 8.1+) — read
[`.github/docs/API.md`](docs/API.md) before changing the REST API or WP-CLI
surface.

## Ground rules

- Every change must keep the plugin working on **PHP 8.1+** and **WordPress 6.4+**.
- No root-level documentation files other than `README.md` (docs live in
  `.github/`). The plugin changelog lives in
  `smart-domain-mapping-system/readme.txt`.
- Security first: capability checks, nonces and sanitization/escaping on every
  path. See [`.github/SECURITY.md`](SECURITY.md).

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
php -l smart-domain-mapping-system/includes/*.php
node --check smart-domain-mapping-system/admin/js/admin.js
```

### Tests

The PHPUnit suite runs against the official WordPress test library
(`WP_TESTS_DIR`) with multisite enabled:

```bash
# One-time: fetch the WP test library (see wp-cli scaffold or wordpress-develop).
export WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit
export WP_MULTISITE=1

composer test           # == vendor/bin/phpunit -c smart-domain-mapping-system/phpunit.xml.dist
```

CI runs the same suite on MySQL (`ci.yml`) so you get a second opinion on every
push.

## Coding standards

- WordPress Coding Standards, enforced by the ruleset in `.phpcs.xml.dist`.
- All user-facing strings are translatable with the `domain-mapping-system`
  text domain; JS strings are passed from PHP via `dmAdmin.i18n`.
- Prefix everything: PHP classes `DMS_`, functions/options/actions `dm_`,
  tables `dm_`.

## Packaging

```bash
pwsh bin/build-zip.ps1      # -> smart-domain-mapping-system-<version>.zip
```

The ZIP root is the plugin slug; dev-only files (`.github/`, `tests/`,
`phpunit.xml.dist`, `SECURITY_AUDIT.md`) are excluded. CI uses the same script.

## Releasing

Version numbers are single-sourced from the plugin header, then propagated:

```bash
pwsh bin/bump-version.ps1 1.2.0   # header, DMS_VERSION, readme Stable tag, changelog
# edit the new changelog entry in smart-domain-mapping-system/readme.txt
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
GPL-2.0-or-later (see `smart-domain-mapping-system/LICENSE.txt`).
