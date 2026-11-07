<?php

declare(strict_types=1);

namespace Zero\Media;

use Zero\Access;
use Zero\App;
use Zero\Extensions;
use Zero\HttpError;
use Zero\Viewer;

use function Zero\db;

/** The media routes (phase-1 §4.6 + phase-2 §7.2 `PATCH /media/{id}`). */
final class Module
{
    public static function register(): void
    {
        Extensions::route('media', self::dispatch(...));
        Files::register();
        if (defined('ABSPATH') && function_exists('add_action')) {
            \Zero\Wp\Uploads::boot();
            \Zero\Wp\WpMedia::boot();
            \Zero\Wp\WpFiles::boot();
        }
    }

    /** @return array{int, mixed}|\Zero\Response */
    public static function dispatch(string $method, array $segments, array $query, string $body, Viewer $viewer, array $files, array $form): array|\Zero\Response
    {
        $n = count($segments);
        if ($n >= 5 && $segments[2] === 'file') {
            return \Zero\Wp\Uploads::dispatch($method, $segments);
        }
        if ($n === 1) {
            App::allow($method, ['GET', 'POST']);
            if ($method === 'GET') {
                $opts = \Zero\Seam::toPhp(App::listOptions($query)); // media has no json() columns
                if (isset($opts['with'])) {
                    throw HttpError::invalidQuery('media has no relations', '/with');
                }

                return [200, db()->mediaList($opts)];
            }
            $file = $files['file'] ?? null;
            if (!is_array($file) || is_array($file['error'] ?? null)) {
                throw HttpError::invalidInput('Send the file as multipart/form-data field "file"', '/file');
            }
            $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new HttpError(413, 'payload_too_large', 'The upload is larger than this server accepts');
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw HttpError::invalidInput('The upload failed (error ' . $err . ')', '/file');
            }
            $alt = $form['alt'] ?? null;
            if ($alt !== null && !is_string($alt)) {
                throw HttpError::invalidInput('alt must be a string', '/alt');
            }

            return [201, db()->mediaUpload((string) $file['tmp_name'], (string) ($file['name'] ?? 'file'), $alt, $viewer)];
        }
        if ($n !== 2) {
            throw HttpError::notFound('No such media route');
        }
        App::allow($method, ['GET', 'PATCH', 'DELETE']);
        $id = preg_match('/^\d{1,18}$/D', $segments[1]) ? (int) $segments[1] : -1;
        $missing = static fn () => HttpError::notFound("No media with id {$segments[1]}");

        return match ($method) {
            'GET' => [200, db()->mediaGet($id) ?? throw $missing()],
            'PATCH' => [200, self::update($id, $body, $viewer) ?? throw $missing()],
            'DELETE' => [200, db()->mediaDelete($id, $viewer) ?? throw $missing()],
        };
    }

    /** `PATCH /media/{id}` `{ alt?, focal? }`. */
    private static function update(int $id, string $body, Viewer $viewer): ?array
    {
        Access::require(Access::MEDIA['update'], $viewer, 'update', null, 'update media');
        try {
            $in = trim($body) === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpError(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($in) || ($in !== [] && array_is_list($in))) {
            throw HttpError::invalidInput('Body must be a JSON object', '');
        }
        $patch = [];
        foreach ($in as $key => $value) {
            $patch[$key] = match ($key) {
                'alt' => $value === null || is_string($value) ? ($value === '' ? null : $value) : throw HttpError::invalidInput('alt must be a string or null', '/alt'),
                'focal' => Images::focal($value),
                default => throw HttpError::invalidInput("Unknown field '$key'", HttpError::seg((string) $key)),
            };
        }

        return db()->media()->update($id, $patch);
    }

}
