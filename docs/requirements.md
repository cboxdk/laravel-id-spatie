---
title: Requirements
description: PHP, Laravel, and package versions the resolver enforces
weight: 3
---

# Requirements

Taken from `composer.json`; these are what the dependency resolver enforces.

| Requirement | Constraint |
|---|---|
| PHP | `^8.4` (developed and tested on 8.4 and 8.5) |
| `cboxdk/laravel-id` | the release carrying the external access-control driver (`>=0.50 <1.0`) |
| `spatie/laravel-permission` | `^8.0` |
| `illuminate/contracts` | `^12.0 \|\| ^13.0` |
| `illuminate/database` | `^12.0 \|\| ^13.0` |
| `illuminate/support` | `^12.0 \|\| ^13.0` |

Laravel majors 12 and 13 are both supported, matching Cbox ID and
spatie/laravel-permission. Development tooling (Pint, Pest, PHPStan/Larastan,
Testbench) is dev-only.
