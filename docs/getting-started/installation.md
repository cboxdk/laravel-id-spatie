---
title: Installation
description: Install, select the external driver, configure the adapter, and declare the contract
weight: 11
---

# Installation

## 1. Require the package

```bash
composer require cboxdk/laravel-id-spatie
```

This package and Cbox ID both auto-register via Laravel package discovery. This
package's provider binds nothing unless the external driver is selected, so requiring
it is inert until you opt in.

## 2. Select the external access-control driver

```php
// config/cbox-id.php
'access_control' => ['driver' => 'external'],
```

or set `CBOX_ID_ACCESS_CONTROL_DRIVER=external`. Under this driver Cbox ID does **not**
create its own `roles`/`permissions`/… tables, so they never collide with Spatie's.

## 3. Configure the adapter

```bash
php artisan vendor:publish --tag=id-spatie-config
```

```php
// config/id-spatie.php
return [
    // The authorizable model — must use HasRoles and implement SpatieSubject, and be
    // keyed by the same id Cbox ID uses for a subject. Null falls back to
    // auth.providers.users.model.
    'user_model' => App\Models\User::class,

    // Map each platform organization onto a Spatie team (org id = team id). Requires
    // Spatie's teams feature to be enabled. Leave false for a flat, single-tenant
    // backend.
    'teams' => false,

    // The Spatie guard roles/permissions are defined under.
    'guard' => 'web',
];
```

## 4. Declare the contract on your user model

Add `implements SpatieSubject` to the model that already uses `HasRoles`:

```php
use Cbox\Id\Spatie\Contracts\SpatieSubject;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements SpatieSubject
{
    use HasRoles;
}
```

`HasRoles` already provides every method `SpatieSubject` requires — the interface just
makes the requirement explicit and lets the adapter stay statically typed. A user
model that does not implement it fails loud (`MisconfiguredUserModel`) the first time
the platform resolves a subject, rather than silently denying.

## The shared-users assumption

A platform authorization check arrives with the subject's Cbox ID. The adapter finds
the Spatie user with `UserModel::find($subjectId)`, so your authorizable model must be
keyed by the same identifier Cbox ID uses — the shared users table. If your app keeps a
separate users table, map the two ids in a subclassed model's `find`/resolution before
adopting the adapter.
