<?php

declare(strict_types=1);

namespace Zero\Media;

use Zero\Env;
use Zero\HttpError;
use Zero\Vendor\AsyncAws\S3\S3Client;
use Zero\Vendor\AsyncAws\Core\Credentials\Credentials;
use Zero\Vendor\Symfony\Component\HttpClient\HttpClient;

/** Bytes only. Identity, grants and metadata belong to the existing database owners. */
final class S3
{
    private readonly S3Client $client;
    private readonly string $bucket;

    public function __construct(array $config)
    {
        try {
            $this->bucket = self::required($config['bucket'] ?? null);
            $options = [
                'region' => self::required($config['region'] ?? 'us-east-1'),
                'accessKeyId' => self::required($config['credentials']['accessKeyId'] ?? null),
                'secretAccessKey' => self::required($config['credentials']['secretAccessKey'] ?? null),
                'pathStyleEndpoint' => !empty($config['pathStyle']) ? 'true' : 'false',
                'sendChunkedBody' => 'false',
            ];
            foreach (['endpoint', 'sessionToken'] as $key) {
                $source = $key === 'sessionToken' ? ($config['credentials'] ?? []) : $config;
                if (isset($source[$key])) {
                    $options[$key] = self::required($source[$key]);
                }
            }
            $credentials = new Credentials($options['accessKeyId'], $options['secretAccessKey'], $options['sessionToken'] ?? null);
            unset($options['accessKeyId'], $options['secretAccessKey'], $options['sessionToken']);
            $this->client = new S3Client($options, $credentials, HttpClient::create(['timeout' => 15, 'max_duration' => 120, 'buffer' => false]));
        } catch (\Throwable) {
            throw self::unavailable();
        }
    }

    private static function required(mixed $value): string
    {
        $resolved = Env::resolve($value);
        if ($resolved === null || trim($resolved) === '') {
            throw self::unavailable();
        }

        return $resolved;
    }

    public static function unavailable(): HttpError
    {
        // Never attach the provider exception: requests may contain credentials or signed URLs.
        return new HttpError(503, 'uploads_unavailable', 'The configured upload storage is unavailable');
    }

    public function put(string $key, string $path, string $mime): void
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw self::unavailable();
        }
        try {
            $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $in,
                'ContentLength' => (int) filesize($path), 'ContentType' => $mime])->resolve();
        } catch (\Throwable) {
            throw self::unavailable();
        } finally {
            fclose($in);
        }
    }

    /** Resolve provider headers before emitting any response. Stream without a full-body buffer. */
    public function stream(string $key): \Closure
    {
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $result->resolve();
            $body = $result->getBody();
        } catch (\Throwable) {
            throw self::unavailable();
        }

        return static function () use ($body): void {
            try {
                foreach ($body->getChunks() as $chunk) {
                    echo $chunk;
                }
            } catch (\Throwable) {
                // Headers are already sent. A truncated response fails its Content-Length; no secret logs.
                error_log('Zero upload stream interrupted');
            }
        };
    }

    public function hydrate(string $key, string $path): void
    {
        try {
            $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $result->resolve();
            $out = fopen($path, 'wb');
            if ($out === false) {
                throw self::unavailable();
            }
            try {
                foreach ($result->getBody()->getChunks() as $chunk) {
                    if (fwrite($out, $chunk) !== strlen($chunk)) {
                        throw self::unavailable();
                    }
                }
            } finally {
                fclose($out);
            }
        } catch (\Throwable) {
            @unlink($path);
            throw self::unavailable();
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key])->resolve();
        } catch (\Throwable) {
            throw self::unavailable();
        }
    }

    /** One bounded page. The caller owns durable continuation and the reference check. */
    public function page(string $prefix, ?string $cursor): array
    {
        try {
            $result = $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $prefix,
                'MaxKeys' => 100, 'ContinuationToken' => $cursor]);
            $objects = [];
            foreach ($result->getContents(true) as $object) {
                $objects[] = ['key' => $object->getKey(), 'time' => $object->getLastModified()?->getTimestamp()];
            }

            return [$objects, $result->getNextContinuationToken()];
        } catch (\Throwable) {
            throw self::unavailable();
        }
    }
}
