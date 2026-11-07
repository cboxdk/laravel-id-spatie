<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\ValueObjects\AppAccessClaims;
use Cbox\Id\OAuthServer\Models\Client;

/** Fresh, scoped managed grants only. Host direct permissions are not ID grants. */
class SpatieAccessChecker implements AccessChecker
{
    public function __construct(private readonly SpatieRoles $roles) {}

    public function can(string $userId, string $permission, string $organizationId): bool
    {
        return in_array($permission, $this->permissionsFor($userId, $organizationId), true);
    }

    public function permissionsFor(string $userId, string $organizationId): array
    {
        return $this->claims($userId, $organizationId, null)->permissions;
    }

    public function forToken(string $userId, ?string $organizationId, string $clientId): AppAccessClaims
    {
        if (! Client::query()->where('client_id', $clientId)->exists()) {
            return new AppAccessClaims([], []);
        }

        return $this->claims($userId, $organizationId, $clientId);
    }

    private function claims(string $userId, ?string $organizationId, ?string $clientId): AppAccessClaims
    {
        $names = [];
        $permissions = [];
        foreach ($this->roles->effectiveRoles($userId, $organizationId) as $role) {
            if ($clientId !== null && $role->client_id !== null && $role->client_id !== $clientId) {
                continue;
            }
            $names[] = $role->name;
            foreach ($this->roles->nativeRole($role)->permissions()->get() as $permission) {
                $name = $permission->getAttribute('name');
                if (is_string($name)) {
                    $permissions[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        $permissions = array_values(array_unique($permissions));
        sort($names, SORT_STRING);
        sort($permissions, SORT_STRING);

        return new AppAccessClaims($names, $permissions);
    }
}
