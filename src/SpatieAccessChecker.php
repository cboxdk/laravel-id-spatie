<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\ValueObjects\AppAccessClaims;
use Cbox\Id\Spatie\Concerns\ResolvesOrganizationTeam;
use Cbox\Id\Spatie\Concerns\ResolvesSpatieSubject;
use Cbox\Id\Spatie\Contracts\SpatieSubject;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Reads authorization from spatie/laravel-permission on behalf of the platform.
 * Bound to the `AccessChecker` contract under the external access-control driver, so
 * the OAuth/OIDC token issuer and the UserInfo endpoint stamp Spatie roles and
 * permissions into the tokens the platform mints.
 *
 * When Spatie's teams feature is on, each check is scoped to the platform
 * organization (org id = team id); otherwise the organization is ignored.
 */
class SpatieAccessChecker implements AccessChecker
{
    use ResolvesOrganizationTeam;
    use ResolvesSpatieSubject;

    public function can(string $userId, string $permission, string $organizationId): bool
    {
        return $this->withOrganizationTeam($organizationId, function () use ($userId, $permission): bool {
            $subject = $this->resolveSubject($userId);

            if ($subject === null) {
                return false;
            }

            try {
                return $subject->hasPermissionTo($permission, $this->guard());
            } catch (PermissionDoesNotExist) {
                // An unknown permission is simply not granted — deny, don't error.
                return false;
            }
        });
    }

    public function permissionsFor(string $userId, string $organizationId): array
    {
        return $this->withOrganizationTeam($organizationId, function () use ($userId): array {
            $subject = $this->resolveSubject($userId);

            return $subject === null ? [] : $this->permissionNames($subject);
        });
    }

    public function forToken(string $userId, string $organizationId, string $clientId): AppAccessClaims
    {
        return $this->withOrganizationTeam($organizationId, function () use ($userId): AppAccessClaims {
            $subject = $this->resolveSubject($userId);

            if ($subject === null) {
                return new AppAccessClaims([], []);
            }

            return new AppAccessClaims(
                roles: array_values($subject->getRoleNames()->all()),
                permissions: $this->permissionNames($subject),
            );
        });
    }

    /**
     * @return list<string>
     */
    private function permissionNames(SpatieSubject $subject): array
    {
        return array_values(array_filter(
            $subject->getAllPermissions()->pluck('name')->all(),
            'is_string',
        ));
    }
}
