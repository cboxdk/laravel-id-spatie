---
title: Architecture
description: The contracts the adapter implements and how they map onto Spatie
weight: 21
---

# Architecture

Cbox ID resolves all authorization through two contracts. This package implements both
against spatie/laravel-permission and binds them under the external driver.

## The read path — `AccessChecker`

`SpatieAccessChecker` answers the platform's authorization questions and supplies token
claims:

| Contract method | Spatie source |
|---|---|
| `can($userId, $permission, $org)` | `hasPermissionTo()` (unknown permission → deny, not error) |
| `permissionsFor($userId, $org)` | `getAllPermissions()` — direct **and** role-derived |
| `forToken($userId, $org, $client)` | `getRoleNames()` + `getAllPermissions()` |

`forToken()` is what the OAuth/OIDC token issuer and the UserInfo endpoint call, so
your Spatie roles and permissions land in the tokens the platform mints. The
`clientId` is not used — a single Spatie backend is not partitioned per app, so the
token carries the subject's full effective set.

## The write path — `Roles`

`SpatieRoles` is what SCIM provisioning and the access-governance module call to author
and assign roles:

| Contract method | Spatie action |
|---|---|
| `define()` | `Role::findOrCreate()` |
| `grantPermission()` | `Role::givePermissionTo(Permission::findOrCreate())` |
| `assign()` / `unassign()` | `assignRole()` / `removeRole()` on the subject |
| `assertAssignableIn()` | resolve the role, else `UnknownRole` |
| `assignmentsForSubject()` | the subject's roles in the team |
| `assignmentsInOrganization()` | the `model_has_roles` pivot for the team |

## The directory bridge — `GroupRoleMappings`

`SpatieGroupRoleMappings` maps SCIM/directory groups onto Spatie roles and reconciles
the derived grants as membership changes, keeping its own mapping table and a ledger of
the grants it made. See [group→role mapping](group-role-mapping.md).

### Model carriers

The `Roles` contract returns the platform's own `Role` and `RoleAssignment` Eloquent
models. Those tables do not exist under the external driver, so the adapter returns
**unsaved** instances as typed carriers of the fields the platform reads — `id`,
`name`, `user_id`, `role_id`, `organization_id`, `source`. Spatie owns persistence; the
carriers are never saved. A role id is the Spatie role's primary key rendered as a
string, so it round-trips back to `grantPermission`/`assign`/`unassign`.

Spatie tracks no *grant source* of its own, so the directory bridge's ledger supplies
it: an assignment recorded there is reported as `pushed`, anything else as `manual`.
That is what access-governance campaigns display, and it is what makes reconciliation
safe (see [group→role mapping](group-role-mapping.md)).

## Organizations and Spatie teams

Cbox ID is hierarchy-aware and passes an `organizationId` on every call. How the
adapter uses it depends on `id-spatie.teams`:

- **Teams on** — each organization maps onto a Spatie team (the org id *is* the team
  id). Before every read and write the adapter pins Spatie's team context to the
  organization, so a role or assignment is scoped to the organization it belongs to and
  never leaks across organizations.
- **Teams off** — the backend is flat; the organization id is ignored (single-tenant).

The platform's *hierarchy roll-down* (a role in an ancestor org applying to
descendants) is a feature of the builtin driver; a flat Spatie backend does not model
it. If you need it, implement it in a custom `AccessChecker` that composes the
ancestor walk with Spatie lookups.
