<?php
declare(strict_types=1);

// Which engine a site runs, for the control plane's scheduled and SSH
// commands (apps/control-plane/src/runtime/engine-files.ts), which run no
// loader. Requests and WordPress ask the thin loader (runtime/custom-redirects.php)
// instead, which restates this rule because it cannot read any engine before
// choosing one. Keep the two in step; runtime/tests/engine-source.test.ts
// runs both. A command on a site with no release of its own has no copy of this
// file to trust, so the command bootstrap restates the chroot checks too.
//
// A site states its engine in its WP Cloud persistent data,
// `SPACEFAST_RUNTIME_ENGINE_SOURCE`: an environment and channel of the
// Spacefast chroot, `chroot/<environment>/<channel>` (say
// `chroot/production/latest`), or `site` for its own release. No value is a
// site from before boxes stated their engine, which runs its own release. The
// control plane sets it through the WP Cloud API; nothing else does. The provider stores it encrypted under a key derived
// from DB_PASSWORD, so a caller defines that before asking. One chroot carries
// every environment, each revision and link as a top-level entry:
//
//   /scripts/sf-engine-<revision>/                              engine (no bin/, no installer)
//   /scripts/sf-engine-<environment>-{latest,previous}          -> sf-engine-<revision>
//   /usr/local/bin/sf-bin-<revision>/stattic-runtime            its native binary
//
// The channel link is resolved once, and the binary is named by the revision it
// resolved to, never through its own channel link, so a deploy that moves both
// links between the two reads cannot pair one revision's engine with another's
// binary. A selected chroot that is missing or incomplete is `unavailable`,
// never the site's own release: the command stops, as a request answers 503.

const SPACEFAST_RUNTIME_CHROOT_SELECTION = '#^chroot/(staging|production)/(latest|previous)$#D';
const SPACEFAST_RUNTIME_CHROOT_REVISION = '/^sf-engine-([0-9a-f]{40})$/D';
const SPACEFAST_RUNTIME_CHROOT_REQUIRED_FILES = [
    'engine/loader/request.php',
    'engine/loader/wordpress.php',
    'engine/shared/engine-selection.php',
    'engine/init.php',
];
const SPACEFAST_RUNTIME_CHROOT_CONTAINMENT_CAPABILITY = 'runtime.security.tenant-containment.v1';

/**
 * Read the publisher's capability claim as data; never execute candidate PHP to
 * ask whether it is safe.
 */
