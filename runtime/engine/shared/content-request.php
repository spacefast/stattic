<?php
declare(strict_types=1);

/**
 * Every operation the content endpoint accepts, each marked by whether it acts
 * for a person. The content-model/document lanes act on management-JWT
 * authority alone (the control plane is the actor); the person-scoped ones need
 * the principal assertion that names who is asking. Storage authorizes against
 * the caller's projected WordPress role, so it acts for a person too.
 */
const STATTIC_CONTENT_OPERATIONS = [
    'authorization.apply' => true,
    'admin.launch' => true,
    'rest.request' => true,
    'knowledge.request' => true,
    'design-system.mutate' => true,
    'design-system.read' => true,
    'media.read' => true,
    'source.convert' => true,
    'source.inspect' => true,
    'source.resolve' => true,
    'storage.list' => true,
    'storage.get' => true,
    'storage.delete' => true,
    'model.stage' => false,
    'model.activate' => false,
    'model.commit' => false,
    'source.reconcile' => false,
    'source.acknowledge' => false,
    'source.materialize' => false,
];

/**
 * Return the management JWT action, or false when the request does not name a
 * supported control-plane operation. Content model data reads and writes execute as
 * Abilities through Zero; this endpoint has no public data lane.
 */
function _stattic_content_management_action(array $request): string|false
{
    $operation = $request['operation'] ?? null;
    return is_string($operation) && array_key_exists($operation, STATTIC_CONTENT_OPERATIONS)
        ? 'content.' . $operation
        : false;
}
