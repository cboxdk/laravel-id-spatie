<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;

/**
 * With teams on, a platform organization maps to a Spatie team, so a role granted in
 * one organization does not leak into another.
 */
beforeEach(function (): void {
    $this->roles = app(Roles::class);
    $this->access = app(AccessChecker::class);
    $this->user = $this->makeUser('01JUSERTEAMAAAAAAAAAAAAAAA');
});

it('scopes a role and its permissions to the organization it was assigned in', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $this->roles->grantPermission('org_a', $role->id, 'posts.edit');
    $this->roles->assign('org_a', $this->user->id, $role->id);

    expect($this->access->can($this->user->id, 'posts.edit', 'org_a'))->toBeTrue()
        ->and($this->access->can($this->user->id, 'posts.edit', 'org_b'))->toBeFalse()
        ->and($this->access->permissionsFor($this->user->id, 'org_a'))->toBe(['posts.edit'])
        ->and($this->access->permissionsFor($this->user->id, 'org_b'))->toBe([]);
});

it('scopes assignment enumeration per organization', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $this->roles->assign('org_a', $this->user->id, $role->id);

    expect($this->roles->assignmentsInOrganization('org_a'))->toHaveCount(1)
        ->and($this->roles->assignmentsInOrganization('org_b'))->toBe([]);
});
