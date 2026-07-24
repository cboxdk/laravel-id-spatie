---
title: Customizing the group→role bridge
description: How the shipped directory-group→role bridge works and how to replace it
weight: 31
---

# Customizing the group→role bridge

This package ships a working `GroupRoleMappings` implementation
(`SpatieGroupRoleMappings`), so SCIM directory groups map onto Spatie roles out of the
box — see [group→role mapping](../core-concepts/group-role-mapping.md) for how to use
it.

You only need this page if you want to **replace** it.

## Why you might replace it

- Your directory groups should map onto something other than a flat Spatie role (a
  permission set, an external policy service).
- You need priority semantics beyond ordering (the shipped bridge stores `priority` and
  orders `forOrganization()` by it, but a role is granted whenever *any* mapped group
  matches).
- You want reconciliation to run somewhere other than the domain-event listener — for
  example batched on a schedule.

## Replacing it

Implement `Cbox\Id\AccessControl\Contracts\GroupRoleMappings` and bind it in a provider
that loads after this package's:

```php
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;

public function boot(): void
{
    $this->app->singleton(GroupRoleMappings::class, \App\Identity\MyGroupRoleMappings::class);
}
```

The contract has five methods: `map`, `unmap`, `forOrganization`, `reconcileUser`, and
`reconcileGroup`.

## The one thing you must get right

Spatie records **no grant origin** — a role is either held or not. So a replacement
must keep its own record of which grants *it* made, or reconciliation will strip roles
an admin granted by hand. The shipped bridge does this with a ledger table
(`id_spatie_pushed_assignments`), including a `pre_existing` flag for a role the subject
already held when a push landed, so revoking the push leaves the manual grant intact.

If you replace the bridge, replicate that property — and test it. The shipped suite
covers the three cases worth copying:

- a manually granted role survives a full reconcile after the subject leaves the group;
- a push that duplicated an existing manual grant leaves the role in place when revoked;
- an admin can still revoke a manual grant directly.

## Disabling it entirely

If you do not use SCIM group mapping, simply never call `map()`. The bridge is inert
until a mapping exists: reconciliation over a group with no mappings grants and revokes
nothing.
