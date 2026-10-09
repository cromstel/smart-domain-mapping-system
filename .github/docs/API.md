# API contract

This document describes the plugin's externally callable surface: the REST API
and the `wp dm` WP-CLI commands. It is the reference for integrators and for the
test suite.

## Conventions

- **Base:** all REST routes live under the namespace `domain-mapping/v1` and are
  served from `/wp-json/domain-mapping/v1/...`.
- **Success responses** are **bare** objects or arrays — there is **no envelope**.
- **Error responses** always have the shape below and a suitable HTTP status:

  ```json
  {
    "code": "dm_domain_exists",
    "message": "That domain is already mapped.",
    "data": { "status": 409 }
  }
  ```

  Clients can distinguish success from error by the presence of `code`.
- **Authentication:** the REST API uses WordPress cookie authentication with the
  standard `X-WP-Nonce` header, or Application Passwords. Every route declares an
  explicit `permission_callback` (see *Permissions*).

## Permissions

| Callback | Rule |
| --- | --- |
| `can_manage_network` | Current user has `manage_network`. |
| `can_read_all` | Current user has `manage_network`. |
| `can_create` | `manage_network`, **or** the user may manage the target site (see below). |
| `can_access_mapping` | `manage_network`, or the user may manage the mapping's site. |
| `can_access_site` | `manage_network`, or the user may manage that site. |

"May manage the target site" means: the `dm_allow_site_admin_mapping` filter
returns `true` for that blog (default `false`) **and** the user holds
`manage_options` on that blog. Promoting a mapping to **primary** always requires
`manage_network`, because it rewrites `wp_blogs.domain`.

## Mapping object

```json
{
  "id": 7,
  "blog_id": 3,
  "domain": "example.org",
  "active": true,
  "verified": false,
  "primary": true,
  "created_at": "2026-01-01 12:00:00"
}
```

- `active` — whether the mapping is enabled.
- `verified` — whether the domain passed an out-of-band verification challenge.
- `primary` — whether this mapping is the site's `wp_blogs.domain`.

## Endpoints

### `GET /mappings`

Lists mappings (bare array).

| Param | Type | Notes |
| --- | --- | --- |
| `site_id` | integer | Filter to one blog. |
| `status` | string | One of `active`, `inactive`, `verified`, `unverified`. |

### `POST /mappings`

Creates a mapping. Responds `201` with the mapping object.

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `site_id` | integer | one of | Target blog ID. |
| `blog_id` | integer | one of | Alias for `site_id`. |
| `domain` | string | **yes** | Normalized (`https://`, trailing dot, case, wildcards). |
| `make_primary` | boolean | no | Requires `manage_network`. |

### `GET /mappings/{id}`

Returns a single mapping object.

### `PATCH /mappings/{id}` (also `PUT`)

| Param | Type | Notes |
| --- | --- | --- |
| `active` | boolean \| null | `null` leaves the flag unchanged. |
| `make_primary` | boolean | Requires `manage_network`. |

### `DELETE /mappings/{id}`

```json
{ "status": "ok", "removed": true, "id": 7 }
```

### `POST /mappings/{id}/verify`

Initiates or completes verification. Requires `manage_network`.

- **Without `token`** — generates a challenge and returns:

  ```json
  {
    "domain": "example.org",
    "token": "…",
    "method": "dns",
    "challenge_path": "_dm-verification.example.org"
  }
  ```

- **With `token`** — performs the external proof (HTTP fetch or DNS TXT lookup)
  and, on success, returns `{ "status": "ok", ... }` with `verified` set.

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `token` | string | — | Present ⇒ complete verification. |
| `method` | string | `dns` | `dns` or `http` (used when initiating). |

### `POST /mappings/{id}/set-primary`

Promotes the mapping: writes the domain to `wp_blogs.domain`. Requires
`manage_network`.

### `GET /sites/{site_id}/mappings`

Bare array of that site's mappings.

## AJAX bridge

The Network Admin screens talk to the same endpoints through `admin-ajax.php`
actions (`dm_rest_create_mapping`, `dm_rest_delete_mapping`,
`dm_rest_patch_mapping`, `dm_rest_set_primary`, `dm_rest_verify_mapping`,
`dm_rest_list_mappings`). These verify the `dm_nonce_action` nonce and require
`manage_network`. The forms also have a no-JS fallback that posts to
`network_admin_url( 'edit.php?action=dm_add_mapping' )`.

## State model

```
            add                     enable
   (none) -------> unverified  <-------------->  verified
                     active   ----------------   active
                        |         disable            ^
                        v                          |
                    inactive  ----------------------+
                                   enable
```

- New mappings default to **active** and **unverified**; verification is
  optional until a challenge is started.
- Once a verification challenge is **pending**, enabling the mapping is blocked
  (`dm_requires_verification`) until it is verified.
- Only one mapping per site can be **primary**; disabling a primary mapping
  restores the site's pre-mapping domain.
- Wildcard domains (`*.example.com`) can be mapped and verified but cannot be
  promoted to primary.

## WP-CLI

```
wp dm mapping add <domain> --site=<id> [--primary] [--porcelain]
wp dm mapping list [--site=<id>] [--format=table|json|csv]
wp dm mapping enable <id>
wp dm mapping disable <id>
wp dm mapping remove <id>
wp dm mapping verify <id> [--method=dns|http] [--token=<t>] [--force]
wp dm mapping set-primary <id>
wp dm cert issue <domain> [--site=<id>]
wp dm cert renew <domain> [--site=<id>]
wp dm audit tail [--site=<id>] [--limit=<n>]
wp dm migrate --source=<legacy|mercator>
```

Run `wp help dm` for the generated reference. WP-CLI commands run as trusted
shell access when no user is loaded; when run with `--user=` they require
`manage_network` (or super admin).
