---
title: Architecture
description: The contracts the adapter implements and how they map onto Spatie
weight: 21
---

# Authorization architecture

`id_spatie_roles` stores environment, organization, app, staff status and readable
names, linking each role to a unique native Spatie role. `id_spatie_assignments`
stores explicit tenant grants; `id_spatie_everywhere_assignments` stores explicit
environment-wide grants. All three use ID environment scopes.

Tenant claims require both a managed grant and its live native team assignment.
Environment grants use the explicit adapter ledger plus the live managed native
role's permissions; they are not projected to arbitrary future teams. Legacy native
roles, direct permissions and forged native assignments contribute nothing to ID.

`assignmentsForSubject` returns role ID strings. Organization/environment assignment
enumerators return models. Grant changes and role lifecycle changes emit ID events
and audit records in the write transaction. Definition locks precede assignment
locks; manual grants survive pushed-source revocation independently.

Both team configuration flags must be true for tenant access. Flat tenant reads
deny and writes fail. Unknown or cross-environment clients are refused. Per-app
claims filter other apps' roles; coarse `can`/`permissionsFor` do not.

External manifest ingestion, staging/activation and hierarchy are host-owned. The
adapter does not register builtin manifest machinery or collide with Spatie tables.
See the [upgrade guide](../getting-started/scoped-authorization-upgrade.md).

## Concurrent grants and reconciliation failures

A persistent environment/subject mutex serializes every grant and revocation before
role and assignment locks. This includes different-role grants, so two concurrent
requests cannot both pass a segregation-of-duties check against the same old state.
No lock assumes the host uses ID's default User model. Role deletion takes the role
lock and removes its grants internally without acquiring subject locks in reverse.

Directory mapping policy writes commit before per-subject reconciliation. Each
subject's derived grants change transactionally under its mutex. Errors propagate;
a failed operation is not reported as successful. After a failed unmap, the pushed
ledger retains every unrevoked holder even if the mapping or group membership is
already gone. Retrying the same unmap enumerates those holders and converges. Hosts
must retry failed reconciliation jobs/requests; until retry succeeds, a failed
revocation remains an outstanding operation, not a completed access removal.
