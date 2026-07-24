<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Tests\Fixtures;

use Cbox\Id\Spatie\Contracts\SpatieSubject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A host application's user model as this package expects to find it: it uses
 * Spatie's HasRoles trait and declares the SpatieSubject contract (the one line a
 * host adds). It maps to the platform's shared `users` table, keyed by the same ULID
 * the platform uses for a subject.
 *
 * @property string $id
 */
class User extends Authenticatable implements SpatieSubject
{
    use HasRoles;
    use HasUlids;

    protected $table = 'users';

    protected $guarded = [];
}
