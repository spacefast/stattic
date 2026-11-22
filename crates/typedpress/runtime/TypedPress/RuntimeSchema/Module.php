<?php

declare(strict_types=1);

namespace TypedPress\RuntimeSchema;

use TypedPress\App;
use TypedPress\Extensions;
use TypedPress\HttpError;
use TypedPress\Viewer;
use TypedPress\Wp\Runtime;
use TypedPress\Wp\SchemaVersions;

/**
 * The runtime schema's own routes under `/wp-json/typedpress/v1/schema-versions` (spec §2.10; the hyphen keeps it clear of every kind name). Registered for
 * every app; an app without `runtimeSchema: true` answers 404, as it would for any unknown path.
 *
 *   GET /schema-versions/lock     the site's active version as `tp/_generated/runtime-schema.json`
 *                          (`typedpress pull` writes it), plus what a deploy of the build this request
 *                          runs would report. Administrators only: it is the site's configuration.
 */
final class Module
{
    public static function register(): void
    {
        Surfaces::register();
        Extensions::route('schema-versions', self::route(...));
        // The description reports a broken chain as soon as there is one, not only once something has
        // read the versions (and recorded the problem on the option).
        Extensions::schemaDescribe(static function (array $description): array {
            if (!isset($description['runtimeSchema']) || isset($description['runtimeSchema']['problem'])) {
                return $description;
            }
            $plugin = class_exists(Runtime::class, false) ? Runtime::plugin() : null;
            $problem = $plugin === null ? null : SchemaVersions::of($plugin)?->problem();
            if ($problem !== null) {
                $description['runtimeSchema']['problem'] = $problem;
            }

            return $description;
        });
    }

    public static function route(string $method, array $parts, array $query, string $body, Viewer $viewer): array
    {
        $plugin = class_exists(Runtime::class, false) ? Runtime::plugin() : null;
        $versions = $plugin === null ? null : SchemaVersions::of($plugin);
        if ($versions === null) {
            throw HttpError::notFound('This app does not use runtime schema changes (runtimeSchema: true in typedpress.config.ts)');
        }
        if (array_slice($parts, 1) !== ['lock']) {
            throw HttpError::notFound('No such schema route');
        }
        App::allow($method, ['GET']);
        if ($viewer->user === null) {
            throw new HttpError(401, 'unauthenticated', 'Sign in as an administrator to read the site\'s schema versions');
        }
        if (($viewer->user['role'] ?? null) !== 'admin') {
            throw new HttpError(403, 'forbidden', 'Only an administrator reads the site\'s schema versions');
        }

        return [200, ['lock' => $versions->lock(), 'deploy' => $versions->deployCheck()]];
    }
}
