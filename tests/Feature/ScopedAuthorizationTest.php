<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Contracts\GrantGuard;
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Contracts\RoleDefinitions;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Spatie\Models\ManagedAssignment;
use Cbox\Id\Spatie\Models\ManagedRole;
use Cbox\Id\Spatie\PushedAssignmentLedger;
use Cbox\Id\Spatie\SpatieRoles;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as NativeRole;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->roles = app(Roles::class);
    $this->access = app(AccessChecker::class);
    $this->user = $this->makeUser();
    Client::query()->create(['client_id' => 'client_2', 'name' => 'Other app']);
});

it('keeps equal role and permission names separate across apps and organizations for one human', function (): void {
    $a = $this->roles->define('org_a', 'editor', clientId: 'client_1');
    $b = $this->roles->define('org_b', 'editor', clientId: 'client_2');
    foreach ([$a, $b] as $role) {
        $this->roles->grantPermission($role->organization_id, $role->id, 'posts.edit');
    }
    $this->roles->assignAsTenant('org_a', $this->user->id, $a->id);
    expect($this->access->forToken($this->user->id, 'org_a', 'client_1')->permissions)->toBe(['posts.edit'])
        ->and($this->access->forToken($this->user->id, 'org_a', 'client_2')->permissions)->toBe([])
        ->and($this->access->forToken($this->user->id, 'org_b', 'client_1')->permissions)->toBe([])
        ->and($this->access->forToken($this->user->id, null, 'client_1')->permissions)->toBe([]);
    expect(fn () => $this->roles->assign('org_a', $this->user->id, $b->id))->toThrow(UnknownRole::class);
    $this->roles->assignAsTenant('org_b', $this->user->id, $b->id);
    expect($this->access->forToken($this->user->id, 'org_b', 'client_2')->permissions)->toBe(['posts.edit']);
});

it('requires an explicit environment grant and keeps it app scoped', function (): void {
    $role = $this->roles->define(null, 'support', clientId: 'client_1', tenantAssignable: false);
    $this->roles->grantPermission(null, $role->id, 'support.read');
    expect(fn () => $this->roles->assignAsTenant('org_a', $this->user->id, $role->id))->toThrow(RoleNotTenantAssignable::class);
    expect($this->roles->tenantAssignableRoles('org_a'))->toBe([]);
    $this->roles->assignEverywhere($this->user->id, $role->id);
    expect($this->access->forToken($this->user->id, null, 'client_1')->permissions)->toBe(['support.read'])
        ->and($this->access->forToken($this->user->id, null, 'client_2')->permissions)->toBe([]);
    $this->roles->unassignEverywhere($this->user->id, $role->id);
    expect($this->access->forToken($this->user->id, null, 'client_1')->permissions)->toBe([]);
});

it('never imports legacy roles direct permissions or forged native assignments', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId('org_a');
    $native = NativeRole::create(['name' => 'legacy_admin', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'everything', 'guard_name' => 'web']);
    $native->givePermissionTo($permission);
    $this->user->assignRole($native);
    $this->user->givePermissionTo($permission);
    $managed = $this->roles->define('org_a', 'managed');
    $this->roles->grantPermission('org_a', $managed->id, 'managed.write');
    $this->user->assignRole(app(SpatieRoles::class)->nativeRole($managed));
    expect($this->access->forToken($this->user->id, 'org_a', 'client_1')->permissions)->toBe([])
        ->and($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([]);
    expect(fn () => $this->roles->assign('org_a', $this->user->id, (string) $native->id))->toThrow(UnknownRole::class);
});

it('denies cross environment roles subjects clients and equal visible names', function (): void {
    $a = $this->roles->define('org_a', 'editor', clientId: 'client_1');
    $this->roles->grantPermission('org_a', $a->id, 'posts.edit');
    $this->roles->assign('org_a', $this->user->id, $a->id);
    app(EnvironmentContext::class)->set(GenericEnvironment::of('other_env'));
    Client::query()->create(['client_id' => 'other_client', 'name' => 'Other environment']);
    $b = $this->roles->define('org_a', 'editor', clientId: 'other_client');
    expect($b->id)->not->toBe($a->id)
        ->and($this->access->forToken($this->user->id, 'org_a', 'client_1')->permissions)->toBe([])
        ->and($this->access->forToken($this->user->id, 'org_a', 'other_client')->permissions)->toBe([]);
    expect(fn () => $this->roles->assign('org_a', $this->user->id, $a->id))->toThrow(UnknownRole::class);
    expect(fn () => $this->roles->define('org_a', 'bad', clientId: 'client_1'))->toThrow(LogicException::class);
    expect(fn () => $this->roles->define('org_a', 'bad', clientId: 'missing'))->toThrow(LogicException::class);
});

it('cannot unmark or rename staff and environment roles from the tenant plane', function (): void {
    $role = $this->roles->define(null, 'support', tenantAssignable: false);
    expect(fn () => $this->roles->updateRole($role->id, 'editor', organizationId: 'org_a', tenantAssignable: true))->toThrow(UnknownRole::class);
    expect(fn () => $this->roles->grantPermission('org_a', $role->id, 'all'))->toThrow(UnknownRole::class);
    expect(fn () => $this->roles->deleteRole($role->id, 'org_a'))->toThrow(UnknownRole::class);
});

