<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/injection-plan.php';

function _stattic_spacefast_sdk_host_is_local(mixed $host): bool
{
    if (!is_string($host)) {
        return false;
    }
    $host = _stattic_normalize_hostname($host);
    return $host === 'localhost'
        || str_ends_with($host, '.localhost')
        || $host === '127.0.0.1'
        || $host === '[::1]'
        || $host === '::1';
}

// THE SDK configuration, and the only place it comes from: the Space overlay,
// written by the control plane's serving-state push. Nothing here asks the
// control plane at request time. A Cast endpoint move, a Comments toggle or a
// theme change syncs when the overlay swaps.
function _stattic_spacefast_sdk_config(array $serving): array
{
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : [];
    return is_array($sdk['config'] ?? null) ? $sdk['config'] : [];
}

/**
 * The Space bar's projection for this request, or null when the page keeps
 * the separate overlay: no projection (an old control plane, or a box whose
 * engine the control plane did not credit with `runtime.space-bar.v1`), a
 * frame URL that is not an https platform URL, or a Space anyone may open.
 */
function _stattic_spacefast_bar_config(array $serving, string $requestHost): ?array
{
    $bar = _stattic_spacefast_bar_projection($serving);
    if ($bar === null) {
        return null;
    }
    $public = _stattic_access_anonymous_admits($serving, _stattic_canonicalize_host($requestHost), '/');
    // A Space anyone may open keeps its overlay for anyone, unless the
    // control plane said this engine may offer the bar there too
    // (`runtime.space-bar-public.v1`): then only a browser that signed in
    // here with an account loads client.js, and the session it asks decides.
    if ($public && ($bar['public'] ?? false) !== true) {
        return null;
    }
    return [...$bar, 'public' => $public];
}

/**
 * The bar this host's page tries, or null: injection has the bar on for the
 * served version, and `_stattic_spacefast_bar_config` gives one for this host
 * (projected, with a platform URL, and on a public host only where the
 * control plane said `public_spaces`). The one decision behind the SDK's bar
 * and the returning-account marker a handoff writes.
 */
function _stattic_spacefast_bar_tried(array $serving, string $requestHost): ?array
{
    return _stattic_injection_on($serving, 'space-bar')
        ? _stattic_spacefast_bar_config($serving, $requestHost)
        : null;
}

/** Whether the control plane projected a usable bar to this Space at all. */
function _stattic_spacefast_bar_projected(array $serving): bool
{
    return _stattic_spacefast_bar_projection($serving) !== null;
}

function _stattic_spacefast_bar_url_valid(string $url): bool
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return ($scheme === 'https'
            || ($scheme === 'http' && _stattic_spacefast_sdk_host_is_local(parse_url($url, PHP_URL_HOST))))
        && preg_match('/[\x00-\x20"\'<>`\\\\]/', $url) !== 1
        && strlen($url) <= 2000;
}

/**
 * The projected bar's URLs, checked, plus whether it may show on a Space
 * anyone may open and any white-label presentation; null when there is none
 * or a URL is not a platform URL.
 */
function _stattic_spacefast_bar_projection(array $serving): ?array
{
    $bar = _stattic_spacefast_sdk_config($serving)['bar'] ?? null;
    if (!is_array($bar)) {
        return null;
    }
    $frame = is_string($bar['frame_url'] ?? null) ? $bar['frame_url'] : '';
    if (!_stattic_spacefast_bar_url_valid($frame)) {
        return null;
    }
    $rawDashboard = $bar['dashboard_url'] ?? null;
    $dashboard = null;
    if ($rawDashboard !== null && $rawDashboard !== '') {
        if (!is_string($rawDashboard) || !_stattic_spacefast_bar_url_valid($rawDashboard)) {
            return null;
        }
        $dashboard = $rawDashboard;
    }
    $brandName = is_string($bar['brand_name'] ?? null) && trim($bar['brand_name']) !== ''
        ? substr(trim($bar['brand_name']), 0, 255)
        : null;
    $rawBrandLogo = is_string($bar['brand_logo'] ?? null) ? trim($bar['brand_logo']) : '';
    $brandLogo = $rawBrandLogo !== ''
        && parse_url($rawBrandLogo, PHP_URL_SCHEME) === 'https'
        && preg_match('/[\x00-\x20"\'<>`\\\\]/', $rawBrandLogo) !== 1
        && strlen($rawBrandLogo) <= 2000
        ? $rawBrandLogo
        : null;
    return [
        'frame' => $frame,
        'dashboard' => $dashboard,
        'public' => ($bar['public_spaces'] ?? false) === true,
        'white_label' => ($bar['white_label'] ?? false) === true,
        'brand_name' => $brandName,
        'brand_logo' => $brandLogo,
    ];
}
