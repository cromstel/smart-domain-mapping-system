# Security Policy

## Supported versions

Security fixes are provided for the latest released minor line.

| Version | Supported |
| ------- | --------- |
| 1.1.x   | ✅        |
| < 1.1   | ❌        |

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
discussions or pull requests.**

Instead, report them privately using GitHub's
[private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
form (repository **Security** tab → *Report a vulnerability*), or email
**security@cromstelit.com**.

Please include:

- Plugin version and WordPress / PHP versions.
- A description of the issue and its impact.
- Reproduction steps or a proof-of-concept (as minimal as possible).
- Any suggested remediation, if you have one.

## What to expect

- **Acknowledgement** within 3 business days.
- **Assessment** and a severity/impact decision within 10 business days.
- **Coordinated disclosure**: we will agree a disclosure timeline with you and
  credit you in the release notes unless you prefer to stay anonymous.

## Scope

In scope: the Smart Domain Mapping System plugin code (mapping engine, REST API,
WP-CLI commands, Network Admin screens, verification/SSL/audit logic) and its
build / release tooling in this repository.

Out of scope: vulnerabilities in WordPress core, the hosting environment, or
third-party services the site may use, and social-engineering or physical
attacks.

## Hardening notes for operators

- The plugin enforces capability checks (`manage_network`) plus nonce validation
  on every mutating admin/REST/AJAX path.
- Verification tokens are stored **only** as `HMAC-SHA256(token, AUTH_KEY)` —
  never in plain text — and proof is external (HTTP fetch or DNS TXT lookup).
- No telemetry or data is transmitted to the plugin author. All data stays in
  the site's own database.