function _spacefast_runtime_chroot_contained(string $engineReal): bool
{
    $inventory = json_decode((string) @file_get_contents($engineReal . '/engine/wordpress/components.json'), true);
    foreach (is_array($inventory['components'] ?? null) ? $inventory['components'] : [] as $component) {
        if (
            is_array($component)
            && ($component['id'] ?? null) === 'runtime-engine'
            && is_array($component['provides'] ?? null)
            && in_array(SPACEFAST_RUNTIME_CHROOT_CONTAINMENT_CAPABILITY, $component['provides'], true)
        ) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve the site's chroot selection as data without falling back to a site
 * release. Shared by command engine selection and the installer.
 *
 * @return array{kind:'chroot',release_root:string,native_binary:string,revision:string,reason:'chroot'}
 *        |array{kind:'fallback',reason:string,revision:?string}
 */
function _spacefast_runtime_resolve_chroot(bool $clearStatCache = false): array
{
    // The provider defines the class in its prepend. A PHP process without it
    // (a bare CLI, a local stack) has no persistent data to select with.
    if (!class_exists('Atomic_Persistent_Data')) {
        return ['kind' => 'fallback', 'reason' => 'no_persistent_data', 'revision' => null];
    }
    $selection = (new Atomic_Persistent_Data())->SPACEFAST_RUNTIME_ENGINE_SOURCE;
    if (preg_match(SPACEFAST_RUNTIME_CHROOT_SELECTION, is_string($selection) ? trim($selection) : '', $selected) !== 1) {
        return ['kind' => 'fallback', 'reason' => 'unselected', 'revision' => null];
    }
    $channel = $selected[1] . '-' . $selected[2];
    // Tests stand a chroot up under a temporary root; the box has it at `/`.
    $chrootRoot = defined('SPACEFAST_RUNTIME_CHROOT_ROOT') ? (string) SPACEFAST_RUNTIME_CHROOT_ROOT : '';
    if ($clearStatCache) {
        clearstatcache(true);
    }
    $scriptsReal = realpath($chrootRoot . '/scripts');
    $engineReal = realpath($chrootRoot . '/scripts/sf-engine-' . $channel);
    if (
        !is_string($scriptsReal)
        || !is_string($engineReal)
        || dirname($engineReal) !== $scriptsReal
        || preg_match(SPACEFAST_RUNTIME_CHROOT_REVISION, basename($engineReal), $match) !== 1
    ) {
        return ['kind' => 'fallback', 'reason' => 'chroot_missing', 'revision' => null];
    }
    $revision = $match[1];
    // An engine without this rule, or without the loader bodies the thin
    // loader hands requests to, predates that loader.
    foreach (SPACEFAST_RUNTIME_CHROOT_REQUIRED_FILES as $file) {
        if (!is_file($engineReal . '/' . $file)) {
            return ['kind' => 'fallback', 'reason' => 'chroot_engine_unsupported', 'revision' => $revision];
        }
    }
    if (!_spacefast_runtime_chroot_contained($engineReal)) {
        return ['kind' => 'fallback', 'reason' => 'chroot_containment_missing', 'revision' => $revision];
    }
    $binary = $chrootRoot . '/usr/local/bin/sf-bin-' . $revision . '/stattic-runtime';
    if (!is_file($binary) || !is_executable($binary)) {
        return ['kind' => 'fallback', 'reason' => 'chroot_binary_missing', 'revision' => $revision];
    }
    return [
        'kind' => 'chroot',
        'release_root' => $engineReal,
        'native_binary' => $binary,
        'revision' => $revision,
        'reason' => 'chroot',
    ];
}

/**
 * `reason` says why the site runs where it does, or why its stated engine
 * cannot run (`source` `unavailable`, with no root or binary), and
 * `chroot_revision` which revision the selected channel resolved to.
 *
 * @return array{release_root:?string,native_binary:?string,source:'chroot'|'site'|'unavailable',reason:string,chroot_revision:?string}
 */
function _spacefast_runtime_engine_source(string $siteReleaseRoot): array
{
    $site = static fn (string $reason): array => [
        'release_root' => $siteReleaseRoot,
        'native_binary' => $siteReleaseRoot . '/bin/stattic-runtime',
        'source' => 'site',
        'reason' => $reason,
        'chroot_revision' => null,
    ];
    // The provider defines the class in its prepend. A PHP process without it
    // (a bare CLI, a local stack) has no persistent data to select with.
    if (!class_exists('Atomic_Persistent_Data')) {
        return $site('no_persistent_data');
    }
    $selection = (new Atomic_Persistent_Data())->SPACEFAST_RUNTIME_ENGINE_SOURCE;
    $selection = is_string($selection) ? trim($selection) : $selection;
    if ($selection === false) {
        return $site('no_persistent_data');
    }
    if ($selection === 'site') {
        return $site('site');
    }
    if (!is_string($selection) || $selection === '') {
        return $site('unselected');
    }
    if (preg_match(SPACEFAST_RUNTIME_CHROOT_SELECTION, $selection) !== 1) {
        return [
            'release_root' => null,
            'native_binary' => null,
            'source' => 'unavailable',
            'reason' => 'selection_invalid',
            'chroot_revision' => null,
        ];
    }
    $resolved = _spacefast_runtime_resolve_chroot(false);
    return [
        'release_root' => $resolved['kind'] === 'chroot' ? $resolved['release_root'] : null,
        'native_binary' => $resolved['kind'] === 'chroot' ? $resolved['native_binary'] : null,
        'source' => $resolved['kind'] === 'chroot' ? 'chroot' : 'unavailable',
        'reason' => $resolved['reason'],
        'chroot_revision' => $resolved['revision'],
    ];
}
