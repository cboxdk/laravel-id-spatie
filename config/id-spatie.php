<?php

declare(strict_types=1);

return [
    /*
     * The host's authorizable Eloquent model — the class that carries roles and
     * permissions through spatie/laravel-permission's HasRoles trait. It MUST also
     * implement Cbox\Id\Spatie\Contracts\SpatieSubject (a one-line `implements` on a
     * model that already uses HasRoles) and be keyed by the same identifier the
     * platform uses for a subject (the shared users table). Null falls back to the
     * app's default auth provider model.
     */
    'user_model' => env('CBOX_ID_SPATIE_USER_MODEL'),

    /*
     * Whether spatie/laravel-permission's teams feature is enabled. When true, each
     * platform organization maps onto a Spatie team (the team id IS the organization
     * id), so a role or assignment is scoped to the organization it was made in —
     * matching the platform's per-organization RBAC. When false the backend is flat
     * and the organization id is ignored (single-tenant).
     */
    'teams' => (bool) env('CBOX_ID_SPATIE_TEAMS', false),

    /*
     * The Spatie guard the roles and permissions are defined under. Roles created
     * and resolved by this adapter use it; it must match the guard the host assigns
     * roles under elsewhere, or the two sets will not see each other.
     */
    'guard' => env('CBOX_ID_SPATIE_GUARD', 'web'),
];
