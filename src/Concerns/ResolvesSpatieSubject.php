<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Concerns;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Spatie\Contracts\SpatieSubject;
use Cbox\Id\Spatie\Exceptions\MisconfiguredUserModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the host's authorizable model and the guard/permission plumbing the
 * adapters share. The resolved subject is always a {@see SpatieSubject}; a model
 * that cannot answer is a configuration error, surfaced loud rather than denied.
 */
trait ResolvesSpatieSubject
{
    private function resolveSubject(string $userId): ?SpatieSubject
    {
        $subject = $this->userModelClass()::find($userId);

        if ($subject === null || $subject->getAttribute('environment_id') !== app(EnvironmentContext::class)->requireEnvironment()->environmentKey()) {
            return null;
        }

        if (! $subject instanceof SpatieSubject) {
            throw MisconfiguredUserModel::notSpatieSubject($subject::class);
        }

        return $subject;
    }

    /**
     * @return class-string<Model>
     */
    private function userModelClass(): string
    {
        $model = config('id-spatie.user_model') ?: config('auth.providers.users.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw MisconfiguredUserModel::invalidClass($model);
        }

        return $model;
    }

    private function guard(): string
    {
        $guard = config('id-spatie.guard', 'web');

        return is_string($guard) ? $guard : 'web';
    }
}
