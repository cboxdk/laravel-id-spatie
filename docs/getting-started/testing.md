---
title: Testing
description: Exercise the adapter against a real Spatie install in your own suite
weight: 12
---

# Testing

This package is proven against a real spatie/laravel-permission install (its own suite
migrates Spatie's tables and asserts round-trips through the platform contracts, with
and without the teams feature). When you adopt it, test your own wiring the same way —
resolve the platform contracts and assert they reflect your Spatie data.

```php
use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;

$roles = app(Roles::class);   // SpatieRoles under the external driver
$access = app(AccessChecker::class);

$role = $roles->define('org_1', 'editor');
$roles->grantPermission('org_1', $role->id, 'posts.edit');
$roles->assign('org_1', $user->id, $role->id);

expect($access->can($user->id, 'posts.edit', 'org_1'))->toBeTrue();
expect($access->forToken($user->id, 'org_1', 'client_1')->permissions)
    ->toContain('posts.edit');
```

Key setup points for a Testbench-based suite:

- Register `SsrfServiceProvider`, `IdServiceProvider`, Spatie's
  `PermissionServiceProvider`, and this package's `SpatieServiceProvider` (last, so its
  boot-time bindings win).
- Set `cbox-id.access_control.driver` to `external` in `defineEnvironment`.
- Run Spatie's permission-table migration and the platform's publishable users
  migration; the platform's own RBAC migrations stay gated off under the external
  driver.
- To test organization scoping, enable both `permission.teams` and `id-spatie.teams`
  before the migration runs so the team columns are built.
