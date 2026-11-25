<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Db;
use SpacePress\Extensions;
use SpacePress\HttpError;
use SpacePress\Js;
use SpacePress\Schema;
use SpacePress\Seam;
use SpacePress\Viewer;

/**
 * Staged versions of post kinds: a change to an item saved beside the live version, which visitors,
 * core REST, the API, Markdown twins, feeds, search and caches keep reading until it is promoted.
 *
 * Storage is one WordPress revision per item ({@see WpPostStore::stagedName()}), shared by everyone
 * who may update the item. It holds the item's whole row as it would be once promoted, and
 * `_sp_staged` names the fields that were staged, who staged last, and a scheduled promote.
 * Only those fields are the staged change: a live write to another field stays, whatever was staged.
 *
 *   stage     the item's update rule with the patch, then the same input checks an update runs
 *             (field write rules, types, rules, references, media); no hook runs and nothing
 *             visible changes
 *   compare   the staged fields that differ from live, both sides through the reader's field masks
 *   promote   the staged fields as an ordinary update by the promoter ({@see Db::update()}), so the
 *             update and field rules, validation, hooks, unique checks, the revision, purges, change
 *             notices and webhooks are an update's, in one transaction with the staged version's end
 *   schedule  a promote at a time, on core's own `publish_future_post` cron event; it runs then as
 *             the user who scheduled it, under the rules as they are then
 *   discard   the staged version ends; the revision stays in the item's history
 *
 * Access: every operation needs the item's update rule. Anyone else gets the 404 a missing item
 * gets, whether or not something is staged, so nothing here says that a staged version exists.
 * A preview token ({@see preview()}) is the one exception: it is signed, expires, names one staged
 * revision, and reads it through an anonymous visitor's field masks.
 */
final class Staged
{
    public const META = '_sp_staged';
    /** Core's cron hook for scheduled publishing; a staged revision's id is its argument here. */
    private const CRON = 'publish_future_post';
    public const PREVIEW_PARAM = 'sp_preview';
    /** Who scheduled a promote, besides a user id: a signed-out caller, or the trusted CLI. */
    private const ANONYMOUS = 'anonymous';
    private const SYSTEM = 'system';
    private const PREVIEW_TTL = 3600;
    private const PREVIEW_MAX_TTL = 86400;

    private static bool $registered = false;

    public static function register(Plugin $plugin): void
    {
        foreach ($plugin->schema->collections() as $spec) {
            if (self::enabled($spec)) {
                // After core added its links and CoreAccess stripped the response.
                add_filter('rest_prepare_' . $spec['wp']['postType'], [self::class, 'restLinks'], PHP_INT_MAX, 2);
            }
        }
        add_action(self::CRON, [self::class, 'due'], 10, 1);
        // Internal, as core's `auto-draft`: a query for `any` status (get_children's default) skips it.
        $status = static fn () => register_post_status(WpPostStore::STAGED_STATUS, ['internal' => true, 'label' => 'Staged']);
        did_action('init') ? $status() : add_action('init', $status, 0);
        // A staged ability is listed only to a caller whose update rule may allow something.
        add_filter('wp_get_abilities_item_include', [self::class, 'listed'], 10, 2);
        add_filter('rest_request_after_callbacks', [self::class, 'abilityItem'], 10, 3);
        add_filter('mcp_adapter_tools_list', [self::class, 'toolList'], 10, 1);
        // After a Markdown twin has answered (on `wp`): a twin never carries a staged version.
        add_action('template_redirect', [self::class, 'previewPage'], -10);
        if (!self::$registered) {
            self::$registered = true;
            Extensions::cli('staged', self::cli(...));
        }
    }

    /** Post kinds with revisions stage; `staged: false` in a kind's compiled options turns it off. */
    public static function enabled(array $spec): bool
    {
        return ($spec['editor'] ?? true) && ($spec['wp'] ?? null) !== null && ($spec['revisions'] ?? false) && ($spec['staged'] ?? true);
    }

    // ================================================================ operations

