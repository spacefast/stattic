<?php

declare(strict_types=1);

namespace SpacePress\Render;

use SpacePress\Html;

/**
 * `<Pagination page pages param? />` (rendering.md §4.5): numbered links to the other pages of a
 * listing, as a `<nav>` that works without JavaScript and navigates inside the router region with
 * it (each link is a `<Link>`).
 *
 * - Each link is this page's URL with only `param` (default `page`) changed; the other query
 *   arguments stay. Page 1 drops the argument, so it has one URL.
 * - The current page is `aria-current="page"` and not a link; Previous and Next carry `rel` and
 *   are absent at the ends rather than dead links.
 * - Many pages collapse to the first, the last and two either side of the current one, with an
 *   ellipsis between, so the list stays short at any count.
 */
final class Pagination
{
    /** Pages shown either side of the current one before collapsing. */
    private const AROUND = 2;

    public static function render(int|float $page, int|float $pages, string $param, mixed $class, mixed $label): Html
    {
        $page = (int) $page;
        $pages = (int) $pages;
        $class = is_string($class) ? $class : null;
        $label = is_string($label) ? $label : null;
        if ($pages <= 1) {
            return Html::raw('');
        }
        $page = max(1, min($page, $pages));
        $items = [];
        if ($page > 1) {
            $items[] = Html::element('li', ['className' => 'spacepress-pagination-prev'], Markup::link(['href' => self::href($param, $page - 1), 'rel' => 'prev'], __('Previous')));
        }
        $last = 0;
        foreach (self::shown($page, $pages) as $n) {
            if ($last > 0 && $n > $last + 1) {
                $items[] = Html::element('li', ['className' => 'spacepress-pagination-gap', 'aria-hidden' => 'true'], '…');
            }
            $items[] = $n === $page
                ? Html::element('li', [], Html::element('span', ['aria-current' => 'page'], (string) $n))
                : Html::element('li', [], Markup::link(['href' => self::href($param, $n), 'aria-label' => sprintf(__('Page %d'), $n)], (string) $n));
            $last = $n;
        }
        if ($page < $pages) {
            $items[] = Html::element('li', ['className' => 'spacepress-pagination-next'], Markup::link(['href' => self::href($param, $page + 1), 'rel' => 'next'], __('Next')));
        }

        return Html::element('nav', ['className' => trim('spacepress-pagination ' . ($class ?? '')), 'aria-label' => $label ?? __('Pagination')], Html::element('ol', [], ...$items));
    }

    /** @return list<int> the page numbers shown, ascending */
    private static function shown(int $page, int $pages): array
    {
        $shown = [1, $pages];
        for ($n = $page - self::AROUND; $n <= $page + self::AROUND; $n++) {
            if ($n >= 1 && $n <= $pages) {
                $shown[] = $n;
            }
        }
        $shown = array_values(array_unique($shown));
        sort($shown);

        return $shown;
    }

    /** This page's path and query with `$param` set to `$n` (dropped for page 1). */
    private static function href(string $param, int $n): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $kept = [];
        foreach (\SpacePress\QueryString::parse($query) as [$name, $value]) {
            // A form's flash marker is this visitor's alone: never carried into another URL.
            if ($name !== $param && $name !== Forms::MARKER) {
                $kept[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
        }
        if ($n > 1) {
            $kept[] = rawurlencode($param) . '=' . $n;
        }

        return $path . ($kept === [] ? '' : '?' . implode('&', $kept));
    }
}
