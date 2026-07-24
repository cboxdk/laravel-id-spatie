# Cbox ID for Spatie Permission

**`cboxdk/laravel-id-spatie`** — the adapter that lets an app which already uses
[spatie/laravel-permission](https://github.com/spatie/laravel-permission) adopt Cbox
ID for authentication, SSO/SAML, the OAuth/OIDC provider, and SCIM, while its existing
Spatie install stays the single source of **roles, permissions, and token claims**.

Cbox ID ships its own hierarchy-aware RBAC, but it resolves authorization through
contracts (`AccessChecker`, `Roles`). This package binds those contracts to Spatie
under the platform's **external** access-control driver, so the roles you already
manage flow straight into the tokens the platform mints — no second source of truth,
no table collision.

## How it fits

- Set `cbox-id.access_control.driver` to `external`. The platform gates its own RBAC
  tables and services off and falls back to deny-by-default.
- This package binds `AccessChecker` → `SpatieAccessChecker` and `Roles` →
  `SpatieRoles`, so every platform authorization check and token stamp reads from
  Spatie. Under the builtin driver it binds nothing, so the two RBACs never collide.
- Your authorizable model adds one line — `implements SpatieSubject` — to the model
  that already uses Spatie's `HasRoles` trait.

## What it provides

- **`SpatieAccessChecker`** — the read path. `can()`, `permissionsFor()`, and
  `forToken()` resolve from Spatie's effective (direct + role-derived) permissions and
  roles. Unknown permissions and unknown subjects deny rather than error.
- **`SpatieRoles`** — the write path used by SCIM provisioning and access governance.
  `define`/`grantPermission`/`assign`/`unassign` drive into Spatie; the read-backs
  (`assignmentsForSubject`, `assignmentsInOrganization`) return the platform's
  contract shapes so governance campaigns and Segregation-of-Duties scans work.
- **`SpatieGroupRoleMappings`** — the SCIM directory-group→role bridge. Map a directory
  group onto a role and the grant is kept in sync as membership changes, driven by the
  platform's `directory.group.membership_changed` event. A ledger of the grants the
  bridge made means reconciliation **never revokes a manually granted role**.
- **Organization scoping** — with Spatie's teams feature on, each platform
  organization maps onto a Spatie team, so a role is scoped to the organization it was
  granted in. With teams off the backend is flat and the organization is ignored.

## Installation

```bash
composer require cboxdk/laravel-id-spatie
```

Then set the driver and point the adapter at your user model:

```php
// config/cbox-id.php
'access_control' => ['driver' => 'external'],
```

```php
// config/id-spatie.php (php artisan vendor:publish --tag=id-spatie-config)
'user_model' => App\Models\User::class,
'teams' => false, // true to scope roles per organization via Spatie teams
'guard' => 'web',
```

```php
use Cbox\Id\Spatie\Contracts\SpatieSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements SpatieSubject
{
    use HasRoles;
}
```

See [`docs/`](docs/index.md) for the full guide.

## Honest scope

- The host's authorizable model must be keyed by the same identifier Cbox ID uses for
  a subject (the shared users table) — that id is how a platform check finds the Spatie
  user.
- Spatie tracks no *grant source* of its own; this package supplies it from the
  directory bridge's ledger (`pushed` when the bridge granted it, `manual` otherwise).
  A role granted directly in Spatie reads as `manual` — the safe direction, since
  reconciliation never revokes it.
- Mapping `priority` is stored and orders `forOrganization()`, but mappings union: a
  role is granted whenever any mapped group matches.
- Custom Spatie role/permission model classes are not auto-resolved; the adapter uses
  Spatie's default models.

## License

MIT. See [LICENSE](LICENSE).