it('revokes live despite a cached host relation and prevents grants returning after removal', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $this->roles->grantPermission('org_a', $role->id, 'posts.edit');
    $this->roles->assign('org_a', $this->user->id, $role->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId('org_a');
    $this->user->load('roles');
    expect($this->roles->unassignAll('org_a', $this->user->id))->toBe(1);
    expect($this->access->can($this->user->id, 'posts.edit', 'org_a'))->toBeFalse();
    $this->user->assignRole(app(SpatieRoles::class)->nativeRole($role));
    expect($this->access->can($this->user->id, 'posts.edit', 'org_a'))->toBeFalse();
});

it('removes revoked orphaned and deleted definitions from live claims', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $this->roles->grantPermission('org_a', $role->id, 'posts.edit');
    $this->roles->assign('org_a', $this->user->id, $role->id);
    $permission = Permission::findByName('posts.edit', 'web');
    $this->roles->revokePermission($role->id, (string) $permission->id, 'org_a');
    expect($this->access->can($this->user->id, 'posts.edit', 'org_a'))->toBeFalse();
    $this->roles->attachPermission($role->id, (string) $permission->id, 'org_a');
    ManagedRole::query()->whereKey($role->id)->update(['orphaned_at' => now()]);
    expect($this->access->can($this->user->id, 'posts.edit', 'org_a'))->toBeFalse();
    ManagedRole::query()->whereKey($role->id)->update(['orphaned_at' => null]);
    $this->roles->deleteRole($role->id, 'org_a');
    expect($this->roles->assignmentsInOrganization('org_a'))->toBe([])
        ->and($this->access->can($this->user->id, 'posts.edit', 'org_a'))->toBeFalse();
});

it('denies tenant operations in flat mode without importing flat grants', function (): void {
    config(['id-spatie.teams' => false, 'permission.teams' => false]);
    expect(fn () => $this->roles->define('org_a', 'editor'))->toThrow(LogicException::class);
    expect($this->roles->tenantAssignableRoles('org_a'))->toBe([])
        ->and($this->access->permissionsFor($this->user->id, 'org_a'))->toBe([]);
    $role = $this->roles->define(null, 'support');
    $this->roles->assignEverywhere($this->user->id, $role->id);
    expect($this->access->forToken($this->user->id, null, 'client_1')->roles)->toBe(['support']);
});

it('refuses staff and foreign directory mappings and ignores quarantined legacy ledgers', function (): void {
    $directory = $this->makeDirectory('org_a');
    $group = $this->makeGroup($directory);
    $staff = $this->roles->define(null, 'staff', tenantAssignable: false);
    expect(fn () => app(GroupRoleMappings::class)->map('org_a', $group, $staff->id))->toThrow(RoleNotTenantAssignable::class);
    $role = $this->roles->define(null, 'editor');
    expect(fn () => app(GroupRoleMappings::class)->map('org_b', $group, $role->id))->toThrow(LogicException::class);
    DB::table('id_spatie_pushed_assignments')->insert(['organization_id' => 'org_a', 'user_id' => $this->user->id, 'role_id' => $role->id, 'pre_existing' => false]);
    expect(app(PushedAssignmentLedger::class)->roleIdsFor('org_a', $this->user->id))->toBe([]);
});

it('preserves a manual grant made after a directory push but explicit admin revocation wins', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $this->roles->assign('org_a', $this->user->id, $role->id, GrantSource::Pushed);
    $this->roles->assign('org_a', $this->user->id, $role->id);
    app(SpatieRoles::class)->unassignPushed('org_a', $this->user->id, $role->id);
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([$role->id]);
    $this->roles->assign('org_a', $this->user->id, $role->id, GrantSource::Pushed);
    $this->roles->unassign('org_a', $this->user->id, $role->id);
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([]);
});

it('leaves external manifest staging host owned without querying builtin schema', function (): void {
    expect(app()->bound(AppManifests::class))->toBeFalse()
        ->and(app()->bound(RoleDefinitions::class))->toBeFalse()
        ->and(Schema::hasTable('role_permission'))->toBeFalse();
    expect(fn () => app(AppManifests::class))->toThrow(BindingResolutionException::class);
    expect(fn () => app(RoleDefinitions::class))->toThrow(BindingResolutionException::class);
});

it('denies existing tenant grants when either team configuration is disabled', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $this->roles->grantPermission('org_a', $role->id, 'posts.edit');
    $this->roles->assign('org_a', $this->user->id, $role->id);
    config(['permission.teams' => false]);
    expect($this->access->forToken($this->user->id, 'org_a', 'client_1')->permissions)->toBe([])
        ->and($this->roles->tenantAssignableRoles('org_a'))->toBe([])
        ->and($this->roles->assignmentsInOrganization('org_a'))->toBe([]);
    expect(fn () => $this->roles->assign('org_a', $this->user->id, $role->id))->toThrow(LogicException::class);
});

