<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Spatie\SpatieAccessChecker;
use Cbox\Id\Spatie\SpatieRoles;

/**
 * Under the external access-control driver the provider binds the Spatie adapters
 * over the platform's deny-by-default fallbacks.
 */
it('binds the Spatie adapters to the platform contracts', function (): void {
    expect(app(AccessChecker::class))->toBeInstanceOf(SpatieAccessChecker::class)
        ->and(app(Roles::class))->toBeInstanceOf(SpatieRoles::class);
});
