<?php

declare(strict_types=1);

namespace SpacePress\Wp\Media;

use SpacePress\Access;
use SpacePress\App;
use SpacePress\HttpError;
use SpacePress\Viewer;
use SpacePress\Wp\CoreAccess;
use SpacePress\Wp\Plugin;
use SpacePress\Wp\Runtime;
use SpacePress\Wp\WpAuth;
use SpacePress\Wp\WpMedia;

/** Additional SpacePress authority around core's media controller, after WP's own permission checks. */
final class Rest
{
    public static function boot(): void
    {
        add_filter('rest_dispatch_request', [self::class, 'dispatch'], 10, 4);
        add_action('rest_api_init', [self::class, 'fields']);
        // Media reads need core's check (an attachment of a post the caller can't read is a 404,
        // as on /wp/v2/media) and SpacePress's media read rule on top of it.
        add_filter('rest_request_before_callbacks', [self::class, 'readable'], 10, 3);
    }

    /**
     * rest_request_before_callbacks: a media item read runs core's permission check first (its parent
     * post's read check, as core does for an attached item), then SpacePress's media read rule. SpacePress's
     * rule only narrows: the API never shows a media item core REST hides from the same caller. Core
     * runs its own check again after this one allows: both read the same state.
     */
    public static function readable(mixed $response, mixed $handler, mixed $request): mixed
    {
        if ($response !== null || !$request instanceof \WP_REST_Request || !self::reads($handler)
            || preg_match('#^/wp/v2/media/(\d+)$#i', $request->get_route()) !== 1) {
            return $response;
        }
        $post = get_post((int) $request['id']);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return $response;
        }
        $allowed = call_user_func($handler['permission_callback'], $request);
        if ($allowed !== true) {
            // On the API a hidden item is a missing one: its existence never leaks. (Core answers a
            // parent it can't read with `false`, which REST turns into rest_forbidden.) Otherwise core's
            // own check gives its answer.
            return \SpacePress\Wp\Facade::dispatching() && ($allowed === false || (is_wp_error($allowed) && $allowed->get_error_code() === 'rest_forbidden'))
                ? new \WP_Error('rest_post_invalid_id', __('Invalid post ID.'), ['status' => 404])
                : $response;
        }
        try {
            Access::require(Access::MEDIA['read'], new Viewer(WpAuth::current()), 'read', null, 'read media');
        } catch (HttpError $e) {
            return CoreAccess::error($e);
        }

