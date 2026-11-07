<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Support\Facades\DB;

/**
 * Records which role grants came from the directory bridge rather than an admin.
 *
 * Spatie stores a role assignment with no notion of WHY it exists, but reconciliation
 * must only ever revoke what it granted — otherwise a directory sync would strip an
 * admin's hand-granted role. This ledger is that missing dimension: a row exists only
 * for a `pushed` grant, and `pre_existing` remembers that the subject already held the
 * role manually when the push landed, so revoking the push leaves the manual grant
 * intact.
 */
class PushedAssignmentLedger
{
    private const TABLE = 'id_spatie_pushed_assignments';

    public function record(string $organizationId, string $userId, string $roleId, bool $preExisting): void
    {
        DB::table(self::TABLE)->where('environment_id', $this->environmentId())->updateOrInsert(
            ['environment_id' => $this->environmentId(), 'organization_id' => $organizationId, 'user_id' => $userId, 'role_id' => $roleId],
            ['pre_existing' => $preExisting, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Drop any ledger row for this grant and report whether the Spatie role itself
     * should now be removed:
     *
     * - no row      → a manual grant; remove it (an admin revoke must take effect).
     * - pushed row  → the bridge granted it; remove it.
     * - pre-existing→ the subject held it manually before the push; keep the role and
     *                 only forget the push, so a directory change cannot strip an
     *                 admin's grant.
     */
    public function forget(string $organizationId, string $userId, string $roleId): bool
    {
        $row = DB::table(self::TABLE)->where('environment_id', $this->environmentId())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();

        if ($row === null) {
            return true;
        }

        DB::table(self::TABLE)->where('environment_id', $this->environmentId())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->delete();

        return ! (bool) ($row->pre_existing ?? false);
    }

    public function has(string $organizationId, string $userId, string $roleId): bool
    {
        return DB::table(self::TABLE)->where('environment_id', $this->environmentId())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();
    }

    /**
     * Role ids the subject holds through the directory bridge in this organization.
     *
     * @return list<string>
     */
    public function roleIdsFor(string $organizationId, string $userId): array
    {
        return $this->strings(DB::table(self::TABLE)->where('environment_id', $this->environmentId())
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->pluck('role_id')
            ->all());
    }

    /**
     * Subjects holding any of these roles through the bridge — the people a mapping
     * change may need to reconcile.
     *
     * @param  list<string>  $roleIds
     * @return list<string>
     */
    public function subjectsHolding(string $organizationId, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        return $this->strings(DB::table(self::TABLE)->where('environment_id', $this->environmentId())
            ->where('organization_id', $organizationId)
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id')
            ->all());
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $values),
            'is_string',
        )));
    }

    private function environmentId(): string
    {
        return app(EnvironmentContext::class)->requireEnvironment()->environmentKey();
    }
}
