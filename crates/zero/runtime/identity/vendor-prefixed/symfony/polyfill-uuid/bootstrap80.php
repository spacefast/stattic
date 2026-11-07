<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Zero\Identity\Vendor\Symfony\Polyfill\Uuid as p;

if (!defined('ZERO_IDENTITY_VENDOR_UUID_VARIANT_NCS')) {
    define('ZERO_IDENTITY_VENDOR_UUID_VARIANT_NCS', 0);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_VARIANT_DCE')) {
    define('ZERO_IDENTITY_VENDOR_UUID_VARIANT_DCE', 1);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_VARIANT_MICROSOFT')) {
    define('ZERO_IDENTITY_VENDOR_UUID_VARIANT_MICROSOFT', 2);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_VARIANT_OTHER')) {
    define('ZERO_IDENTITY_VENDOR_UUID_VARIANT_OTHER', 3);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_DEFAULT')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_DEFAULT', 0);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_TIME')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_TIME', 1);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_MD5')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_MD5', 3);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_DCE')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_DCE', 4); // Deprecated alias
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_NAME')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_NAME', 1); // Deprecated alias
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_RANDOM')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_RANDOM', 4);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_SHA1')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_SHA1', 5);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_NULL')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_NULL', -1);
}
if (!defined('ZERO_IDENTITY_VENDOR_UUID_TYPE_INVALID')) {
    define('ZERO_IDENTITY_VENDOR_UUID_TYPE_INVALID', -42);
}

if (!function_exists('zero_identity_vendor_uuid_create')) {
    function zero_identity_vendor_uuid_create(?int $uuid_type = \ZERO_IDENTITY_VENDOR_UUID_TYPE_DEFAULT): string { return p\Uuid::uuid_create((int) $uuid_type); }
}
if (!function_exists('zero_identity_vendor_uuid_generate_md5')) {
    function zero_identity_vendor_uuid_generate_md5(?string $uuid_ns, ?string $name): string { return p\Uuid::uuid_generate_md5((string) $uuid_ns, (string) $name); }
}
if (!function_exists('zero_identity_vendor_uuid_generate_sha1')) {
    function zero_identity_vendor_uuid_generate_sha1(?string $uuid_ns, ?string $name): string { return p\Uuid::uuid_generate_sha1((string) $uuid_ns, (string) $name); }
}
if (!function_exists('zero_identity_vendor_uuid_is_valid')) {
    function zero_identity_vendor_uuid_is_valid(?string $uuid): bool { return p\Uuid::uuid_is_valid((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_compare')) {
    function zero_identity_vendor_uuid_compare(?string $uuid1, ?string $uuid2): int { return p\Uuid::uuid_compare((string) $uuid1, (string) $uuid2); }
}
if (!function_exists('zero_identity_vendor_uuid_is_null')) {
    function zero_identity_vendor_uuid_is_null(?string $uuid): bool { return p\Uuid::uuid_is_null((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_type')) {
    function zero_identity_vendor_uuid_type(?string $uuid): int { return p\Uuid::uuid_type((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_variant')) {
    function zero_identity_vendor_uuid_variant(?string $uuid): int { return p\Uuid::uuid_variant((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_time')) {
    function zero_identity_vendor_uuid_time(?string $uuid): int { return p\Uuid::uuid_time((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_mac')) {
    function zero_identity_vendor_uuid_mac(?string $uuid): string { return p\Uuid::uuid_mac((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_parse')) {
    function zero_identity_vendor_uuid_parse(?string $uuid): string { return p\Uuid::uuid_parse((string) $uuid); }
}
if (!function_exists('zero_identity_vendor_uuid_unparse')) {
    function zero_identity_vendor_uuid_unparse(?string $uuid): string { return p\Uuid::uuid_unparse((string) $uuid); }
}
