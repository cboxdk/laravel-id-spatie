<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the directory-group→role bridge under the external RBAC driver.
 *
 * The platform's own `group_role_mappings` table is gated off in this mode, and Spatie
 * models neither a mapping nor a grant's ORIGIN — so both live here:
 *
 * - `id_spatie_group_role_mappings` — everyone in a directory group holds a role.
 * - `id_spatie_pushed_assignments`  — the ledger of grants this bridge made. It is what
 *   makes reconciliation safe: only a grant recorded here is ever revoked, so an
 *   admin's hand-granted role always survives a directory sync. `pre_existing` marks a
 *   role the subject ALREADY held when the push landed, so revoking the push drops the
 *   ledger row without stripping the underlying manual grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_spatie_group_role_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('group_id')->index();
            $table->string('role_id');
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'group_id', 'role_id'], 'id_spatie_grm_unique');
        });

        Schema::create('id_spatie_pushed_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('user_id')->index();
            $table->string('role_id');
            $table->boolean('pre_existing')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'role_id'], 'id_spatie_pushed_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_spatie_pushed_assignments');
        Schema::dropIfExists('id_spatie_group_role_mappings');
    }
};
