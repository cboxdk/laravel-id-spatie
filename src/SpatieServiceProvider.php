<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Kernel\Events\EventDelivered;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(GroupRoleMappings::class, SpatieGroupRoleMappings::class);

        // The bridge's mapping + pushed-grant ledger tables. The platform's equivalents
        // are gated off in this mode.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'id-spatie-migrations');
        }

        // Reconcile group→role grants when a directory group's membership changes.
        // The platform's own listener is registered only under the builtin driver, so
        // the bridge supplies its own here.
        Event::listen(EventDelivered::class, function (EventDelivered $delivered): void {
            $this->app->make(ReconcileGroupRoles::class)->handle($delivered);
        });
    }
}
