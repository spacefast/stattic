<?php

declare(strict_types=1);

namespace Zero\Wp\Media;

use Zero\Access;
use Zero\App;
use Zero\HttpError;
use Zero\Viewer;
use Zero\Wp\CoreAccess;
use Zero\Wp\Plugin;
use Zero\Wp\Runtime;
use Zero\Wp\WpAuth;
use Zero\Wp\WpMedia;

/** Additional Zero authority around core's media controller, after WP's own permission checks. */
final class Rest
{
    public static function boot(): void
    {
        add_filter('rest_dispatch_request', [self::class, 'dispatch'], 10, 4);
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
                $request->has_param('alt_text'), $endpoint === 'edit' => 'update',
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

    /** Keep native local-file effects within the same commit/rollback boundary as Zero's writes. */
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
