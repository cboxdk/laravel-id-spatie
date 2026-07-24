---
title: Quickstart
description: Wire Spatie in as Cbox ID's authorization backend in three steps
weight: 2
---

# Quickstart

Three steps take an app that already runs spatie/laravel-permission to one where Cbox
ID mints tokens from your Spatie roles.

## 1. Install

```bash
composer require cboxdk/laravel-id-spatie
```

Both this package and Cbox ID auto-register.

## 2. Select the external driver and configure the adapter

```php
// config/cbox-id.php
'access_control' => ['driver' => 'external'],
```

Publish and set the adapter config:

```bash
php artisan vendor:publish --tag=id-spatie-config
```

```php
// config/id-spatie.php
return [
    'user_model' => App\Models\User::class,
    'teams' => false, // true to scope roles per organization via Spatie teams
    'guard' => 'web',
];
```

## 3. Declare the contract on your user model

The model that already uses Spatie's `HasRoles` trait adds one interface:

```php
use Cbox\Id\Spatie\Contracts\SpatieSubject;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements SpatieSubject
{
    use HasRoles;
}
```

That is all. The platform now resolves every authorization check and stamps every
token from Spatie:

```php
use Cbox\Id\AccessControl\Contracts\AccessChecker;

app(AccessChecker::class)->can($user->id, 'posts.edit', $organizationId); // ← Spatie
```

Next: **[how it works](core-concepts/architecture.md)** and
**[installation details](getting-started/installation.md)**.
