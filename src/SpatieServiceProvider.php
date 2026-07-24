<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\Roles;
use Illuminate\Support\ServiceProvider;

/**
 * Wires spatie/laravel-permission in as the platform's authorization backend.
 *
 * The adapters are bound only when `cbox-id.access_control.driver` is `external` —
 * the platform's bring-your-own-RBAC mode, in which its own RBAC tables and services
 * are gated off. Under the builtin driver this provider binds nothing, so the two
 * RBACs never collide. Bindings happen in boot() so they win over the platform's
 * deny-by-default fallbacks regardless of provider registration order.
 */
class SpatieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/id-spatie.php', 'id-spatie');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/id-spatie.php' => config_path('id-spatie.php'),
            ], 'id-spatie-config');
        }

        if (config('cbox-id.access_control.driver') !== 'external') {
            return;
        }

        $this->app->singleton(AccessChecker::class, SpatieAccessChecker::class);
        $this->app->singleton(Roles::class, SpatieRoles::class);
    }
}
