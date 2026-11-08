<?php

declare(strict_types=1);

namespace Zero\Services;

use Zero\Access;
use Zero\Env;
use Zero\Extensions;
use Zero\HttpError;
use Zero\Jobs\Jobs;
use Zero\Jobs\Receipts;
use Zero\Js;
use Zero\Schema;
use Zero\Viewer;

use function Zero\db;

/** Optional native WordPress services. Private configuration is never part of schema/describe. */
final class Module
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        Storage::register();
        Extensions::route('services', self::route(...));
        self::$registered = true;
    }

    public static function config(): array
    {
        $config = apply_filters('zero_services_config', []);
        if (!is_array($config)) {
            throw new \LogicException('zero_services_config must return an array');
        }
        return $config;
    }

    public static function secret(string $name): string
    {
        $value = Env::get($name);
        if ($value === null || $value === '') {
            throw new HttpError(503, 'service_unconfigured', 'This service is not configured yet.');
        }
        return $value;
    }

    public static function body(string $raw): array
    {
        if (strlen($raw) > 32768) {
            throw new HttpError(413, 'payload_too_large', 'Service requests are limited to 32 KiB');
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'invalid_json', 'Send a JSON object');
        }
        if (!is_array($data) || !str_starts_with(ltrim($raw), '{')) {
            throw HttpError::invalidInput('Send a JSON object', '');
        }
        return $data;
    }

    /** Existing receipts own request idempotency, including changed-input rejection. */
    public static function once(string $scope, array $input, array $headers, callable $run): mixed
    {
        $key = $headers['idempotency-key'] ?? '';
        if (!is_string($key) || $key === '' || strlen($key) > 64) {
            throw HttpError::invalidInput('Send an Idempotency-Key of 1–64 bytes', '/Idempotency-Key');
        }
        return Receipts::withFingerprint(hash('sha256', \Zero\Json::encode($input, JSON_THROW_ON_ERROR)),
            static fn () => Receipts::once($scope, $key, $run));
    }

    public static function route(string $method, array $parts, array $query, string $body, Viewer $viewer, array $files = [], array $form = [], array $headers = []): array
    {
        $path = implode('/', array_slice($parts, 1));
        if ($method === 'POST' && preg_match('#^forms/([a-z][a-z0-9_-]{0,79})$#D', $path, $match)) {
            return [201, self::submit($match[1], self::body($body), $headers)];
        }
        if ($method === 'GET' && $path === 'search') {
            return [200, Search::query($query, $viewer)];
        }
        if ($method === 'POST' && $path === 'checkout') {
            return [202, Payments::checkout(self::body($body), $headers)];
        }
        if ($method === 'GET' && preg_match('#^checkout/([a-f0-9]{64})$#D', $path, $match)) {
            return [200, Payments::status($match[1])];
        }
        if ($method === 'POST' && $path === 'stripe/webhook') {
            return [200, Payments::webhook($body, $headers['stripe-signature'] ?? '')];
        }
        if ($method === 'GET' && preg_match('#^submissions/([a-f0-9-]{36})$#D', $path, $match)) {
            Access::require(['role' => 'editor'], $viewer, 'read', null, 'read submissions');
            $row = Storage::run('SELECT * FROM {submissions} WHERE `id` = ?', [$match[1]])[0] ?? throw HttpError::notFound('Submission not found');
            $row['body'] = json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR);
            $row['delivery'] = self::jobStatus($row['jobId']);
            return [200, $row];
        }
        throw HttpError::notFound('Service route not found');
    }

    public static function jobStatus(string $id): array
    {
        $row = \Zero\Jobs\Storage::run('SELECT "state", "attempts", "doneAt" FROM {jobs} WHERE "id" = ?', [$id])[0]
            ?? throw new \RuntimeException('Service delivery record is missing');
        return ['state' => $row['state'], 'attempts' => (int) $row['attempts'], 'doneAt' => $row['doneAt']];
    }

    private static function submit(string $name, array $input, array $headers): array
    {
        $form = self::config()['forms'][$name] ?? throw HttpError::notFound('Form not found');
        foreach ($input as $key => $_) {
            if (!in_array($key, ['data', 'website'], true)) {
                throw HttpError::invalidInput('Unknown submission property', HttpError::seg((string) $key));
            }
        }
        if (($input['website'] ?? '') !== '') {
            throw new HttpError(422, 'spam', 'Submission rejected');
        }
        $fields = $form['fields'];
        // A deliberately bounded form subset; files and arbitrary HTML use WordPress plugins.
        foreach ($fields as $field) {
            if (!in_array($field['type'] ?? '', ['text', 'email', 'boolean', 'integer'], true)) {
                throw new \LogicException('Services forms support text, email, boolean and integer fields');
            }
        }
        $values = Schema::validateInput($fields, $input['data'] ?? null, '/data', null);
        return self::once('services:form:' . $name, $input, $headers, static function () use ($name, $form, $values): array {
            Storage::rate('form:' . $name, max(1, min(100, (int) ($form['limit'] ?? 5))));
            $id = Jobs::uuid();
            $job = Jobs::enqueue('zero.services.mail', ['submissionId' => $id], ['key' => $id]);
            Storage::run('INSERT INTO {submissions} (`id`, `form`, `body`, `jobId`, `createdAt`) VALUES (?, ?, ?, ?, ?)',
                [$id, $name, \Zero\Json::encode($values, JSON_THROW_ON_ERROR), $job['id'], Js::iso(Js::now())]);
            return ['id' => $id, 'message' => 'Thanks — your message is saved.', 'delivery' => 'queued'];
        });
    }
}
