<?php

declare(strict_types=1);

namespace TypedPress\Search;

use TypedPress\App;
use TypedPress\Extensions;
use TypedPress\HttpError;
use TypedPress\Viewer;

use function TypedPress\db;

/**
 * Search (spec §2.6, §3): the index ({@see Index}) behind every kind's `search` option and one
 * site-wide endpoint, `GET /wp-json/typedpress/v1/search?q=…`.
 *
 * A kind's `search` (`posts.list({ search })`, `?search=` on the API and on core's list routes)
 * becomes a condition on the index, ANDed into the read. The kind's read rule, draft visibility and
 * filter rules are the same guards as on any list, so the rows, their count and their order are
 * what the viewer would get by listing the kind and filtering by hand. Only the fields the viewer
 * may read are matched.
 *
 * The site-wide endpoint lists, per kind with searchable fields, the rows that match as the
 * caller reads them: a kind the caller may not read is left out, without saying it exists.
 */
final class Module
{
    /** Rows per kind the site-wide endpoint returns. */
    public const LIMIT = 10;

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        Index::register();
        Extensions::route('search', self::route(...));
    }

    /**
     * The condition `search` adds to a read of $collection for $viewer; a search with no word, or
     * on no field the viewer may read, matches nothing.
     *
     * @return list<array|\TypedPress\SqlPredicate>
     */
    public static function guard(string $collection, string $search, ?Viewer $viewer, ?array $only = null): array
    {
        $predicate = Index::predicate($collection, $search, $viewer, $only);

        return [$predicate ?? new \TypedPress\SqlPredicate('1 = 0')];
    }

    /** `GET /search?q=…[&kinds=a,b][&limit=n]`: the matching rows of each kind, as the caller reads them. */
    public static function route(string $method, array $segments, array $query, string $body, Viewer $viewer): array
    {
        if (count($segments) !== 1) {
            throw HttpError::notFound('No such search route');
        }
        App::allow($method, ['GET', 'HEAD']);
        $q = $query['q'] ?? null;
        if (!is_string($q) || Index::terms($q) === []) {
            throw HttpError::invalidQuery('q must hold at least one word', '/q');
        }
        $limit = self::LIMIT;
        if (isset($query['limit'])) {
            if (!is_string($query['limit']) || !preg_match('/^\d{1,3}$/D', $query['limit']) || (int) $query['limit'] < 1) {
                throw HttpError::invalidQuery('limit must be a positive integer', '/limit');
            }
            $limit = min((int) $query['limit'], 50);
        }
        $kinds = Index::kinds();
        if (isset($query['kinds'])) {
            if (!is_string($query['kinds'])) {
                throw HttpError::invalidQuery('kinds is a comma-separated list', '/kinds');
            }
            $asked = array_values(array_filter(array_map('trim', explode(',', $query['kinds'])), static fn (string $k): bool => $k !== ''));
            // A kind that isn't searchable, or doesn't exist, is simply not searched: nothing tells the
            // caller which of the two it is.
            $kinds = array_values(array_intersect($kinds, $asked));
        }
        $results = [];
        foreach ($kinds as $kind) {
            try {
                $rows = db()->findMany($kind, ['search' => $q, 'limit' => $limit] + self::everyLocale($kind), $viewer);
            } catch (HttpError $e) {
                if (in_array($e->status, [401, 403], true)) {
                    continue; // the caller may not read this kind: leave it out
                }
                throw $e;
            }
            if ($rows !== []) {
                $results[] = ['kind' => $kind, 'rows' => array_map(static fn (array $row) => \TypedPress\Wp\PostMeta::values($kind, $row, array_map('strval', array_keys($row))), $rows)];
            }
        }

        return [200, ['q' => $q, 'results' => $results]];
    }

    private static function everyLocale(string $kind): array
    {
        return \TypedPress\Locale\Module::localized($kind) ? ['locale' => '*'] : [];
    }
}
