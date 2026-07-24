<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Exceptions;

use Cbox\Id\Spatie\Contracts\SpatieSubject;
use RuntimeException;

/**
 * Thrown when `id-spatie.user_model` (or the app's default auth model) is not a
 * usable authorizable subject for this adapter — it must be an Eloquent model that
 * implements {@see SpatieSubject} (i.e. uses Spatie's HasRoles trait). Failing loud
 * here beats silently denying every check against a model that can never answer.
 */
class MisconfiguredUserModel extends RuntimeException
{
    public static function invalidClass(mixed $model): self
    {
        $given = is_string($model) ? $model : get_debug_type($model);

        return new self(
            "id-spatie.user_model must be an Eloquent model class name, got [{$given}]. "
            .'Set it in config/id-spatie.php or via auth.providers.users.model.'
        );
    }

    public static function notSpatieSubject(string $model): self
    {
        return new self(
            "The user model [{$model}] must implement ".SpatieSubject::class
            .' (add the interface to the model that already uses Spatie\'s HasRoles trait). '
            .'See docs/getting-started/installation.md.'
        );
    }
}
