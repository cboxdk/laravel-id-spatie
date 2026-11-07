<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\GrantGuard;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Spatie\Concerns\ResolvesOrganizationTeam;
use Cbox\Id\Spatie\Concerns\ResolvesSpatieSubject;
use Cbox\Id\Spatie\Exceptions\UnknownSubject;
use Cbox\Id\Spatie\Models\EverywhereAssignment;
use Cbox\Id\Spatie\Models\ManagedAssignment;
use Cbox\Id\Spatie\Models\ManagedRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as NativeRole;

/** Explicit, scoped grants. Unmapped host roles and direct permissions are never imported. */
class SpatieRoles implements Roles
{
    use ResolvesOrganizationTeam;
    use ResolvesSpatieSubject;

    public function __construct(private readonly PushedAssignmentLedger $ledger) {}

    public function define(?string $organizationId, string $name, ?string $description = null, ?string $clientId = null, bool $tenantAssignable = true): Role
    {
        $this->requireTenantMode($organizationId);
        $this->assertClient($clientId);
        $scope = $this->scopeKey($organizationId, $clientId, $name);

        return DB::transaction(function () use ($scope, $organizationId, $name, $description, $clientId, $tenantAssignable): ManagedRole {
            $existing = ManagedRole::query()->where('scope_key', $scope)->first();
            if ($existing !== null) {
                return $existing;
            }
            $native = $this->withOrganizationTeam($organizationId, fn () => NativeRole::create([
                'name' => 'cbox:'.Str::ulid(), 'guard_name' => $this->guard(),
            ]));

            return ManagedRole::query()->create([
                'scope_key' => $scope, 'organization_id' => $organizationId, 'client_id' => $clientId,
                'name' => $name, 'description' => $description, 'tenant_assignable' => $tenantAssignable, 'source' => 'manual',
                'spatie_role_id' => (string) $native->id,
            ]);
        });
    }

    public function grantPermission(?string $organizationId, string $roleId, string $permission): void
    {
        $role = $this->ownedRole($roleId, $organizationId);
        $nativePermission = Permission::findOrCreate($permission, $this->guard());
        $this->attachPermission($role->id, (string) $nativePermission->id, $organizationId);
    }

    public function updateRole(string $roleId, string $name, ?string $description = null, ?string $organizationId = null, ?bool $tenantAssignable = null): Role
    {
        return DB::transaction(function () use ($roleId, $name, $description, $organizationId, $tenantAssignable): ManagedRole {
            $role = $this->ownedRole($roleId, $organizationId);
            $before = $role->only(['name', 'description', 'tenant_assignable']);
            $this->assertClient($role->client_id);
            $role->name = $name;
            $role->description = $description;
            $role->scope_key = $this->scopeKey($role->organization_id, $role->client_id, $name);
            if ($tenantAssignable !== null) {
                $role->tenant_assignable = $tenantAssignable;
            }
            $role->save();
            $this->record('role.updated', $role, $organizationId, ['from' => $before, 'to' => $role->only(['name', 'description', 'tenant_assignable'])]);

            return $role;
        });
    }

    public function attachPermission(string $roleId, string $permissionId, ?string $organizationId = null): void
    {
        DB::transaction(function () use ($roleId, $permissionId, $organizationId): void {
            $role = $this->ownedRole($roleId, $organizationId);
            $permission = Permission::query()->whereKey($permissionId)->where('guard_name', $this->guard())->firstOrFail();
            $native = $this->nativeRole($role);
            if (! $native->permissions()->whereKey($permission->id)->exists()) {
                $native->givePermissionTo($permission);
                $this->record('role.permission_granted', $role, $organizationId, ['permission_id' => $permissionId]);
            }
        });
    }

    public function revokePermission(string $roleId, string $permissionId, ?string $organizationId = null): void
    {
        DB::transaction(function () use ($roleId, $permissionId, $organizationId): void {
            $role = $this->ownedRole($roleId, $organizationId);
            $native = $this->nativeRole($role);
            $permission = $native->permissions()->whereKey($permissionId)->first();
            if ($permission !== null && is_string($permission->getAttribute('name'))) {
                $native->revokePermissionTo($permission->getAttribute('name'));
                $this->record('role.permission_revoked', $role, $organizationId, ['permission_id' => $permissionId]);
            }
        });
    }