    /** The staged version as $viewer reads it: the row once promoted, and what was staged. */
    public static function get(string $collection, int|string $pk, ?Viewer $viewer): array
    {
        [$db, $posts, $spec] = self::kind($collection);

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $pk, $viewer): array {
            [$live, $state] = self::open($db, $posts, $spec, $collection, $pk, $viewer);

            return self::view($spec, $collection, $live, $state ?? throw self::nothing($collection, $pk), $viewer);
        });
    }

    /**
     * Save $patch into the item's staged version (created by the first stage). Each call adds to
     * what is staged; a field staged again takes the new value.
     */
    public static function stage(string $collection, int|string $pk, mixed $patch, ?Viewer $viewer): array
    {
        [$db, $posts, $spec] = self::kind($collection);
        $patch = Seam::container($patch);
        if (!is_array($patch) || ($patch !== [] && array_is_list($patch))) {
            throw HttpError::invalidInput('Body must be a JSON object', '');
        }
        [$patch, $rev, $takeover] = Rev::split($patch);
        $patch = Seam::toPhp($patch, $spec['columns']);
        if ($patch === []) {
            throw HttpError::invalidInput('Name at least one field to stage', '');
        }

        return $db->transaction(static fn (): array => Js::atInstant(static function () use ($db, $posts, $spec, $collection, $pk, $patch, $rev, $takeover, $viewer): array {
            $live = self::item($db, $spec, $collection, $pk);
            $id = (int) $pk;
            if ($viewer !== null && !$db->permits($collection, 'update', $id, $viewer, $patch)) {
                throw self::missing($spec, $collection, $pk);
            }
            $rid = $posts->stagedLock($id);
            Rev::require($collection, $id, $rev, $takeover, $viewer, staged: true, data: $patch);
            $state = self::state($posts, $spec, $collection, $id, $rid);
            $norm = self::checked($db, $spec, $collection, $patch, $live, array_replace($live, $state['values'] ?? []), $viewer);
            // Every staged field in storage form, in schema order: what was staged before, then this patch over it.
            $stored = $state['stored'] ?? [];
            foreach ($norm as $name => $value) {
                $stored[$name] = Schema::store($spec['columns'][$name], $value);
            }
            $stored = array_replace(array_intersect_key($spec['columns'], $stored), $stored);
            $rid = $posts->stagedWrite($collection, $id, $rid, $stored, $viewer?->user['id'] ?? null);
            $meta = array_diff_key($state['meta'] ?? [], ['error' => true]);
            self::writeMeta($rid, ['fields' => array_map('strval', array_keys($stored))] + $meta);
            self::reschedule($rid, $meta); // core clears the revision's cron event whenever the revision is saved

            return self::view($spec, $collection, $live, self::state($posts, $spec, $collection, $id, $rid) ?? throw self::nothing($collection, $pk), $viewer);
        }));
    }

    /** The staged fields whose value differs from live, in schema order, as $viewer may read each side. */
    public static function compare(string $collection, int|string $pk, ?Viewer $viewer): array
    {
        [$db, $posts, $spec] = self::kind($collection);

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $pk, $viewer): array {
            [$live, $state] = self::open($db, $posts, $spec, $collection, $pk, $viewer);
            $state ??= throw self::nothing($collection, $pk);
            $before = self::masked($spec, $collection, $live, $viewer);
            $after = self::masked($spec, $collection, array_replace($live, $state['values']), $viewer);
            $changes = [];
            foreach ($state['fields'] as $name) {
                // A field hidden from the reader on either side is absent, changed or not.
                if (array_key_exists($name, $before) && array_key_exists($name, $after) && !Js::sameJson($before[$name], $after[$name])) {
                    $changes[] = ['field' => $name, 'live' => $before[$name], 'staged' => $after[$name]];
                }
            }

            return ['id' => $state['id'], 'changes' => $changes] + self::who($state) + [Rev::FIELD => Rev::staged($collection, (int) $pk)];
        });
    }

    /** End the staged version without promoting it. Its revision stays in the item's history. */
    public static function discard(string $collection, int|string $pk, ?Viewer $viewer, mixed $rev = null, bool $takeover = false): array
    {
        [$db, $posts, $spec] = self::kind($collection);

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $pk, $viewer, $rev, $takeover): array {
            self::open($db, $posts, $spec, $collection, $pk, $viewer);
            $rid = $posts->stagedLock((int) $pk) ?? throw self::nothing($collection, $pk);
            Rev::require($collection, (int) $pk, $rev, $takeover, $viewer, staged: true);
            self::retire($posts, (int) $pk, $rid, 'discarded', $viewer);

            return ['revision' => $rid];
        });
    }

    /**
     * Make the staged version live: its staged fields are an update of the item by $viewer, and the
     * staged version ends in the same transaction. Returns the updated row as $viewer reads it.
     */
    public static function promote(string $collection, int|string $pk, ?Viewer $viewer, mixed $rev = null, bool $takeover = false): array
    {
        [$db, $posts, $spec] = self::kind($collection);

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $pk, $viewer, $rev, $takeover): array {
            [$live] = self::open($db, $posts, $spec, $collection, $pk, $viewer);
            $id = (int) $pk;
            $rid = $posts->stagedLock($id) ?? throw self::nothing($collection, $pk);
            Rev::require($collection, $id, $rev, $takeover, $viewer, staged: true);
            $state = self::state($posts, $spec, $collection, $id, $rid) ?? throw self::nothing($collection, $pk);
            $patch = self::patch($spec, $collection, $state, $live, $viewer);
            $previous = $db->actor();
            $db->setActor($viewer?->user);
            try {
                $row = $db->update($collection, $patch, [$spec['primaryKey'] => $id], true, $viewer)[0]
                    ?? throw self::missing($spec, $collection, $pk); // the update rule, given the staged data, doesn't cover the row
                self::restHooks($spec, $id, $patch);
            } finally {
                $db->setActor($previous);
            }
            self::retire($posts, $id, $rid, 'promoted', $viewer);
            clean_post_cache($id);

            return $row;
        });
    }

    /**
     * Promote at $at (ISO-8601, in the future), or with null cancel a scheduled promote. $viewer
     * must be able to promote now; the promote itself is decided again when it runs ({@see due()}).
     */
    public static function schedule(string $collection, int|string $pk, mixed $at, ?Viewer $viewer, mixed $rev = null, bool $takeover = false): array
    {
        [$db, $posts, $spec] = self::kind($collection);
        $time = null;
        if ($at !== null) {
            $time = is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $at) ? strtotime($at) : false;
            if ($time === false) {
                throw HttpError::invalidInput('at must be an ISO-8601 timestamp, or null to cancel', '/at');
            }
            if ($time <= self::now()) {
                throw HttpError::invalidInput('at must be in the future; promote without it to promote now', '/at');
            }
        }

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $pk, $time, $viewer, $rev, $takeover): array {
            [$live] = self::open($db, $posts, $spec, $collection, $pk, $viewer);
            $id = (int) $pk;
            $rid = $posts->stagedLock($id) ?? throw self::nothing($collection, $pk);
            Rev::require($collection, $id, $rev, $takeover, $viewer, staged: true);
            $state = self::state($posts, $spec, $collection, $id, $rid) ?? throw self::nothing($collection, $pk);
            $meta = array_diff_key($state['meta'], ['promoteAt' => true, 'promoteBy' => true, 'error' => true]);
            if ($time !== null) {
                if ($viewer !== null) {
                    if (!$db->permits($collection, 'update', $id, $viewer, $state['values'])) {
                        throw self::missing($spec, $collection, $pk);
                    }
                    self::checked($db, $spec, $collection, self::patch($spec, $collection, $state, $live, $viewer), $live, array_replace($live, $state['values']), $viewer);
                }
                $meta += ['promoteAt' => gmdate('Y-m-d\TH:i:s.000\Z', $time), 'promoteBy' => self::actor($viewer)];
            }
            self::writeMeta($rid, $meta);
            self::reschedule($rid, $meta);

            return self::view($spec, $collection, $live, ['meta' => $meta] + $state, $viewer);
        });
    }

    /**
     * A signed link to the staged version that works without signing in until it expires, the staged
     * version is promoted or discarded, or the site's keys change.
     *
     * @return array{token: string, expiresAt: string, api: string, url: ?string}
     */
    public static function preview(string $collection, int|string $pk, mixed $ttl, ?Viewer $viewer): array
    {
        [$db, $posts, $spec] = self::kind($collection);
        if ($ttl !== null && (!is_int($ttl) || $ttl < 60 || $ttl > self::PREVIEW_MAX_TTL)) {
            throw HttpError::invalidInput('ttl is a number of seconds from 60 to ' . self::PREVIEW_MAX_TTL, '/ttl');
        }

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $pk, $ttl, $viewer): array {
            [, $state] = self::open($db, $posts, $spec, $collection, $pk, $viewer);
            $id = (int) $pk;
            $rid = $state['id'] ?? throw self::nothing($collection, $pk);
            $expires = self::now() + ($ttl ?? self::PREVIEW_TTL);
            $payload = self::base64((string) json_encode([$collection, $id, $rid, $expires]));
            $token = $payload . '.' . self::sign($payload);
            // The item's own page shows the staged version only where the theme renders it: not with
            // `frontend: false` (no pages), and not where a `sp/app` page answers the URL.
            $public = Plugin::instance()?->schema->frontend() !== false && is_post_publicly_viewable($id) ? get_permalink($id) : false;
            if (is_string($public) && AppRouter::instance()?->serves($public)) {
                $public = false;
            }

            return [
                'token' => $token,
                'expiresAt' => gmdate('Y-m-d\TH:i:s.000\Z', $expires),
                'api' => add_query_arg('preview', $token, Facade::url('/' . rawurlencode($collection) . "/$id/staged")),
                'url' => is_string($public) && $public !== '' ? add_query_arg(self::PREVIEW_PARAM, $token, $public) : null,
            ];
        });
    }

    /** The staged version a preview token names, through an anonymous visitor's field masks. */
    public static function previewed(string $collection, int|string $pk, string $token): ?array
    {
        $claim = self::redeem($token);
        if ($claim === null || $claim['collection'] !== $collection || (string) $claim['id'] !== (string) $pk) {
            return null;
        }
        [$db, $posts, $spec] = self::kind($collection);

        return $db->transaction(static function () use ($db, $posts, $spec, $collection, $claim): ?array {
            $live = $db->editorRow($collection, $claim['id']);
            $state = $live === null ? null : self::state($posts, $spec, $collection, $claim['id'], $claim['revision']);

            return $state === null ? null : array_diff_key(self::view($spec, $collection, $live, $state, new Viewer(null)), ['by' => true, 'promoteAt' => true, 'error' => true]);
        });
    }

    // ================================================================ HTTP and CLI

    /**
     * `/<kind>/<id>/staged[/compare|/promote|/preview]` below the API prefix, as the signed-in user.
     *
     * @param list<string> $rest the path after `staged`
     * @return array{int, mixed} status and `data`
     */
    public static function route(string $collection, int|string $pk, string $method, array $rest, array $query, array $body): array
    {
        $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
        // Rows in the API's wire form (`{}` in a json() value stays an object), as its item reads.
        $wire = static fn (array $row): array => PostMeta::values($collection, $row, array_map('strval', array_keys($row)));
        $staged = static fn (array $view): array => ['row' => $wire($view['row'])] + $view;
        switch (implode('/', $rest)) {
            case '':
                \SpacePress\App::allow($method, ['GET', 'HEAD', 'PATCH', 'DELETE']);
                if ($method === 'PATCH') {
                    return [200, $staged(self::stage($collection, $pk, $body, $viewer))];
                }
                if ($method === 'DELETE') {
                    return [200, self::discard($collection, $pk, $viewer, $query[Rev::FIELD] ?? null, ($query[Rev::TAKEOVER] ?? '') === '1')];
                }
                $token = $query['preview'] ?? null;

                return [200, $staged((is_string($token) ? self::previewed($collection, $pk, $token) : null) ?? self::get($collection, $pk, $viewer))];
            case 'compare':
                \SpacePress\App::allow($method, ['GET', 'HEAD']);
                $diff = self::compare($collection, $pk, $viewer);
                foreach ($diff['changes'] as $i => $change) {
                    $name = $change['field'];
                    $diff['changes'][$i] = ['field' => $name, 'live' => $wire([$name => $change['live']])[$name], 'staged' => $wire([$name => $change['staged']])[$name]];
                }

                return [200, $diff];
            case 'promote':
                \SpacePress\App::allow($method, ['POST']);

                [$body, $rev, $takeover] = Rev::split($body);

                return [200, array_key_exists('at', $body)
                    ? $staged(self::schedule($collection, $pk, $body['at'], $viewer, $rev, $takeover))
                    : $wire(Rev::attach($collection, self::promote($collection, $pk, $viewer, $rev, $takeover)))];
            case 'preview':
                \SpacePress\App::allow($method, ['POST']);

                return [200, self::preview($collection, $pk, $body['ttl'] ?? null, $viewer)];
        }
        throw HttpError::notFound('No such staged route');
    }

    /**
     * `wp spacepress staged list [<kind>] | show|compare|promote|discard <kind> <id> | stage <kind> <id> <json>`,
     * as the trusted CLI: no access rule and no field mask. `promote --at=<ISO>` schedules a promote and
     * `promote --cancel` cancels one.
     *
     * @param list<string> $args
     * @param array<string, string|true> $flags
     */
    public static function cli(array $args, array $flags): mixed
    {
        $usage = new HttpError(400, 'invalid_command', 'Usage: wp spacepress staged list [<kind>] | show|compare|promote|discard <kind> <id> | stage <kind> <id> <json> (each write takes --rev=<token>; promote takes --at=<ISO time> or --cancel)');
        $command = $args[0] ?? '';
        if ($command === 'list') {
            $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
            $out = [];
            foreach (isset($args[1]) ? [$args[1]] : array_keys(array_filter($plugin->schema->collections(), self::enabled(...))) as $collection) {
                [, $posts, $spec] = self::kind((string) $collection);
                foreach ($posts->stagedIds(null, (string) $collection) as $id => $rid) {
                    $state = self::state($posts, $spec, (string) $collection, $id, $rid);
                    if ($state !== null) {
                        $out[] = ['kind' => (string) $collection, 'id' => $id, 'revision' => $rid, 'fields' => $state['fields']] + self::who($state);
                    }
                }
            }

            return $out;
        }
        [$collection, $pk] = [$args[1] ?? throw $usage, $args[2] ?? throw $usage];
        // `--rev=<token>` refuses the write when the item changed since that read, as on the API.
        $rev = is_string($flags['rev'] ?? null) ? $flags['rev'] : null;

        return match ($command) {
            'show' => self::get($collection, $pk, null),
            'compare' => self::compare($collection, $pk, null),
            'discard' => self::discard($collection, $pk, null, $rev),
            'promote' => isset($flags['at']) || isset($flags['cancel'])
                ? self::schedule($collection, $pk, isset($flags['cancel']) ? null : $flags['at'], null, $rev)
                : self::promote($collection, $pk, null, $rev),
            'stage' => self::stage($collection, $pk, (static function () use ($args, $usage, $rev): mixed {
                $patch = Js::decodeJson($args[3] ?? throw $usage);
                if ($rev !== null && $patch instanceof \SpacePress\JsObject) {
                    $patch->p[Rev::FIELD] = $rev;
                }

                return $patch;
            })(), null),
            default => throw $usage,
        };
    }

    // ================================================================ WordPress hooks

    /**
     * `publish_future_post` for a staged revision: its scheduled promote is due. It runs as the user
     * who scheduled it, as a request of theirs would now. A promote that is refused or fails is not
     * retried: the reason is kept on the staged version for its editors to read.
     */
    public static function due(mixed $revision): void
    {
        $plugin = Plugin::instance();
        $post = get_post(is_object($revision) ? $revision : (int) $revision);
        if ($plugin === null || !$post instanceof \WP_Post || $post->post_type !== 'revision'
            || $post->post_name !== WpPostStore::stagedName((int) $post->post_parent)
            || ($collection = CoreAccess::collection((string) get_post_type($post->post_parent))) === null
            || !self::enabled($plugin->schema->collection($collection))) {
            return;
        }
        $meta = self::readMeta($post->ID);
        $at = is_string($meta['promoteAt'] ?? null) ? strtotime($meta['promoteAt']) : false;
        if ($at === false) {
            return;
        }
        if ($at > self::now() || $plugin->unavailable() !== null) {
            wp_schedule_single_event(max($at, time() + 60), self::CRON, [$post->ID]); // early, or storage is behind
            return;
        }
        $by = $meta['promoteBy'] ?? null;
        $previous = get_current_user_id();
        try {
            // The promote runs as exactly who scheduled it. Only the trusted CLI's own schedule
            // promotes trusted; anything else unrecognised is refused, never promoted as the system.
            if (is_int($by) && $by > 0) {
                if (!get_userdata($by) instanceof \WP_User) {
                    throw new HttpError(403, 'forbidden', 'The user who scheduled this promote no longer exists');
                }
                wp_set_current_user($by);
                $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'The account that scheduled this promote is suspended');
            } elseif ($by === self::ANONYMOUS) {
                wp_set_current_user(0);
                $viewer = new Viewer(null);
            } elseif ($by === self::SYSTEM) {
                $viewer = null; // `wp spacepress staged promote --at`, the trusted CLI
            } else {
                throw new HttpError(403, 'forbidden', 'This scheduled promote names no one who may make it');
            }
            self::promote($collection, (int) $post->post_parent, $viewer);
        } catch (\Throwable $e) {
            $problem = \SpacePress\App::problem($e);
            self::writeMeta($post->ID, ['error' => ['code' => $problem['code'], 'detail' => $problem['detail'], 'at' => gmdate('Y-m-d\TH:i:s.000\Z', self::now())]]
                + array_diff_key(self::readMeta($post->ID), ['promoteAt' => true, 'promoteBy' => true]));
            self::reschedule($post->ID, []);
        } finally {
            wp_set_current_user($previous);
        }
    }

    /**
     * Core's REST links count every revision of a post and name the newest one, for every reader.
     * Staged revisions, current or retired, are left out of both, so a reader can't tell that one
     * exists or ever existed: a discard leaves the links as they were, a promote moves them as one
     * update does.
     */
    public static function restLinks(mixed $response, mixed $post): mixed
    {
        $history = $response instanceof \WP_REST_Response && $post instanceof \WP_Post ? ($response->get_links()['version-history'][0] ?? null) : null;
        $store = is_array($history) ? Plugin::instance()?->db()->store() : null;
        if (!$store instanceof CompositeStore) {
            return $response;
        }
        // Every staged revision that was promoted or discarded (they keep their record,
        // `_sp_staged`): none of them is a version a reader saw. Core counts `inherit` revisions
        // only, so the current staged revision, with its own status, is not in its count.
        $wpdb = $store->db->wpdb();
        $hidden = array_map('intval', array_column($store->db->run(
            "SELECT r.ID FROM `{$wpdb->posts}` r JOIN `{$wpdb->postmeta}` m ON m.post_id = r.ID AND m.meta_key = ? WHERE r.post_parent = ? AND r.post_type = 'revision' AND r.post_status = 'inherit'",
            [self::META, $post->ID],
        ), 'ID'));
        if ($hidden === []) {
            return $response;
        }
        $count = max(0, (int) ($history['attributes']['count'] ?? 0) - count($hidden));
        $response->remove_link('version-history');
        $response->remove_link('predecessor-version');
        $response->add_link('version-history', (string) $history['href'], ['count' => $count]);
        $latest = $store->db->run(
            "SELECT ID FROM `{$wpdb->posts}` WHERE post_parent = ? AND post_type = 'revision' AND post_status = 'inherit' AND post_name NOT LIKE ?"
            . ' AND ID NOT IN (' . implode(', ', array_fill(0, count($hidden), '?')) . ') ORDER BY post_date DESC, ID DESC LIMIT 1',
            [$post->ID, '%autosave%', ...$hidden],
        )[0]['ID'] ?? null;
        if ($latest !== null) {
            $response->add_link('predecessor-version', (string) $history['href'] . '/' . (int) $latest, ['id' => (int) $latest]);
        }

        return $response;
    }

    /**
     * The item's own page with a valid preview token in `?sp_preview=`: the theme renders the
     * staged title, content and fields for this request, as it renders an editor's own preview, and
     * no cache stores the response. A page rendered another way (`sp/app`, an external frontend)
     * reads the staged row from the token's `api` URL instead ({@see preview()} gives no page URL
     * for a `sp/app` page).
     */
    public static function previewPage(): void
    {
        global $wp_query, $post;
        $token = $_GET[self::PREVIEW_PARAM] ?? null;
        $shown = $wp_query instanceof \WP_Query && $wp_query->is_singular() ? ($wp_query->posts[0] ?? null) : null;
        $claim = is_string($token) && $shown instanceof \WP_Post ? self::redeem(wp_unslash($token)) : null;
        $revision = $claim !== null && $claim['id'] === $shown->ID ? get_post($claim['revision']) : null;
        if (!$revision instanceof \WP_Post) {
            return;
        }
        $plugin = Plugin::instance();
        $collection = CoreAccess::collection($shown->post_type);
        if ($plugin === null || $collection === null) {
            return;
        }
        // Only what was staged comes from the revision, and only the staged fields an anonymous
        // visitor may read there, as the preview API's `row` gives them; every other field is live.
        $spec = $plugin->schema->collection($collection);
        $view = self::previewed($collection, $claim['id'], wp_unslash($token));
        if ($view === null) {
            return;
        }
        $fields = $view['fields'];
        // Fields an anonymous visitor may not read: absent from the row, so blank on the page too.
        $masked = array_values(array_diff(array_map('strval', array_keys($spec['columns'])), array_keys($view['row'])));
        $staged = clone $shown;
        foreach (['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt'] as $designation => $native) {
            if (in_array($spec[$designation] ?? null, $fields, true)) {
                $staged->$native = $revision->$native;
            }
        }
        $wp_query->posts[0] = $wp_query->post = $wp_query->queried_object = $post = $staged;
        [$id, $rid, $prefix] = [$claim['id'], $claim['revision'], (string) $spec['wp']['metaPrefix']];
        $columns = array_map('strval', array_keys($spec['columns']));
        usort($columns, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        // A field's meta lives under `<prefix><field>`, SCF's reference under `_<prefix><field>`, and a
        // structured field's parts under `<prefix><field>_…`: the longest field name that fits owns the key.
        add_filter('get_post_metadata', static function (mixed $value, mixed $object, mixed $key) use ($id, $rid, $prefix, $columns, $fields, $masked): mixed {
            if ((int) $object !== $id || !is_string($key) || !str_starts_with(ltrim($key, '_'), $prefix)) {
                return $value;
            }
            $name = substr(ltrim($key, '_'), strlen($prefix));
            foreach ($columns as $column) {
                if ($name === $column || str_starts_with($name, $column . '_')) {
                    return match (true) {
                        in_array($column, $masked, true) => [''],
                        in_array($column, $fields, true) => get_metadata('post', $rid, $key, false),
                        default => $value,
                    };
                }
            }

            return $value;
        }, 10, 3);
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('batcache_cancel')) {
            batcache_cancel();
        }
        nocache_headers();
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
            header('Referrer-Policy: no-referrer'); // the token is in this page's URL
        }
    }

    /**
     * `wp_get_abilities_item_include`: core's abilities list shows every agent ability to anyone
     * signed in. A staged ability (`<kind>-stage`, `-staged-*`) is left out for a caller whose update
     * rule covers no row of that kind, before core pages the list, so a read-only caller never sees
     * it offered. Running one is refused for them as it always was.
     */
    public static function listed(mixed $include, mixed $ability): mixed
    {
        return $include && $ability instanceof \WP_Ability && get_current_user_id() !== 0 ? self::offered($ability->get_name()) : $include;
    }

    /** One ability's description (`GET /wp-abilities/v1/abilities/<name>`): the same rule, as a 404. */
    public static function abilityItem(mixed $response, mixed $handler, mixed $request): mixed
    {
        if ($response instanceof \WP_REST_Response && !$response->is_error() && $request instanceof \WP_REST_Request && $request->get_method() === 'GET'
            && preg_match('#^/wp-abilities/v1/abilities/(.+)$#', $request->get_route(), $m) && !str_ends_with($m[1], '/run') && !self::offered(urldecode($m[1]))) {
            return new \WP_Error('rest_ability_not_found', __('Ability not found.'), ['status' => 404]);
        }

        return $response;
    }

    /** `mcp_adapter_tools_list`: the same rule for the app's MCP tools. */
    public static function toolList(mixed $tools): mixed
    {
        if (!is_array($tools)) {
            return $tools;
        }

        return array_values(array_filter($tools, static function (mixed $tool): bool {
            $name = is_object($tool) && method_exists($tool, 'getName') ? (string) $tool->getName() : '';
            foreach (\SpacePress\Wp\Abilities\Register::operations() as $op) {
                if (str_replace('/', '-', $op['name']) === $name) {
                    return self::offered($op['name']);
                }
            }

            return true;
        }));
    }

    /** Is ability $name one this caller is offered: not staged, or on a kind whose update rule may allow them something? */
    private static function offered(string $name): bool
    {
        foreach (\SpacePress\Wp\Abilities\Register::operations() as $op) {
            if ($op['name'] !== $name) {
                continue;
            }
            if (!in_array($op['op'], ['stage', 'staged-get', 'staged-compare', 'staged-promote', 'staged-discard', 'staged-preview'], true)) {
                return true;
            }
            $plugin = Plugin::instance();
            $collection = (string) ($op['spacepress']['collection'] ?? '');
            $viewer = CoreAccess::viewer();
            if ($plugin === null || $viewer === null || $viewer->user === null || !$plugin->schema->hasCollection($collection)) {
                return false;
            }
            $plugin->app(); // rule functions live in the app's functions.php
            $spec = $plugin->schema->collection($collection);
            $rule = \SpacePress\Access::collectionRules($spec)['update'];
            try {
                $r = $plugin->db()->current(static fn () => \SpacePress\Access::evaluate($rule, $viewer, 'update', null, $spec['columns'], $collection));
            } catch (\Throwable) {
                return true; // a rule that needs the write's data to answer: offered, and each run decides
            }
            // Offered when the rule allows outright, or its filter covers at least one row now.
            return $r === true || (is_array($r) && CoreAccess::keys($collection, 'update', $viewer) !== []);
        }

        return true;
    }

    // ================================================================ internals

    /** @return array{Db, WpPostStore, array} */
    private static function kind(string $collection): array
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        $db = $plugin->db();
        $store = $db->store();
        $spec = $plugin->schema->hasCollection($collection) ? $plugin->schema->collection($collection) : null;
        if ($spec === null || !$store instanceof CompositeStore || !self::enabled($spec)) {
            throw HttpError::notFound("$collection has no staged versions");
        }

        return [$db, $store->posts, $spec];
    }

    /** The item's stored row, or the 404 of a missing item. */
    private static function item(Db $db, array $spec, string $collection, int|string $pk): array
    {
        $row = is_int($pk) || preg_match('/^\d{1,18}$/D', $pk) ? $db->editorRow($collection, (int) $pk) : null;

        return $row ?? throw self::missing($spec, $collection, $pk);
    }

    /**
     * The item's stored row and its staged version (or null), for a viewer whose update rule covers
     * the item: as the rule answers without data, or given the staged data, as a promote would ask
     * it. Anyone else gets the 404 of a missing item, whatever is staged.
     *
     * @return array{array, ?array}
     */
    private static function open(Db $db, WpPostStore $posts, array $spec, string $collection, int|string $pk, ?Viewer $viewer): array
    {
        $live = self::item($db, $spec, $collection, $pk);
        $id = (int) $pk;
        $state = self::state($posts, $spec, $collection, $id, $posts->stagedId($id));
        // Whatever is staged, a caller the rule doesn't cover gets the same 404, even from a rule
        // that throws on the staged data.
        $permits = static function (?array $data) use ($db, $collection, $id, $viewer): bool {
            try {
                return $db->permits($collection, 'update', $id, $viewer, $data);
            } catch (\Throwable) {
                return false;
            }
        };
        if ($viewer !== null && !$permits(null) && ($state === null || !$permits($state['values']))) {
            throw self::missing($spec, $collection, $pk);
        }
        // WordPress's own posts: staging is editing, so core's edit_post decides first.
        if (($spec['native'] ?? false) && !NativePosts::allows($viewer, 'update', $id)) {
            throw self::missing($spec, $collection, $pk);
        }

        return [$live, $state];
    }

    private static function missing(array $spec, string $collection, int|string $pk): HttpError
    {
        return HttpError::notFound("No $collection row with {$spec['primaryKey']} $pk");
    }

    private static function nothing(string $collection, int|string $pk): HttpError
    {
        return HttpError::notFound("Nothing is staged for $collection $pk");
    }

    /**
     * The staged version behind revision $rid: the staged fields, their values in storage form
     * (`stored`) and decoded (`values`), and its record.
     *
     * @return ?array{id: int, fields: list<string>, stored: array, values: array, meta: array, at: string, userId: ?int}
     */
    private static function state(WpPostStore $posts, array $spec, string $collection, int $id, ?int $rid): ?array
    {
        $revision = $rid === null ? null : $posts->revision($collection, $id, $rid);
        if ($revision === null || get_post($rid)?->post_name !== WpPostStore::stagedName($id)) {
            return null;
        }
        $meta = self::readMeta($rid);
        $fields = array_values(array_filter(array_map('strval', (array) ($meta['fields'] ?? [])), static fn (string $name): bool => isset($spec['columns'][$name])));
        $stored = array_intersect_key($revision['row'], array_flip($fields));

        return [
            'id' => $rid, 'fields' => $fields, 'stored' => $stored,
            'values' => Schema::decodeRow(array_intersect_key($spec['columns'], $stored), $stored),
            'meta' => $meta, 'at' => $revision['at'], 'userId' => $revision['userId'],
        ];
    }

    private static function view(array $spec, string $collection, array $live, array $state, ?Viewer $viewer): array
    {
        $row = self::masked($spec, $collection, array_replace($live, $state['values']), $viewer);

        return ['id' => $state['id'], 'row' => $row, 'fields' => array_values(array_filter($state['fields'], static fn (string $name): bool => array_key_exists($name, $row)))]
            + self::who($state) + [Rev::FIELD => Rev::staged($collection, (int) $live[$spec['primaryKey']])];
    }

    /** Who staged last and when, and the scheduled promote (or why it did not run). */
    private static function who(array $state): array
    {
        $user = $state['userId'] === null ? false : get_userdata($state['userId']);

        return [
            'by' => $user instanceof \WP_User ? ['id' => (int) $user->ID, 'name' => (string) $user->display_name] : null,
            'at' => $state['at'],
            'promoteAt' => is_string($state['meta']['promoteAt'] ?? null) ? $state['meta']['promoteAt'] : null,
        ] + (is_array($state['meta']['error'] ?? null) ? ['error' => $state['meta']['error']] : []);
    }

    /** A stored row as $viewer reads it: every read extension (field masks first), as an API read. */
    private static function masked(array $spec, string $collection, array $row, ?Viewer $viewer): array
    {
        Extensions::lookup($collection, [$row[$spec['primaryKey']]]);
        $row = Extensions::read([$row], $collection, $viewer)[0] ?? throw self::missing($spec, $collection, $row[$spec['primaryKey']]);

        return array_diff_key($row, array_filter($spec['columns'], static fn (array $def): bool => (bool) ($def['hidden'] ?? false)));
    }

    /**
     * The staged fields as $viewer's update of the item, for a promote. A staged field holds the
     * whole value it would store. Where part of a group, repeater or blocks value is hidden from
     * the promoter, the patch is the part they see, so the update keeps the hidden part as it is
     * live, exactly as an update they sent themselves would. When that would not store the staged
     * value (the hidden part was staged by someone who may set it, or changed live since), the
     * promote is refused rather than made without it.
     *
     * @return array<string, mixed>
     */
    private static function patch(array $spec, string $collection, array $state, array $live, ?Viewer $viewer): array
    {
        $patch = $state['values'];
        if ($viewer === null) {
            return $patch;
        }
        $seen = \SpacePress\FieldAccess::strip([array_replace($live, $patch)], $collection, $viewer)[0] ?? [];
        foreach ($patch as $name => $value) {
            $def = $spec['columns'][$name];
            if (!in_array($def['type'], ['group', 'repeater', 'blocks'], true) || !array_key_exists($name, $seen) || Js::sameJson($seen[$name], $value)) {
                continue; // a plain value, or one the promoter sees whole (or not at all: the field rules decide)
            }
            // What an update with the visible part would store: field access resolves it against the live row.
            $resolved = [$name => $seen[$name]];
            \SpacePress\FieldAccess::checkWrite('update', $collection, $resolved, $live, $viewer, $resolved);
            $pointer = HttpError::seg((string) $name);
            if (Schema::store($def, Schema::normalize($def, $resolved[$name], (string) $name, $pointer)) !== $state['stored'][$name]) {
                throw new HttpError(403, 'forbidden', "The staged $name holds values you can't set that differ from the live item", $pointer);
            }
            $patch[$name] = $seen[$name];
        }

        return $patch;
    }

    /**
     * $patch as an update of $existing would accept it from $viewer, normalized: the write extensions
     * (field write rules, immutable and virtual input), types, rules ($data: the item as the rules'
     * validators see it), media and references. Nothing is written and no hook of the kind runs.
     *
     * @return array<string, mixed>
     */
    private static function checked(Db $db, array $spec, string $collection, array $patch, array $existing, array $data, ?Viewer $viewer): array
    {
        $columns = $spec['columns'];
        $refuse = static function (array $patch) use ($spec, $columns): void {
            foreach ($patch as $name => $_) {
                $def = $columns[$name] ?? throw HttpError::invalidInput("Unknown field '$name'", HttpError::seg((string) $name));
                if ($name === $spec['primaryKey'] || ($def['system'] ?? false)) {
                    throw HttpError::invalidInput("$name can't be staged; it changes with an update of the item", HttpError::seg((string) $name));
                }
            }
        };
        $provided = $patch;
        Extensions::input('update', $collection, $patch, $existing, $viewer, $provided);
        $refuse($patch);
        Extensions::write('update', $collection, $patch, $existing, $viewer, $provided);
        $refuse($patch);
        $norm = [];
        foreach ($patch as $name => $value) {
            $norm[(string) $name] = Schema::normalize($columns[$name], $value, (string) $name, HttpError::seg((string) $name));
        }
        $ctx = ['data' => $norm + $data, 'user' => $viewer?->user];
        foreach ($norm as $name => $value) {
            Schema::checkRules($columns[$name], $value, $name, HttpError::seg($name), $ctx, $columns);
        }
        $db->checkMedia($columns, $norm);
        $db->validateWriteReferences($collection, $patch, $viewer);

        return $norm;
    }

    /** The staged revision becomes an ordinary one, recorded as promoted or discarded and by whom. */
    private static function retire(WpPostStore $posts, int $id, int $rid, string $how, ?Viewer $viewer): void
    {
        $meta = self::readMeta($rid);
        self::reschedule($rid, []);
        self::writeMeta($rid, [$how => ['by' => $viewer?->user['id'] ?? null, 'at' => gmdate('Y-m-d\TH:i:s.000\Z', self::now())]]
            + array_diff_key($meta, ['promoteAt' => true, 'promoteBy' => true, 'error' => true]));
        $posts->stagedRetire($id, $rid);
    }

    private static function readMeta(int $rid): array
    {
        $meta = json_decode((string) get_metadata('post', $rid, self::META, true), true);

        return is_array($meta) ? $meta : [];
    }

    private static function writeMeta(int $rid, array $meta): void
    {
        Plugin::instance()?->connection()->touchPost($rid);
        update_metadata('post', $rid, self::META, wp_slash((string) Js::jsonStringify($meta)));
    }

    /** One cron event per staged revision: at `promoteAt`, or none. */
    private static function reschedule(int $rid, array $meta): void
    {
        $at = is_string($meta['promoteAt'] ?? null) ? strtotime($meta['promoteAt']) : false;
        if ($at === false && wp_next_scheduled(self::CRON, [$rid]) === false) {
            return;
        }
        Plugin::instance()?->connection()->touchOption('cron'); // a rollback must not leave the cached schedule behind
        wp_clear_scheduled_hook(self::CRON, [$rid]);
        if ($at !== false) {
            wp_schedule_single_event($at, self::CRON, [$rid]);
        }
    }

    /** @return ?array{collection: string, id: int, revision: int} what a valid, unexpired token names, while it is still staged */
    private static function redeem(string $token): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ($payload === '' || !hash_equals(self::sign($payload), $signature)) {
            return null;
        }
        $claim = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (!is_array($claim) || count($claim) !== 4 || !is_string($claim[0]) || !is_int($claim[1]) || !is_int($claim[2]) || !is_int($claim[3]) || $claim[3] < self::now()) {
            return null;
        }
        $revision = get_post($claim[2]);
        if (!$revision instanceof \WP_Post || (int) $revision->post_parent !== $claim[1] || $revision->post_name !== WpPostStore::stagedName($claim[1])) {
            return null;
        }

        return ['collection' => $claim[0], 'id' => $claim[1], 'revision' => $claim[2]];
    }

    /**
     * What the API's update fires around its registered fields, in the same transaction
     * ({@see PostsController}): `rest_insert_{type}` and `rest_after_insert_{type}`, with the update
     * a client would have sent the core route. A plugin hooked there sees a promote as the update it
     * is. (Registered REST fields update only when a request names them; a promote names SpacePress's
     * fields only, so none would run.)
     */
    private static function restHooks(array $spec, int $id, array $patch): void
    {
        $type = (string) $spec['wp']['postType'];
        $request = new \WP_REST_Request('POST', '/wp/v2/' . (get_post_type_object($type)?->rest_base ?: $type) . '/' . $id);
        $request->set_url_params(['id' => $id]);
        $request->set_body_params(Kinds::input(['type' => 'post', 'name' => '', 'spec' => $spec], $patch));
        clean_post_cache($id);
        $post = get_post($id) ?: throw new \LogicException("Post $id vanished");
        do_action("rest_insert_$type", $post, $request, false);
        do_action("rest_after_insert_$type", get_post($id), $request, false);
    }

    /** Who $viewer is, as a scheduled promote records it: a user id, `anonymous`, or `system` (no viewer: trusted). */
    private static function actor(?Viewer $viewer): int|string
    {
        return $viewer === null ? self::SYSTEM : ($viewer->user['id'] ?? self::ANONYMOUS);
    }

    /** Now in epoch seconds, on the runtime's clock (`SP_NOW` pins it in tests). */
    private static function now(): int
    {
        return intdiv(Js::now(), 1000);
    }

    private static function sign(string $payload): string
    {
        return self::base64(hash_hmac('sha256', 'spacepress-staged-preview:' . $payload, wp_salt('auth'), true));
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
