<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\Role as PlatformRole;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Spatie\Concerns\ResolvesOrganizationTeam;
use Cbox\Id\Spatie\Concerns\ResolvesSpatieSubject;
use Cbox\Id\Spatie\Exceptions\UnknownSubject;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Contracts\Role as SpatieRoleContract;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Authors and assigns roles in spatie/laravel-permission on behalf of the platform.
 * Bound to the `Roles` contract under the external access-control driver, so SCIM
 * provisioning and access governance drive their writes into the host's Spatie
 * backend instead of the platform's own (absent) RBAC tables.
 *
 * The contract speaks in the platform's `Role`/`RoleAssignment` models; those tables
 * do not exist under the external driver, so this adapter returns UNSAVED instances
 * as typed carriers of the fields the platform reads (id, name, user/role/org, source)
 * — Spatie owns persistence. A role id is the Spatie role's primary key as a string;
 * Spatie tracks no grant source, so every assignment is reported as Manual.
 */
class SpatieRoles implements Roles
{
    use ResolvesOrganizationTeam;
    use ResolvesSpatieSubject;

    public function __construct(private readonly PushedAssignmentLedger $ledger) {}

    public function define(?string $organizationId, string $name, ?string $description = null, ?string $clientId = null): PlatformRole
    {
        return $this->withOrganizationTeam($organizationId, function () use ($organizationId, $name): PlatformRole {
            $role = SpatieRole::findOrCreate($name, $this->guard());

            return $this->roleCarrier($role, $organizationId);
        });
    }

    public function grantPermission(string $organizationId, string $roleId, string $permission): void
    {
        $this->withOrganizationTeam($organizationId, function () use ($roleId, $permission): void {
            $role = $this->findRole($roleId);
            $role->givePermissionTo(SpatiePermission::findOrCreate($permission, $this->guard()));
        });
    }

    public function assertAssignableIn(string $organizationId, string $roleId): void
    {
        $this->withOrganizationTeam($organizationId, function () use ($roleId): void {
            $this->findRole($roleId);
        });
    }

    public function assign(
        string $organizationId,
        string $userId,
        string $roleId,
        GrantSource $source = GrantSource::Manual,
    ): RoleAssignment {
        return $this->withOrganizationTeam($organizationId, function () use ($organizationId, $userId, $roleId, $source): RoleAssignment {
            $role = $this->findRole($roleId);
            $subject = $this->resolveSubject($userId) ?? throw UnknownSubject::make($userId);

            // A directory-derived grant is recorded in the ledger, since Spatie itself
            // cannot say WHY a role is held. Note whether the subject already held it,
            // so revoking the push later cannot strip an underlying manual grant.
            if ($source === GrantSource::Pushed) {
                $alreadyHeld = $subject->roles()->where($role->getQualifiedKeyName(), $role->getKey())->exists();
                $this->ledger->record($organizationId, $userId, $roleId, $alreadyHeld);
            }

            $subject->assignRole($role);

            return $this->assignmentCarrier($userId, $roleId, $organizationId, $source);
        });
    }

    public function unassign(string $organizationId, string $userId, string $roleId): void
    {
        $this->withOrganizationTeam($organizationId, function () use ($organizationId, $userId, $roleId): void {
            $subject = $this->resolveSubject($userId);

            // Forgetting the push reports whether the role itself should go: a manual
            // grant underneath a revoked push survives.
            if ($this->ledger->forget($organizationId, $userId, $roleId)) {
                $subject?->removeRole($this->findRole($roleId));
            }
        });
    }

    public function assignmentsForSubject(string $organizationId, string $userId): array
    {
        return $this->withOrganizationTeam($organizationId, function () use ($organizationId, $userId): array {
            $subject = $this->resolveSubject($userId);

            if ($subject === null) {
                return [];
            }

            $assignments = [];

            foreach ($subject->roles()->get() as $role) {
                $roleId = $this->stringifyId($role->getKey());
                $assignments[] = $this->assignmentCarrier($userId, $roleId, $organizationId, $this->sourceOf($organizationId, $userId, $roleId));
            }

            return $assignments;
        });
    }

    public function assignmentsInOrganization(string $organizationId): array
    {
        return $this->withOrganizationTeam($organizationId, function () use ($organizationId): array {
            $query = DB::table($this->pivotTable())
                ->where('model_type', (new ($this->userModelClass()))->getMorphClass())
                ->select($this->morphKeyColumn().' as subject_id', $this->rolePivotColumn().' as role_id');

            if ($this->teamsEnabled()) {
                $query->where($this->teamColumn(), $organizationId);
            }

            $assignments = [];

            foreach ($query->get() as $row) {
                $subjectId = $this->stringifyId($row->subject_id ?? null);
                $roleId = $this->stringifyId($row->role_id ?? null);

                $assignments[] = $this->assignmentCarrier(
                    $subjectId,
                    $roleId,
                    $organizationId,
                    $this->sourceOf($organizationId, $subjectId, $roleId),
                );
            }

            return $assignments;
        });
    }

    /**
     * Where a held role came from. Spatie records no origin, so the ledger is the only
     * thing that can distinguish a directory-pushed grant from an admin's.
     */
    private function sourceOf(string $organizationId, string $userId, string $roleId): GrantSource
    {
        return $this->ledger->has($organizationId, $userId, $roleId)
            ? GrantSource::Pushed
            : GrantSource::Manual;
    }

    private function findRole(string $roleId): SpatieRoleContract
    {
        $role = SpatieRole::find($roleId);

        if (! $role instanceof SpatieRoleContract) {
            throw UnknownRole::make($roleId);
        }

        return $role;
    }

    private function roleCarrier(SpatieRoleContract $role, ?string $organizationId): PlatformRole
    {
        $carrier = new PlatformRole;
        $carrier->id = $this->stringifyId($role->getKey());
        $carrier->name = $role->name;
        $carrier->organization_id = $organizationId;
        $carrier->source = RoleSource::Manual;

        return $carrier;
    }

    /**
     * Spatie identifiers are int or string primary keys; the platform speaks in
     * string ids. Convert with a guard rather than a blind cast so a surprising
     * non-scalar surfaces instead of silently becoming "".
     */
    private function stringifyId(mixed $id): string
    {
        if (is_int($id) || is_string($id)) {
            return (string) $id;
        }

        throw new RuntimeException('Encountered a non-scalar identifier from the Spatie backend.');
    }

    private function assignmentCarrier(string $userId, string $roleId, string $organizationId, GrantSource $source): RoleAssignment
    {
        $carrier = new RoleAssignment;
        $carrier->user_id = $userId;
        $carrier->role_id = $roleId;
        $carrier->organization_id = $organizationId;
        $carrier->source = $source;

        return $carrier;
    }

    private function pivotTable(): string
    {
        return $this->permissionConfig('table_names.model_has_roles', 'model_has_roles');
    }

    private function morphKeyColumn(): string
    {
        return $this->permissionConfig('column_names.model_morph_key', 'model_id');
    }

    private function rolePivotColumn(): string
    {
        return $this->permissionConfig('column_names.role_pivot_key', 'role_id');
    }

    private function teamColumn(): string
    {
        return $this->permissionConfig('column_names.team_foreign_key', 'team_id');
    }

    private function permissionConfig(string $key, string $default): string
    {
        $value = config("permission.{$key}");

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
