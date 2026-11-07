<?php
declare(strict_types=1);

function _stattic_page_resolve(string $versionRoot, string $requestPath): ?array
{
    $artifact = $versionRoot . '/pages.php';
    if (!is_file($artifact)) {
        return null;
    }
    $pages = require $artifact;
    if (!is_array($pages)) {
        return null;
    }
    $segments = array_values(array_filter(explode('/', trim($requestPath, '/')), static fn ($part) => $part !== ''));
    foreach ($segments as &$segment) {
        if (preg_match('/%(?![0-9a-f]{2})/i', $segment)) {
            return null;
        }
        $segment = rawurldecode($segment);
        if (str_contains($segment, '/') || str_contains($segment, "\\") || str_contains($segment, "\0") || preg_match('//u', $segment) !== 1) {
            return null;
        }
    }
    unset($segment);
    usort($pages, static function (array $left, array $right): int {
        $a = trim($left['path'], '/') === '' ? [] : explode('/', trim($left['path'], '/'));
        $b = trim($right['path'], '/') === '' ? [] : explode('/', trim($right['path'], '/'));
        $rank = static fn (string $part): int => str_starts_with($part, '*') ? 1 : (str_starts_with($part, ':') ? 2 : 3);
        for ($index = 0; $index < min(count($a), count($b)); $index++) {
            $priority = $rank($b[$index]) - $rank($a[$index]);
            if ($priority !== 0) return $priority;
            if ($rank($a[$index]) === 3 && $a[$index] !== $b[$index]) return strcmp($a[$index], $b[$index]);
        }
        return count($a) - count($b) ?: strcmp($left['path'], $right['path']);
    });
    require_once __DIR__ . '/artifacts.php';
    foreach ($pages as $page) {
        $pattern = preg_replace('~\*[^/]+~', ':splat', $page['path']);
        if (str_contains($page['path'], '*') && count($segments) < count(explode('/', trim($page['path'], '/')))) {
            continue;
        }
        if (_stattic_match_route_pattern_segments($pattern, implode('/', $segments)) !== null) {
            return $page;
        }
    }
    return null;
}