    public function deleteRole(string $roleId, ?string $organizationId = null): void
    {
        DB::transaction(function () use ($roleId, $organizationId): void {
            $role = $this->ownedRole($roleId, $organizationId);
            foreach (ManagedAssignment::query()->where('role_id', $roleId)->get() as $grant) {
                $this->removeGrant($grant);
            }
            foreach (EverywhereAssignment::query()->where('role_id', $roleId)->get() as $grant) {
                $grant->delete();
                $this->record('role.unassigned_everywhere', $role, null, ['user_id' => $grant->user_id, 'client_id' => $role->client_id]);
            }
            DB::table('id_spatie_group_role_mappings')->where('environment_id', $role->environment_id)->where('role_id', $roleId)->delete();
            $this->nativeRole($role)->delete();
            $role->delete();
            $this->record('role.deleted', $role, $organizationId);
        });
    }

    public function assertAssignableIn(string $organizationId, string $roleId): void
    {
        $this->requireTenantMode($organizationId);
        $role = $this->managedRole($roleId);
        if ($role->organization_id !== null && $role->organization_id !== $organizationId) {
            throw UnknownRole::make($roleId);
        }
    }

    public function tenantAssignableRoles(string $organizationId, ?string $clientId = null): array
    {
        if (! $this->teamsEnabled()) {
            return [];
        }

        return array_values(ManagedRole::query()->tenantAssignable($organizationId, $clientId)->orderBy('name')->get()->all());
    }

    public function assertTenantAssignable(string $organizationId, string $roleId): void
    {
        $this->assertAssignableIn($organizationId, $roleId);
        if (! $this->managedRole($roleId)->tenant_assignable) {
            throw RoleNotTenantAssignable::forRole($roleId);
        }
    }

    public function assignAsTenant(string $organizationId, string $userId, string $roleId, GrantSource $source = GrantSource::Manual): RoleAssignment
    {
        return DB::transaction(function () use ($organizationId, $userId, $roleId, $source): RoleAssignment {
            $this->lockSubject($userId);
            $this->assertTenantAssignable($organizationId, $roleId);

            return $this->assign($organizationId, $userId, $roleId, $source);
        });
    }

