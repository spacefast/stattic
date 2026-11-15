<?php
declare(strict_types=1);

// The injection plan, as the engine reads it: whether each thing Spacefast
// adds is on for the version this request serves. Every engine consumer (the
// HTML filter, the static lane's SDK tag and share image, `/__spacefast/sdk.js`
// and `/__spacefast/t.js`) asks `_stattic_injection_on`; none keeps its own
// "is injection on" check.
//
// The control plane resolves the plan (`resolveInjectionPlan` in
// packages/common/src/injection/plan.ts) and sends it in the route's SDK
// section as `sdk.plan` (`routeInjectionPlanSchema` in
// packages/common/src/contracts/injection.ts). The engine looks the served
// version up, `versions[id] ?? default`, and never resolves precedence itself.
// A section without a plan comes from a control plane that sends none: the
// legacy mapper below reads the same answers from the two switches it does
// send.
//
// Raw author `inject` snippets are never removed here: no switch turns them
// off. Essential items (security and identity headers, Spacefast's own pages,
// the output of compiling the author's source) are always on.

const STATTIC_INJECTION_ITEMS = [
    'access-client' => 'essential',
    'platform-pages' => 'essential',
    'security-headers' => 'essential',
    'identity-headers' => 'essential',
    'wp-shell' => 'essential',
    'islands-boot' => 'essential',
    'theme-css' => 'essential',
    'pages-meta' => 'essential',
    'users-api' => 'app',
    'zero-realtime' => 'app',
    'sdk-tag' => 'platform',
    'meta' => 'platform',
    'csp-origins' => 'platform',
    'remix' => 'platform',
    'referral-chip' => 'platform',
    'space-bar' => 'platform',
    'review' => 'platform',
    'tag-preview' => 'platform',
    'comments' => 'customer',
    'tracker' => 'customer',
    'tags-dynamic' => 'customer',
    'tags-baked' => 'customer',
    'snippets' => 'author',
];

/**
 * Every item's on/off for `$versionId` on the surface this request arrived on
 * (`version` on an immutable version host, else `live`), and the plan's mode.
 *
 * With `sdk.plan` (v1), the entry is `versions[$versionId] ?? default`. An
 * item the entry leaves out is off, except an essential one, so an item the
 * control plane does not know yet is never added. A plan the engine cannot
 * read (another `v`, no `default`) is ignored like a missing one: the control
 * plane still sends the legacy switch beside it.
 *
 * @return array{mode: string, items: array<string, bool>}
 */
function _stattic_injection_plan(array $serving, ?string $versionId): array
{
    $sdk = is_array($serving['sdk'] ?? null) ? $serving['sdk'] : null;
    $surface = empty($serving['immutable']) ? 'live' : 'version';
    $plan = is_array($sdk) && is_array($sdk['plan'] ?? null) ? $sdk['plan'] : null;
    $entry = null;
    if ($plan !== null && ($plan['v'] ?? null) === 1 && _stattic_injection_plan_entry_valid($plan['default'] ?? null)) {
        $versions = is_array($plan['versions'] ?? null) ? $plan['versions'] : [];
        $own = $versionId !== null && array_key_exists($versionId, $versions) ? $versions[$versionId] : null;
        // A version the plan names decides for itself. An entry the engine
        // cannot read means off, never the default: falling back could turn
        // on what that version's own file turned off.
        $entry = $own === null
            ? $plan['default']
            : (_stattic_injection_plan_entry_valid($own) ? $own : ['mode' => 'off', 'items' => []]);
    }
    if ($entry === null) {
        return _stattic_injection_legacy_plan($serving, $sdk);
    }
    $items = [];
    foreach (STATTIC_INJECTION_ITEMS as $id => $class) {
        $value = $entry['items'][$id] ?? false;
        $items[$id] = $class === 'essential'
            || $value === true
            || (is_array($value) && in_array($surface, $value, true));
    }
    $mode = in_array($entry['mode'] ?? null, ['on', 'off', 'locked'], true) ? $entry['mode'] : 'off';
    return ['mode' => $mode, 'items' => $items];
}

