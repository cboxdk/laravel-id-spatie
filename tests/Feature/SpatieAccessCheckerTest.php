<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\ValueObjects\AppAccessClaims;

/**
 * The read path: platform authorization checks and token claims resolve from the
 * host's Spatie roles and permissions.
 */
beforeEach(function (): void {
    $this->roles = app(Roles::class);
    $this->access = app(AccessChecker::class);

    $this->user = $this->makeUser('01JUSERAAAAAAAAAAAAAAAAAAA');

    $role = $this->roles->define('org_1', 'editor');
    $this->roles->grantPermission('org_1', $role->id, 'posts.edit');
    $this->roles->grantPermission('org_1', $role->id, 'posts.publish');
    $this->roles->assign('org_1', $this->user->id, $role->id);
});

it('grants a permission the subject holds and denies one it does not', function (): void {
    expect($this->access->can($this->user->id, 'posts.edit', 'org_1'))->toBeTrue()
        ->and($this->access->can($this->user->id, 'posts.delete', 'org_1'))->toBeFalse();
});

it('denies an unknown permission instead of erroring', function (): void {
    expect($this->access->can($this->user->id, 'nonexistent.permission', 'org_1'))->toBeFalse();
});

it('denies an unknown subject', function (): void {
    expect($this->access->can('01JMISSINGAAAAAAAAAAAAAAAAA', 'posts.edit', 'org_1'))->toBeFalse();
});

it('lists the effective permission names', function (): void {
    expect($this->access->permissionsFor($this->user->id, 'org_1'))
        ->toEqualCanonicalizing(['posts.edit', 'posts.publish']);
});

it('stamps roles and permissions into a token', function (): void {
    $claims = $this->access->forToken($this->user->id, 'org_1', 'client_1');

    expect($claims)->toBeInstanceOf(AppAccessClaims::class)
        ->and($claims->roles)->toBe(['editor'])
        ->and($claims->permissions)->toEqualCanonicalizing(['posts.edit', 'posts.publish']);
});

it('returns empty claims for an unknown subject', function (): void {
    expect($this->access->forToken('01JMISSINGAAAAAAAAAAAAAAAAA', 'org_1', 'client_1'))
        ->toEqual(new AppAccessClaims([], []));
});
