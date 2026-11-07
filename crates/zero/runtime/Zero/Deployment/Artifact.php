<?php

declare(strict_types=1);

namespace Zero\Deployment;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $problem, string $detail, public readonly array $context = [])
    {
        parent::__construct($detail);
    }
}

/** Restricted local filesystem boundary, shared by artifact inspection and backup archives. */
final class Files
{
    public const MAX_BYTES = 8_589_934_592;
    public const MAX_FILES = 100_000;

    public static function require(bool $condition, string $code, string $detail): void
    {
        if (!$condition) {
            throw new Failure($code, $detail);
        }
    }

    public static function relative(string $path): void
    {
        self::require($path !== '' && strlen($path) <= 1024 && !preg_match('/[\x00-\x1f\x7f\\\\:]/', $path)
            && !str_starts_with($path, '/') && !preg_match('~(^|/)(\.|\.\.|)($|/)~', $path)
            && preg_match('//u', $path) === 1, 'unsafe_path', 'Only plain relative archive paths are supported.');
    }

    public static function directory(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
        $cursor = '';
        foreach (explode('/', $absolute) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            self::require($part !== '..', 'unsafe_path', 'Use a directory path without parent traversal.');
            $cursor .= '/' . $part;
            self::require(!is_link($cursor) && is_dir($cursor), 'unsafe_path', 'Directory paths must exist and contain no symbolic links.');
        }
        return realpath($absolute) ?: throw new Failure('unsafe_path', 'Directory is unavailable.');
    }

    public static function fresh(string $path): string
    {
        $parent = self::directory(dirname($path));
        $name = basename($path);
        self::relative($name);
        $target = $parent . '/' . $name;
        self::require(!file_exists($target) && !is_link($target), 'destination_exists', 'Destination already exists; overwrite is never implicit.');
        return $target;
    }

