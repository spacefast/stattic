<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use TypedPress\Identity\Vendor\Symfony\Polyfill\Uuid as p;

if (extension_loaded('uuid')) {
    return;
}

if (\PHP_VERSION_ID >= 80000) {
    return require __DIR__.'/bootstrap80.php';
}

if (!defined('TP_IDENTITY_VENDOR_UUID_VARIANT_NCS')) {
    define('TP_IDENTITY_VENDOR_UUID_VARIANT_NCS', 0);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_VARIANT_DCE')) {
    define('TP_IDENTITY_VENDOR_UUID_VARIANT_DCE', 1);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_VARIANT_MICROSOFT')) {
    define('TP_IDENTITY_VENDOR_UUID_VARIANT_MICROSOFT', 2);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_VARIANT_OTHER')) {
    define('TP_IDENTITY_VENDOR_UUID_VARIANT_OTHER', 3);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_DEFAULT')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_DEFAULT', 0);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_TIME')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_TIME', 1);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_MD5')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_MD5', 3);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_DCE')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_DCE', 4); // Deprecated alias
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_NAME')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_NAME', 1); // Deprecated alias
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_RANDOM')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_RANDOM', 4);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_SHA1')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_SHA1', 5);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_NULL')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_NULL', -1);
}
if (!defined('TP_IDENTITY_VENDOR_UUID_TYPE_INVALID')) {
    define('TP_IDENTITY_VENDOR_UUID_TYPE_INVALID', -42);
}

if (!function_exists('typedpress_identity_vendor_uuid_create')) {
    function typedpress_identity_vendor_uuid_create($uuid_type = \TP_IDENTITY_VENDOR_UUID_TYPE_DEFAULT) { return p\Uuid::uuid_create($uuid_type); }
}
if (!function_exists('typedpress_identity_vendor_uuid_generate_md5')) {
    function typedpress_identity_vendor_uuid_generate_md5($uuid_ns, $name) { return p\Uuid::uuid_generate_md5($uuid_ns, $name); }
}
if (!function_exists('typedpress_identity_vendor_uuid_generate_sha1')) {
    function typedpress_identity_vendor_uuid_generate_sha1($uuid_ns, $name) { return p\Uuid::uuid_generate_sha1($uuid_ns, $name); }
}
if (!function_exists('typedpress_identity_vendor_uuid_is_valid')) {
    function typedpress_identity_vendor_uuid_is_valid($uuid) { return p\Uuid::uuid_is_valid($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_compare')) {
    function typedpress_identity_vendor_uuid_compare($uuid1, $uuid2) { return p\Uuid::uuid_compare($uuid1, $uuid2); }
}
if (!function_exists('typedpress_identity_vendor_uuid_is_null')) {
    function typedpress_identity_vendor_uuid_is_null($uuid) { return p\Uuid::uuid_is_null($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_type')) {
    function typedpress_identity_vendor_uuid_type($uuid) { return p\Uuid::uuid_type($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_variant')) {
    function typedpress_identity_vendor_uuid_variant($uuid) { return p\Uuid::uuid_variant($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_time')) {
    function typedpress_identity_vendor_uuid_time($uuid) { return p\Uuid::uuid_time($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_mac')) {
    function typedpress_identity_vendor_uuid_mac($uuid) { return p\Uuid::uuid_mac($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_parse')) {
    function typedpress_identity_vendor_uuid_parse($uuid) { return p\Uuid::uuid_parse($uuid); }
}
if (!function_exists('typedpress_identity_vendor_uuid_unparse')) {
    function typedpress_identity_vendor_uuid_unparse($uuid) { return p\Uuid::uuid_unparse($uuid); }
}
