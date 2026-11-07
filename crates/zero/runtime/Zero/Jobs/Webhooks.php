<?php

declare(strict_types=1);

namespace Zero\Jobs;

use Zero\Env;
use Zero\Extensions;
use Zero\HttpError;
use Zero\Http;
use Zero\Js;
use Zero\Viewer;

use function Zero\db;

/**
 * Webhooks (§6.1, D2). Outbound: every committed pipeline write on a collection a webhook listens
 * to enqueues the built-in `zero.webhook` job, which POSTs a signed JSON event and retries on
 * failure with the job backoff. Inbound: an action with `signature` verifies an HMAC over the raw
 * body instead of an access rule.
 */
final class Webhooks
{
    public const JOB = 'zero.webhook';
    public const RETRIES = 8;
    public const TOLERANCE_S = 300;
    private const EVENTS = ['create' => 'created', 'update' => 'updated', 'delete' => 'deleted'];

    public static function register(): void
    {
        Extensions::onWritten(self::written(...));
        Extensions::beforeAction(self::verify(...));
    }

    public static function spec(): array
    {
        $json = ['type' => 'json'];

        return [
            'input' => ['webhook' => ['type' => 'integer', 'notNull' => true], 'event' => ['type' => 'text', 'notNull' => true],
                'collection' => ['type' => 'text', 'notNull' => true], 'id' => $json, 'row' => $json, 'previous' => $json],
            'handler' => self::deliver(...),
            'retries' => self::RETRIES,
            'agents' => false,
        ];
    }

    /** Extensions::onWritten: inside the write's transaction, so a rollback drops the event too. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        $db = db();
        $event = $collection . '.' . self::EVENTS[$op];
        $pk = $db->schema()->collection($collection)['primaryKey'];
        foreach ($db->schema()->webhooks() as $i => $hook) {
            if (!in_array($event, $hook['events'], true) && !in_array('*', $hook['events'], true)) {
                continue;
            }
            $input = ['webhook' => $i, 'event' => $event, 'collection' => $collection, 'id' => $row[$pk] ?? null, 'row' => $row];
            if ($op === 'update') {
                $input['previous'] = $previous;
            }
            Jobs::enqueue(self::JOB, $input);
        }
    }

    /** The job handler: one delivery attempt; any non-2xx throws so the runner backs off and retries. */
    public static function deliver(array $input, array $ctx): void
    {
        $hook = db()->schema()->webhooks()[$input['webhook']] ?? throw new \RuntimeException("webhook {$input['webhook']} is no longer configured");
        $url = Env::resolve($hook['url']);
        $secret = Env::resolve($hook['secret']);
        if ($url === null || $url === '' || $secret === null || $secret === '') {
            throw new \RuntimeException('webhook url or secret is not set in the environment');
        }
        $data = ['row' => $input['row']];
        if (array_key_exists('previous', $input) && $input['previous'] !== null) {
            $data['previous'] = $input['previous'];
        }
        $body = Js::jsonStringify(['id' => $ctx['jobId'], 'event' => $input['event'], 'collection' => $input['collection'],
            'at' => Js::iso(Js::now()), 'data' => $data]) ?? '{}';
        $t = intdiv(Js::now(), 1000);
        $response = Http::post($url, $body, [
            'Content-Type' => 'application/json',
            'Zero-Event-Id' => $ctx['jobId'],
            'Zero-Event' => $input['event'],
            'Zero-Signature' => 't=' . $t . ',v1=' . self::sign($secret, $t . '.' . $body),
        ]);
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new \RuntimeException("webhook endpoint answered {$response['status']}");
        }
    }

    /** Extensions::beforeAction: `signature` replaces the access check; the verified caller is anonymous. */
    public static function verify(array $spec, string $name, string $rawBody, array $headers, ?Viewer $viewer): void
    {
        $signature = $spec['signature'] ?? null;
        if ($signature === null || $viewer === null) {
            return; // no signature option, or the trusted CLI
        }
        $secret = Env::resolve($signature['secret']);
        $given = $headers[strtolower($signature['header'])] ?? null;
        if ($secret === null || $secret === '') {
            error_log("[zero] action $name: signature secret {$signature['secret']['env']} is not set");
            throw self::invalid();
        }
        if (!is_string($given) || !match ($signature['scheme']) {
            'zero' => self::verifyZero($secret, $given, $rawBody),
            'hmac-sha256' => hash_equals(self::sign($secret, $rawBody), strtolower(trim($given))),
            default => false,
        }) {
            throw self::invalid();
        }
    }

    /** `t=<unix>,v1=<hex>[,v1=<hex>]`: fresh timestamp and any matching v1 (secret rotation). */
    private static function verifyZero(string $secret, string $header, string $rawBody): bool
    {
        $t = null;
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && preg_match('/^\d{1,12}$/D', $v)) {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $candidates[] = strtolower($v);
            }
        }
        if ($t === null || abs(intdiv(Js::now(), 1000) - $t) > self::TOLERANCE_S) {
            return false;
        }
        $expected = self::sign($secret, $t . '.' . $rawBody);
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private static function invalid(): HttpError
    {
        return new HttpError(401, 'invalid_signature', 'The request signature is missing, stale or invalid');
    }
}