    public static function json(string $path): array
    {
        self::require(is_file($path) && !is_link($path) && filesize($path) <= 16_777_216, 'invalid_metadata', 'Expected bounded JSON metadata.');
        try {
            $value = json_decode(file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('invalid_metadata', 'Metadata is not valid JSON.');
        }
        self::require(is_array($value), 'invalid_metadata', 'Metadata must be a JSON object.');
        return $value;
    }

    /** @return array<string, array{size: int, sha256: string}> */
    public static function inventory(string $root): array
    {
        $root = self::directory($root);
        $files = [];
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            $name = substr($entry->getPathname(), strlen($root) + 1);
            self::relative($name);
            self::require($entry->isFile() && !$entry->isLink(), 'unsafe_file', 'Only regular files are supported.');
            self::require(lstat($entry->getPathname())['nlink'] === 1, 'unsafe_file', 'Hard-linked files are not supported.');
            $bytes += $entry->getSize();
            self::require(count($files) < self::MAX_FILES && $bytes <= self::MAX_BYTES, 'size_limit', 'Artifact exceeds the local adapter size limit.');
            $files[$name] = ['size' => $entry->getSize(), 'sha256' => hash_file('sha256', $entry->getPathname())];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    public static function writeJson(string $path, array $value): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $file = fopen($temp, 'x');
        self::require($file !== false, 'write_failed', 'Could not create private metadata.');
        try {
            chmod($temp, 0600);
            fwrite($file, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($file);
            fsync($file);
        } finally {
            fclose($file);
        }
        rename($temp, $path);
    }

    public static function copy(string $from, string $to): void
    {
        $files = self::inventory($from);
        self::require(mkdir($to, 0700), 'write_failed', 'Could not create private destination.');
        foreach ($files as $name => $record) {
            $target = $to . '/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            self::require(copy($from . '/' . $name, $target), 'write_failed', 'File copy failed.');
            chmod($target, 0600);
            self::require(hash_file('sha256', $target) === $record['sha256'], 'digest_mismatch', 'File changed during copy.');
        }
    }

    /** Call only for a newly created operation-owned scratch directory. */
    public static function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}

/** Inspects bytes and compiler-emitted literal env references, without evaluating uploaded PHP. */
final class Artifact
{
    public static function inspect(string $root): array
    {
        $root = Files::directory($root);
        $files = Files::inventory($root);
        $manifest = Files::json($root . '/zero-manifest.json');
        Files::require(is_string($manifest['app'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $manifest['app']) === 1
            && is_string($manifest['runtime'] ?? null) && is_array($manifest['plugins'] ?? null), 'invalid_manifest', 'Build manifest is incomplete.');
        $app = $manifest['app'];
        foreach (['zero-runtime', 'zero-app-' . $app, ...array_keys($manifest['plugins'])] as $slug) {
            Files::require(is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,100}$/D', $slug) === 1
                && is_dir($root . '/plugins/' . $slug), 'invalid_manifest', 'A declared plugin is missing or has an unsafe slug.');
        }
        $prefix = 'plugins/zero-app-' . $app . '/zero/app/';
        $env = [];
        $migrations = [];
        foreach ($files as $name => $record) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            if (str_ends_with($name, '.php')) {
                foreach (self::environment(file_get_contents($root . '/' . $name)) as $variable) {
                    $env[$variable] = ['name' => $variable, 'present' => getenv($variable) !== false, 'value' => '[redacted]'];
                }
            }
            if (str_starts_with($name, $prefix . 'migrations/') && str_ends_with($name, '.sql')) {
                Files::require(preg_match('~^' . preg_quote($prefix, '~') . 'migrations/([0-9]{4}_[a-z0-9_]+)\.sql$~D', $name, $matches) === 1,
                    'invalid_migration', 'Migration filenames must use the compiler numbering convention.');
                $sql = file_get_contents($root . '/' . $name);
                $sql = preg_replace('~/\*.*?\*/|--[^\n]*~s', ' ', $sql);
                $destructive = preg_match('/\b(DROP|TRUNCATE|DELETE|REPLACE|UPDATE|MODIFY|CHANGE|RENAME)\b/i', $sql) === 1;
                $migrations[] = ['id' => $matches[1], 'sha256' => $record['sha256'], 'destructive' => $destructive,
                    'review' => 'required; lexical classification is advisory and never authorizes execution'];
            }
        }
        ksort($env);
        $metadata = isset($files['zero-deployment.json']) ? Files::json($root . '/zero-deployment.json') : null;
        if ($metadata !== null) {
            Files::require(($metadata['version'] ?? null) === 1 && is_array($metadata['snapshot'] ?? null)
                && is_array($metadata['destructiveChanges'] ?? null), 'invalid_metadata', 'Unsupported compiler deployment metadata.');
        }
        return ['app' => $app, 'runtime' => $manifest['runtime'], 'plugins' => array_keys($manifest['plugins']),
            'digest' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'fileCount' => count($files), 'bytes' => array_sum(array_column($files, 'size')),
            'environment' => array_values($env), 'environmentCoverage' => 'generated literal Zero Env references; host wp-config constants are not inspected',
            'migrations' => $migrations, 'compilerMetadata' => $metadata === null ? 'unavailable; rebuild with deployment metadata support' : 'present',
            'schemaDigest' => $metadata === null ? null : hash('sha256', json_encode($metadata['snapshot'], JSON_THROW_ON_ERROR)),
            'compilerDestructiveChanges' => $metadata['destructiveChanges'] ?? [], 'publisherAuthenticated' => false, 'publishReady' => false,
            'missingCapabilities' => ['authenticated_site_app_ownership', 'enrolled_publisher_signature', 'durable_stage_activate_finalize',
                'early_admission_barrier', 'host_acknowledged_process_and_database_session_drain', 'installed_digest_attestation']];
    }

    /** Token parsing never includes/evals artifact PHP or interpolates literal values. */
    private static function environment(string $source): array
    {
        $tokens = array_values(array_filter(token_get_all($source), static fn ($token) => !is_array($token)
            || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $names = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $name = substr($token[1], 1, -1);
            $key = $tokens[$i - 2] ?? null;
            $call = $tokens[$i - 4] ?? null;
            $member = $tokens[$i - 2] ?? null;
            $isEnvValue = is_array($key) && in_array($key[1], ["'env'", '"env"'], true) && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_ARROW;
            $isEnvCall = is_array($call) && ltrim($call[1], '\\') === 'Zero\\Env' && is_array($member) && $member[1] === 'get' && ($tokens[$i - 1] ?? null) === '(';
            if (($isEnvValue || $isEnvCall) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }
}
