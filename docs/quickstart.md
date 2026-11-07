---
title: Quickstart
description: Wire Spatie in as Cbox ID's authorization backend in three steps
weight: 2
---

# Quickstart

Set ID's access-control driver to `external` before providers register, enable both
`id-spatie.teams` and `permission.teams`, and implement `SpatieSubject` on the shared
user model. Its `environment_id` must match the current ID environment.

Prepare string-compatible Spatie team/model keys, then run `php artisan migrate`.
Create managed roles through `Roles::define`, grant their permissions, and use
`assignAsTenant` from tenant invitations and member controls. The role ID returned by
ID is distinct from the internal native Spatie role ID.

Use `AccessChecker::forToken` for app-scoped token claims and fresh ID permission
decisions for protected actions, followed by app-owned row checks. Native
`hasPermissionTo` is not an ID app/environment authorization decision.

The complete code example and legacy migration procedure are in the
[upgrade guide](getting-started/scoped-authorization-upgrade.md).
