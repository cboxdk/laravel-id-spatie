<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Models;

use Cbox\Id\AccessControl\Models\Role;

/**
 * @property string $spatie_role_id
 * @property string $scope_key
 */
class ManagedRole extends Role
{
    protected $table = 'id_spatie_roles';
}
