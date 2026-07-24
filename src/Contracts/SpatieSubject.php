<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Models\Role;

/**
 * The contract the host's authorizable model must satisfy for this adapter to read
 * and write its Spatie roles and permissions.
 *
 * A model that already uses spatie/laravel-permission's `HasRoles` trait satisfies
 * every method here — implementing this interface is a one-line `implements` that
 * makes the requirement explicit and lets the adapter stay statically typed instead
 * of calling methods on an unknown model. Its signatures deliberately match the
 * `HasRoles`/`HasPermissions` traits so the trait's methods fulfil the interface.
 */
interface SpatieSubject
{
    /**
     * @return Collection<int, string>
     */
    public function getRoleNames(): Collection;

    /**
     * The subject's effective permissions — direct plus role-derived.
     *
     * @return Collection<int, Permission>
     */
    public function getAllPermissions(): Collection;

    /**
     * @param  Permission|string|int  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool;

    /**
     * @return BelongsToMany<Role, Model>
     */
    public function roles(): BelongsToMany;

    /**
     * @param  \Spatie\Permission\Contracts\Role|Collection<int, mixed>|string|int|array<int, mixed>  ...$roles
     */
    public function assignRole(...$roles): static;

    /**
     * @param  \Spatie\Permission\Contracts\Role|string|int  ...$role
     */
    public function removeRole(...$role): static;
}
