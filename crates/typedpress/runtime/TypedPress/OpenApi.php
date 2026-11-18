<?php

declare(strict_types=1);

namespace TypedPress;

/**
 * `GET /__tp/api/v1/openapi.json`: the app's API as an OpenAPI 3.1 document. `typedpress build` writes
 * the document from the app's schema (the same data as schema.php and the typed client) into the
 * app plugin; this serves that file, with the site's own address as its server. Like
 * `GET /__tp/api/v1` it describes the app's kinds, fields, globals and actions (signed webhook
 * actions left out, as there), never rows, so it is public and the same for everyone.
 */
final class OpenApi
{
    /** The document's path segment below the API prefix. */
    public const ROUTE = 'openapi.json';

    public static function register(): void
    {
        if (Extensions::routeHandler(self::ROUTE) === null) {
            Extensions::route(self::ROUTE, self::route(...));
        }
    }

    public static function route(string $method, array $segments): Response
    {
        if (count($segments) !== 1) {
            throw HttpError::notFound('No such route');
        }
        App::allow($method, ['GET', 'HEAD']);
        // The representation is built once, from one read of the built file and one server, encoded
        // once: the ETag is that string's digest and the body is that string.
        $document = self::document() ?? throw HttpError::notFound('This build has no OpenAPI document; build the app again with this TypedPress version');
        $body = (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $etag = \TypedPress\Validator::bytes($body);
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'ETag' => $etag];
        if (\TypedPress\Validator::matches($etag)) {
            return Response::raw('', 304, $headers);
        }

        return Response::raw($method === 'HEAD' ? '' : $body, 200, $headers);
    }

    /** @return array{string, string} the document's server: the API's address and the site's name */
    private static function server(): array
    {
        return [Wp\Facade::url(), (string) get_bloginfo('name')];
    }

    /** The built document with this site as its server, or null when the build carries none. */
    public static function document(): ?\stdClass
    {
        $plugin = Wp\Plugin::instance();
        $file = $plugin === null ? null : $plugin->dir . '/tp/app/openapi.json';
        $bytes = $file === null ? false : @file_get_contents($file);
        if (!is_string($bytes)) {
            return null;
        }
        // Decoded to objects, so an empty schema `{}` stays an object when it is encoded again.
        $document = json_decode($bytes, false);
        if (!$document instanceof \stdClass) {
            return null;
        }
        [$url, $name] = self::server();
        $document->servers = [['url' => $url, 'description' => $name]];

        return $document;
    }
}
