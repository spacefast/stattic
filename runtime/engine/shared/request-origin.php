<?php
declare(strict_types=1);

function _stattic_runtime_request_origin(string $requestHost): string
{
    $authority = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (
        preg_match('/\A[a-z0-9.-]+(?::[0-9]{1,5})?\z/D', $authority) !== 1
        || _stattic_normalize_host_authority($authority) !== _stattic_normalize_hostname($requestHost)
    ) {
        $authority = _stattic_normalize_hostname($requestHost);
    }
    return _stattic_request_scheme() . '://' . $authority;
}

// Every browser that can reach these forms sends Origin on a form POST, so a
// submission proving nothing is refused rather than trusted: no Origin, no
// same-origin fetch metadata.
function _stattic_access_same_origin_post(string $requestHost): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site === 'cross-site' || $site === 'same-site') {
        return false;
    }
    // Chromium sends the opaque Origin "null" for same-origin form posts from a
    // no-referrer document. Safe only alongside same-origin fetch metadata:
    // same-site is not enough, another origin on the site can produce it.
    if ($origin === 'null') {
        return $site === 'same-origin';
    }
    if ($origin !== '') {
        $expectedOrigin = _stattic_runtime_request_origin($requestHost);
        return hash_equals(strtolower($expectedOrigin), strtolower($origin));
    }
    return $site === 'same-origin';
}
