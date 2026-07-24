---
title: Custom group→role mapping
description: Add the SCIM directory-group→role bridge this package does not ship
weight: 31
---

# Custom group→role mapping

Cbox ID's `GroupRoleMappings` contract is the bridge from SCIM/directory groups to
roles: it maps a directory group onto a role and reconciles the derived (`pushed`)
assignments as group membership changes. This package **does not** ship a Spatie
implementation of it — most adopters assign roles directly rather than through
group-mapping, and a faithful bridge needs its own store for the mappings plus a
reconciliation strategy against Spatie.

Under the external driver, with only this package installed, `GroupRoleMappings` stays
bound to the platform's deny-by-default `UnboundGroupRoleMappings`, which throws
`ExternalRbacNotBound` on any call — so SCIM group→role sync fails loud rather than
silently doing nothing.

## Adding it

Implement the contract against your own mapping store plus Spatie, and bind it in a
provider that loads after this package's:

```php
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;

public function boot(): void
{
    $this->app->singleton(GroupRoleMappings::class, \App\Identity\SpatieGroupRoleMappings::class);
}
```

Your implementation:

- Stores group→role mappings (a table you own), keyed by organization + group.
- On `reconcileUser` / `reconcileGroup`, reads the user's current directory-group
  membership (from Cbox ID's Directory module), computes the mapped roles, and
  grants/revokes only the derived assignments in Spatie — never disturbing a manually
  assigned role.

Until then, assign roles directly through the `Roles` contract (the `SpatieRoles`
adapter this package binds), which SCIM's user-provisioning path and the console both
use.
