<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\App;
use Zero\HttpError;
use Zero\Js;
use Zero\Seam;
use Zero\Wp\Plugin;

/** First-sync content from compiled sources, with one transaction per collection and no deletes. */
final class Seed
{
    public static function wpCli(Plugin $plugin, array $args, array $flags): int
    {
        try {
            if ($args !== ['seed'] || array_diff(array_keys($flags), ['records', 'dry-run']) !== []
                || !is_string($flags['records'] ?? null) || $flags['records'] === ''
                || (isset($flags['dry-run']) && $flags['dry-run'] !== true)) {
                throw new HttpError(400, 'invalid_command', 'Usage: seed --records=<dir> [--dry-run]');
            }
            $receipt = self::run($plugin, $flags['records'], isset($flags['dry-run']));
            $out = ['data' => $receipt];
            $skipped = array_sum(array_column($receipt, 'skipped_existing'));
            if ($skipped > 0) {
                $out['warnings'] = [['code' => 'zero_seed_skipped_existing', 'count' => $skipped, 'detail' => 'Existing editable records kept their site content. Run zero sync to merge file edits.']];
            }
            \WP_CLI::line((string) Js::jsonStringify($out));
            return 0;
        } catch (\Throwable $e) {
            \WP_CLI::line((string) Js::jsonStringify(['error' => App::problem($e)]));
            return 1;
        }
    }

    public static function run(Plugin $plugin, string $directory, bool $dry = false): array
    {
        if (!is_dir($directory)) {
            throw new HttpError(400, 'invalid_command', 'Seed records directory does not exist.', '/records');
        }
        $plugin->app();
        $files = glob(rtrim($directory, '/') . '/*.json');
        if ($files === false) { throw new \RuntimeException('Cannot list seed records'); }
        sort($files, SORT_STRING);
        $receipt = [];
        foreach ($files as $file) {
            try {
                $input = Seam::container(Js::decodeJson((string) file_get_contents($file)));
            } catch (\JsonException $e) {
                throw new HttpError(400, 'invalid_json', 'Invalid seed JSON: ' . $e->getMessage(), '/records/' . basename($file));
            }
            $collection = $input['collection'] ?? null;
            if (!is_string($collection) || basename($file) !== $collection . '.json' || isset($receipt[$collection])) {
                throw HttpError::invalidInput('Seed collection must match its filename.', '/collection');
            }
            $spec = $plugin->schema->collection($collection);
            if (($spec['sync'] ?? null) === null || ($input['format'] ?? null) !== $spec['sync']['format']) {
                throw HttpError::invalidInput('Seed format must match a synced collection.', '/format');
            }
            $records = Seam::container($input['records'] ?? null);
            if (!is_array($records) || !array_is_list($records)) {
                throw HttpError::invalidInput('Seed records must be a list.', '/records');
            }
            try {
                $receipt[$collection] = $plugin->connection()->transaction(static function () use ($collection, $spec, $records, $dry): array {
                    $counts = array_fill_keys(['created', 'unchanged', 'skipped_existing', 'updated', 'trashed_skipped'], 0);
                    $seen = [];
                    foreach ($records as $index => $record) {
                        $row = Seam::container($record);
                        $uid = $row['uid'] ?? null;
                        if (!Uid::valid($uid) || isset($seen[$uid])) {
                            throw HttpError::invalidInput('Seed records need unique valid uids.', '/records/' . $index . '/uid');
                        }
                        $seen[$uid] = true;
                        $counts[Routes::seedRecord($collection, $spec, $record)]++;
                    }
                    if ($dry) { throw new SeedDryRun($counts); }
                    return $counts;
                });
            } catch (SeedDryRun $rollback) {
                $receipt[$collection] = $rollback->counts;
            }
        }
        return $receipt;
    }
}

final class SeedDryRun extends \RuntimeException
{
    public function __construct(public readonly array $counts) { parent::__construct('rollback seed dry run'); }
}
