<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Tests\Concurrency;

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\GrantGuard;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;
use Cbox\Id\Spatie\Models\ManagedAssignment;
use Cbox\Id\Spatie\SpatieRoles;
use Cbox\Id\Spatie\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/** Opt in with SPATIE_PG_RACES=1 and PGHOST/PGPORT/PGUSER/PGPASSWORD. Creates only disposable databases. */
class PostgresGrantRaceTest extends TestCase
{
    private ?string $raceDatabase = null;

    protected function setUp(): void
    {
        if (getenv('SPATIE_PG_RACES') !== '1' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires opt-in PostgreSQL and pcntl.');
        }
        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->raceDatabase = 'id_spatie_race_'.bin2hex(random_bytes(8));
        $this->admin()->exec('CREATE DATABASE "'.$this->raceDatabase.'"');
        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1',
            'port' => getenv('PGPORT') ?: '5432', 'database' => $this->raceDatabase,
            'username' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        // Spatie's published integer template must support the host's string IDs.
        foreach (['roles', 'model_has_roles', 'model_has_permissions'] as $table) {
            DB::statement('ALTER TABLE '.$table.' ALTER COLUMN team_id TYPE varchar(255) USING team_id::text');
        }
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::statement('ALTER TABLE '.$table.' ALTER COLUMN model_id TYPE varchar(255) USING model_id::text');
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->raceDatabase !== null) {
                $this->admin()->exec('DROP DATABASE "'.$this->raceDatabase.'" WITH (FORCE)');
            }
        }
    }

    private function admin(): PDO
    {
        return new PDO('pgsql:host='.(getenv('PGHOST') ?: '127.0.0.1').';port='.(getenv('PGPORT') ?: '5432').';dbname=postgres', getenv('PGUSER') ?: 'postgres', getenv('PGPASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function test_manual_grant_survives_concurrent_pushed_revocation(): void
    {
        $roles = app(SpatieRoles::class);
        $user = $this->makeUser();
        $role = $roles->define('org_a', 'editor', clientId: 'client_1');
        $roles->grantPermission('org_a', $role->id, 'posts.edit');
        $roles->assignAsTenant('org_a', $user->id, $role->id, GrantSource::Pushed);

        $result = $this->race(
            fn () => app(SpatieRoles::class)->unassignPushed('org_a', $user->id, $role->id),
            fn () => app(SpatieRoles::class)->assignAsTenant('org_a', $user->id, $role->id),
        );
        self::assertSame('ok', $result);
        self::assertSame(['posts.edit'], app(AccessChecker::class)->forToken($user->id, 'org_a', 'client_1')->permissions);
        // A later reconciliation must also preserve the new manual authority.
        self::assertSame('manual', DB::table('id_spatie_assignments')->where('user_id', $user->id)->value('source'));
    }

    public function test_tenant_assignment_rechecks_staff_flag_after_waiting_for_update(): void
    {
        $roles = app(SpatieRoles::class);
        $user = $this->makeUser();
        $role = $roles->define(null, 'support', clientId: 'client_1');
        $roles->grantPermission(null, $role->id, 'support.read');
        $result = $this->race(
            fn () => app(SpatieRoles::class)->updateRole($role->id, 'support', tenantAssignable: false),
            fn () => app(SpatieRoles::class)->assignAsTenant('org_a', $user->id, $role->id),
        );
        self::assertSame(RoleNotTenantAssignable::class, $result);
        self::assertSame([], app(AccessChecker::class)->forToken($user->id, 'org_a', 'client_1')->permissions);
        self::assertSame(0, DB::table('id_spatie_assignments')->count());
    }

    public function test_different_role_grants_cannot_create_a_concurrent_toxic_pair(): void
    {
        $roles = app(SpatieRoles::class);
        $user = $this->makeUser();
        $requester = $roles->define('org_a', 'requester', clientId: 'client_1');
        $approver = $roles->define('org_a', 'approver', clientId: 'client_1');
        app()->instance(GrantGuard::class, new class implements GrantGuard
        {
            public function refuse(string $organizationId, string $userId, string $roleId, GrantSource $source = GrantSource::Manual): ?string
            {
                return ManagedAssignment::query()
                    ->where('organization_id', $organizationId)->where('user_id', $userId)
                    ->where('role_id', '!=', $roleId)->exists() ? 'Requesting and approving cannot be combined.' : null;
            }
        });
        $result = $this->race(
            fn () => app(SpatieRoles::class)->assignAsTenant('org_a', $user->id, $requester->id),
            fn () => app(SpatieRoles::class)->assignAsTenant('org_a', $user->id, $approver->id),
        );
        self::assertSame(GrantRefused::class, $result);
        self::assertSame([$requester->id], $roles->assignmentsForSubject('org_a', $user->id));
        self::assertSame(1, DB::table('id_spatie_assignments')->count());
    }

    public function test_populated_legacy_ledgers_are_quarantined_without_blocking_scoped_recreation(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_07_000100_create_scoped_spatie_authorization.php';
        // Return this fresh disposable database to the actual previous schema.
        $migration->down();
        DB::table('id_spatie_group_role_mappings')->insert([
            'organization_id' => 'org_a', 'group_id' => 'group_a', 'role_id' => 'legacy_role', 'priority' => 0,
        ]);
        DB::table('id_spatie_pushed_assignments')->insert([
            'organization_id' => 'org_a', 'user_id' => 'user_a', 'role_id' => 'legacy_role', 'pre_existing' => true,
        ]);
        $migration->up();
        self::assertSame(1, DB::table('id_spatie_group_role_mappings')->whereNull('environment_id')->count());
        self::assertSame(1, DB::table('id_spatie_pushed_assignments')->whereNull('environment_id')->count());
        foreach (['env_test', 'env_other'] as $environment) {
            DB::table('id_spatie_group_role_mappings')->insert([
                'environment_id' => $environment, 'organization_id' => 'org_a', 'group_id' => 'group_a', 'role_id' => 'legacy_role', 'priority' => 0,
            ]);
            DB::table('id_spatie_pushed_assignments')->insert([
                'environment_id' => $environment, 'organization_id' => 'org_a', 'user_id' => 'user_a', 'role_id' => 'legacy_role', 'pre_existing' => false,
            ]);
        }
        self::assertSame(3, DB::table('id_spatie_group_role_mappings')->count());
        self::assertSame(3, DB::table('id_spatie_pushed_assignments')->count());
        try {
            $migration->down();
            self::fail('Downgrade must refuse scoped data before mutating any schema.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Cannot roll back', $error->getMessage());
        }
        self::assertSame(3, DB::table('id_spatie_group_role_mappings')->count());
        self::assertSame(3, DB::table('id_spatie_pushed_assignments')->count());
        self::assertTrue(Schema::hasTable('id_spatie_roles'));
    }

    /** A real second backend waits on the first transaction; no sleep-only ordering assertions. */
    private function race(callable $first, callable $second): string
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        DB::disconnect(); // Never share a PostgreSQL connection across fork.
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            fgets($sockets[1]);
            DB::purge();
            DB::statement("SET application_name = 'id_spatie_race_child'");
            DB::statement("SET lock_timeout = '10s'");
            try {
                $second();
                $result = 'ok';
            } catch (Throwable $error) {
                $result = $error::class;
            }
            fwrite($sockets[1], $result."\n");
            fclose($sockets[1]);
            DB::disconnect();
            exit(0);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 15);
        try {
            DB::beginTransaction();
            $first();
            fwrite($sockets[0], "go\n");
            $blocked = false;
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $row = DB::selectOne("SELECT count(*) AS waiting FROM pg_stat_activity WHERE datname = current_database() AND application_name = 'id_spatie_race_child' AND wait_event_type = 'Lock'");
                if ($row !== null && (int) $row->waiting > 0) {
                    $blocked = true;
                    break;
                }
                usleep(10000);
            }
            DB::commit();
            $result = fgets($sockets[0]);
            self::assertTrue($blocked, 'Child must demonstrably block on a PostgreSQL lock.');
            self::assertIsString($result);

            return trim($result);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($sockets[0]);
            pcntl_waitpid($pid, $status);
        }
    }
}
