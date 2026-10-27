<?php
declare(strict_types=1);

// Which engine a site runs. The loaders resolve the site's own release first,
// exactly as before, then ask here. One answer for every loader: the request
// prepend (custom-redirects.php and its aliases) and the WordPress content
// loader read this file from that site release, so the rule ships with the
// engine instead of being restated in each copy.
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
 * @return array{release_root:string,native_binary:string,source:'chroot'|'site'}
 */
function _spacefast_runtime_engine_source(string $siteReleaseRoot): array
{
    $site = [
        'release_root' => $siteReleaseRoot,
        'native_binary' => $siteReleaseRoot . '/bin/stattic-runtime',
        'source' => 'site',
    ];
    // The provider defines the class in its prepend. A PHP process without it
    // (a bare CLI, a local stack) has no persistent data to select with.
    if (!class_exists('Atomic_Persistent_Data')) {
        return $site;
    }
    $selection = (new Atomic_Persistent_Data())->SPACEFAST_RUNTIME_ENGINE_SOURCE;
    if (preg_match(SPACEFAST_RUNTIME_CHROOT_SELECTION, is_string($selection) ? trim($selection) : '', $selected) !== 1) {
        return $site;
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
        // An engine without this rule selects some other way, or not at all.
        // Running it would put its loaders on the site at its next install,
        // and the site would stop reading its channel: the site's own release
        // serves instead, until the chroot carries an engine with this rule.
        || !is_file($engineReal . '/engine/shared/engine-selection.php')
        || !is_file($engineReal . '/engine/init.php')
    ) {
        return $site;
    }
    $binary = $chrootRoot . '/usr/local/bin/sf-bin-' . $match[1] . '/stattic-runtime';
    if (!is_file($binary) || !is_executable($binary)) {
        return $site;
    }
    return ['release_root' => $engineReal, 'native_binary' => $binary, 'source' => 'chroot'];
}
