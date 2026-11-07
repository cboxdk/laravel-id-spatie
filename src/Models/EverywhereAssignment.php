<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Models;

use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;

/** Adapter-owned authorization storage; never the host Spatie tables. */
class EverywhereAssignment extends EnvironmentRoleAssignment
{
    protected $table = 'id_spatie_everywhere_assignments';
}
