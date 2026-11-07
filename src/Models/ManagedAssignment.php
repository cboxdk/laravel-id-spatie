<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Models;

use Cbox\Id\AccessControl\Models\RoleAssignment;

/** Adapter-owned authorization storage; never the host Spatie tables. */
class ManagedAssignment extends RoleAssignment
{
    protected $table = 'id_spatie_assignments';
}
