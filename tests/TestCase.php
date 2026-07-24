<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Tests;

use Cbox\Id\IdServiceProvider;
use Cbox\Id\Spatie\SpatieServiceProvider;
use Cbox\Id\Spatie\Tests\Fixtures\User;
use Cbox\Ssrf\SsrfServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether Spatie's teams feature (and org→team scoping) is on for this case.
     */
    protected bool $teams = false;

    protected function defineDatabaseMigrations(): void
    {
        // The platform's shared users table (publishable, not auto-loaded) plus
        // Spatie's own permission tables. The platform's RBAC tables are gated off by
        // the external driver, so Spatie's `roles`/`permissions` own those names.
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/cboxdk/laravel-id/database/publishable');

        // Everything the providers register: the platform's own tables (including the
        // directory/SCIM tables the bridge reconciles against) and this package's
        // mapping + pushed-ledger tables. The platform's RBAC migrations stay gated off
        // by the external driver, so Spatie owns roles/permissions.
        $this->artisan('migrate')->run();

        $stub = dirname(__DIR__).'/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        (require $stub)->up();
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        // SpatieServiceProvider is registered last so its boot() rebinds the platform
        // contracts after the platform's own AccessControl provider has run.
        return [
            SsrfServiceProvider::class,
            IdServiceProvider::class,
            PermissionServiceProvider::class,
            SpatieServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cbox-id.crypto.key', base64_encode(random_bytes(32)));
        $app['config']->set('cbox-id.environments.default', 'env_test');

        // Bring-your-own RBAC: the platform gates its own RBAC off and this package
        // binds Spatie in its place.
        $app['config']->set('cbox-id.access_control.driver', 'external');

        // Point the adapter at the fixture user model and mirror the teams toggle
        // into Spatie's own config so the migration builds the matching schema.
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('id-spatie.user_model', User::class);
        $app['config']->set('id-spatie.teams', $this->teams);
        $app['config']->set('permission.teams', $this->teams);
    }

    /**
     * Create a directory (a SCIM connection) owned by an organization.
     */
    protected function makeDirectory(string $organizationId, string $id = 'dir_1'): string
    {
        DB::table('directories')->insert([
            'id' => $id,
            'environment_id' => 'env_test',
            'organization_id' => $organizationId,
            'name' => 'Test directory',
            'bearer_token_hash' => hash('sha256', $id),
            'status' => 'active',
            'mappings' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Create a directory group inside a directory.
     */
    protected function makeGroup(string $directoryId, string $id = 'grp_1', string $name = 'Engineering'): string
    {
        DB::table('directory_groups')->insert([
            'id' => $id,
            'environment_id' => 'env_test',
            'directory_id' => $directoryId,
            'external_id' => $id,
            'display_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Link a platform user into a directory group as an active member.
     */
    protected function addGroupMember(string $directoryId, string $groupId, string $userId, bool $active = true): string
    {
        $directoryUserId = 'du_'.$userId;

        DB::table('directory_users')->insert([
            'id' => $directoryUserId,
            'environment_id' => 'env_test',
            'directory_id' => $directoryId,
            'external_id' => $directoryUserId,
            'resource' => '{}',
            'user_id' => $userId,
            'active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('directory_group_members')->insert([
            'group_id' => $groupId,
            'directory_user_id' => $directoryUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $directoryUserId;
    }

    /**
     * Remove a subject from a directory group (membership only; the link survives).
     */
    protected function removeGroupMember(string $groupId, string $userId): void
    {
        DB::table('directory_group_members')
            ->where('group_id', $groupId)
            ->where('directory_user_id', 'du_'.$userId)
            ->delete();
    }

    /**
     * Create a user in the shared users table, keyed by an explicit id when given.
     */
    protected function makeUser(?string $id = null, string $email = 'user@example.test'): User
    {
        $user = new User;

        if ($id !== null) {
            $user->id = $id;
        }

        $user->forceFill([
            'environment_id' => 'env_test',
            'email' => $email,
        ])->save();

        return $user;
    }
}
