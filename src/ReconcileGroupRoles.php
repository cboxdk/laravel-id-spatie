<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie;

use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\Kernel\Events\EventDelivered;

/**
 * Reconciles a directory group's mapped roles whenever its membership changes (SCIM
 * create/replace/patch/delete), driven by the platform's domain-event outbox.
 *
 * This mirrors the platform's own listener, which is registered only under the builtin
 * driver — under `external` the bridge in this package owns the reaction.
 */
class ReconcileGroupRoles
{
    public function __construct(private readonly GroupRoleMappings $mappings) {}

    public function handle(EventDelivered $delivered): void
    {
        $event = $delivered->event;

        if ($event->type !== 'directory.group.membership_changed') {
            return;
        }

        $groupId = $event->payload['group_id'] ?? null;
        $organizationId = $event->payload['organization_id'] ?? null;

        if (is_string($groupId)) {
            $this->mappings->reconcileGroup($groupId, is_string($organizationId) ? $organizationId : null);
        }
    }
}
