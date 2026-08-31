<?php
declare(strict_types=1);

/**
 * Return the management JWT action, or false when the request does not name a
 * supported control-plane operation. Content model data reads and writes execute as
 * Abilities through Zero; this endpoint has no public data lane.
 */
function _stattic_content_management_action(array $request): string|false
{
    return match ((string) ($request['operation'] ?? '')) {
        'admin.launch' => 'content.admin.launch',
        'authorization.apply' => 'content.authorization.apply',
        'model.stage' => 'content.model.stage',
        'model.activate' => 'content.model.activate',
        'source.reconcile' => 'content.source.reconcile',
        'source.acknowledge' => 'content.source.acknowledge',
        'storage.list' => 'content.storage.list',
        'storage.get' => 'content.storage.get',
        'storage.delete' => 'content.storage.delete',
        default => false,
    };
}
