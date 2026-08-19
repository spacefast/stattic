<?php
declare(strict_types=1);

// GENERATED FILE — DO NOT EDIT.
// Source of truth: packages/common/src/utils/storage-policy.ts
// Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy
// Parity is enforced by storage-policy.fixtures.json.

const SPACEFAST_STORAGE_OBJECT_MAX_FILE_BYTES = 5242880;
const SPACEFAST_STORAGE_CONTENT_SNIFF_BYTES = 512;
const SPACEFAST_STORAGE_ANON_DAILY_BYTES = 209715200;
const SPACEFAST_STORAGE_BLOCKED_CONTENT_TYPES = ['application/java-archive', 'application/java-vm', 'application/vnd.microsoft.portable-executable', 'application/x-dosexec', 'application/x-httpd-php', 'application/x-java-archive', 'application/x-mach-binary', 'application/x-msdownload', 'application/xhtml+xml', 'text/html'];
const SPACEFAST_STORAGE_BLOCKED_CONTENT_TYPE_FRAGMENTS = ['javascript', 'ecmascript'];
const SPACEFAST_STORAGE_BLOCKED_MAGIC_HEX = ['4d5a', '7f454c46', 'feedface', 'cefaedfe', 'feedfacf', 'cffaedfe', 'cafebabe'];
const SPACEFAST_STORAGE_BLOCKED_TEXT_PREFIXES = ['#!', '<!doctype html', '<html', '<script', '<?php'];

function _stattic_storage_declared_type_blocked(string $contentType): bool
{
    $declared = strtolower(trim(explode(';', $contentType, 2)[0]));
    if (in_array($declared, SPACEFAST_STORAGE_BLOCKED_CONTENT_TYPES, true)) {
        return true;
    }
    foreach (SPACEFAST_STORAGE_BLOCKED_CONTENT_TYPE_FRAGMENTS as $fragment) {
        if (str_contains($declared, $fragment)) {
            return true;
        }
    }
    return false;
}

function _stattic_storage_content_blocked(string $contentType, string $input): bool
{
    if (_stattic_storage_declared_type_blocked($contentType)) {
        return true;
    }
    $prefix = substr($input, 0, SPACEFAST_STORAGE_CONTENT_SNIFF_BYTES);
    foreach (SPACEFAST_STORAGE_BLOCKED_MAGIC_HEX as $hex) {
        $magic = hex2bin($hex);
        if (is_string($magic) && str_starts_with($prefix, $magic)) {
            return true;
        }
    }
    $text = strtolower(ltrim($prefix, " \t\n\r\0\x0B"));
    foreach (SPACEFAST_STORAGE_BLOCKED_TEXT_PREFIXES as $blocked) {
        if (str_starts_with($text, $blocked)) {
            return true;
        }
    }
    return false;
}

