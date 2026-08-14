<?php
declare(strict_types=1);

// GENERATED FILE — DO NOT EDIT.
// Source of truth: packages/common/src/utils/publish-policy.ts
// Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy
// Parity is enforced by publish-policy.fixtures.json, which
// apps/control-plane/src/runtime/php-policy-parity.test.ts runs through this
// file AND through staticUploadPathViolation, asserting one verdict per path.

const SPACEFAST_UPLOAD_MAX_PATH_BYTES = 1024;
const SPACEFAST_UPLOAD_EXECUTION_CONTROL_FILES = ['.htaccess', '.user.ini'];
// Root-level docroot files this engine installs (from its own engine-manifest.json).
const SPACEFAST_UPLOAD_RESERVED_ROOT_CONTROL_FILES = ['custom-redirects.php', 'engine-manifest.json', 'installer.php'];
// Whole root segments, exactly the namespaces the front door's control table
// claims at request time (see SPACEFAST_CONTROL_PATHS in shared/context.php):
// '__sfx' and '__spanish' are tenant paths, '__sf/x' and '__span/x' are ours.
const SPACEFAST_UPLOAD_RESERVED_ROOT_SEGMENTS = ['__sf', '__span'];
// Matched as a segment PREFIX at any depth, which is broader than the front
// door: it also holds '__spacefast_generated/' and the planned '__stattic/*'.
const SPACEFAST_UPLOAD_RESERVED_SEGMENT_PREFIXES = ['__spacefast', '__stattic'];
const SPACEFAST_UPLOAD_RESERVED_PATHS = ['.well-known/spacefast-runtime', '.well-known/stattic-runtime'];
const SPACEFAST_UPLOAD_RESERVED_PREFIXES = ['.spacefast/storage', '.spacefast/engine', '.stattic/storage', '.stattic/engine'];
// The one subtree and the one file a publish may write inside the control
// prefix: a compiled Functions bundle and the Zero deploy record. Their
// siblings are the signing key, the runtime artifact and the runtime binary, so
// a publish that could overwrite those could mint its own tokens or replace the
// runtime. index.php is deliberately unreserved: published files never land in
// the docroot (CAS only), so a publisher's index.php serves as inert bytes.
const SPACEFAST_UPLOAD_PUBLISHABLE_BUNDLE_PREFIX = '__spacefast/functions/bundles/';
const SPACEFAST_UPLOAD_PUBLISHABLE_DEPLOY_PATH = '__spacefast/zero/deploy.json';

function _stattic_static_path_violation(string $code, string $message, string $path): array
{
    return [
        'code' => $code,
        'message' => $message,
        'path' => $path,
    ];
}

// Mirrors normalizeVersionPath(): NFC, and no control, empty, '.' or '..'
// segment. The TS policy demands the input already BE its normal form, so any
// difference is a rejection rather than a rewrite. Without intl an NFD spelling
// is indistinguishable from an NFC one, so a non-ASCII path fails closed.
function _stattic_static_path_is_normal(string $path): bool
{
    if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
        return false;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }
    if (preg_match('/[\x80-\xff]/', $path) !== 1) {
        return true;
    }
    return class_exists('Normalizer') && Normalizer::normalize($path, Normalizer::FORM_C) === $path;
}

function _stattic_static_upload_path_violation(string $path): ?array
{
    if (
        trim($path) !== $path
        || str_starts_with($path, '/')
        || str_contains($path, '\\')
        || str_contains($path, '//')
        || !_stattic_static_path_is_normal($path)
    ) {
        return _stattic_static_path_violation('invalid_file_path', 'File path is invalid.', $path);
    }
    if (strlen($path) > SPACEFAST_UPLOAD_MAX_PATH_BYTES) {
        return _stattic_static_path_violation('path_too_long', 'File paths support up to ' . SPACEFAST_UPLOAD_MAX_PATH_BYTES . ' bytes in canonical form.', $path);
    }

    $lowerPath = strtolower($path);
    $lowerSegments = explode('/', $lowerPath);
    foreach ($lowerSegments as $segment) {
        if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
            return _stattic_static_path_violation('invalid_file_path', 'File path is invalid.', $path);
        }
    }

    $reserved = static fn (string $p): array => _stattic_static_path_violation('static_runtime_control_path_not_supported', 'Static deploys cannot upload runtime control paths.', $p);
    $lowerName = $lowerSegments[count($lowerSegments) - 1];
    if (in_array($lowerName, SPACEFAST_UPLOAD_EXECUTION_CONTROL_FILES, true)) {
        return _stattic_static_path_violation('static_control_file_not_supported', 'Static deploys cannot upload execution-control files.', $path);
    }
    if ($lowerPath === $lowerName && in_array($lowerName, SPACEFAST_UPLOAD_RESERVED_ROOT_CONTROL_FILES, true)) {
        return $reserved($path);
    }
    if (in_array($lowerSegments[0], SPACEFAST_UPLOAD_RESERVED_ROOT_SEGMENTS, true)) {
        return $reserved($path);
    }
    $publishableBuildArtifact =
        str_starts_with($lowerPath, SPACEFAST_UPLOAD_PUBLISHABLE_BUNDLE_PREFIX)
        || $path === SPACEFAST_UPLOAD_PUBLISHABLE_DEPLOY_PATH;
    if (!$publishableBuildArtifact) {
        foreach ($lowerSegments as $segment) {
            foreach (SPACEFAST_UPLOAD_RESERVED_SEGMENT_PREFIXES as $prefix) {
                if (str_starts_with($segment, $prefix)) {
                    return $reserved($path);
                }
            }
        }
    }
    foreach (SPACEFAST_UPLOAD_RESERVED_PREFIXES as $prefix) {
        if ($lowerPath === $prefix || str_starts_with($lowerPath, $prefix . '/')) {
            return $reserved($path);
        }
    }
    if (in_array($lowerPath, SPACEFAST_UPLOAD_RESERVED_PATHS, true)) {
        return $reserved($path);
    }

    return null;
}

function _stattic_runtime_assert_static_upload_path(string $path): void
{
    $violation = _stattic_static_upload_path_violation($path);
    if (is_array($violation)) {
        _stattic_problem_response(
            422,
            $violation['code'],
            $violation['message'],
            ['details' => ['path' => $violation['path']]],
        );
    }
}
