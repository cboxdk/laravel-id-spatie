<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Concerns;

use Spatie\Permission\PermissionRegistrar;

/**
 * Runs a piece of work with Spatie's team context pinned to a platform organization,
 * so roles, permissions, and assignments are scoped to the organization they belong
 * to. When the teams feature is off the organization is ignored (a flat, single-tenant
 * backend) and the callback runs unchanged.
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
        return (bool) config('id-spatie.teams', false);
    }
}