        return $response;
    }

    /** Whether a route handler is one that answers GET (and so HEAD) and has a permission check. */
    public static function reads(mixed $handler): bool
    {
        if (!is_array($handler) || !is_callable($handler['permission_callback'] ?? null)) {
            return false;
        }
        $methods = $handler['methods'] ?? '';

        return is_array($methods) ? isset($methods['GET']) : str_contains((string) $methods, 'GET');
    }

    /**
     * `sp_focal` on core's attachments: the focal point (`{ x, y }` from the top-left, 0..1, or null).
     * Writing it re-renders the cover sizes, inside {@see dispatch()}'s transaction and under SpacePress's
     * media `update` rule, so the facade's `PATCH /media/<id> { focal }` is a core media update.
     */
    public static function fields(): void
    {
        register_rest_field('attachment', 'sp_focal', [
            'schema' => ['description' => 'The focal point SpacePress crops cover sizes around.', 'type' => ['object', 'null'], 'context' => ['view', 'edit'],
                'properties' => ['x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]]],
            'get_callback' => static fn (array $post): ?array => WpMedia::focal((int) $post['id']),
            'update_callback' => static function (mixed $value, \WP_Post $post): ?\WP_Error {
                try {
                    Runtime::plugin()?->db()->media()->update($post->ID, ['focal' => \SpacePress\Media\Images::focal($value)]);
                } catch (HttpError $e) {
                    return CoreAccess::error($e);
                }

                return null;
            },
        ]);
    }

    public static function dispatch(mixed $result, \WP_REST_Request $request, string $route, array $handler): mixed
    {
        $method = $request->get_method();
        if ($result !== null || in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            || !preg_match('#^/wp/v2/media(?:/(\d+))?(?:/(edit|post-process|sideload|finalize))?/?$#i', $request->get_route(), $match)) {
            return $result;
        }
        $plugin = Runtime::plugin();
        if ($plugin === null) {
            return $result;
        }
        try {
            if ($plugin->unavailable() !== null) {
                throw new HttpError(503, 'unavailable', Plugin::UNAVAILABLE);
            }
            $viewer = new Viewer(WpAuth::current());
            $id = isset($match[1]) ? (int) $match[1] : null;
            $endpoint = strtolower($match[2] ?? '');
            $operation = match (true) {
                $method === 'DELETE' => 'delete',
                $id === null, in_array($endpoint, ['post-process', 'sideload', 'finalize'], true) => 'create',
                $request->has_param('alt_text'), $request->has_param('sp_focal'), $endpoint === 'edit' => 'update',
                default => null, // Caption/title and other core fields retain WP's permissions.
            };
            if ($operation !== null) {
                Access::require(Access::MEDIA[$operation], $viewer, $operation, null, "$operation media");
            }
            $db = $plugin->db();
            $previous = $db->actor();
            $db->setActor($viewer->user);
            try {
                return $db->transaction(static function () use ($plugin, $db, $viewer, $id, $endpoint, $method, $request, $handler) {
                    // Keep core's file validation (multipart AND raw uploads), metadata, additional
                    // REST fields, plugin hooks, response links and status; never reconstruct them.
                    $run = static fn () => self::native($plugin, $handler, $request);
                    if ($id !== null) {
                        $plugin->connection()->touchPost($id);
                    }
                    if ($method === 'DELETE' && $id !== null) {
                        // Shared authorization + reference writes (including hooks/revisions) finish
                        // before core removes the attachment. A native failure rolls those back.
                        $response = null;
                        $db->mediaDelete($id, $viewer, static function () use ($run, &$response): void {
                            $response = $run();
                        }) ?? throw HttpError::notFound("No media with id $id");

                        return $response;
                    }

                    return $run();
                });
            } finally {
                $db->setActor($previous);
            }
        } catch (Refused $e) {
            return $e->error;
        } catch (\Throwable $e) {
            $problem = App::problem($e);

            return CoreAccess::error(new HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        }
    }

    /** Keep native local-file effects within the same commit/rollback boundary as SpacePress's writes. */
    private static function native(Plugin $plugin, array $handler, \WP_REST_Request $request): mixed
    {
        $connection = $plugin->connection();
        $directories = [];
        $owned = [];
        // Observe destination contents before core writes. Metadata may mention files belonging
        // to an older attachment, so it cannot establish ownership of a rollback deletion.
        $remember = static function (string $directory) use (&$directories): void {
            $directory = wp_normalize_path($directory);
            if (!isset($directories[$directory])) {
                $files = @scandir($directory);
                if ($files !== false) {
                    $directories[$directory] = array_fill_keys($files, true);
                }
            }
        };
        $created = static function (string $path) use ($connection, &$directories, &$owned): string {
            $original = $path;
            $path = wp_normalize_path($path);
            $directory = dirname($path);
            if (isset($directories[$directory]) && !isset($directories[$directory][basename($path)]) && !isset($owned[$path])) {
                $owned[$path] = true;
                $connection->onRollback(static fn () => @unlink($path));
            }

            return $original;
        };
        $unique = static function (string $filename, string $extension, string $directory) use ($remember): string {
            $remember($directory);

            return $filename;
        };
        $moved = static function (array $file) use ($created): array {
            if (isset($file['file'])) {
                $created($file['file']);
            }

            return $file;
        };
        $inserted = static fn (int $id) => $connection->touchPost($id);
        $id = (int) $request->get_param('id');
        if ($id > 0) {
            $file = get_attached_file($id);
            if (is_string($file) && $file !== '') {
                $remember(dirname($file));
            }
        }
        // Previous filters retain ownership (for example an offload plugin may return an empty
        // path). Defer only the local unlink core itself would have performed, without filtering twice.
        $delete = static function (string $path) use ($connection): string {
            if ($path !== '') {
                $connection->afterCommit(static fn () => @unlink($path));
            }

            return '';
        };
        // A native edit creates a new attachment too. Unique-name selection precedes the
        // original write; image-save observations cover core sub-sizes even before metadata
        // filters run or throw. Never sweep a directory: concurrent requests own their files.
        add_filter('wp_unique_filename', $unique, PHP_INT_MAX, 3);
        add_filter('image_make_intermediate_size', $created, PHP_INT_MIN);
        add_filter('wp_handle_upload', $moved, PHP_INT_MIN);
        add_filter('wp_handle_sideload', $moved, PHP_INT_MIN);
        add_action('add_attachment', $inserted, PHP_INT_MIN);
        if ($request->get_method() === 'DELETE') {
            add_filter('wp_delete_file', $delete, PHP_INT_MAX);
        }
        try {
            $response = call_user_func($handler['callback'], $request);
            if (is_wp_error($response)) {
                // The storage refused the file: SpacePress's 503 (retry later), not core's generic 500.
                if (\SpacePress\Wp\Uploads::storageFailed()) {
                    throw \SpacePress\Media\S3::unavailable();
                }
                throw new Refused($response);
            }
            WpMedia::requireRendered();

            return $response;
        } finally {
            remove_filter('wp_unique_filename', $unique, PHP_INT_MAX);
            remove_filter('image_make_intermediate_size', $created, PHP_INT_MIN);
            remove_filter('wp_handle_upload', $moved, PHP_INT_MIN);
            remove_filter('wp_handle_sideload', $moved, PHP_INT_MIN);
            remove_action('add_attachment', $inserted, PHP_INT_MIN);
            remove_filter('wp_delete_file', $delete, PHP_INT_MAX);
        }
    }
}

/** Preserve core's original error object while using a throwable to roll back the operation. */
final class Refused extends \RuntimeException
{
    public function __construct(public readonly \WP_Error $error)
    {
        parent::__construct($error->get_error_message());
    }
}
