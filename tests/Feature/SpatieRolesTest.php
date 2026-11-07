<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\Role as PlatformRole;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Spatie\Exceptions\UnknownSubject;
use Cbox\Id\Spatie\SpatieRoles;

/**
 * The write path: role authoring and assignment drive into Spatie, and the read-back
 * surfaces the platform's Roles contract expects (for SCIM + governance) round-trip.
 */
beforeEach(function (): void {
    $this->roles = app(Roles::class);
    $this->user = $this->makeUser('01JUSERBBBBBBBBBBBBBBBBBBB');
});

it('defines a role backed by a real Spatie role', function (): void {
    $role = $this->roles->define('org_1', 'manager');

    expect($role)->toBeInstanceOf(PlatformRole::class)
        ->and($role->name)->toBe('manager')
        ->and($role->source)->toBe(RoleSource::Manual)
        ->and($role->id)->toBe($role->id);
});

it('grants a permission to a role', function (): void {
    $role = $this->roles->define('org_1', 'manager');
    $this->roles->grantPermission('org_1', $role->id, 'billing.read');

    expect(app(SpatieRoles::class)->nativeRole($role)->hasPermissionTo('billing.read'))->toBeTrue();
});

it('assigns and reads back a subject\'s assignments', function (): void {
    $role = $this->roles->define('org_1', 'manager');
    $assignment = $this->roles->assign('org_1', $this->user->id, $role->id);

    expect($assignment)->toBeInstanceOf(RoleAssignment::class)
        ->and($assignment->user_id)->toBe($this->user->id)
        ->and($assignment->role_id)->toBe($role->id)
        ->and($assignment->organization_id)->toBe('org_1');

    $held = $this->roles->assignmentsForSubject('org_1', $this->user->id);

    expect($held)->toHaveCount(1)
        ->and($held[0])->toBe($role->id);
});

it('enumerates every assignment in an organization', function (): void {
    $role = $this->roles->define('org_1', 'manager');
    $other = $this->makeUser('01JUSERCCCCCCCCCCCCCCCCCCC', 'other@example.test');

    $this->roles->assign('org_1', $this->user->id, $role->id);
    $this->roles->assign('org_1', $other->id, $role->id);

    $all = $this->roles->assignmentsInOrganization('org_1');

    expect($all)->toHaveCount(2)
        ->and(collect($all)->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->user->id, $other->id]);
});

it('unassigns a role', function (): void {
    $role = $this->roles->define('org_1', 'manager');
    $this->roles->assign('org_1', $this->user->id, $role->id);
    $this->roles->unassign('org_1', $this->user->id, $role->id);

    expect($this->roles->assignmentsForSubject('org_1', $this->user->id))->toBe([]);
});

it('refuses to assert an unknown role', function (): void {
    expect(fn () => $this->roles->assertAssignableIn('org_1', '999999'))
        ->toThrow(UnknownRole::class);
});

it('refuses to assign a role to an unknown subject', function (): void {
    $role = $this->roles->define('org_1', 'manager');

    expect(fn () => $this->roles->assign('org_1', '01JMISSINGAAAAAAAAAAAAAAAAA', $role->id))
        ->toThrow(UnknownSubject::class);
});
