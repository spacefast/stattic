<?php
declare(strict_types=1);

// RFC 9457 problem documents. The `type` and `title` derivations below duplicate
// errorDocsUrl() / errorTitle() in packages/common/src/contracts/error-codes.ts,
// which PHP cannot import: change one, change the other.
// `requestId` is deliberately absent — the runtime mints no request ids, and
// reflecting a caller-supplied header would invent one that correlates with
// nothing.

const STATTIC_PROBLEM_MEDIA_TYPE = 'application/problem+json';
const STATTIC_ERROR_DOCS_BASE_URL = 'https://spacefast.com/docs/errors';

function _stattic_error_docs_url(string $code): string
{
    return STATTIC_ERROR_DOCS_BASE_URL . '/' . rawurlencode($code);
}

/** "version_not_found" → "Version not found". */
function _stattic_error_title(string $code): string
{
    return ucfirst(str_replace('_', ' ', $code));
}

/**
 * `$extra` members (`details`, `pointer`, `page`, …) ride after the required
 * ones, in the order given.
 *
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function _stattic_problem_document(int $status, string $code, string $message, array $extra = []): array
{
    $problem = [
        'type' => _stattic_error_docs_url($code),
        'title' => _stattic_error_title($code),
        'status' => $status,
    ];
    if ($message !== '') {
        $problem['detail'] = $message;
    }
    $problem['code'] = $code;
    return array_merge($problem, $extra);
}