it('removes the last mapping for an empty group without leaving pushed grants', function (): void {
    $directory = $this->makeDirectory('org_a');
    $group = $this->makeGroup($directory);
    $this->addGroupMember($directory, $group, $this->user->id);
    $role = $this->roles->define('org_a', 'editor');
    $mappings = app(GroupRoleMappings::class);
    $mappings->map('org_a', $group, $role->id);
    $this->removeGroupMember($group, $this->user->id);
    $mappings->unmap('org_a', $group, $role->id);
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([]);
});

it('allows explicit scoped recreation alongside quarantined legacy rows', function (): void {
    $row = ['organization_id' => 'org_a', 'user_id' => $this->user->id, 'role_id' => 'legacy', 'pre_existing' => false];
    DB::table('id_spatie_pushed_assignments')->insert($row);
    DB::table('id_spatie_pushed_assignments')->insert($row + ['environment_id' => 'env_test']);
    DB::table('id_spatie_pushed_assignments')->insert($row + ['environment_id' => 'other_env']);
    expect(app(PushedAssignmentLedger::class)->roleIdsFor('org_a', $this->user->id))->toBe(['legacy'])
        ->and(DB::table('id_spatie_pushed_assignments')->count())->toBe(3);
});

it('rolls back a native projection when auditing fails', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    app()->instance(AuditLog::class, Mockery::mock(AuditLog::class, function ($mock): void {
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
    }));
    expect(fn () => $this->roles->assign('org_a', $this->user->id, $role->id))->toThrow(RuntimeException::class, 'audit unavailable');
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([])
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

it('sorts numeric-looking role and permission names bytewise', function (): void {
    foreach (['2', '10'] as $name) {
        $role = $this->roles->define('org_a', $name);
        $this->roles->grantPermission('org_a', $role->id, $name);
        $this->roles->assign('org_a', $this->user->id, $role->id);
    }
    $claims = $this->access->forToken($this->user->id, 'org_a', 'client_1');
    expect($claims->roles)->toBe(['10', '2'])->and($claims->permissions)->toBe(['10', '2']);
});

it('refuses a lossy schema rollback before altering any scoped authorization table', function (): void {
    $role = $this->roles->define('org_a', 'editor');
    $migration = require __DIR__.'/../../database/migrations/2026_11_07_000100_create_scoped_spatie_authorization.php';
    expect(fn () => $migration->down())->toThrow(LogicException::class);
    expect(ManagedRole::query()->find($role->id))->not->toBeNull()
        ->and(Schema::hasTable('id_spatie_assignments'))->toBeTrue()
        ->and(Schema::hasTable('id_spatie_everywhere_assignments'))->toBeTrue();
});

it('retries failed unmapping after the mapping and group membership are gone', function (): void {
    $directory = $this->makeDirectory('org_a');
    $group = $this->makeGroup($directory);
    $this->addGroupMember($directory, $group, $this->user->id);
    $role = $this->roles->define('org_a', 'editor');
    $mappings = app(GroupRoleMappings::class);
    $mappings->map('org_a', $group, $role->id);
    $this->removeGroupMember($group, $this->user->id);
    $audit = app(AuditLog::class);
    app()->instance(AuditLog::class, Mockery::mock(AuditLog::class, function ($mock): void {
        $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
    }));
    expect(fn () => $mappings->unmap('org_a', $group, $role->id))->toThrow(RuntimeException::class, 'audit unavailable');
    expect($mappings->forOrganization('org_a'))->toBe([])
        ->and(app(PushedAssignmentLedger::class)->roleIdsFor('org_a', $this->user->id))->toBe([$role->id]);
    app()->instance(AuditLog::class, $audit);
    $mappings->unmap('org_a', $group, $role->id);
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([])
        ->and(app(PushedAssignmentLedger::class)->roleIdsFor('org_a', $this->user->id))->toBe([]);
});

it('reconciles a conflicting replacement after its obsolete mapping is removed', function (): void {
    $directory = $this->makeDirectory('org_a');
    $group = $this->makeGroup($directory);
    $this->addGroupMember($directory, $group, $this->user->id);
    $old = $this->roles->define('org_a', 'requester');
    $new = $this->roles->define('org_a', 'approver');
    $mappings = app(GroupRoleMappings::class);
    $mappings->map('org_a', $group, $old->id);
    app()->instance(GrantGuard::class, new class implements GrantGuard
    {
        public function refuse(string $organizationId, string $userId, string $roleId, GrantSource $source = GrantSource::Manual): ?string
        {
            return ManagedAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->where('role_id', '!=', $roleId)->exists() ? 'Conflicting role.' : null;
        }
    });
    expect(fn () => $mappings->map('org_a', $group, $new->id))->toThrow(GrantRefused::class);
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([$old->id]);
    $mappings->unmap('org_a', $group, $old->id);
    expect($this->roles->assignmentsForSubject('org_a', $this->user->id))->toBe([$new->id]);
});
