<?php

declare(strict_types=1);

namespace TypedPress\Services;

use TypedPress\HttpError;
use TypedPress\Search\Index;
use TypedPress\Viewer;

use function TypedPress\db;

/**
 * `GET /services/search?collection=…&q=…`: one configured kind's published rows whose configured
 * fields hold every word, matched by the search index ({@see Index}) on the kind's own read as the
 * caller, like every other search. The configured fields are also the columns returned, so a field
 * the caller may not read is the same 403 as asking for it on a list.
 */
final class Search
{
    public const LIMIT = 20;

    public static function query(array $query, Viewer $viewer): array
    {
        $name = $query['collection'] ?? '';
        $term = $query['q'] ?? '';
        if (!is_string($name) || !is_string($term) || mb_strlen(trim($term)) < 2 || mb_strlen($term) > 100) {
            throw HttpError::invalidInput('Choose a collection and a query of 2–100 characters', '/q');
        }
        $fields = Module::config()['search'][$name] ?? throw HttpError::notFound('Search collection not found');
        $spec = db()->schema()->collection($name);
        if (!($spec['drafts'] ?? false) || !is_array($fields) || $fields === [] || array_diff($fields, Index::fields($spec)) !== []) {
            throw new \LogicException('Search requires a publication-enabled collection and searchable text fields');
        }
        $fields = array_values($fields);
        $pk = $spec['primaryKey'];
        $rows = db()->findMany($name, ['where' => ['status' => 'published'], 'search' => $term, 'searchFields' => $fields,
            'columns' => array_fill_keys([$pk, ...$fields], true), 'limit' => self::LIMIT, 'orderBy' => [$pk => 'asc']], $viewer);

        return ['provider' => 'wordpress-lexical', 'results' => $rows, 'limit' => self::LIMIT];
    }
}
