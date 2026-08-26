<?php
declare(strict_types=1);

/**
 * Return the management JWT action, null for a public query, or false when the
 * request does not name a supported content operation.
 */
function _stattic_content_management_action(array $request): string|false|null
{
    if (($request['format'] ?? null) === 'spacefast.content.query') {
        if (($request['managed'] ?? false) === true) {
            return 'content.query';
        }
        foreach (is_array($request['queries'] ?? null) ? $request['queries'] : [] as $query) {
            if (is_array($query) && ($query['status'] ?? 'publish') !== 'publish') {
                return 'content.query';
            }
        }
        return null;
    }
    if (($request['operation'] ?? null) === 'document.render') {
        return ($request['managed'] ?? false) === true ? 'content.render' : null;
    }
    return match ((string) ($request['operation'] ?? '')) {
        'admin.launch' => 'content.admin.launch',
        'authorization.apply' => 'content.authorization.apply',
        'schema.apply' => 'content.schema.apply',
        'schema.compile' => 'content.schema.compile',
        'document.upsert' => 'content.document.upsert',
        'markdown.sync' => 'content.markdown.sync',
        default => false,
    };
}
