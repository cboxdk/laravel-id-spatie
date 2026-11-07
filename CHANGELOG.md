# Changelog

## [0.3.0] — prepared candidate, not published

### Changes

Scope adapter authorization by environment and application, preserve manual grant ownership, serialize conflicting grants and reconcile role replacements deterministically.

### Upgrade

Requires framework ^2.0.0 and spatie/laravel-permission ^8.0. Follow docs/getting-started/scoped-authorization-upgrade.md and run adapter migrations before switching app traffic. External mode uses its own grant mutex schema, not the built-in framework table.
