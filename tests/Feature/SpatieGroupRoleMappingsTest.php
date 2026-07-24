<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\Spatie\SpatieGroupRoleMappings;

/**
 * The SCIM directory-group → role bridge against a Spatie backend.
 */
beforeEach(function (): void {
    $this->roles = app(Roles::class);
    $this->access = app(AccessChecker::class);
    $this->mappings = app(GroupRoleMappings::class);

    $this->user = $this->makeUser('01JGRPUSERAAAAAAAAAAAAAAAA');
    $this->directory = $this->makeDirectory('org_1');
    $this->group = $this->makeGroup($this->directory);

    $this->role = $this->roles->define('org_1', 'engineer');
    $this->roles->grantPermission('org_1', $this->role->id, 'deploy.run');
});

it('is bound under the external driver', function (): void {
    expect($this->mappings)->toBeInstanceOf(SpatieGroupRoleMappings::class);
});

it('grants a mapped role to everyone already in the group', function (): void {
    $this->addGroupMember($this->directory, $this->group, $this->user->id);

    $mapping = $this->mappings->map('org_1', $this->group, $this->role->id);

    expect($mapping)->toBeInstanceOf(GroupRoleMapping::class)
        ->and($mapping->group_id)->toBe($this->group)
        ->and($mapping->role_id)->toBe($this->role->id)
        ->and($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeTrue();
});

it('grants the mapped role when a subject joins the group', function (): void {
    $this->mappings->map('org_1', $this->group, $this->role->id);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeFalse();

    $this->addGroupMember($this->directory, $this->group, $this->user->id);
    $this->mappings->reconcileGroup($this->group);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeTrue();
});

it('revokes the derived role when a subject leaves the group', function (): void {
    $this->addGroupMember($this->directory, $this->group, $this->user->id);
    $this->mappings->map('org_1', $this->group, $this->role->id);

    $this->removeGroupMember($this->group, $this->user->id);
    $this->mappings->reconcileGroup($this->group);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeFalse();
});

it('revokes derived roles when the mapping is removed', function (): void {
    $this->addGroupMember($this->directory, $this->group, $this->user->id);
    $this->mappings->map('org_1', $this->group, $this->role->id);

    $this->mappings->unmap('org_1', $this->group, $this->role->id);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeFalse()
        ->and($this->mappings->forOrganization('org_1'))->toBe([]);
});

it('reports a derived grant with the pushed source', function (): void {
    $this->addGroupMember($this->directory, $this->group, $this->user->id);
    $this->mappings->map('org_1', $this->group, $this->role->id);

    $held = $this->roles->assignmentsForSubject('org_1', $this->user->id);

    expect($held)->toHaveCount(1)
        ->and($held[0]->source)->toBe(GrantSource::Pushed);
});

it('lists an organization\'s mappings', function (): void {
    $this->mappings->map('org_1', $this->group, $this->role->id, priority: 5);

    $all = $this->mappings->forOrganization('org_1');

    expect($all)->toHaveCount(1)
        ->and($all[0]->group_id)->toBe($this->group)
        ->and($all[0]->priority)->toBe(5);
});

it('refuses to map an unknown role before writing the mapping', function (): void {
    expect(fn () => $this->mappings->map('org_1', $this->group, '999999'))
        ->toThrow(UnknownRole::class);

    // No poison-pill row is left behind to wedge later syncs.
    expect($this->mappings->forOrganization('org_1'))->toBe([]);
});

it('ignores an inactive directory user', function (): void {
    $this->addGroupMember($this->directory, $this->group, $this->user->id, active: false);
    $this->mappings->map('org_1', $this->group, $this->role->id);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeFalse();
});

/**
 * The safety property that makes reconciliation trustworthy: it only ever removes what
 * it granted.
 */
it('never reconciles away a manually granted role', function (): void {
    $manual = $this->roles->define('org_1', 'admin');
    $this->roles->grantPermission('org_1', $manual->id, 'org.manage');
    $this->roles->assign('org_1', $this->user->id, $manual->id);

    $this->addGroupMember($this->directory, $this->group, $this->user->id);
    $this->mappings->map('org_1', $this->group, $this->role->id);

    // A full reconcile, then the subject leaves the group entirely.
    $this->removeGroupMember($this->group, $this->user->id);
    $this->mappings->reconcileGroup($this->group);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeFalse()
        ->and($this->access->can($this->user->id, 'org.manage', 'org_1'))->toBeTrue();
});

it('keeps a manual grant that a push later duplicated', function (): void {
    // Manually granted first, then the same role arrives through the directory.
    $this->roles->assign('org_1', $this->user->id, $this->role->id);
    $this->addGroupMember($this->directory, $this->group, $this->user->id);
    $this->mappings->map('org_1', $this->group, $this->role->id);

    // The directory grant goes away; the admin's original grant must remain.
    $this->removeGroupMember($this->group, $this->user->id);
    $this->mappings->reconcileGroup($this->group);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeTrue();
});

it('still lets an admin revoke a manual grant', function (): void {
    $this->roles->assign('org_1', $this->user->id, $this->role->id);
    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeTrue();

    $this->roles->unassign('org_1', $this->user->id, $this->role->id);

    expect($this->access->can($this->user->id, 'deploy.run', 'org_1'))->toBeFalse();
});
