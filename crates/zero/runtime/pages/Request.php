<?php

declare(strict_types=1);

namespace Zero\Pages;

/**
 * One claimed request: its route, the visitor, and every value read for it.
 *
 * Every query runs once, as the visitor (`new Viewer(user)` or `Viewer(null)`
 * for anonymous visitors), through Zero's own read pipeline, so read rules,
 * draft visibility and field masks apply exactly as they do on the REST API.
 * Nothing is read with the trusted (null) viewer, and nothing is shared
 * between requests.
 */
final class Request
{
    /** @var array<string, mixed> query id → rows (list) or row (array|null) */
    private array $queries = [];
    /** @var array<string, array|null> loop id → current row, while a loop body renders */
    public array $scope = [];
    /** @var array<string, int> loop id → current index */
    public array $index = [];

    /**
     * @param array<string, mixed> $route manifest entry
     * @param ?array<string, mixed> $item the routed row as the visitor reads it
     * @param array<string, string> $params decoded URL params
     */
    public function __construct(
        public readonly array $route,
        public readonly \Zero\Viewer $viewer,
        public readonly ?array $item,
        public readonly array $params,
        private readonly Plugin $plugin,
    ) {
    }

    /** Run every declared query before output, so a failure is a clean error page and no partial HTML. */
    public function load(): void
    {
        foreach ($this->route['queries'] as $id => $spec) {
            $options = $this->bind($spec['options']);
            $db = $this->plugin->db();
            $this->queries[$id] = $spec['op'] === 'many'
                ? $db->findMany($spec['collection'], $options, $this->viewer)
                : $db->findFirst($spec['collection'], $options, $this->viewer);
        }
    }

    /** Fill `{ $zp: path }` placeholders in query options from the item and params. */
    private function bind(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_key_exists('$zp', $value) && array_diff(array_keys($value), ['$zp', 'fallback']) === []) {
            return $this->read($value['$zp']) ?? ($value['fallback'] ?? null);
        }
        $out = [];
        foreach ($value as $key => $entry) {
            $out[$key] = $this->bind($entry);
        }
        return $out;
    }

    /**
     * A value by its recorded path. Roots: `item`, `params`, `q<n>` (a query),
     * `loop<n>` / `index<n>` (the current loop row and index), `@url` (a static page URL).
     * Missing values read as null; `@href:<collection>` is a routed row's permalink.
     *
     * @param list<string|int> $path
     */
    public function read(array $path): mixed
    {
        $root = (string) array_shift($path);
        if ($root === '@url') {
            return $this->plugin->routes->urlFor((string) ($path[0] ?? ''));
        }
        $value = match (true) {
            $root === 'item' => $this->item,
            $root === 'params' => $this->params,
            str_starts_with($root, 'loop') => $this->scope[substr($root, 4)] ?? null,
            str_starts_with($root, 'index') => $this->index[substr($root, 5)] ?? null,
            default => $this->queries[$root] ?? null,
        };
        foreach ($path as $segment) {
            if (is_string($segment) && str_starts_with($segment, '@href:')) {
                $value = is_array($value) ? $this->plugin->routes->itemUrl(substr($segment, 6), $value) : null;
                continue;
            }
            if ($segment === 'length' && is_array($value) && array_is_list($value)) {
                $value = count($value);
                continue;
            }
            $value = is_array($value) && array_key_exists($segment, $value) ? $value[$segment] : null;
        }
        return $value;
    }
}
