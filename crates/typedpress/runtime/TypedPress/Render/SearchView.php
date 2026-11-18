<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Html;
use TypedPress\Search\Index;

use function TypedPress\db;

/**
 * `<Search>` and `<SearchResults>` (rendering.md §4.5): a search form that works without JavaScript,
 * and the matching rows a visitor may read, linked to their kinds' URLs.
 *
 * - The form is a `GET` of the current page (or `action`) with `q`: never `s`, WordPress's own
 *   search variable, which would start core's search query.
 * - Results are the kind's own read with `search` (the index's rules: the visitor's read rule, draft
 *   visibility, and only fields the visitor may read), so a draft or a match only in a hidden field
 *   never shows and isn't counted.
 * - A render that searched (a non-empty `q`) is the visitor's alone: `private, no-store`, nothing
 *   recorded for purges, and `noindex`. Each distinct query would otherwise be a cached URL that
 *   lists the kind, and enough of them would turn every write to it into a whole-host purge.
 */
final class SearchView
{
    /** The query parameter. */
    public const PARAM = 'q';
    /** The page parameter, the same one `<Pagination>` uses. */
    public const PAGE = 'page';
    /** Rows per page (one kind) and per kind (site-wide), unless `perPage` says otherwise. */
    public const PER_PAGE = 10;

    private static bool $searched = false;

    /** Whether this render ran a search: its response is the visitor's alone. */
    public static function marked(): bool
    {
        return self::$searched;
    }

    /** The search form. */
    public static function form(?string $action, mixed $label, mixed $placeholder, mixed $class): Html
    {
        $label = is_string($label) && $label !== '' ? $label : __('Search');
        $q = self::query();
        $id = 'typedpress-search-q';

        return Html::element('form', ['role' => 'search', 'method' => 'get', 'action' => $action ?? self::here(), 'className' => trim('typedpress-search ' . (is_string($class) ? $class : ''))],
            Html::element('label', ['htmlFor' => $id], $label),
            Html::element('input', ['type' => 'search', 'id' => $id, 'name' => self::PARAM, 'value' => $q ?? '', 'maxLength' => Index::MAX_QUERY, 'placeholder' => is_string($placeholder) ? $placeholder : null]),
            Html::element('button', ['type' => 'submit'], $label),
        );
    }

    /**
     * The results of `q` on `$kind` (or every searchable kind with a URL, with no kind), as the
     * visitor reads them. An empty `q` renders nothing. `$kind` is a registered collection known at
     * build time; the site-wide search's `kind=` narrows to one kind (one the search doesn't cover
     * gives no results, as the API does).
     */
    public static function results(?string $kind, mixed $q, mixed $page, mixed $perPage, mixed $class): Html
    {
        $q = is_string($q) ? trim($q) : '';
        if ($q === '') {
            return Html::raw('');
        }
        self::$searched = true;
        $class = trim('typedpress-search-results ' . (is_string($class) ? $class : ''));
        if (mb_strlen($q, 'UTF-8') > Index::MAX_QUERY) {
            return Html::element('section', ['className' => $class], Html::element('p', ['className' => 'typedpress-search-too-long'], sprintf(__('Searches are at most %d characters.'), Index::MAX_QUERY)));
        }
        $perPage = is_int($perPage) || is_float($perPage) ? max(1, min(50, (int) $perPage)) : self::PER_PAGE;
        $viewer = \TypedPress\Wp\CoreAccess::viewer();
        if ($viewer === null) {
            return Html::raw('');
        }
        $narrow = $kind === null ? ($_GET['kind'] ?? null) : null;
        if ($kind !== null || is_string($narrow)) {
            $one = $kind ?? (string) $narrow;
            $covered = $kind !== null || (in_array($one, self::kinds(), true));
            $page = is_int($page) || is_float($page) ? max(1, (int) $page) : max(1, (int) ($_GET[self::PAGE] ?? 1));
            [$rows, $total] = $covered ? self::read($one, $q, $perPage, ($page - 1) * $perPage, $viewer) : [[], 0];
            $pages = (int) ceil($total / $perPage);

            return Html::element('section', ['className' => $class, 'aria-label' => __('Search results')],
                Html::element('p', ['className' => 'typedpress-search-count', 'role' => 'status'], $total === 0 ? __('No results.') : sprintf(_n('%d result', '%d results', $total), $total)),
                self::list($one, $rows),
                Pagination::render($page, $pages, self::PAGE, null, null),
            );
        }
        $groups = [];
        $found = 0;
        foreach (self::kinds() as $one) {
            [$rows, $total] = self::read($one, $q, $perPage, 0, $viewer);
            if ($total === 0) {
                continue;
            }
            $found += $total;
            $more = $total > count($rows)
                ? Html::element('p', ['className' => 'typedpress-search-more'], Markup::link(['href' => self::narrowed($one)], sprintf(__('All %1$d in %2$s'), $total, self::label($one))))
                : null;
            $groups[] = Html::element('section', ['className' => 'typedpress-search-group'], Html::element('h2', [], self::label($one)), self::list($one, $rows), $more);
        }

        return Html::element('section', ['className' => $class, 'aria-label' => __('Search results')],
            Html::element('p', ['className' => 'typedpress-search-count', 'role' => 'status'], $found === 0 ? __('No results.') : sprintf(_n('%d result', '%d results', $found), $found)),
            ...$groups,
        );
    }

