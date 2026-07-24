<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Exceptions;

use RuntimeException;

/**
 * Thrown when a role assignment targets a subject that does not exist in the host's
 * user table — the platform asked to grant a role to someone the backend cannot find.
 */
class UnknownSubject extends RuntimeException
{
    public static function make(string $userId): self
    {
        return new self("Subject [{$userId}] was not found in the configured user model.");
    }
}
