---
title: Installation
description: Install, select the external driver, configure the adapter, and declare the contract
weight: 11
---

# Installation and configuration

Install the coordinated adapter/framework releases, publish `id-spatie-config`, and
set `cbox-id.access_control.driver` to `external` before service providers register.
Both `id-spatie.teams` and `permission.teams` must be true for tenant authorization.
Use the same configured Spatie guard for the adapter and host.

The host model must use `HasRoles`, implement `SpatieSubject`, and use ID's shared
subject IDs with an `environment_id`. Spatie's default migration uses integer keys;
change its team and polymorphic model keys to string/ULID-compatible columns before
migrating. Existing schemas require a reviewed column-type migration.

Run `php artisan migrate`. The adapter creates isolated metadata/grant tables and
adds environment scope to its group ledger; it never loads the builtin ID role
schema over Spatie's tables.

Follow the [scoped authorization upgrade](scoped-authorization-upgrade.md) for the
explicit legacy-data review and recreation procedure. No roles or grants are
inferred from old native rows.