    /**
     * The kinds a site-wide search covers: those the index covers (searchable fields, an API list)
     * that also have a URL to link a result to.
     *
     * @return list<string>
     */
    private static function kinds(): array
    {
        $urls = db()->schema()->collections();

        return array_values(array_filter(Index::kinds(), static fn (string $k): bool => isset($urls[$k]['url']) && is_string($urls[$k]['url'])));
    }

    /** @return array{list<array<string, mixed>>, int} the page of rows and how many match */
    private static function read(string $kind, string $q, int $limit, int $offset, \TypedPress\Viewer $viewer): array
    {
        $locale = \TypedPress\Locale\Module::localized($kind) ? ['locale' => '*'] : [];
        try {
            $rows = db()->findMany($kind, ['search' => $q, 'limit' => $limit, 'offset' => $offset] + $locale, $viewer);
            $total = db()->count($kind, null, $viewer, ['search' => $q] + $locale);
        } catch (\TypedPress\HttpError $e) {
            if (in_array($e->status, [401, 403], true)) {
                return [[], 0]; // a kind the visitor may not read is left out, as the API does
            }
            throw $e;
        }

        return [$rows, $total];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function list(string $kind, array $rows): ?Html
    {
        if ($rows === []) {
            return null;
        }
        $spec = db()->schema()->collection($kind);
        $pk = $spec['primaryKey'] ?? 'id';
        $title = $spec['title'] ?? null;
        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row[$pk] ?? 0);
            $text = is_string($title) && is_scalar($row[$title] ?? null) ? (string) $row[$title] : (string) get_the_title($id);
            $link = get_permalink($id);
            $items[] = Html::element('li', ['className' => 'typedpress-search-result'], is_string($link) ? Html::element('a', ['href' => $link], $text) : $text);
        }

        return Html::element('ol', [], ...$items);
    }

    private static function label(string $kind): string
    {
        $spec = db()->schema()->collection($kind);

        return (string) ($spec['label'] ?? $spec['wp']['labels']['name'] ?? $kind);
    }

    /** The trimmed `q` of this request, or null. */
    private static function query(): ?string
    {
        $q = $_GET[self::PARAM] ?? null;

        return is_string($q) && trim($q) !== '' ? trim($q) : null;
    }

    /** This page's path, for a form with no action. */
    private static function here(): string
    {
        return (string) (wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    }

    /** This page with `q` kept, `kind` set and no page number. */
    private static function narrowed(string $kind): string
    {
        $q = self::query() ?? '';

        return self::here() . '?' . self::PARAM . '=' . rawurlencode($q) . '&kind=' . rawurlencode($kind);
    }
}
