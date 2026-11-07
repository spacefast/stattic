<?php

declare(strict_types=1);

namespace Zero\Sync;

use Zero\Extensions;
use Zero\HttpError;
use Zero\Js;
use Zero\Viewer;
use Zero\Wp\Plugin;

use function Zero\db;

/** Content sync (§5): stable uids, the site id, and the `/sync` routes. */
final class Module
{
    private const SITE_OPTION = 'zero_runtime_site_id';

    public static function register(): void
    {
        Extensions::onWrite(self::onWrite(...));
        Extensions::onWritten(self::onWritten(...));
        Extensions::schemaDescribe(self::describe(...));
        Extensions::route('sync', Routes::dispatch(...));
        if (function_exists('add_action')) {
            // Before the editor-save pipeline (Plugin, priority 20), so its hooks and every onWritten
            // observer (webhooks included) see the post's uid.
            add_action('wp_after_insert_post', self::editorUid(...), 15, 2);
        }
    }

    /** Give a post saved in wp-admin or core REST its uid, written as meta outside the pipeline. */
    public static function editorUid(int $postId, \WP_Post $post): void
    {
        $plugin = Plugin::instance();
        if ($plugin === null || $plugin->connection()->busy() || in_array($post->post_status, ['auto-draft', 'trash', 'inherit'], true)) {
            return;
        }
        foreach ($plugin->schema->collections() as $name => $spec) {
            if (($spec['uid'] ?? false) && ($spec['wp']['postType'] ?? null) === $post->post_type && ($spec['editor'] ?? true)) {
                if (get_post_meta($postId, \Zero\Wp\WpPostStore::UID_META, true) === '') {
                    $plugin->app()->db->transaction(static fn () => self::ensureUids((string) $name, [[$spec['primaryKey'] => $postId, 'uid' => null]]));
                }

                return;
            }
        }
    }

    /** Mint a uid on create when the writer gave none; refuse to change one. */
    public static function onWrite(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        if (!(db()->schema()->collection($collection)['uid'] ?? false)) {
            return;
        }
        if ($op === 'create') {
            $data['uid'] ??= Uid::mint();
        } elseif ($op === 'update' && array_key_exists('uid', $data) && ($existing['uid'] ?? null) !== null && $data['uid'] !== $existing['uid']) {
            throw HttpError::invalidInput('uid is immutable', '/uid');
        }
    }

    /** Rows written outside the API pipeline (wp-admin, core REST) get their uid here. */
    public static function onWritten(string $op, string $collection, array $row, ?array $previous): void
    {
        if ($op === 'delete' || !(db()->schema()->collection($collection)['uid'] ?? false)) {
            return;
        }
        // A beforeUpdate hook can still change the patch after onWrite; the stored row is the last word,
        // and throwing here rolls the write back.
        if (($previous['uid'] ?? null) !== null && ($row['uid'] ?? null) !== $previous['uid']) {
            throw HttpError::invalidInput('uid is immutable', '/uid');
        }
        if (($row['uid'] ?? null) === null) {
            self::ensureUids($collection, [$row]);
        }
    }

    /**
     * Give every row in $rows that has none a uid, stored directly (bookkeeping: no hooks, no revision).
     *
     * @param list<array> $rows full rows
     * @return list<array> the rows with `uid` filled
     */
    public static function ensureUids(string $collection, array $rows): array
    {
        $pk = db()->schema()->collection($collection)['primaryKey'];
        foreach ($rows as &$row) {
            if (($row['uid'] ?? null) !== null) {
                continue;
            }
            // Another request may have assigned one since $rows was read: the committed uid wins.
            $row['uid'] = Lock::uid($collection, $row[$pk]);
            if ($row['uid'] === null) {
                $row['uid'] = Uid::mint();
                db()->store()->update($collection, $row[$pk], ['uid' => $row['uid']]);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Trusted rows for sync: every status and, for localized collections, every locale (lane H's
     * `locale: "*"`), since each translation is its own record and file.
     */
    public static function find(string $collection, array $opts): array
    {
        if (db()->schema()->collection($collection)['localized'] ?? false) {
            $opts['locale'] = '*';
        }

        return db()->findMany($collection, $opts);
    }

    /** This site's identity, minted once: base files are keyed by it (§5.4). */
    public static function siteId(): string
    {
        // add_option() overwrites on a concurrent first boot; the first id written must win.
        $connection = Plugin::instance()->connection();
        $options = $connection->wpdb()->options;
        $connection->run("INSERT IGNORE INTO `$options` (option_name, option_value, autoload) VALUES (?, ?, 'off')", [self::SITE_OPTION, Js::uuid()]);
        $connection->touchOption(self::SITE_OPTION);

        return (string) $connection->run("SELECT option_value FROM `$options` WHERE option_name = ?", [self::SITE_OPTION])[0]['option_value'];
    }

    public static function describe(array $describe): array
    {
        try {
            db();
        } catch (\LogicException) {
            return $describe; // a schema described without a running app has no site
        }
        $describe['site'] = ['id' => self::siteId()];

        return $describe;
    }
}
