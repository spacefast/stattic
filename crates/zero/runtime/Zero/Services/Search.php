<?php

declare(strict_types=1);

namespace Zero\Services;

use Zero\HttpError;
use Zero\Viewer;

use function Zero\db;

/** No shadow index: every search observes publication, deletion, row access and field access now. */
final class Search
{
    public static function query(array $query, Viewer $viewer): array
    {
        $name = $query['collection'] ?? '';
        $term = $query['q'] ?? '';
        if (!is_string($name) || !is_string($term) || mb_strlen(trim($term)) < 2 || mb_strlen($term) > 100) {
            throw HttpError::invalidInput('Choose a collection and a query of 2–100 characters', '/q');
        }
        $fields = Module::config()['search'][$name] ?? throw HttpError::notFound('Search collection not found');
        $spec = db()->schema()->collection($name);
        if (!($spec['drafts'] ?? false) || !is_array($fields) || $fields === []) {
            throw new \LogicException('Search requires a publication-enabled collection and explicit text fields');
        }
        $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)) . '%';
        $where = [];
        $columns = [$spec['primaryKey'] => true];
        foreach ($fields as $field) {
            if (($spec['columns'][$field]['type'] ?? '') !== 'text') {
                throw new \LogicException('Search fields must be text columns');
            }
            $where[] = [$field => ['ilike' => $pattern]];
            $columns[$field] = true;
        }
        // The query/field-access owner rejects probing unreadable fields before evaluating SQL.
        $rows = db()->findMany($name, ['where' => ['AND' => [['status' => 'published'], ['OR' => $where]]],
            'columns' => $columns, 'limit' => 20, 'orderBy' => [$spec['primaryKey'] => 'asc']], $viewer);
        return ['provider' => 'wordpress-lexical', 'results' => $rows, 'limit' => 20];
    }
}
