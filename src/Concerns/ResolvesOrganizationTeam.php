<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Concerns;

use Spatie\Permission\PermissionRegistrar;

/**
 * Runs a piece of work with Spatie's team context pinned to a platform organization,
 * so roles, permissions, and assignments are scoped to the organization they belong
 * to. Tenant operations require both team switches. Environment-only operations do not
 * project a native assignment and may run without teams.
 */
trait ResolvesOrganizationTeam
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationTeam(?string $organizationId, callable $callback): mixed
    {
        if (! $this->teamsEnabled()) {
            return $callback();
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organizationId);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    private function teamsEnabled(): bool
    {
        return config('id-spatie.teams', false) === true && config('permission.teams', false) === true;
    }
}
