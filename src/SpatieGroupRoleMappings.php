<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Illuminate\Support\Facades\DB;

/**
 * The directory-group → role bridge against a Spatie backend: maps a SCIM/directory
 * group onto a role and keeps the derived grants in sync as group membership changes.
 *
 * Mappings live in this package's own table (the platform's is gated off under the
 * external driver), and every grant this bridge makes is recorded in the
 * {@see PushedAssignmentLedger} — reconciliation only ever revokes what it granted, so
 * a manually assigned role is never reconciled away.
 *
 * Like the `Roles` adapter, `map()` returns an UNSAVED platform model as a typed
 * carrier of the mapping's fields; this package owns the persistence.
 */
class SpatieGroupRoleMappings implements GroupRoleMappings
{
    private const TABLE = 'id_spatie_group_role_mappings';

    public function __construct(
        private readonly Roles $roles,
        private readonly PushedAssignmentLedger $ledger,
    ) {}

    public function map(string $organizationId, string $groupId, string $roleId, int $priority = 0): GroupRoleMapping
    {
        // Refuse a role this org may not assign BEFORE the mapping row is written, so a
        // bad id fails at the point of the mistake instead of wedging every later sync.
        $this->roles->assertAssignableIn($organizationId, $roleId);

        DB::table(self::TABLE)->updateOrInsert(
            ['organization_id' => $organizationId, 'group_id' => $groupId, 'role_id' => $roleId],
            ['priority' => $priority, 'updated_at' => now(), 'created_at' => now()],
        );

        // A mapping change takes effect immediately for everyone in the group.
        $this->reconcileGroup($groupId, $organizationId);

        return $this->carrier($organizationId, $groupId, $roleId, $priority);
    }

    public function unmap(string $organizationId, string $groupId, string $roleId): void
    {
        DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->where('role_id', $roleId)
            ->delete();

        $this->reconcileGroup($groupId, $organizationId);
    }

    public function forOrganization(string $organizationId): array
    {
        $mappings = [];

        $rows = DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->orderByDesc('priority')
            ->get();

        foreach ($rows as $row) {
            $mappings[] = $this->carrier(
                $organizationId,
                $this->string($row->group_id ?? null),
                $this->string($row->role_id ?? null),
                is_numeric($row->priority ?? null) ? (int) $row->priority : 0,
            );
        }

        return $mappings;
    }

    public function reconcileUser(string $organizationId, string $userId): void
    {
        $groupIds = $this->groupIdsForUser($organizationId, $userId);

        // Roles the subject SHOULD hold via the directory.
        $mapped = $groupIds === []
            ? []
            : $this->strings(DB::table(self::TABLE)
                ->where('organization_id', $organizationId)
                ->whereIn('group_id', $groupIds)
                ->pluck('role_id')
                ->all());

        // Roles they currently hold BECAUSE of the directory (the ledger — never a
        // manual grant).
        $pushed = $this->ledger->roleIdsFor($organizationId, $userId);

        foreach (array_diff($mapped, $pushed) as $roleId) {
            $this->roles->assign($organizationId, $userId, $roleId, GrantSource::Pushed);
        }

        foreach (array_diff($pushed, $mapped) as $roleId) {
            $this->roles->unassign($organizationId, $userId, $roleId);
        }
    }

    public function reconcileGroup(string $groupId, ?string $organizationId = null): void
    {
        // The org is passed in when the group row is already gone (a delete); else it
        // is resolved from the group's directory.
        $organizationId ??= $this->organizationOf($groupId);

        if ($organizationId === null) {
            return;
        }

        // Reconcile the union of who is in the group NOW (may GAIN a role) and who
        // holds one of its mapped roles through the bridge (may LOSE it). This is
        // correct for adds, removals, and group deletion alike, with no delta tracking.
        $currentMembers = $this->memberUserIds($groupId);

        $mappedRoleIds = $this->strings(DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->pluck('role_id')
            ->all());

        $priorHolders = $this->ledger->subjectsHolding($organizationId, $mappedRoleIds);

        foreach (array_unique([...$currentMembers, ...$priorHolders]) as $userId) {
            $this->reconcileUser($organizationId, $userId);
        }
    }

    private function carrier(string $organizationId, string $groupId, string $roleId, int $priority): GroupRoleMapping
    {
        $carrier = new GroupRoleMapping;
        $carrier->organization_id = $organizationId;
        $carrier->group_id = $groupId;
        $carrier->role_id = $roleId;
        $carrier->priority = $priority;

        return $carrier;
    }

    /**
     * Active directory-group memberships resolved to platform user ids.
     *
     * @return list<string>
     */
    private function memberUserIds(string $groupId): array
    {
        return $this->strings(DB::table('directory_group_members')
            ->join('directory_users', 'directory_users.id', '=', 'directory_group_members.directory_user_id')
            ->where('directory_group_members.group_id', $groupId)
            ->where('directory_users.active', true)
            ->whereNotNull('directory_users.user_id')
            ->pluck('directory_users.user_id')
            ->all());
    }

    /**
     * Directory group ids the subject belongs to within this organization.
     *
     * @return list<string>
     */
    private function groupIdsForUser(string $organizationId, string $userId): array
    {
        return $this->strings(DB::table('directory_group_members')
            ->join('directory_users', 'directory_users.id', '=', 'directory_group_members.directory_user_id')
            ->join('directory_groups', 'directory_groups.id', '=', 'directory_group_members.group_id')
            ->join('directories', 'directories.id', '=', 'directory_groups.directory_id')
            ->where('directory_users.user_id', $userId)
            ->where('directory_users.active', true)
            ->where('directories.organization_id', $organizationId)
            ->distinct()
            ->pluck('directory_group_members.group_id')
            ->all());
    }

    private function organizationOf(string $groupId): ?string
    {
        $organizationId = DB::table('directory_groups')
            ->join('directories', 'directories.id', '=', 'directory_groups.directory_id')
            ->where('directory_groups.id', $groupId)
            ->value('directories.organization_id');

        return is_string($organizationId) ? $organizationId : null;
    }

    private function string(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $v): string => $this->string($v), $values),
            static fn (string $v): bool => $v !== '',
        )));
    }
}
