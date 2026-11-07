# laravel-id-spatie 0.3.0

Status: prepared local candidate. No public tag, registry publication or deployment has occurred.

Scope adapter authorization by environment and application, preserve manual grant ownership, serialize conflicting grants and reconcile role replacements deterministically.

## Upgrade requirements

Requires framework ^2.0.0 and spatie/laravel-permission ^8.0. Follow docs/getting-started/scoped-authorization-upgrade.md and run adapter migrations before switching app traffic. External mode uses its own grant mutex schema, not the built-in framework table.

Dependency installation against public registries remains contingent on the approved release cascade. Local artifact checks prove the enclosed contents only; they do not claim public indexing.