function _stattic_injection_plan_entry_valid(mixed $entry): bool
{
    return is_array($entry) && is_array($entry['items'] ?? null);
}

/**
 * The plan a control plane without `sdk.plan` means. Off is the Space's
 * `superpowers.disable_all_injection` (the section's `injection: false`) or
 * the served version's own `sf.jsonc` opt-out (its root's `platform: false`).
 * On, every item is on and the section decides what there is to add, as it
 * always has: no section is no SDK tag, a null tracker is no tracker, and the
 * Comments flags say which surface boots Comments. App items follow their own
 * settings either way.
 */
function _stattic_injection_legacy_plan(array $serving, ?array $sdk): array
{
    $off = ($serving['inject']['platform'] ?? null) === false
        || (is_array($sdk) && ($sdk['injection'] ?? null) === false);
    $items = [];
    foreach (STATTIC_INJECTION_ITEMS as $id => $class) {
        $items[$id] = match (true) {
            $class === 'essential', $class === 'author' => true,
            $id === 'users-api' => ($serving['users']['enabled'] ?? null) === true,
            $id === 'zero-realtime' => ($serving['zero_generation'] ?? null) === 'v2',
            $off => false,
            $id === 'sdk-tag' => is_array($sdk),
            default => true,
        };
    }
    return ['mode' => $off ? 'off' : 'on', 'items' => $items];
}

/**
 * Whether `$item` is on for this request. serve.php resolves the plan once,
 * for the version it serves, into `$serving['injection_plan']`; a serving
 * state built anywhere else resolves it here for its own `version_id`.
 */
function _stattic_injection_on(array $serving, string $item): bool
{
    $plan = is_array($serving['injection_plan'] ?? null)
        ? $serving['injection_plan']
        : _stattic_injection_plan(
            $serving,
            is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null
        );
    return ($plan['items'][$item] ?? false) === true;
}

/**
 * `sdk.plan` as the route sends it, reduced to what the reader uses, or null
 * when it is not a v1 plan. management.php and generate.php keep it through
 * the SDK section they rebuild; anything else in it is dropped, so a malformed
 * plan never reaches the overlay every request includes.
 */
function _stattic_injection_plan_section(mixed $plan): ?array
{
    if (!is_array($plan) || ($plan['v'] ?? null) !== 1) {
        return null;
    }
    $entry = static function (mixed $entry): ?array {
        if (!_stattic_injection_plan_entry_valid($entry)) {
            return null;
        }
        $items = [];
        foreach ($entry['items'] as $id => $value) {
            if (!is_string($id) || strlen($id) > 64) {
                continue;
            }
            if (is_bool($value)) {
                $items[$id] = $value;
            } elseif (is_array($value)) {
                // Only the surface names; anything else (a nested list) is dropped.
                $surfaces = array_filter($value, 'is_string');
                $items[$id] = array_values(array_intersect(['live', 'version'], $surfaces));
            }
        }
        $mode = in_array($entry['mode'] ?? null, ['on', 'off', 'locked'], true) ? $entry['mode'] : 'off';
        return ['mode' => $mode, 'items' => $items];
    };
    $default = $entry($plan['default'] ?? null);
    if ($default === null) {
        return null;
    }
    // An unreadable version entry is kept as off, not dropped: the engine
    // reads only this rebuilt section, and a dropped entry would hand that
    // version the default (_stattic_injection_plan).
    $versions = [];
    foreach (is_array($plan['versions'] ?? null) ? $plan['versions'] : [] as $versionId => $value) {
        if (is_string($versionId) && $value !== null) {
            $versions[$versionId] = $entry($value) ?? ['mode' => 'off', 'items' => []];
        }
    }
    return ['v' => 1, 'default' => $default, 'versions' => $versions];
}
