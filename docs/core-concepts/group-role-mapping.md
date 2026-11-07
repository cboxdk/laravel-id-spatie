---
title: Directory group → role mapping
description: Map SCIM/directory groups onto Spatie roles, and how reconciliation stays safe around manual grants
weight: 22
---

# Directory group → role mapping

The bridge maps a SCIM/directory group onto an explicitly managed ID role projected into Spatie: everyone in the group holds
the role, and the grant is kept in sync as membership changes.

```php
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;

$mappings = app(GroupRoleMappings::class);   // SpatieGroupRoleMappings

// Everyone in the "Engineering" directory group holds the "engineer" role in org_1.
$mappings->map($organizationId, $groupId, $roleId);

// Later — remove the mapping; derived grants are revoked.
$mappings->unmap($organizationId, $groupId, $roleId);
```

`map()` takes effect immediately for everyone already in the group. After that,
reconciliation runs automatically: the package listens for the platform's
`directory.group.membership_changed` domain event (emitted by SCIM create/replace/patch/
delete) and reconciles the affected group. You can also drive it by hand with
`reconcileUser()` or `reconcileGroup()`.

## What reconciliation does

For a subject, it compares two sets:

- the roles they **should** hold — from the mappings of every directory group they are
  an active member of, within the organization;
- the roles they **currently** hold *through the bridge*.

It grants the difference and revokes the reverse. Membership is read from the platform's
directory tables, and an inactive directory user is treated as a non-member.

## Manual grants are never reconciled away

This is the property that makes the bridge safe to run against a live Spatie install.

Spatie stores a role assignment with no notion of *why* it exists, so the bridge keeps
its own ledger (`id_spatie_pushed_assignments`) of the grants it made. Reconciliation
only ever revokes what is in that ledger:

| Situation | Result |
|---|---|
| Role granted by the bridge, subject leaves the group | Revoked |
| Role granted by an admin, subject leaves the group | **Kept** |
| Role granted by an admin, later also pushed, then push revoked | **Kept** (the ledger's `pre_existing` flag) |
| Role granted by an admin, admin revokes it | Revoked |

Because of the ledger, `assignmentsForSubject()` and `assignmentsInOrganization()` also
report an accurate `GrantSource` — `pushed` for a directory-derived grant, `manual`
otherwise — which is what access-governance campaigns display.

## Storage

Two tables ship with this package, created by its own migration (the platform's
equivalents are gated off under the external driver):

- `id_spatie_group_role_mappings` — organization, group, role, priority.
- `id_spatie_pushed_assignments` — the ledger described above.

`priority` is stored and orders `forOrganization()`; a role is granted whenever any
mapped group matches.

Mappings are environment-scoped and tenant-only: staff roles and groups owned by another organization/environment are refused. Legacy NULL-environment mappings are quarantined. `assignmentsForSubject` returns role ID strings; use `assignmentsInOrganization` for assignment models and grant sources.
