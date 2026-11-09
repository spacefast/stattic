<?php
declare(strict_types=1);

// Which engine a site runs, for the control plane's scheduled and SSH
// commands (apps/control-plane/src/runtime/engine-files.ts), which run no
// loader. Requests and WordPress ask the thin loader (runtime/custom-redirects.php)
// instead, which restates this rule because it cannot read any engine before
// choosing one. Keep the two in step; runtime/tests/engine-source.test.ts
// runs both.
//
// A site runs its own release unless its WP Cloud persistent data names an
// environment and channel of the Spacefast chroot in
// `SPACEFAST_RUNTIME_ENGINE_SOURCE`, `chroot/<environment>/<channel>` (say
// `chroot/production/latest`). The control plane sets it through the WP Cloud
// API; nothing else does. The provider stores it encrypted under a key derived
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
// binary. A chroot that is missing or incomplete answers with the site's own
// release: a site switched back to the generic chroot keeps serving.

const SPACEFAST_RUNTIME_CHROOT_SELECTION = '#^chroot/(staging|production)/(latest|previous)$#D';

/**
 * `reason` says why the site runs where it does, and `chroot_revision` which
 * revision the selected channel resolved to, so health can explain a site
 * that selects the chroot and still runs its own release.
 *
 * @return array{release_root:string,native_binary:string,source:'chroot'|'site',reason:string,chroot_revision:?string}
 */
function _spacefast_runtime_engine_source(string $siteReleaseRoot): array
{
    $site = static fn (string $reason, ?string $chrootRevision = null): array => [
        'release_root' => $siteReleaseRoot,
        'native_binary' => $siteReleaseRoot . '/bin/stattic-runtime',
        'source' => 'site',
        'reason' => $reason,
        'chroot_revision' => $chrootRevision,
    ];
    // The provider defines the class in its prepend. A PHP process without it
    // (a bare CLI, a local stack) has no persistent data to select with.
    if (!class_exists('Atomic_Persistent_Data')) {
        return $site('no_persistent_data');
    }
    $selection = (new Atomic_Persistent_Data())->SPACEFAST_RUNTIME_ENGINE_SOURCE;
    if (preg_match(SPACEFAST_RUNTIME_CHROOT_SELECTION, is_string($selection) ? trim($selection) : '', $selected) !== 1) {
        return $site('unselected');
    }
    $channel = $selected[1] . '-' . $selected[2];
    // Tests stand a chroot up under a temporary root; the box has it at `/`.
    $chrootRoot = defined('SPACEFAST_RUNTIME_CHROOT_ROOT') ? (string) SPACEFAST_RUNTIME_CHROOT_ROOT : '';
    $scriptsReal = realpath($chrootRoot . '/scripts');
    $engineReal = realpath($chrootRoot . '/scripts/sf-engine-' . $channel);
    if (
        !is_string($scriptsReal)
        || !is_string($engineReal)
        || dirname($engineReal) !== $scriptsReal
        || preg_match('/^sf-engine-([0-9a-f]{40})$/D', basename($engineReal), $match) !== 1
    ) {
        return $site('chroot_missing');
    }
    // An engine without this rule, or without the loader bodies the thin
    // loader hands requests to, predates that loader: the site's own release
    // serves instead, until the chroot carries an engine with both.
    if (
        !is_file($engineReal . '/engine/loader/request.php')
        || !is_file($engineReal . '/engine/loader/wordpress.php')
        || !is_file($engineReal . '/engine/shared/engine-selection.php')
        || !is_file($engineReal . '/engine/init.php')
    ) {
        return $site('chroot_engine_unsupported', $match[1]);
    }
    // Read data from the resolved release, never execute candidate PHP to ask
    // whether it is safe. The artifact publisher owns this capability claim.
    $inventory = json_decode((string) @file_get_contents($engineReal . '/engine/wordpress/components.json'), true);
    $contained = false;
    foreach (is_array($inventory['components'] ?? null) ? $inventory['components'] : [] as $component) {
        if (
            is_array($component)
            && ($component['id'] ?? null) === 'runtime-engine'
            && is_array($component['provides'] ?? null)
            && in_array('runtime.security.tenant-containment.v1', $component['provides'], true)
        ) {
            $contained = true;
            break;
        }
    }
    if (!$contained) {
        return $site('chroot_containment_missing', $match[1]);
    }
    $binary = $chrootRoot . '/usr/local/bin/sf-bin-' . $match[1] . '/stattic-runtime';
    if (!is_file($binary) || !is_executable($binary)) {
        return $site('chroot_binary_missing', $match[1]);
    }
    return [
        'release_root' => $engineReal,
        'native_binary' => $binary,
        'source' => 'chroot',
        'reason' => 'chroot',
        'chroot_revision' => $match[1],
    ];
}
