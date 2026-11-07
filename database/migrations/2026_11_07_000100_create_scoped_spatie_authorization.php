<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_spatie_grant_subjects', function (Blueprint $table): void {
            $table->string('environment_id');
            $table->string('user_id');
            $table->primary(['environment_id', 'user_id']);
        });
        Schema::create('id_spatie_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('environment_id')->index();
            $table->string('organization_id')->nullable()->index();
            $table->string('client_id')->nullable()->index();
            $table->string('scope_key', 64)->unique();
            $table->string('spatie_role_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('tenant_assignable')->default(true);
            $table->string('source')->default('manual');
            $table->timestamp('orphaned_at')->nullable();
            $table->timestamps();
        });
        foreach (['id_spatie_assignments', 'id_spatie_everywhere_assignments'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->ulid('id')->primary();
                $table->string('environment_id')->index();
                $table->string('user_id')->index();
                $table->ulid('role_id')->index();
                $table->string('source')->default('manual');
                if ($name === 'id_spatie_assignments') {
                    $table->string('organization_id')->index();
                    $table->unique(['environment_id', 'organization_id', 'user_id', 'role_id'], 'id_spatie_assignment_unique');
                } else {
                    $table->unique(['environment_id', 'user_id', 'role_id'], 'id_spatie_everywhere_unique');
                }
                $table->timestamps();
            });
        }
        // Legacy mappings/grants have no verifiable environment. Leave them quarantined
        // with NULL: only explicitly recreated mappings enter the new scoped queries.
        foreach (['id_spatie_group_role_mappings', 'id_spatie_pushed_assignments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->string('environment_id')->nullable()->index();
                if ($name === 'id_spatie_group_role_mappings') {
                    $table->dropUnique('id_spatie_grm_unique');
                    $table->unique(['environment_id', 'organization_id', 'group_id', 'role_id'], 'id_spatie_grm_env_unique');
                } else {
                    $table->dropUnique('id_spatie_pushed_unique');
                    $table->unique(['environment_id', 'organization_id', 'user_id', 'role_id'], 'id_spatie_pushed_env_unique');
                }
            });
        }
    }

    public function down(): void
    {
        // Older storage cannot represent these authorities. Refuse before dropping
        // any table/index; operators must explicitly revoke/migrate first.
        foreach (['id_spatie_roles', 'id_spatie_assignments', 'id_spatie_everywhere_assignments'] as $name) {
            if (DB::table($name)->exists()) {
                throw new LogicException('Cannot roll back scoped authorization while managed definitions or grants exist.');
            }
        }
        foreach (['id_spatie_group_role_mappings', 'id_spatie_pushed_assignments'] as $name) {
            if (DB::table($name)->whereNotNull('environment_id')->exists()) {
                throw new LogicException('Cannot roll back scoped authorization while environment-scoped mappings or ledger entries exist.');
            }
        }
        Schema::dropIfExists('id_spatie_grant_subjects');
        Schema::dropIfExists('id_spatie_everywhere_assignments');
        Schema::dropIfExists('id_spatie_assignments');
        Schema::dropIfExists('id_spatie_roles');
        foreach (['id_spatie_group_role_mappings', 'id_spatie_pushed_assignments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if ($name === 'id_spatie_group_role_mappings') {
                    $table->dropUnique('id_spatie_grm_env_unique');
                    $table->unique(['organization_id', 'group_id', 'role_id'], 'id_spatie_grm_unique');
                } else {
                    $table->dropUnique('id_spatie_pushed_env_unique');
                    $table->unique(['organization_id', 'user_id', 'role_id'], 'id_spatie_pushed_unique');
                }
                $table->dropColumn('environment_id');
            });
        }
    }
};
