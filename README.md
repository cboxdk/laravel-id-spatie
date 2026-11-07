# Scoped Spatie authorization for Cbox ID

This adapter implements Cbox ID's external `Roles`, `AccessChecker` and
`GroupRoleMappings` contracts. It stores explicit ID grants in environment-scoped
adapter tables and projects tenant grants into Spatie teams. Existing native roles,
direct permissions and native assignment pivots are **not imported into ID claims**.

## Configure the host

Set `cbox-id.access_control.driver` to `external` before providers register. Set both
`id-spatie.teams` and `permission.teams` to `true` for organization access. The host
user model must implement `Cbox\Id\Spatie\Contracts\SpatieSubject`, use Spatie's
`HasRoles`, and expose the same user and environment IDs as ID.

Spatie's published migration uses integer team and model keys by default. Configure
the host's permission schema to use string/ULID-compatible `team_id` and `model_id`
columns **before** migrating: ID organization and subject IDs are strings. Existing
hosts must migrate those column types while preserving their existing native data.
Run the adapter's migrations with `php artisan migrate`.

With either team switch disabled, tenant reads deny and tenant writes throw a clear
configuration exception. Flat mode supports explicit environment-wide grants only;
it never treats an organization argument as irrelevant.

## Define and assign

```php
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Contracts\AccessChecker;

$roles = app(Roles::class);
$role = $roles->define($organizationId, 'Editor', clientId: $oauthClientId);
$roles->grantPermission($organizationId, $role->id, 'posts.edit');
$roles->assignAsTenant($organizationId, $userId, $role->id);
$claims = app(AccessChecker::class)->forToken($userId, $organizationId, $oauthClientId);
```

The client identifier is the OAuth client's public `client_id` in the current
environment. A missing or foreign client is refused. Role names may repeat across
apps, organizations and environments; native Spatie role names are internal unique
identifiers. Keep the returned managed role ID for subsequent operations. Never pass
a legacy native role ID to the ID role API.

Tenant-facing invitations, member controls and directory mappings use
`assignAsTenant`; staff-only roles are excluded and refused. Environment operators
may use `assign` for an explicit tenant-scoped staff grant. `assignEverywhere` accepts
only an environment-owned role. `unassign` removes the complete tenant grant;
directory reconciliation uses the separate pushed-grant ledger so removing a group
does not remove an independent manual grant. `unassignAll` removes tenant grants on
membership removal, preventing their return when the member rejoins.

## Authorization boundary

ID claims require a managed role and an explicit scoped grant. Tenant grants also
require their live native Spatie assignment. Reads use fresh queries, including
app/environment fences and orphan state; cached host model relations do not restore
revoked access. Environment-wide grants use their explicit adapter ledger and live
managed native role definition. They are not projected to every current or future
Spatie team.

Native `hasPermissionTo()` is therefore **not equivalent** to an ID authorization
decision. It has no app/environment-wide grant semantics and must not replace the
ID live decision API. `forToken` is an issuance snapshot; protected actions require
a fresh ID permission decision and the application's own resource/row checks.
`can` and `permissionsFor` are coarse cross-app queries, not a per-app decision.

## External-driver capabilities

Automatic manifest fetching, definition staging/activation and hierarchy roll-down
belong to ID's builtin driver. This external adapter does not register
`AppManifests`, `RoleDefinitions` or their command/scheduler. Resolving an unbound
catalog contract fails clearly; it does not query the builtin role tables. Hosts
that need those features must provide their own external implementation or use the
builtin driver. The default external `StaffAccess` remains deny-by-default.

This limitation applies to external Spatie hosts, not Cbox applications using ID's
builtin catalog. The adapter never creates builtin `roles`/`permissions` tables over
an existing Spatie schema.

## Upgrade from the legacy adapter

The scoped migration adds `id_spatie_roles`, `id_spatie_assignments` and
`id_spatie_everywhere_assignments`, and adds environment scope to the existing group
mapping and pushed-grant ledger. Legacy rows remain with a NULL environment and are
quarantined: no environment, organization, app or staff classification is inferred.
Their presence does not block explicitly scoped new rows.

Before admitting users, inventory old role definitions and assignments, approve
each owning environment/organization/app and its staff status, then recreate it
through `define`, `grantPermission`, and explicit assignment methods. Recreate
approved directory mappings through `GroupRoleMappings::map` with their new managed
role IDs. Verify positive and negative live decisions per app and organization.
Keep old native rows for the host's independent uses or remove them through the
host's reviewed migration; this package never deletes or adopts them automatically.

Rollback refuses before any schema mutation while managed definitions, grants, or
environment-scoped ledger rows remain. Revoke and migrate those authorities explicitly
before rollback, and take a database backup. Never merge authorities from different
environments to satisfy the old uniqueness constraints.

The production Composer range is changed only with the coordinated framework and
adapter release. The candidate is tested against the actual framework branch, not
an invented semantic version alias.

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
