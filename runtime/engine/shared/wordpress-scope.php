<?php
declare(strict_types=1);

/** The meta key a Space's posts, terms and users carry: this Space owns that row. */
const SPACEFAST_CONTENT_SPACE_META = '_spacefast_space_id';

function spacefast_content_space_id(): string
{
    $spaceId = $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null;
    return is_string($spaceId)
        && strlen($spaceId) >= 1
        && strlen($spaceId) <= 128
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $spaceId) === 1
        ? $spaceId
        : '';
}

function spacefast_content_request_origin(): string
{
    // Recomputed on every site_url/home_url/upload_dir filter, of which one
    // request fires many; the request host and cookie-secure verdict are both
    // request-invariant, so the first non-empty answer stands for the rest.
    static $origin = null;
    if (is_string($origin)) {
        return $origin;
    }
    if (spacefast_content_space_id() === '') {
        return '';
    }
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (preg_match('/^[a-z0-9.-]+(?::[1-9][0-9]{0,4})?$/', $host) !== 1) {
        return '';
    }
    $secure = !function_exists('_stattic_cookies_secure') || _stattic_cookies_secure();
    return $origin = ($secure ? 'https://' : 'http://') . $host;
}

function spacefast_content_request_url(mixed $url): mixed
{
    return spacefast_content_url_origin($url, spacefast_content_request_origin());
}

function spacefast_content_public_origin(): string
{
    $origin = $GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] ?? null;
    return spacefast_content_space_id() !== '' && is_string($origin) && $origin !== ''
        ? $origin
        : spacefast_content_request_origin();
}

function spacefast_content_public_url(mixed $url): mixed
{
    return spacefast_content_url_origin($url, spacefast_content_public_origin());
}

function spacefast_content_url_origin(mixed $url, string $origin): mixed
{
    if ($origin === '' || !is_string($url)) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    return $origin
        . (is_string($parts['path'] ?? null) ? $parts['path'] : '')
        . (is_string($parts['query'] ?? null) ? '?' . $parts['query'] : '')
        . (is_string($parts['fragment'] ?? null) ? '#' . $parts['fragment'] : '');
}

/**
 * The private storage root media bytes are written under: the same
 * `<installRoot>/storage` the content-media lane serves them from. It is never
 * derived from ABSPATH. On a managed box WordPress core lives under the
 * provider's read-only `__wp__/` and only wp-load.php is linked into the site
 * root, so ABSPATH names core's directory, not the one the engine installs
 * into: a root built from it is unwritable and is not where the media lane
 * reads.
 */
function spacefast_content_upload_private_root(): string
{
    $root = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    if ((!is_string($root) || $root === '') && function_exists('_stattic_access_private_root')) {
        $root = _stattic_access_private_root();
    }
    return is_string($root) && str_starts_with($root, '/') ? rtrim($root, '/') : '';
}

function spacefast_content_scope_upload_dir(array $uploads): array
{
    $spaceId = spacefast_content_space_id();
    $baseDir = $uploads['basedir'] ?? null;
    $baseUrl = $uploads['baseurl'] ?? null;
    if ($spaceId === '' || !is_string($baseDir) || !is_string($baseUrl)) {
        return $uploads;
    }
    $subdir = is_string($uploads['subdir'] ?? null) ? $uploads['subdir'] : '';
    $privateRoot = spacefast_content_upload_private_root();
    if ($privateRoot === '') {
        return $uploads;
    }
    $spaceHash = substr(hash('sha256', $spaceId), 0, 32);
    $uploads['basedir'] = $privateRoot . '/spaces/' . $spaceId . '/content-media';
    $uploads['baseurl'] = '/__spacefast/content-media/' . $spaceHash;
    $uploads['path'] = $uploads['basedir'] . $subdir;
    $uploads['url'] = $uploads['baseurl'] . $subdir;
    return $uploads;
}
