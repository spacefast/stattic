<?php
declare(strict_types=1);

// Which engine a site runs. The loaders resolve the site's own release first,
// exactly as before, then ask here. One answer for every loader: the request
// prepend (custom-redirects.php and its aliases) and the WordPress content
// loader read this file from that site release, so the rule ships with the
// engine instead of being restated in each copy.
//
// A site runs its own release unless `.stattic/engine-source` names an
// environment and channel of the Spacefast chroot, `chroot/<environment>/<channel>`
// (say `chroot/production/latest`). The control plane writes that file; nothing
// else does. One chroot carries every environment, each revision and link as a
// top-level entry:
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

const SPACEFAST_RUNTIME_ENGINE_SOURCE_FILE = 'engine-source';
const SPACEFAST_RUNTIME_CHROOT_SELECTION = '#^chroot/(staging|production)/(latest|previous)$#D';

/**
 * @return array{release_root:string,native_binary:string,source:'chroot'|'site'}
 */
function _spacefast_runtime_engine_source(string $installRoot, string $siteReleaseRoot): array
{
    $site = [
        'release_root' => $siteReleaseRoot,
        'native_binary' => $siteReleaseRoot . '/bin/stattic-runtime',
        'source' => 'site',
    ];
    $selection = $installRoot . '/' . SPACEFAST_RUNTIME_ENGINE_SOURCE_FILE;
    if (!is_file($selection) || is_link($selection)) {
        return $site;
    }
    $raw = file_get_contents($selection, false, null, 0, 64);
    if (preg_match(SPACEFAST_RUNTIME_CHROOT_SELECTION, is_string($raw) ? trim($raw) : '', $selected) !== 1) {
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
        // An engine older than this file derives the install root from its
        // own location, which in the chroot is not the site's. It cannot run
        // from there.
        || !is_file($engineReal . '/engine/shared/engine-source.php')
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