    public function assign(string $organizationId, string $userId, string $roleId, GrantSource $source = GrantSource::Manual): RoleAssignment
    {

        return DB::transaction(fn () => $this->withOrganizationTeam($organizationId, function () use ($organizationId, $userId, $roleId, $source): ManagedAssignment {
            $this->lockSubject($userId);
            $this->assertAssignableIn($organizationId, $roleId);
            $this->guardGrant($organizationId, $userId, $roleId, $source);
            $role = $this->managedRole($roleId);
            $subject = $this->resolveSubject($userId) ?? throw UnknownSubject::make($userId);
            $grant = ManagedAssignment::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'user_id' => $userId, 'role_id' => $roleId], ['source' => $source],
            );
            if ($source === GrantSource::Pushed && ! $this->ledger->has($organizationId, $userId, $roleId)) {
                $this->ledger->record($organizationId, $userId, $roleId, ! $grant->wasRecentlyCreated);
            }
            if ($source === GrantSource::Manual && $this->ledger->has($organizationId, $userId, $roleId)) {
                $this->ledger->record($organizationId, $userId, $roleId, true);
            }
            $subject->assignRole($this->nativeRole($role));
            if ($grant->wasRecentlyCreated) {
                $this->record('role.assigned', $role, $organizationId, ['user_id' => $userId]);
            }

            return $grant;
        }));
    }

    public function unassign(string $organizationId, string $userId, string $roleId): void
    {
        $this->requireTenantMode($organizationId);
        DB::transaction(function () use ($organizationId, $userId, $roleId): void {
            $this->lockSubject($userId);
            $this->managedRole($roleId, false);
            $grant = ManagedAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->where('role_id', $roleId)->lockForUpdate()->first();
            if ($grant !== null) {
                $this->removeGrant($grant);
            }
        });
    }

    public function unassignPushed(string $organizationId, string $userId, string $roleId): void
    {
        $this->requireTenantMode($organizationId);
        DB::transaction(function () use ($organizationId, $userId, $roleId): void {
            $this->lockSubject($userId);
            $this->managedRole($roleId, false);
            $grant = ManagedAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->where('role_id', $roleId)->lockForUpdate()->first();
            if ($grant !== null && $this->ledger->forget($organizationId, $userId, $roleId)) {
                $this->removeGrant($grant);
            } elseif ($grant !== null) {
                $grant->source = GrantSource::Manual;
                $grant->save();
            }
        });
    }

    public function unassignAll(string $organizationId, string $userId): int
    {
        $this->requireTenantMode($organizationId);

        return DB::transaction(function () use ($organizationId, $userId): int {
            $this->lockSubject($userId);
            $grants = ManagedAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->orderBy('role_id')->get();
            foreach ($grants as $grant) {
                $this->removeGrant($grant);
            }

            return $grants->count();
        });
    }

    public function assignmentsForSubject(string $organizationId, string $userId): array
    {
        if (! $this->teamsEnabled()) {
            return [];
        }

        return array_values(array_unique([...array_map(fn (ManagedRole $role): string => $role->id, $this->effectiveRoles($userId, $organizationId)), ...$this->everywhereFor($userId)]));
    }

    public function assignEverywhere(string $userId, string $roleId, GrantSource $source = GrantSource::Manual): EnvironmentRoleAssignment
    {
        return DB::transaction(function () use ($userId, $roleId, $source): EverywhereAssignment {
            $this->lockSubject($userId);
            $role = $this->ownedRole($roleId, null);
            if ($role->orphaned_at !== null) {
                throw UnknownRole::make($roleId);
            }
            $this->resolveSubject($userId) ?? throw UnknownSubject::make($userId);
            foreach (app(Memberships::class)->forUser($userId) as $membership) {
                $this->guardGrant($membership->organization_id, $userId, $roleId, $source);
            }
            $grant = EverywhereAssignment::query()->firstOrCreate(['user_id' => $userId, 'role_id' => $roleId], ['source' => $source]);
            if ($grant->wasRecentlyCreated) {
                $this->record('role.assigned_everywhere', $role, null, ['user_id' => $userId, 'client_id' => $role->client_id]);
            }

            return $grant;
        });
    }

    public function unassignEverywhere(string $userId, string $roleId): void
    {
        DB::transaction(function () use ($userId, $roleId): void {
            $this->lockSubject($userId);
            $this->managedRole($roleId, false);
            if (EverywhereAssignment::query()->where('user_id', $userId)->where('role_id', $roleId)->delete() > 0) {
                $role = $this->managedRole($roleId, false);
                $this->record('role.unassigned_everywhere', $role, null, ['user_id' => $userId, 'client_id' => $role->client_id]);
            }
        });
    }

    public function everywhereFor(string $userId): array
    {
        return array_map(fn (ManagedRole $role): string => $role->id, $this->effectiveRoles($userId, null));
    }

    public function assignmentsInOrganization(string $organizationId): array
    {
        if (! $this->teamsEnabled()) {
            return [];
        }

        return array_values(ManagedAssignment::query()->where('organization_id', $organizationId)->orderBy('user_id')->get()->all());
    }

    public function assignmentsEverywhere(): array
    {
        return array_values(EverywhereAssignment::query()->orderBy('user_id')->orderBy('role_id')->get()->all());
    }

    /** @return list<ManagedRole> */
    public function effectiveRoles(string $userId, ?string $organizationId): array
    {
        if (($organizationId !== null && ! $this->teamsEnabled()) || $this->resolveSubject($userId) === null) {
            return [];
        }
        $ids = EverywhereAssignment::query()->where('user_id', $userId)->pluck('role_id')->all();
        $roles = ManagedRole::query()->whereIn('id', $ids)->whereNull('organization_id')->whereNull('orphaned_at')->get()->all();
        if ($organizationId !== null) {
            $this->withOrganizationTeam($organizationId, function () use ($userId, $organizationId, &$roles): void {
                $subject = $this->resolveSubject($userId);
                if ($subject === null) {
                    return;
                }
                foreach (ManagedAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->get() as $grant) {
                    $role = ManagedRole::query()->whereKey($grant->role_id)->whereNull('orphaned_at')->first();
                    if ($role !== null && ($role->organization_id === null || $role->organization_id === $organizationId)
                        && $subject->roles()->whereKey($role->spatie_role_id)->exists()) {
                        $roles[] = $role;
                    }
                }
            });
        }

        return array_values(array_filter($roles, fn (ManagedRole $role): bool => ($role->client_id === null || Client::query()->where('client_id', $role->client_id)->exists()) && NativeRole::query()->whereKey($role->spatie_role_id)->where('guard_name', $this->guard())->exists()));
    }

    public function nativeRole(ManagedRole $role): NativeRole
    {
        return NativeRole::query()->whereKey($role->spatie_role_id)->where('guard_name', $this->guard())->first() ?? throw UnknownRole::make($role->id);
    }

    private function managedRole(string $roleId, bool $active = true): ManagedRole
    {
        return ManagedRole::query()->whereKey($roleId)->when($active, fn ($query) => $query->whereNull('orphaned_at'))->lockForUpdate()->first() ?? throw UnknownRole::make($roleId);
    }

    private function ownedRole(string $roleId, ?string $organizationId): ManagedRole
    {
        $this->requireTenantMode($organizationId);
        $role = $this->managedRole($roleId, false);
        if ($role->organization_id !== $organizationId || ($organizationId !== null && ! $role->tenant_assignable)) {
            throw UnknownRole::make($roleId);
        }

        return $role;
    }

    /** Serialize all grants for this subject, including different roles and scopes. */
    public function lockSubject(string $userId): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A grant subject lock requires an active transaction.');
        }
        $key = ['environment_id' => app(EnvironmentContext::class)->requireEnvironment()->environmentKey(), 'user_id' => $userId];
        DB::table('id_spatie_grant_subjects')->insertOrIgnore($key);
        DB::table('id_spatie_grant_subjects')->where($key)->lockForUpdate()->first();
    }

    private function requireTenantMode(?string $organizationId): void
    {
        if ($organizationId !== null && (! $this->teamsEnabled() || config('permission.teams') !== true)) {
            throw new LogicException('Organization authorization requires both id-spatie.teams and permission.teams to be enabled.');
        }
    }

    private function assertClient(?string $clientId): void
    {
        if ($clientId !== null && ! Client::query()->where('client_id', $clientId)->exists()) {
            throw new LogicException('The role client must belong to the current environment.');
        }
    }

    private function scopeKey(?string $organizationId, ?string $clientId, string $name): string
    {
        return hash('sha256', json_encode([app(EnvironmentContext::class)->requireEnvironment()->environmentKey(), $organizationId, $clientId, $name], JSON_THROW_ON_ERROR));
    }

    private function guardGrant(string $organizationId, string $userId, string $roleId, GrantSource $source): void
    {
        $refusal = app(GrantGuard::class)->refuse($organizationId, $userId, $roleId, $source);
        if ($refusal !== null) {
            throw new GrantRefused($organizationId, $userId, $roleId, $refusal);
        }
    }

    private function removeGrant(ManagedAssignment $grant): void
    {
        $role = ManagedRole::query()->lockForUpdate()->find($grant->role_id);
        $grant = ManagedAssignment::query()->lockForUpdate()->find($grant->id);
        if ($grant === null) {
            return;
        }
        $this->withOrganizationTeam($grant->organization_id, function () use ($grant, $role): void {
            if ($role !== null) {
                $this->resolveSubject($grant->user_id)?->removeRole($this->nativeRole($role));
            }
        });
        $this->ledger->forget($grant->organization_id, $grant->user_id, $grant->role_id);
        $grant->delete();
        if ($role !== null) {
            $this->record('role.unassigned', $role, $grant->organization_id, ['user_id' => $grant->user_id]);
        }
    }

    /** @param array<string, mixed> $context */
    private function record(string $action, ManagedRole $role, ?string $organizationId, array $context = []): void
    {
        app(EventBus::class)->emit(new DomainEvent($action, ['role_id' => $role->id] + $context, $organizationId));
        app(AuditLog::class)->record(new AuditEvent(action: $action, organizationId: $organizationId, targetType: 'role', targetId: $role->id, context: $context));
    }
}
