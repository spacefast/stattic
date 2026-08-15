<?php
declare(strict_types=1);

// Pure-function unit tests for runtime policy modules, run directly with
// `php runtime/tests/unit.php` (no server, no network — literal IPs only).

require_once __DIR__ . '/../engine/shared/context.php'; // engine identity + config_value helpers
require_once __DIR__ . '/../engine/shared/egress.php';
require_once __DIR__ . '/../engine/shared/safety.php';
require_once __DIR__ . '/../engine/runtime/headers.php'; // also loads runtime/rules.php
require_once __DIR__ . '/../engine/runtime/proxy.php';
require_once __DIR__ . '/../engine/admin/upload-policy.php';
require_once __DIR__ . '/../engine/admin/upload.php';
require_once __DIR__ . '/../engine/runtime/access-rules.php'; // unified Rule matchers (pure helpers)
require_once __DIR__ . '/../engine/shared/artifacts.php'; // route pattern + artifact path guards
require_once __DIR__ . '/../engine/shared/storage.php';
require_once __DIR__ . '/../engine/shared/purge.php';
require_once __DIR__ . '/../engine/admin/generate.php'; // hostname-intent scope derivation (pure with an injected intent)
require_once __DIR__ . '/../engine/admin/jobs.php'; // job runner policy (§22) — pure functions only here
require_once __DIR__ . '/../engine/runtime/functions-artifacts.php'; // signed build-artifact reads
require_once __DIR__ . '/../engine/runtime/functions-dispatch.php'; // origin -> host dispatch contract
require_once __DIR__ . '/../engine/shared/runtime-log.php'; // the one runtime log writer
require_once __DIR__ . '/../engine/shared/db-broker.php'; // MySQL broker value encoding (pure helpers only here)

$assertions = 0;
$failures = [];

function check(bool $condition, string $label): void
{
    global $assertions, $failures;
    $assertions += 1;
    if (!$condition) {
        $failures[] = $label;
    }
}

// --- Proxy egress policy: hostnames -------------------------------------------------

foreach ([
    'localhost',
    'sub.localhost',
    'view.fast',
    'site.view.fast',
    'wpc-manage-site.view.fast',
    'atomicsites.net',
    'client-ssh.atomicsites.net',
    '',
] as $host) {
    check(!_stattic_egress_host_allowed($host), "egress denies host: {$host}");
}
foreach (['example.com', 'api.github.com', 'my-static.example.net'] as $host) {
    check(_stattic_egress_host_allowed($host), "egress allows host: {$host}");
}

// --- Proxy egress policy: IPv4 ------------------------------------------------------

foreach ([
    '0.0.0.0',           // "this network"
    '10.1.2.3',          // RFC1918
    '100.64.0.1',        // CGNAT
    '127.0.0.1',         // loopback
    '169.254.169.254',   // link-local / cloud metadata
    '172.16.0.1',        // RFC1918
    '192.0.0.1',         // IETF protocol assignments
    '192.0.2.10',        // TEST-NET-1
    '192.168.1.1',       // RFC1918
    '198.18.0.1',        // benchmarking
    '198.51.100.7',      // TEST-NET-2
    '203.0.113.9',       // TEST-NET-3
    '224.0.0.1',         // multicast
    '255.255.255.255',   // broadcast
] as $ip) {
    check(!_stattic_egress_ip_public($ip), "egress denies IPv4: {$ip}");
}
foreach (['8.8.8.8', '93.184.216.34', '1.1.1.1'] as $ip) {
    check(_stattic_egress_ip_public($ip), "egress allows IPv4: {$ip}");
}

// --- Proxy egress policy: IPv6 ------------------------------------------------------

foreach ([
    '::',                 // unspecified
    '::1',                // loopback
    '::10.0.0.1',         // deprecated IPv4-compatible (::/96)
    '::ffff:10.0.0.1',    // IPv4-mapped private
    '::ffff:127.0.0.1',   // IPv4-mapped loopback
    '::ffff:169.254.169.254', // IPv4-mapped metadata
    '64:ff9b::808:808',   // NAT64
    '100::1',             // discard-only
    '2001:db8::1',        // documentation
    'fd00:ec2::254',      // ULA / cloud metadata
    'fc00::1',            // ULA
    'fe80::1',            // link-local
    'ff02::1',            // multicast
] as $ip) {
    check(!_stattic_egress_ip_public($ip), "egress denies IPv6: {$ip}");
}
foreach (['2606:4700:4700::1111', '2a00:1450:4001:80b::200e', '::ffff:8.8.8.8'] as $ip) {
    check(_stattic_egress_ip_public($ip), "egress allows IPv6: {$ip}");
}

// --- Proxy egress resolution pins literal IPs without DNS ---------------------------

check(_stattic_egress_resolve_public_ips('127.0.0.1') === null, 'resolve denies loopback literal');
check(_stattic_egress_resolve_public_ips('[::1]') === null, 'resolve denies bracketed loopback');
check(_stattic_egress_resolve_public_ips('169.254.169.254') === null, 'resolve denies metadata literal');
check(_stattic_egress_resolve_public_ips('8.8.8.8') === ['8.8.8.8'], 'resolve pins public literal');
check(
    _stattic_egress_curl_resolve_entries('Example.COM', 443, ['8.47.69.0', '2606:4700:10::ac42:93f3']) === ['example.com:443:8.47.69.0'],
    'egress curl pinning prefers public IPv4 when DNS also returns IPv6',
);
check(
    _stattic_egress_curl_resolve_entries('ipv6.example', 443, ['2606:4700:10::ac42:93f3']) === ['ipv6.example:443:[2606:4700:10::ac42:93f3]'],
    'egress curl pinning keeps IPv6-only upstreams reachable',
);
check(
    _stattic_egress_curl_resolve_entries('[2606:4700:4700::1111]', 443, ['2606:4700:4700::1111']) === [],
    'egress curl pinning leaves IPv6 literals as already-pinned URLs',
);

// --- Runtime source URL upload policy -----------------------------------------------

$sourceUrl = _stattic_runtime_assert_fetch_url('https://1.1.1.1/assets/index.html');
check($sourceUrl['url'] === 'https://1.1.1.1/assets/index.html', 'source URL policy preserves public HTTPS URL');
check($sourceUrl['resolve'] === ['1.1.1.1:443:1.1.1.1'], 'source URL policy pins the validated address');
$ipv6SourceUrl = _stattic_runtime_assert_fetch_url('https://[2606:4700:4700::1111]/assets/index.html');
check($ipv6SourceUrl['resolve'] === [], 'source URL policy accepts public IPv6 literals without DNS rebinding risk');

// --- Header operations: denylist, merge, placeholder expansion ----------------------

$applied = [];
_stattic_apply_header_operations($applied, [
    ['kind' => 'set', 'name' => 'X-One', 'value' => 'a'],
    ['kind' => 'set', 'name' => 'X-One', 'value' => 'b'],            // repeated set joins
    ['kind' => 'set', 'name' => 'X-Gone', 'value' => 'x'],
    ['kind' => 'remove', 'name' => 'X-Gone'],                         // remove deletes prior set
    ['kind' => 'set', 'name' => 'Set-Cookie', 'value' => 'sid=1'],    // platform-managed: skipped
    ['kind' => 'set', 'name' => 'Location', 'value' => '/elsewhere'],
    ['kind' => 'set', 'name' => 'Content-Length', 'value' => '0'],
    ['kind' => 'set', 'name' => 'Strict-Transport-Security', 'value' => 'max-age=0'],
    ['kind' => 'set', 'name' => 'CDN-Cache-Control', 'value' => 'no-store'],
    ['kind' => 'set', 'name' => 'X-Spacefast-Version', 'value' => 'ver_forged'], // reserved namespace
    ['kind' => 'set', 'name' => 'X-Stattic-Anything', 'value' => 'forged'],
    ['kind' => 'set', 'name' => 'X-File', 'value' => 'file=:file'],   // placeholder expansion
], ['file' => 'app.js']);
$headers = [];
foreach ($applied as $entry) {
    $headers[$entry['name']] = $entry['value'];
}
check($headers === ['X-One' => 'a,b', 'X-File' => 'file=app.js'], 'header ops: ' . json_encode($headers));

$deniedNames = array_keys(SPACEFAST_PLATFORM_MANAGED_HEADERS);
foreach (SPACEFAST_PLATFORM_MANAGED_HEADER_PREFIXES as $prefix) {
    $deniedNames[] = $prefix . 'anything';
    $deniedNames[] = strtoupper($prefix) . 'Mixed-Case';
}
foreach ($deniedNames as $denied) {
    $applied = [];
    _stattic_apply_header_operations($applied, [['kind' => 'set', 'name' => $denied, 'value' => 'x']], []);
    check($applied === [], "denylist blocks header: {$denied}");
}

// A benign custom header that merely mentions the product name is NOT reserved.
$applied = [];
_stattic_apply_header_operations($applied, [['kind' => 'set', 'name' => 'X-Spacefastish', 'value' => 'ok']], []);
check(count($applied) === 1, 'denylist prefix does not over-match X-Spacefastish');

// _headers leads across grammars: a sf.jsonc rule that sets a header name a file
// rule already set is skipped, not appended. `X-Frame-Options: DENY,SAMEORIGIN`
// is a header browsers discard, so appending would hand an additive config rule
// the power to remove the file's clickjacking protection. Repeats within one
// grammar still accumulate.
$applied = [];
_stattic_apply_header_operations($applied, [['kind' => 'set', 'name' => 'X-Frame-Options', 'value' => 'DENY']], [], 'file');
_stattic_apply_header_operations($applied, [
    ['kind' => 'set', 'name' => 'X-Frame-Options', 'value' => 'SAMEORIGIN'],
    ['kind' => 'set', 'name' => 'Link', 'value' => '</a.css>; rel=preload'],
    ['kind' => 'set', 'name' => 'Link', 'value' => '</b.css>; rel=preload'],
], [], 'config');
$headers = [];
foreach ($applied as $entry) {
    $headers[$entry['name']] = $entry['value'];
}
check(
    $headers === ['X-Frame-Options' => 'DENY', 'Link' => '</a.css>; rel=preload,</b.css>; rel=preload'],
    'config header rules never overwrite a _headers value: ' . json_encode($headers)
);

check(_stattic_expand_template('/to/:splat?q=:value', ['splat' => 'a/b', 'value' => '1']) === '/to/a/b?q=1', 'template expansion');

// --- Ordered-rule matching: the compiled regex carries the PCRE delimiter -----------

// `#` is a legal path character — `_redirects` only treats it as a comment when
// it is the first character of a line — so `/a#b/:id` is an accepted source and
// the compilers emit it unescaped into `regex`. Interpolated raw into a `#...#`
// pattern it ended the delimiter early: PCRE warned "Unknown modifier", the rule
// silently never matched, and every request logged into the tenant-visible lane.
$hashRule = ['regex' => '^/a#b/(?P<id>[^/]+)/?$'];
$pathMatches = [];
$hostMatches = [];
check(
    _stattic_ordered_rule_request_matches($hashRule, false, '/a#b/42', 'example.com', $pathMatches, $hostMatches)
        && ($pathMatches['id'] ?? null) === '42',
    'rule regex containing the PCRE delimiter matches and captures'
);
check(
    !_stattic_ordered_rule_request_matches($hashRule, false, '/ab/42', 'example.com'),
    'rule regex containing the PCRE delimiter still refuses a non-matching path'
);
// The host matcher is the same seam with the `i` modifier, which has to survive
// the delimiter escaping.
check(
    _stattic_ordered_rule_request_matches(
        ['regex' => '^/x$', 'hostRegex' => '^b#c\.example\.com$'],
        false,
        '/x',
        'B#C.example.com'
    ),
    'host regex containing the PCRE delimiter matches case-insensitively'
);

// --- Upload path policy --------------------------------------------------------------

// Deliberately no cases here. This file's copy of the expected verdicts was one of the
// two hand-maintained lists that let the policy drift; upload-policy.php is now generated
// from packages/common/src/utils/publish-policy.ts, and publish-policy.fixtures.json is
// asserted against this file AND against the TypeScript policy by
// apps/control-plane/src/runtime/php-policy-parity.test.ts. New cases go in the fixture.

// --- PHP-like safety policy ----------------------------------------------------------

foreach (['x.php', 'x.PHP', 'x.php5', 'x.phtml', 'x.phar', 'a/b.php8'] as $path) {
    check(_stattic_path_is_php_like($path), "php-like: {$path}");
}
foreach (['x.html', 'x.php.txt', 'php', '.php'] as $path) {
    check(!_stattic_path_is_php_like($path), "not php-like: {$path}");
}

// --- Admin route query decoding ------------------------------------------------------

$oldQueryString = $_SERVER['QUERY_STRING'] ?? null;
$oldRequestUri = $_SERVER['REQUEST_URI'] ?? null;
$oldGet = $_GET;
$oldRuntimeRequestUri = $GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI'] ?? null;
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REQUEST_URI'] = '/__spacefast/api.php?route=%2Fspaces%2Fspc%2Fversions';
$_GET = [];
check(
    _stattic_runtime_management_api_route_path('/__spacefast/api.php') === '/spaces/spc/versions',
    'management route falls back to REQUEST_URI query'
);
$_SERVER['REQUEST_URI'] = '/index.php';
$GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI'] = '/__spacefast/api.php?route=%2Fspaces%2Fshim%2Fversions';
check(
    _stattic_runtime_management_api_route_path('/__spacefast/api.php') === '/spaces/shim/versions',
    'management route prefers shim-preserved REQUEST_URI query'
);
$_SERVER['REQUEST_URI'] = '/__spacefast/upload.php?op=file&upload_id=upl_provider_preflight&path=index.html';
unset($GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI']);
check(
    _stattic_runtime_upload_api_route_path('/__spacefast/upload.php') === '/upl_provider_preflight/files/index.html',
    'compact upload route falls back to REQUEST_URI query when the provider leaves GET empty'
);
if ($oldQueryString === null) {
    unset($_SERVER['QUERY_STRING']);
} else {
    $_SERVER['QUERY_STRING'] = $oldQueryString;
}
if ($oldRequestUri === null) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $oldRequestUri;
}
$_GET = $oldGet;
if ($oldRuntimeRequestUri === null) {
    unset($GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI']);
} else {
    $GLOBALS['SPACEFAST_RUNTIME_REQUEST_URI'] = $oldRuntimeRequestUri;
}

// --- Route pattern / artifact path guards ------------------------------------------

check(
    _stattic_runtime_route_pattern_valid('/generated/src/pages/[...slug].astro'),
    'route pattern accepts literal segments containing consecutive dots'
);
check(
    !_stattic_runtime_route_pattern_valid('/generated/src/pages/../secrets'),
    'route pattern rejects an exact parent traversal segment'
);
check(
    _stattic_runtime_relative_artifact_path_valid('generated/src/pages/[...slug].astro'),
    'artifact path accepts literal segments containing consecutive dots'
);
check(
    !_stattic_runtime_relative_artifact_path_valid('generated/src/pages/../secrets'),
    'artifact path rejects an exact parent traversal segment'
);

// --- Canonical Grant network constraints ---------------------------------------------

// Contracts §16: IP/CIDR grants are gone. The only client-IP-ish headers that
// reach PHP here (X-Real-IP, CF-Connecting-IP, X-Forwarded-Host) are
// attacker-controlled, so there is no address to match against and no CIDR
// helper left to test. What survives is country (server-set fastcgi_param) and
// user-agent selectors, plus the fail-closed rule for a stored ipCidrs grant.
$networkGrant = [
    'id' => 'grt_network',
    'generation' => 1,
    'audience' => ['kind' => 'public'],
    'resources' => ['include' => ['/**'], 'exclude' => []],
    'capabilities' => ['page.view'],
    'constraints' => ['network' => [
        'countries' => ['NL'],
        'excludedCountries' => ['US'],
        'excludedUserAgentSubstrings' => ['GPTBot'],
    ]],
    'target' => ['kind' => 'live'],
    'source' => ['kind' => 'managed', 'reference' => 'network-test'],
];
$rawNetworkProjection = [
    'generation' => 1,
    'sessionVersion' => 0,
    'fence' => 'none',
    'acquireUrl' => 'https://access.spacefast.test/acquire/network-test',
    'spaceClaimed' => true,
    'grants' => [$networkGrant],
];
$networkProjection = _stattic_compile_authorization_projection($rawNetworkProjection);
check(
    is_array($networkProjection),
    'network Grant fixture compiles into a runtime projection'
);
$networkProjection = is_array($networkProjection) ? $networkProjection : [];
$upgradedNetworkProjection = _stattic_authorization_projection_for_runtime($rawNetworkProjection);
check(
    is_array($upgradedNetworkProjection)
        && _stattic_authorization_projection_compiled($upgradedNetworkProjection),
    'raw stored authorization is upgraded at the runtime boundary'
);
$unclaimedPasswordGrant = [
    ...$networkGrant,
    'id' => 'grt_unclaimed_config_password',
    'audience' => ['kind' => 'password', 'credentialId' => 'pwd_unclaimed'],
    'constraints' => [],
    'source' => ['kind' => 'config', 'reference' => 'sf.jsonc'],
];
$unclaimedProjection = _stattic_compile_authorization_projection([
    ...$rawNetworkProjection,
    'accessPage' => [
        'displayName' => null,
        'accountUrl' => null,
        'connections' => [],
        'exchange' => [
            'passwordUrl' => 'https://access.spacefast.test/acquire/password',
            'requestUrl' => 'https://access.spacefast.test/acquire/request',
            'credential' => str_repeat('x', 32),
        ],
    ],
    'spaceClaimed' => false,
    'grants' => [$unclaimedPasswordGrant],
]);
$unclaimedLanes = is_array($unclaimedProjection)
    ? _stattic_access_page_lanes([
        'authorization' => $unclaimedProjection,
        'target' => ['kind' => 'live'],
    ])
    : null;
check(
    is_array($unclaimedLanes)
        && ($unclaimedLanes['password'] ?? false) === true
        && ($unclaimedLanes['exchange']['tokenUrl'] ?? null) === null
        && ($unclaimedProjection['grantIndex']['public'] ?? null) === []
        && ($unclaimedProjection['grantIndex']['authorities'] ?? null) === [],
    'historical access projections retain existing lanes without browser-token exchange'
);
check(
    in_array('page.view', _stattic_grant_decision(
        $networkProjection,
        '/',
        ['kind' => 'live'],
        [],
        [],
        'NL',
        'Mozilla/5.0'
    )['capabilities'], true),
    'Grant network combines matching country and user agent selectors'
);
check(
    !in_array('page.view', _stattic_grant_decision(
        $networkProjection,
        '/',
        ['kind' => 'live'],
        [],
        [],
        'US',
        'Mozilla/5.0'
    )['capabilities'], true),
    'Grant network excludes a configured country locally'
);
// No provider geo header means no country: a country selector must fail closed
// rather than admitting the unknown.
check(
    !in_array('page.view', _stattic_grant_decision(
        $networkProjection,
        '/',
        ['kind' => 'live'],
        [],
        [],
        '',
        'Mozilla/5.0'
    )['capabilities'], true),
    'Grant network fails closed when the platform supplies no country'
);
check(
    !in_array('page.view', _stattic_grant_decision(
        $networkProjection,
        '/',
        ['kind' => 'live'],
        [],
        [],
        'NL',
        'Mozilla GPTBot/1.0'
    )['capabilities'], true),
    'Grant network excludes configured user-agent substrings case-insensitively'
);

// §16: an overlay that still carries an IP grant compiles (the control plane may
// hold one), but the grant authorizes nobody — the runtime cannot name the
// visitor, so "inside the CIDR" would degrade to "sent the right header".
$ipCidrProjection = _stattic_compile_authorization_projection([
    ...$rawNetworkProjection,
    'grants' => [[
        ...$networkGrant,
        'id' => 'grt_network_ip',
        'constraints' => ['network' => ['ipCidrs' => ['203.0.113.0/24']]],
    ]],
]);
check(is_array($ipCidrProjection), 'an ipCidrs Grant still compiles into a projection');
check(
    _stattic_grant_decision(
        is_array($ipCidrProjection) ? $ipCidrProjection : [],
        '/',
        ['kind' => 'live'],
        [],
        [],
        'NL',
        'Mozilla/5.0'
    )['capabilities'] === [],
    'a Grant carrying an IP constraint is ignored rather than honoured (fail closed)'
);

$indexedGrant = static function (int $index, array $audience, array $constraints = []): array {
    return [
        'id' => 'grt_indexed_' . $index,
        'generation' => 1,
        'audience' => $audience,
        'resources' => ['include' => ['/**'], 'exclude' => []],
        'capabilities' => ['page.view'],
        'constraints' => $constraints,
        'target' => ['kind' => 'live'],
        'source' => ['kind' => 'managed', 'reference' => 'index-test'],
    ];
};
$projectionEnvelope = static fn (array $grants): array => [
    'generation' => 1,
    'sessionVersion' => 0,
    'fence' => 'none',
    'acquireUrl' => 'https://access.spacefast.test/acquire/index-test',
    'spaceClaimed' => true,
    'grants' => $grants,
];
$unrelatedGrants = [];
for ($index = 1; $index < 500; $index += 1) {
    $unrelatedGrants[] = $indexedGrant(
        $index,
        ['kind' => 'external', 'issuer' => 'index-test', 'subject' => 'unrelated-' . $index],
        ['expiresAt' => '2099-12-31T23:59:59.000Z']
    );
}
$publicProjection = _stattic_compile_authorization_projection($projectionEnvelope([
    $indexedGrant(0, ['kind' => 'public']),
    ...$unrelatedGrants,
]));
check(
    is_array($publicProjection)
        && _stattic_authorization_projection_valid($publicProjection)
        && count(_stattic_grant_candidates($publicProjection, [])) === 1,
    'compiled Public admission selects one candidate regardless of 499 unrelated Grants'
);
$privateProjection = _stattic_compile_authorization_projection($projectionEnvelope([
    $indexedGrant(0, [
        'kind' => 'external',
        'issuer' => 'spacefast-membership',
        'subject' => 'mem_indexed',
    ]),
    ...$unrelatedGrants,
]));
check(
    is_array($privateProjection)
        && count(_stattic_grant_candidates($privateProjection, ['member:mem_indexed'])) === 1,
    'compiled authenticated admission selects only the exact authority bucket'
);

check(
    _stattic_compile_authorization_projection($projectionEnvelope(array_fill(
        0,
        STATTIC_AUTHORIZATION_GRANT_LIMIT + 1,
        $indexedGrant(0, ['kind' => 'public'])
    ))) === null,
    'runtime projection compilation rejects Grants beyond the hard ceiling'
);

// --- Grant-decision conformance: the shared corpus ------------------------------------
//
// This engine re-implements the Grant vocabulary that
// packages/common/src/utils/grants.ts defines, because a request has to be
// admitted here without calling home. The corpus below is generated by that
// reference evaluator (packages/common/scripts/generate-grant-decision-fixtures.ts)
// and answered again here: same inputs, same capabilities, same Grants firing.
// Asserting WHICH Grant matched is the point — a verdict alone passes when the
// right answer comes out of the wrong rule.
require_once __DIR__ . '/grant-conformance.php';
$conformance = _stattic_test_grant_conformance_fixture(
    __DIR__ . '/../../packages/common/src/utils/grant-decision.fixtures.json'
);
check($conformance['cases'] !== [], 'grant conformance: the shared corpus is not empty');
foreach ($conformance['cases'] as $conformanceCase) {
    $answer = _stattic_test_grant_conformance_case($conformanceCase, $conformance['now']);
    $expectedCapabilities = $conformanceCase['expected']['capabilities'];
    $expectedGrantIds = $conformanceCase['expected']['matchedGrantIds'];
    sort($expectedCapabilities);
    sort($expectedGrantIds);
    check(
        $answer['compiled'] === $conformanceCase['phpProjectionCompiles']
            && $answer['capabilities'] === $expectedCapabilities
            && $answer['matchedGrantIds'] === $expectedGrantIds,
        'grant conformance: ' . $conformanceCase['name']
            . ' (expected ' . json_encode($conformanceCase['expected'])
            . ', got ' . json_encode($answer) . ')'
    );
}

// Issuer candidates by kid: an exact kid narrows to that issuer, a miss yields
// no candidates, and a kid-less token keeps every key so verification can retry.
check(
    _stattic_jwt_issuers_for_kid([['kid' => 'k1', 'publicKey' => 'p1'], ['kid' => 'k2', 'publicKey' => 'p2']], 'k2')
        === [['kid' => 'k2', 'publicKey' => 'p2']],
    'issuer candidates: exact kid selects that issuer'
);
check(
    _stattic_jwt_issuers_for_kid([['kid' => 'k1', 'publicKey' => 'p1']], 'nope') === [],
    'issuer candidates: kid miss yields no candidates'
);
check(
    _stattic_jwt_issuers_for_kid([['kid' => 'k1', 'publicKey' => 'p1'], ['kid' => 'k2', 'publicKey' => 'p2']], '')
        === [['kid' => 'k1', 'publicKey' => 'p1'], ['kid' => 'k2', 'publicKey' => 'p2']],
    'issuer candidates: a kid-less token keeps every key for retry'
);

// --- Visitor verify: per-request signature memo (shared/jwt.php) ---------------------

// The memo primitive itself: identical (key material, token) skips the
// verifier; different key material re-verifies; negative verdicts memoize too.
$memoCalls = 0;
$memoVerify = function () use (&$memoCalls): bool {
    $memoCalls++;
    return true;
};
check(_stattic_jwt_signature_valid_memo('fp-a', 'tok-1', $memoVerify) === true, 'sig memo: first call runs the verifier');
check(_stattic_jwt_signature_valid_memo('fp-a', 'tok-1', $memoVerify) === true && $memoCalls === 1, 'sig memo: warm call skips the verifier');
check(_stattic_jwt_signature_valid_memo('fp-b', 'tok-1', $memoVerify) === true && $memoCalls === 2, 'sig memo: different key material re-verifies the same token');
check(_stattic_jwt_signature_valid_memo('fp-a', 'tok-2', $memoVerify) === true && $memoCalls === 3, 'sig memo: different token re-verifies under the same key');
$memoDenies = 0;
$memoDeny = function () use (&$memoDenies): bool {
    $memoDenies++;
    return false;
};
check(_stattic_jwt_signature_valid_memo('fp-c', 'tok-1', $memoDeny) === false, 'sig memo: negative verdict is returned');
check(_stattic_jwt_signature_valid_memo('fp-c', 'tok-1', $memoDeny) === false && $memoDenies === 1, 'sig memo: negative verdict is memoized without re-running');

// End-to-end through _stattic_visitor_verify: the memo must never change a
// verdict, and everything time/state-dependent must keep running per call.
$jwtSignKeypair = sodium_crypto_sign_keypair();
$jwtIssuerA = [
    'alg' => 'EdDSA',
    'kid' => 'unit-k1',
    'publicKey' => _stattic_base64url_encode(sodium_crypto_sign_publickey($jwtSignKeypair)),
];
$jwtOtherKeypair = sodium_crypto_sign_keypair();
$jwtIssuerB = [
    'alg' => 'EdDSA',
    'kid' => 'unit-k1',
    'publicKey' => _stattic_base64url_encode(sodium_crypto_sign_publickey($jwtOtherKeypair)),
];
$mintEdDSA = static function (array $claims, string $secretKey): string {
    $header = _stattic_base64url_encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'unit-k1'], JSON_UNESCAPED_SLASHES));
    $body = _stattic_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $body;
    return $signingInput . '.' . _stattic_base64url_encode(sodium_crypto_sign_detached($signingInput, $secretKey));
};
$jwtToken = $mintEdDSA(
    ['sub' => 'user:carol', 'grants' => ['user:carol'], 'spaceId' => 'spc_unit', 'generation' => 0, 'iss' => 'spacefast-api', 'aud' => 'spc_unit', 'host' => 'unit.test', 'exp' => time() + 600],
    sodium_crypto_sign_secretkey($jwtSignKeypair),
);

$jwtOptionsA = ['issuers' => [$jwtIssuerA], 'issuer' => 'spacefast-api', 'host' => 'unit.test', 'spaceId' => 'spc_unit', 'generation' => 0];
$jwtOptionsB = ['issuers' => [$jwtIssuerB], 'issuer' => 'spacefast-api', 'host' => 'unit.test', 'spaceId' => 'spc_unit', 'generation' => 0];
$jwtFirst = _stattic_visitor_verify($jwtToken, $jwtOptionsA);
check(is_array($jwtFirst) && $jwtFirst['sub'] === 'user:carol', 'visitor verify accepts a valid EdDSA token');

// The audience names the Space, so host binding rides in its own claim — and it
// still binds: the same token is refused on a host its `host` claim does not
// name, and a Space-audienced token carrying no host claim is refused outright.
check(
    _stattic_visitor_verify($jwtToken, [...$jwtOptionsA, 'host' => 'other.test']) === null,
    'space-audienced token is rejected on a host its host claim does not name'
);
check(
    _stattic_visitor_verify(
        $mintEdDSA(
            ['sub' => 'user:carol', 'spaceId' => 'spc_unit', 'generation' => 0, 'iss' => 'spacefast-api', 'aud' => 'spc_unit', 'exp' => time() + 600],
            sodium_crypto_sign_secretkey($jwtSignKeypair),
        ),
        $jwtOptionsA
    ) === null,
    'space-audienced token without a host claim is rejected'
);
check(_stattic_visitor_verify($jwtToken, $jwtOptionsA) === $jwtFirst, 'memo-warm re-verify returns the identical verdict');
check(_stattic_visitor_verify($jwtToken, $jwtOptionsB) === null, 'memo is keyed by key material: the same token fails a different issuer key');
check(_stattic_visitor_verify($jwtToken, $jwtOptionsA) !== null, 'cross-key rejection does not poison the original verdict');

$jwtForged = $mintEdDSA(
    ['sub' => 'user:mallory', 'grants' => ['user:mallory'], 'spaceId' => 'spc_unit', 'generation' => 0, 'iss' => 'spacefast-api', 'aud' => 'spc_unit', 'host' => 'unit.test', 'exp' => time() + 600],
    sodium_crypto_sign_secretkey($jwtOtherKeypair),
);
check(_stattic_visitor_verify($jwtForged, $jwtOptionsA) === null, 'wrong-key token is rejected');
check(_stattic_visitor_verify($jwtForged, $jwtOptionsA) === null, 'wrong-key token stays rejected on the memo-warm path');

// Time/session checks stay outside the signature memo.
check(_stattic_visitor_verify($jwtToken, [...$jwtOptionsA, 'generation' => 3]) === null, 'generation mismatch rejects despite a warm signature memo');
$jwtExpired = $mintEdDSA(
    ['sub' => 'user:carol', 'grants' => ['user:carol'], 'spaceId' => 'spc_unit', 'generation' => 0, 'iss' => 'spacefast-api', 'aud' => 'spc_unit', 'host' => 'unit.test', 'exp' => time() - 3600],
    sodium_crypto_sign_secretkey($jwtSignKeypair),
);
check(_stattic_visitor_verify($jwtExpired, $jwtOptionsA) === null, 'expired token is rejected (exp check runs outside the memo)');

// Rotation: a kid-less token signed by one live key must still verify when the
// verifier tries a different (also-live) key first, rather than signing out.
$mintKidless = static function (array $claims, string $secretKey): string {
    $header = _stattic_base64url_encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $body = _stattic_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $body;
    return $signingInput . '.' . _stattic_base64url_encode(sodium_crypto_sign_detached($signingInput, $secretKey));
};
$jwtKidless = $mintKidless(
    ['sub' => 'user:dave', 'grants' => ['user:dave'], 'spaceId' => 'spc_unit', 'generation' => 0, 'iss' => 'spacefast-api', 'aud' => 'spc_unit', 'host' => 'unit.test', 'exp' => time() + 600],
    sodium_crypto_sign_secretkey($jwtSignKeypair),
);
$jwtRotationVerdict = _stattic_visitor_verify($jwtKidless, [
    'issuers' => [$jwtIssuerB, $jwtIssuerA],
    'issuer' => 'spacefast-api',
    'host' => 'unit.test',
    'spaceId' => 'spc_unit',
    'generation' => 0,
]);
check(
    is_array($jwtRotationVerdict) && $jwtRotationVerdict['sub'] === 'user:dave',
    'kid-less token verifies against a later key when the first candidate fails'
);

// The request boundary decodes and NFC-normalizes once. Access scoping then
// canonicalizes only the already-decoded identity it receives.
check(_stattic_scope_path('/docs//intro/') === '/docs/intro', 'access path collapses duplicate and trailing separators');
check(_stattic_scope_path('/docs/./guide/../intro/index.html') === '/docs/intro', 'access path resolves dots and index alias');
check(_stattic_artifact_scope_path('/docs/./guide/../intro/index.html') === '/docs/intro/index.html', 'artifact access path retains the resolved index file');
check(
    _stattic_scope_contains('/docs', '/docs/intro') && !_stattic_scope_contains('/docs', '/admin'),
    'access scope contains only itself and descendant paths'
);
check(_stattic_scope_path(_stattic_canonical_request_path('/caf%C3%A9') ?? '') === '/café', 'access path uses decoded UTF-8');
check(_stattic_scope_path(_stattic_canonical_request_path('/%252e%252e/secret') ?? '') === '/%2e%2e/secret', 'access path never decodes twice');
check(_stattic_canonical_request_path('/' . str_repeat('a', 2047)) !== null, 'request boundary accepts its 2048-byte path limit');
check(_stattic_canonical_request_path('/' . str_repeat("\u{0301}", 1024)) === null, 'request boundary rejects oversized combining-mark input before normalization');
foreach ([' relative', '//host/path', '/%2fsecret', '/%5csecret', '/%3fsecret', '/%23secret', '/%00secret', '/%zz'] as $invalidAccessPath) {
    check(_stattic_canonical_request_path($invalidAccessPath) === null, 'request boundary rejects ambiguous input: ' . $invalidAccessPath);
}

// Clean-URL knob resolution moved into the finalize compiler (v4): the runtime
// no longer computes it, so the resolver tests live in crates/stattic-runtime-core.

// --- Job runner: backoff policy, time-stop, lane derivation (§22) ----------------------

check(_stattic_runtime_job_backoff_delay_seconds(1) === 10.0, 'job backoff: attempt 1 is the floor (10s)');
check(_stattic_runtime_job_backoff_delay_seconds(2) === 15.0, 'job backoff: attempt 2 is 10 * 1.5');
check(_stattic_runtime_job_backoff_delay_seconds(3) === 22.5, 'job backoff: attempt 3 is 10 * 1.5^2');
check(
    abs(_stattic_runtime_job_backoff_delay_seconds(4) - 33.75) < 0.001,
    'job backoff: attempt 4 is 10 * 1.5^3'
);
check(_stattic_runtime_job_backoff_delay_seconds(50) === 900.0, 'job backoff: caps at 15 minutes');
check(_stattic_runtime_job_backoff_delay_seconds(0) === 10.0, 'job backoff: attempt below 1 clamps to the floor');

$epoch = 1_800_000_000;
check(
    !_stattic_runtime_job_time_stopped(null, $epoch),
    'job time-stop: no first_failed_at means not stopped'
);
check(
    !_stattic_runtime_job_time_stopped(gmdate('c', $epoch - (8 * 3600 - 1)), $epoch),
    'job time-stop: one second under 8h is not stopped yet'
);
check(
    _stattic_runtime_job_time_stopped(gmdate('c', $epoch - 8 * 3600), $epoch),
    'job time-stop: exactly 8h since first failure is stopped'
);
check(
    _stattic_runtime_job_time_stopped(gmdate('c', $epoch - 9 * 3600), $epoch),
    'job time-stop: past 8h since first failure is stopped'
);

// D54: the registry collapsed to one maintenance tick and one demote. Space
// moves are a control-plane workflow over the ordinary read/write surfaces
// (D21/D72/D96), so there is no transfer lane to derive.
foreach ([
    'maintenance_tick' => 'bulk',
    'tier_demote' => 'bulk',
] as $type => $expectedLane) {
    check(
        _stattic_runtime_job_lane_for_type($type) === $expectedLane,
        "job lane: {$type} derives to {$expectedLane}"
    );
}

$unknownLaneThrew = false;
try {
    _stattic_runtime_job_lane_for_type('not_a_real_job_type');
} catch (StatticJobFatal $error) {
    $unknownLaneThrew = $error->getMessage() === 'unknown_job_type';
}
check($unknownLaneThrew, 'job lane: unknown type throws StatticJobFatal(unknown_job_type)');

// job_create is real filesystem I/O (upsert-by-idempotency-key, journaling) rather than
// pure logic, but it has no HTTP surface of its own in this wave (only /jobs/tick and
// GET /jobs/{jobId} are routes — job_create is called in-process by future producers), so
// it is exercised here directly against a throwaway private root instead of invented as a
// test-only HTTP route.
function _stattic_job_runner_unit_temp_private_root(): string
{
    // realpath() the base first: the path-safety spine (storage.php) re-resolves
    // ancestors with realpath() on every write, so a literal /tmp on platforms
    // where it symlinks elsewhere (macOS: /tmp -> /private/tmp) would mismatch
    // against the un-resolved path we handed to the job functions.
    $base = realpath(sys_get_temp_dir());
    if (!is_string($base)) {
        $base = sys_get_temp_dir();
    }
    $root = $base . '/stattic-jobs-unit-' . bin2hex(random_bytes(6)) . '/.stattic/storage';
    mkdir($root, 0775, true);
    return $root;
}

function _stattic_job_runner_unit_rm_recursive(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        _stattic_job_runner_unit_rm_recursive($path . '/' . $entry);
    }
    @rmdir($path);
}

$jobsPrivateRoot = _stattic_job_runner_unit_temp_private_root();
$claims = ['operation_id' => 'op_test', 'space_id' => 'spc_unit', 'action' => 'create_engine_job'];
check(!_stattic_tiering_enabled(), 'tiering switch: disabled when no explicit opt-in is configured');
putenv('SPACEFAST_TIERING_ENABLED=1');
check(_stattic_tiering_enabled(), 'tiering switch: exact opt-in enables the retained implementation');
$created = _stattic_runtime_job_create($jobsPrivateRoot, 'tier_demote', 'idem-1', ['target' => 3], $claims);
check($created['type'] === 'tier_demote', 'job_create: stamps the requested type');
check($created['lane'] === 'bulk', 'job_create: lane derived from type');
check($created['status'] === 'pending', 'job_create: starts pending');
check($created['space_id'] === 'spc_unit', 'job_create: lifts space_id from claims onto the record');
check($created['operation_id'] === 'op_test', 'job_create: lifts operation_id from claims onto the record');
check($created['payload']['target'] === 3, 'job_create: keeps job-specific payload fields');
check($created['payload']['_claims']['action'] === 'create_engine_job', 'job_create: stashes claims under payload._claims');
check(
    is_file(_stattic_runtime_job_path($jobsPrivateRoot, $created['id'])),
    'job_create: writes the queue record to disk'
);

$journalPath = $jobsPrivateRoot . '/runtime/journal.jsonl';
$journalLines = is_file($journalPath) ? array_filter(explode("\n", (string) file_get_contents($journalPath))) : [];
$createdEvents = array_filter($journalLines, static fn ($line) => str_contains($line, '"job_created"'));
check(count($createdEvents) === 1, 'job_create: journals exactly one job_created event');

$again = _stattic_runtime_job_create($jobsPrivateRoot, 'tier_demote', 'idem-1', ['target' => 999], $claims);
check($again['id'] === $created['id'], 'job_create: upserts by idempotency_key instead of duplicating');
check($again['payload']['target'] === 3, 'job_create: upsert returns the ORIGINAL record, not a re-created one');
$queueFiles = glob(_stattic_runtime_jobs_queue_dir($jobsPrivateRoot) . '/*.json') ?: [];
check(count($queueFiles) === 1, 'job_create: idempotency key collision creates no second file');

$publicResponse = _stattic_runtime_job_public_response($created);
check(!array_key_exists('_claims', $publicResponse['payload']), 'job public response: strips payload._claims');
check($publicResponse['payload']['target'] === 3, 'job public response: keeps non-secret payload fields');
putenv('SPACEFAST_TIERING_ENABLED');

// --- CAS install (§8) ----------------------------------------------------------------

// D14/D15 deleted the per-version file tree, so there is no hardlink destination
// left and `_stattic_runtime_blob_link` has no caller — its tests went with the
// tree. What remains is the one write path every ingest lane shares: install
// verified bytes at the derived CAS key `spaces/<s>/blobs/<aa>/<sha>` (D16).
$blobSource = $jobsPrivateRoot . '/runtime/blob-source.txt';
_stattic_runtime_mkdir(dirname($blobSource));
file_put_contents($blobSource, 'blob bytes');
$blobSha = hash('sha256', 'blob bytes');
_stattic_runtime_blob_put($jobsPrivateRoot, 'spc_blob_unit', $blobSource, $blobSha);
check(_stattic_runtime_blob_has($jobsPrivateRoot, 'spc_blob_unit', $blobSha), 'blob store: put makes a sha addressable in the space store');
check(!is_file($blobSource), 'blob store: put renames the tmp source away');
$casPath = $jobsPrivateRoot . '/spaces/spc_blob_unit/blobs/' . substr($blobSha, 0, 2) . '/' . $blobSha;
check(
    is_file($casPath) && file_get_contents($casPath) === 'blob bytes',
    'blob store: the CAS key is derived from the sha, never stored'
);
check(
    !_stattic_runtime_blob_has($jobsPrivateRoot, 'spc_other_space', $blobSha),
    'blob store: the CAS is per-space, so the same sha is not addressable elsewhere'
);

// GC grace (§15/§17): blob mtime is a validator and is never written, so the
// grace window cannot be read off the file. It is a configured span applied to
// the publish timestamps carried in pin/publish-session records, and its default
// must be non-zero or a mark-then-delete pass could collect within its own tick.
require_once __DIR__ . '/../engine/admin/tier.php';
check(getenv('SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS') === false, 'gc grace: unit env leaves the knob unset');
check(_stattic_tier_gc_grace_seconds() >= 1, 'gc grace: default grace window is non-zero seconds');

_stattic_job_runner_unit_rm_recursive(dirname(dirname($jobsPrivateRoot)));

// --- Proxy cache policy: explicit opt-in, bounded status/header gates ---------

$relayOrigin = [
    ['Content-Type', 'application/json'],
    ['Cache-Control', 'public, max-age=300, s-maxage=600'],
    ['ETag', '"abc123"'],
    ['Last-Modified', 'Wed, 01 Jul 2026 00:00:00 GMT'],
    ['Expires', 'Thu, 02 Jul 2026 00:00:00 GMT'],
    ['Vary', 'Accept-Encoding'],
    ['Age', '42'],
    ['Transfer-Encoding', 'chunked'],
    ['Connection', 'keep-alive'],
    ['Content-Length', '128'],
    ['Surrogate-Control', 'max-age=86400'],
    ['CDN-Cache-Control', 'max-age=86400'],
];
$sharedPolicy = _stattic_proxy_response_cache_policy(true, 200, $relayOrigin, false);
check($sharedPolicy['cache_control'] === STATTIC_PROXY_SHARED_CACHE_POLICY, 'proxy cache: explicit safe GET response gets the bounded shared policy');
$relayed = _stattic_proxy_response_header_lines($relayOrigin, [], [], $sharedPolicy);
$relayedNames = array_map(static fn (array $line): string => strtolower($line[0]), $relayed);
check(in_array(['Cache-Control', STATTIC_PROXY_SHARED_CACHE_POLICY], $relayed, true), 'proxy cache: platform shared policy replaces origin cache metadata');
check(in_array(['ETag', '"abc123"'], $relayed, true), 'proxy relay: origin ETag reaches the client');
check(in_array(['Last-Modified', 'Wed, 01 Jul 2026 00:00:00 GMT'], $relayed, true), 'proxy relay: origin Last-Modified reaches the client');
check(in_array(['Vary', 'Accept-Encoding'], $relayed, true), 'proxy relay: origin Vary reaches the client');
check(!in_array('expires', $relayedNames, true), 'proxy cache: origin Expires never replaces platform policy');
check(!in_array('age', $relayedNames, true), 'proxy cache: origin Age never leaks into the new cache policy');
check(!in_array('transfer-encoding', $relayedNames, true), 'proxy relay: hop-by-hop Transfer-Encoding is stripped');
check(!in_array('connection', $relayedNames, true), 'proxy relay: hop-by-hop Connection is stripped');
check(!in_array('content-length', $relayedNames, true), 'proxy relay: origin Content-Length is re-framed, not relayed');
check(!in_array('surrogate-control', $relayedNames, true), 'proxy relay: origin Surrogate-Control never reaches the edge');
check(!in_array('cdn-cache-control', $relayedNames, true), 'proxy relay: origin CDN-Cache-Control never reaches the edge');
check(count(array_keys($relayedNames, 'cache-control', true)) === 1, 'proxy relay: exactly one Cache-Control on a cacheable relay');

$protectedPolicy = _stattic_proxy_response_cache_policy(true, 200, $relayOrigin, true);
$protectedLines = _stattic_proxy_response_header_lines($relayOrigin, [], [], $protectedPolicy);
$protectedNames = array_map(static fn (array $line): string => strtolower($line[0]), $protectedLines);
check(in_array(['Cache-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE], $protectedLines, true), 'proxy relay: access-protected responses pin private revalidation');
check(count(array_keys($protectedNames, 'cache-control', true)) === 1, 'proxy relay: protected responses carry exactly one Cache-Control');
check(!in_array('expires', $protectedNames, true), 'proxy relay: protected responses drop origin Expires');
check(!in_array('age', $protectedNames, true), 'proxy relay: protected responses drop origin Age');
check(!in_array('set-cookie', $protectedNames, true), 'proxy relay: protected responses still never relay Set-Cookie');
check(in_array(['ETag', '"abc123"'], $protectedLines, true), 'proxy relay: protected responses keep validators (inert under no-store)');

$platformOverride = _stattic_proxy_response_header_lines(
    [['Cache-Control', 'public, max-age=600'], ['X-Origin', 'up']],
    ['cache-control' => true, 'x-origin' => true],
    [['Cache-Control', 'no-cache']],
    _stattic_proxy_response_cache_policy(false, 200, [], false)
);
check(in_array(['Cache-Control', STATTIC_PROXY_EDGE_CACHE_POLICY], $platformOverride, true), 'proxy cache: classifier policy wins over platform and origin cache metadata');
check(!in_array(['Cache-Control', 'public, max-age=600'], $platformOverride, true), 'proxy relay: platform-overridden origin Cache-Control is suppressed');
check(!in_array(['X-Origin', 'up'], $platformOverride, true), 'proxy relay: platform-suppressed origin headers stay suppressed');
check(count(array_filter($platformOverride, static fn (array $line): bool => strtolower($line[0]) === 'cache-control')) === 1, 'proxy relay: platform override emits exactly one Cache-Control');

foreach ([
    [['Set-Cookie', 'session=secret']],
    [['Vary', '*']],
    [['Vary', 'Authorization']],
    [['Vary', 'X-Forwarded-For']],
    [['Cache-Control', 'public, no-store']],
    [['Cache-Control', 'private="Authorization"']],
    [['Pragma', 'no-cache']],
] as $unsafeOrigin) {
    check(
        _stattic_proxy_response_cache_policy(true, 200, $unsafeOrigin, false)['cache_control'] === STATTIC_PROXY_EDGE_CACHE_POLICY,
        'proxy cache: personalized or restrictive upstream metadata revokes shared caching'
    );
}
check(
    _stattic_proxy_response_cache_policy(false, 200, [], false)['cache_control'] === STATTIC_PROXY_EDGE_CACHE_POLICY,
    'proxy cache: a request the route never made shared-eligible stays no-store'
);

// Request-side eligibility (the boolean the serving path hands the classifier).
check(
    _stattic_proxy_cache_request_eligible('shared', [], [], 'GET', 'GET', false),
    'proxy cache: an opted anonymous safe GET is shared-cache eligible'
);
check(
    !_stattic_proxy_cache_request_eligible(null, [], [], 'GET', 'GET', false),
    'proxy cache: an unopted route stays no-store'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', [], [], 'POST', 'POST', false),
    'proxy cache: unsafe request methods stay no-store'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', ['Authorization' => 'Bearer configured'], [], 'GET', 'GET', false),
    'proxy cache: a static Authorization route header revokes shared caching'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', ['Cookie' => 'session=configured'], [], 'GET', 'GET', false),
    'proxy cache: a static Cookie route header revokes shared caching'
);

// Conditional-request relay: the client's validators are forwarded upstream for
// safe methods (via the real header-collection function), and never duplicated
// when a route's forwardHeaders already lists them.
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        return is_array($GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] ?? null) ? $GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] : [];
    }
}
$GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] = [
    'If-None-Match' => '"abc123"',
    'If-Modified-Since' => 'Wed, 01 Jul 2026 00:00:00 GMT',
    'X-Other' => 'ignored',
];
$conditionalForwarded = _stattic_collect_proxy_request_headers([], [], true);
check(in_array('If-None-Match: "abc123"', $conditionalForwarded, true), 'proxy conditionals: If-None-Match forwards upstream for safe methods');
check(in_array('If-Modified-Since: Wed, 01 Jul 2026 00:00:00 GMT', $conditionalForwarded, true), 'proxy conditionals: If-Modified-Since forwards upstream for safe methods');
check(!in_array('X-Other: ignored', $conditionalForwarded, true), 'proxy conditionals: non-allowlisted headers still do not forward');
$conditionalUnsafe = _stattic_collect_proxy_request_headers([], [], false);
check(!in_array('If-None-Match: "abc123"', $conditionalUnsafe, true), 'proxy conditionals: unsafe upstream methods do not forward validators');
$conditionalAllowlisted = _stattic_collect_proxy_request_headers([], ['If-None-Match'], false);
check(count(array_keys($conditionalAllowlisted, 'If-None-Match: "abc123"', true)) === 1, 'proxy conditionals: a forwardHeaders-listed validator forwards exactly once');
$GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] = [];

// A shared-cache candidate must be anonymous at the origin. Two distinct
// visitor addresses therefore produce the same request headers, while the
// ordinary unshared proxy path retains its X-Forwarded-For contract.
$originalRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$anonymousFirst = _stattic_collect_proxy_request_headers([], [], true);
$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
$anonymousSecond = _stattic_collect_proxy_request_headers([], [], true);
check($anonymousFirst === $anonymousSecond, 'proxy shared cache: outbound headers do not vary by visitor address');
check(count(array_filter($anonymousFirst, static fn (string $header): bool => str_starts_with(strtolower($header), 'x-forwarded-for:'))) === 0, 'proxy shared cache: anonymous outbound headers omit X-Forwarded-For');

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$ordinaryFirst = _stattic_collect_proxy_request_headers([], []);
$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
$ordinarySecond = _stattic_collect_proxy_request_headers([], []);
check(in_array('X-Forwarded-For: 203.0.113.10', $ordinaryFirst, true), 'proxy request: unshared outbound headers preserve the first visitor address');
check(in_array('X-Forwarded-For: 203.0.113.20', $ordinarySecond, true), 'proxy request: unshared outbound headers preserve the second visitor address');
if ($originalRemoteAddress === null) {
    unset($_SERVER['REMOTE_ADDR']);
} else {
    $_SERVER['REMOTE_ADDR'] = $originalRemoteAddress;
}

// --- Proxy body streaming: response-size limit state machine (runtime/proxy.php) ---
// The curl leg cannot be driven in-process (egress policy denies loopback
// upstreams, so the streaming decisions
// live in pure helpers asserted here: headers are emitted at the first body
// byte, an honestly-declared oversize Content-Length is rejected BEFORE headers
// (clean 502), and only a lying/chunking origin hits the mid-stream abort.

check(_stattic_proxy_origin_content_length([['Content-Type', 'text/html'], ['Content-Length', '1024']]) === 1024, 'proxy stream: origin Content-Length parses from raw header pairs');
check(_stattic_proxy_origin_content_length([['content-length', ' 2048 ']]) === 2048, 'proxy stream: Content-Length parsing is case- and whitespace-tolerant');
check(_stattic_proxy_origin_content_length([['Content-Type', 'text/html']]) === null, 'proxy stream: absent Content-Length reads as unknown');
check(_stattic_proxy_origin_content_length([['Content-Length', 'chunked-nonsense']]) === null, 'proxy stream: malformed Content-Length reads as unknown, not zero');
check(_stattic_proxy_origin_content_length([['Content-Length', '-1']]) === null, 'proxy stream: negative Content-Length reads as unknown');
check(_stattic_proxy_origin_content_length([['Content-Length', '100'], ['Content-Length', '200']]) === null, 'proxy stream: self-contradictory duplicate Content-Length reads as unknown');
check(_stattic_proxy_origin_content_length([['Content-Length', '100'], ['Content-Length', '100']]) === 100, 'proxy stream: agreeing duplicate Content-Length still parses');

check(_stattic_proxy_stream_limit_decision(2000, false, 0, 100, 1000) === 'reject', 'proxy stream: declared-oversize origin is rejected before headers are emitted');
check(_stattic_proxy_stream_limit_decision(null, false, 0, 1500, 1000) === 'reject', 'proxy stream: a first chunk already over the limit is rejected before headers');
check(_stattic_proxy_stream_limit_decision(500, false, 0, 100, 1000) === 'relay', 'proxy stream: an in-limit declared body relays its first chunk');
check(_stattic_proxy_stream_limit_decision(null, false, 0, 100, 1000) === 'relay', 'proxy stream: an undeclared-length body relays until the counter says otherwise');
check(_stattic_proxy_stream_limit_decision(null, true, 900, 200, 1000) === 'abort', 'proxy stream: crossing the limit mid-stream aborts the transfer (truncation, not an error page)');
check(_stattic_proxy_stream_limit_decision(null, true, 800, 200, 1000) === 'relay', 'proxy stream: exactly reaching the limit still relays');
check(_stattic_proxy_stream_limit_decision(null, true, 0, 0, 0) === 'relay', 'proxy stream: a zero-byte chunk at a zero limit does not abort');

// --- Response cache policy (shared/cache-policy.php) --------------------------------
//
// One decision for every lane. The dynamic lanes (Zero, Functions) carry no
// declared policy of their own, so what a runner, an upstream or a `_headers`
// rule declared is suppressed rather than relayed.

require_once __DIR__ . '/../engine/shared/cache-policy.php';
require_once __DIR__ . '/../engine/runtime/zero.php';

$dynamicPublic = _stattic_cache_policy(['public' => STATTIC_CACHE_CONTROL_NO_STORE]);
check(
    $dynamicPublic['lines'] === [['Cache-Control', STATTIC_CACHE_CONTROL_NO_STORE]],
    'cache policy: a declared policy cannot opt a dynamic response into shared cache'
);
check(
    in_array('cache-control', $dynamicPublic['suppress'], true)
        && in_array('expires', $dynamicPublic['suppress'], true)
        && in_array('age', $dynamicPublic['suppress'], true),
    'cache policy: every shared-cache policy header a lane could relay is suppressed'
);

$protectedResponse = _stattic_cache_policy(['private' => true, 'public' => 'public, max-age=600']);
check(
    $protectedResponse['lines'] === [
        ['Cache-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE],
        ['Vary', 'Cookie'],
        ['X-Robots-Tag', 'noindex, nofollow'],
        ['Cross-Origin-Resource-Policy', 'same-origin'],
        ['Content-Security-Policy', "frame-ancestors 'self'"],
    ],
    'cache policy: access-protected responses pin private revalidation and the canonical private boundary'
);
// The framing allowlist is assembled from origins, so this parser IS the
// boundary. Every rejected value below looks like an origin and is not one, and
// each fails for its own reason; the last two show why directive injection is
// structurally impossible rather than filtered — nothing past the origin is read.
check(
    array_map(_stattic_absolute_url_origin(...), [
        'https://live.example/',
        'http://live.example:8443/path?q=1',
        '//live.example/',
        'javascript:alert(1)',
        'ftp://live.example/',
        'https://evil.example@live.example/',
        'https://live.example/;script-src *',
        null,
    ]) === [
        'https://live.example',
        'http://live.example:8443',
        null,
        null,
        null,
        null,
        'https://live.example',
        null,
    ],
    'framing boundary: only an absolute http(s) URL yields an origin, and only its origin'
);
check(
    in_array('vary', $protectedResponse['suppress'], true)
        && in_array('x-robots-tag', $protectedResponse['suppress'], true)
        && in_array('access-control-allow-origin', $protectedResponse['suppress'], true),
    'cache policy: a protected response never relays the headers the boundary owns'
);

check(
    _stattic_cache_policy([
        'private' => true,
        'no_store' => true,
        'public' => STATTIC_DEFAULT_EDGE_CACHE_CONTROL,
    ])['cache_control'] === STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE,
    'cache policy: a private response the browser must not keep either is no-store'
);

$laneOwned = _stattic_cache_policy(['public' => null]);
check(
    $laneOwned['cache_control'] === null && $laneOwned['lines'] === [] && $laneOwned['suppress'] === [],
    'cache policy: a lane that declares no public policy keeps its own response headers'
);

$relayed = _stattic_cache_policy_apply_lines(
    _stattic_cache_policy(['private' => true, 'public' => null]),
    [['X-Origin', 'up'], ['Vary', 'Accept-Encoding'], ['X-Robots-Tag', 'index']]
);
check(
    in_array(['X-Origin', 'up'], $relayed, true)
        && in_array(['Vary', 'Accept-Encoding, Cookie'], $relayed, true)
        && in_array(['X-Robots-Tag', 'noindex, nofollow'], $relayed, true)
        && count(array_filter($relayed, static fn (array $line): bool => strtolower($line[0]) === 'x-robots-tag')) === 1,
    'cache policy: relayed lines survive a private verdict except where the boundary owns the header'
);

// --- Zero realtime replay credential (runtime/zero.php) -----------------------------
//
// The fleet secret presented upstream on `x-spacefast-runtime-realtime-token`
// comes only from process config. A visitor request header is untrusted (not in
// the edge strip list) and is never a fallback source for that credential.

$replayServer = $_SERVER;
check(
    _stattic_zero_replay_headers([]) === ['Accept: application/json'],
    'zero replay: no configured token means no credential is presented'
);
putenv('SPACEFAST_ZERO_REALTIME_TOKEN=fleet-secret');
check(
    in_array('X-Spacefast-Runtime-Realtime-Token: fleet-secret', _stattic_zero_replay_headers([]), true),
    'zero replay: the process-config token is presented on the runtime header'
);
$_SERVER['HTTP_X_SPACEFAST_RUNTIME_REALTIME_TOKEN'] = 'visitor-token';
check(
    !in_array('X-Spacefast-Runtime-Realtime-Token: visitor-token', _stattic_zero_replay_headers([]), true),
    'zero replay: a visitor request header never supplies the credential'
);
putenv('SPACEFAST_ZERO_REALTIME_TOKEN');
check(
    _stattic_zero_replay_headers([]) === ['Accept: application/json'],
    'zero replay: with no configured token, a visitor request header is not a fallback'
);
unset($_SERVER['HTTP_X_SPACEFAST_RUNTIME_REALTIME_TOKEN']);
foreach ([
    ['header injection', "secret\r\nX-Injected: 1"],
    ['oversize', str_repeat('t', STATTIC_ZERO_REALTIME_TOKEN_MAX_BYTES + 1)],
    ['empty', ''],
] as [$label, $token]) {
    putenv('SPACEFAST_ZERO_REALTIME_TOKEN=' . $token);
    check(
        _stattic_zero_replay_headers([]) === ['Accept: application/json'],
        "zero replay: a {$label} configured token is not presented"
    );
}
putenv('SPACEFAST_ZERO_REALTIME_TOKEN');
$_SERVER = $replayServer;
check(
    _stattic_zero_replay_headers([
        'realtime' => ['eventCallback' => ['token' => 'callback-token']],
    ]) === ['Accept: application/json', 'Authorization: Bearer callback-token'],
    'zero replay: the version-scoped callback capability authorizes replay without a fleet secret'
);

// --- S3 SigV4 signer + client: pure canonicalization/signing logic (§26, DECISIONS I-12/I-13) ---

require_once __DIR__ . '/../engine/shared/s3.php';

check(
    _stattic_s3_canonical_object_path('spaces/spc_1/blobs/9f/9f3a hash') === '/spaces/spc_1/blobs/9f/9f3a%20hash',
    's3 canonical path: encodes each segment, keeps slashes literal'
);
check(
    _stattic_s3_canonical_object_path('') === '/',
    's3 canonical path: empty key canonicalizes to "/"'
);
check(
    _stattic_s3_canonical_object_path('a/b/c') === '/a/b/c',
    's3 canonical path: plain segments pass through unencoded'
);

$pathBucket = [
    'id' => 'b1', 'endpoint' => 'https://minio.internal:9000', 'region' => 'us-east-1',
    'bucket' => 'stattic-cold', 'urlStyle' => 'path',
];
$pathLocator = _stattic_s3_locator($pathBucket, 'spaces/spc_1/blobs/9f/9f3a');
check($pathLocator['host'] === 'minio.internal:9000', 's3 locator (path style): host is the bare endpoint host');
check($pathLocator['path'] === '/stattic-cold/spaces/spc_1/blobs/9f/9f3a', 's3 locator (path style): bucket name prefixes the object path');
check($pathLocator['url'] === 'https://minio.internal:9000/stattic-cold/spaces/spc_1/blobs/9f/9f3a', 's3 locator (path style): full URL assembled correctly');

$vhostBucket = ['id' => 'b1', 'endpoint' => 'https://s3.example.com', 'region' => 'us-east-1', 'bucket' => 'stattic-cold', 'urlStyle' => 'vhost'];
$vhostLocator = _stattic_s3_locator($vhostBucket, 'spaces/spc_1/blobs/9f/9f3a');
check($vhostLocator['host'] === 'stattic-cold.s3.example.com', 's3 locator (vhost style): bucket prefixes the host');
check($vhostLocator['path'] === '/spaces/spc_1/blobs/9f/9f3a', 's3 locator (vhost style): path is the bare object key');

check(_stattic_s3_locator(['endpoint' => '', 'bucket' => 'x'], 'k') === null, 's3 locator: missing endpoint is rejected');
check(_stattic_s3_locator(['endpoint' => 'https://x', 'bucket' => ''], 'k') === null, 's3 locator: missing bucket is rejected');

[$canonicalHeaders, $signedHeaders] = _stattic_s3_canonical_headers([
    'X-Amz-Date' => '20260703T000000Z',
    'Host' => 'example.com',
    'x-amz-content-sha256' => STATTIC_S3_EMPTY_PAYLOAD_SHA256,
]);
check(
    $canonicalHeaders === "host:example.com\nx-amz-content-sha256:" . STATTIC_S3_EMPTY_PAYLOAD_SHA256 . "\nx-amz-date:20260703T000000Z\n",
    's3 canonical headers: sorted lowercase "name:value\\n" block'
);
check($signedHeaders === 'host;x-amz-content-sha256;x-amz-date', 's3 canonical headers: ";"-joined sorted signed-header names');

check(_stattic_s3_credentials(['getKeyId' => 'G', 'getKeySecret' => 'g', 'putKeyId' => 'P', 'putKeySecret' => 'p'], 'get') === ['key' => 'G', 'secret' => 'g'], 's3 credentials: get mode selects the GET-only key');
check(_stattic_s3_credentials(['getKeyId' => 'G', 'getKeySecret' => 'g', 'putKeyId' => 'P', 'putKeySecret' => 'p'], 'put') === ['key' => 'P', 'secret' => 'p'], 's3 credentials: put mode selects the PUT-capable key');

// Signing determinism + payload-hash sensitivity (the actual HMAC-chain
// derivation is exercised end-to-end by the fixture round-trips in
// s3.test.ts; this checks the pure function's contract in isolation).
$signBucket = ['region' => 'us-east-1'];
$signCreds = ['key' => 'AKIDEXAMPLE', 'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'];
$signedA = _stattic_s3_sign($signBucket, $signCreds, 'GET', 'example.com', '/key', [], STATTIC_S3_EMPTY_PAYLOAD_SHA256);
check(
    preg_match('/^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE\/\d{8}\/us-east-1\/s3\/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}$/', $signedA['authorization']) === 1,
    's3 sign: Authorization header has the AWS4-HMAC-SHA256 shape'
);
$signedSamePayload = _stattic_s3_sign($signBucket, $signCreds, 'GET', 'example.com', '/key', [], STATTIC_S3_EMPTY_PAYLOAD_SHA256);
check(
    // The escape hatch must compare the full x-amz-date, not just YYYYMMDD:
    // the signature covers the whole timestamp, so two calls that straddle a
    // second boundary on the same day legitimately differ.
    $signedA['authorization'] === $signedSamePayload['authorization'] || $signedA['x-amz-date'] !== $signedSamePayload['x-amz-date'],
    's3 sign: identical inputs within the same second produce an identical signature'
);
$signedDifferentPayload = _stattic_s3_sign($signBucket, $signCreds, 'GET', 'example.com', '/key', [], hash('sha256', 'not empty'));
check(
    $signedA['authorization'] !== $signedDifferentPayload['authorization'],
    's3 sign: a different payload hash changes the signature'
);
check(
    $signedA['x-amz-content-sha256'] === STATTIC_S3_EMPTY_PAYLOAD_SHA256,
    's3 sign: carries the payload hash through as a signed header'
);

// Bucket manifest reading (persist-data seam: SPACEFAST_STORAGE_BUCKETS_JSON).
putenv('SPACEFAST_STORAGE_BUCKETS_JSON=' . json_encode([
    ['id' => 'primary', 'endpoint' => 'https://minio.internal:9000', 'region' => 'us-east-1', 'bucket' => 'cold', 'urlStyle' => 'path'],
]));
$manifestRow = _stattic_s3_bucket_row('primary', true);
check($manifestRow !== null && $manifestRow['bucket'] === 'cold', 's3 bucket manifest: reads a row by id from the env-delivered JSON');
check(_stattic_s3_bucket_row('does-not-exist', true) === null, 's3 bucket manifest: unknown bucket id resolves to null');
putenv('SPACEFAST_STORAGE_BUCKETS_JSON');
check(_stattic_s3_bucket_row('primary', true) === null, 's3 bucket manifest: force-reloading after the env clears sees the change');


// --- Signed build artifacts ------------------------------------------------------------

$digest = str_repeat('a', 64);

// A real Ed25519 keypair, a real JWKS, a real signature. The bundle token is
// the platform's existing runtime JWT rather than a second signing scheme, so
// this exercises the same parse/verify path every other runtime token uses.
$keypair = sodium_crypto_sign_keypair();
$artifactSecretKey = sodium_crypto_sign_secretkey($keypair);
$artifactPublicKey = sodium_crypto_sign_publickey($keypair);
putenv('SPACEFAST_RUNTIME_INSTANCE_ID=rti_test');
putenv('SPACEFAST_RUNTIME_JWKS_B64=' . base64_encode(json_encode([
    'keys' => [[
        'kty' => 'OKP',
        'crv' => 'Ed25519',
        'x' => _stattic_base64url_encode($artifactPublicKey),
        'kid' => 'runtime',
        'alg' => 'EdDSA',
        'use' => 'sig',
    ]],
])));

$configuredJwksRoot = realpath(sys_get_temp_dir())
    . '/spacefast-jwks-' . bin2hex(random_bytes(8)) . '/.stattic/storage';
mkdir($configuredJwksRoot . '/runtime', 0700, true);
$configuredJwks = _stattic_runtime_jwks($configuredJwksRoot, false);
$configuredJwksCache = json_decode(
    (string) file_get_contents($configuredJwksRoot . '/runtime/jwks.json'),
    true
);
check(
    is_array($configuredJwks)
        && ($configuredJwksCache['source'] ?? null) === 'configured'
        && ($configuredJwksCache['jwks'] ?? null) === $configuredJwks,
    'runtime JWKS: configured provider keys persist for the no-bootstrap blob gate'
);
putenv('SPACEFAST_RUNTIME_JWKS_B64');
check(
    _stattic_runtime_jwks($configuredJwksRoot, false) === $configuredJwks,
    'runtime JWKS: the blob gate can read the persisted configured keys without provider bootstrap'
);
unlink($configuredJwksRoot . '/runtime/jwks.json');
rmdir($configuredJwksRoot . '/runtime');
rmdir($configuredJwksRoot);
rmdir(dirname($configuredJwksRoot));
rmdir(dirname($configuredJwksRoot, 2));
putenv('SPACEFAST_RUNTIME_JWKS_B64=' . base64_encode(json_encode([
    'keys' => [[
        'kty' => 'OKP',
        'crv' => 'Ed25519',
        'x' => _stattic_base64url_encode($artifactPublicKey),
        'kid' => 'runtime',
        'alg' => 'EdDSA',
        'use' => 'sig',
    ]],
])));

$mintBundleToken = static function (array $claims) use ($artifactSecretKey): string {
    $header = _stattic_base64url_encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'runtime'], JSON_UNESCAPED_SLASHES));
    $body = _stattic_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $body;
    return $signingInput . '.' . _stattic_base64url_encode(sodium_crypto_sign_detached($signingInput, $artifactSecretKey));
};

$validClaims = [
    'aud' => 'spacefast-functions-bundle',
    'runtime_instance_id' => 'rti_test',
    'space_id' => 'spc_1',
    'version_id' => 'ver_1',
    'sha256' => 'sha256:' . $digest,
    'exp' => time() + 3600,
    'nbf' => time() - 5,
];

check(
    _stattic_artifact_bundle_token_valid('/tmp', $mintBundleToken($validClaims), 'spc_1', 'ver_1', $digest),
    'bundle token: a correctly scoped, correctly signed token verifies'
);

// Every scope claim must be load-bearing. A token that survived a change of
// space, version, or digest would let one tenant replay a URL against another's
// compiled source.
foreach ([
    'space' => ['space_id' => 'spc_other'],
    'version' => ['version_id' => 'ver_other'],
    'digest' => ['sha256' => 'sha256:' . str_repeat('b', 64)],
    'runtime' => ['runtime_instance_id' => 'rti_other'],
    'audience' => ['aud' => 'stattic-runtime-file-fetch'],
] as $label => $override) {
    check(
        !_stattic_artifact_bundle_token_valid('/tmp', $mintBundleToken(array_merge($validClaims, $override)), 'spc_1', 'ver_1', $digest),
        'bundle token: refuses a token whose ' . $label . ' does not match'
    );
}

check(
    !_stattic_artifact_bundle_token_valid('/tmp', $mintBundleToken(array_merge($validClaims, ['exp' => time() - 1])), 'spc_1', 'ver_1', $digest),
    'bundle token: refuses an expired token'
);
$noExp = $validClaims;
unset($noExp['exp']);
check(
    !_stattic_artifact_bundle_token_valid('/tmp', $mintBundleToken($noExp), 'spc_1', 'ver_1', $digest),
    'bundle token: refuses a token with no expiry, so one cannot be unbounded by omission'
);

// A forged signature over correct claims must fail. This is the check that
// makes every claim above meaningful rather than advisory.
$forgedKeypair = sodium_crypto_sign_keypair();
$forgedHeader = _stattic_base64url_encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'runtime'], JSON_UNESCAPED_SLASHES));
$forgedBody = _stattic_base64url_encode(json_encode($validClaims, JSON_UNESCAPED_SLASHES));
$forgedInput = $forgedHeader . '.' . $forgedBody;
$forgedToken = $forgedInput . '.' . _stattic_base64url_encode(
    sodium_crypto_sign_detached($forgedInput, sodium_crypto_sign_secretkey($forgedKeypair))
);
check(
    !_stattic_artifact_bundle_token_valid('/tmp', $forgedToken, 'spc_1', 'ver_1', $digest),
    'bundle token: refuses a token signed by a key that is not the runtime key'
);

// --- Relay: the credential carries the grant ----------------------------------------

require_once __DIR__ . '/../engine/runtime/functions-relay.php';

// The relay verifies against a real version directory, because a token naming a
// version this origin never had is a replay from somewhere else.
$relayRoot = realpath(sys_get_temp_dir()) . '/sf-fx-relay-' . bin2hex(random_bytes(6)) . '/.stattic/storage';
mkdir($relayRoot . '/spaces/spc_1/versions/ver_1', 0o777, true);

$relayClaims = [
    'aud' => 'spacefast-functions-relay',
    'runtime_instance_id' => 'rti_test',
    'space_id' => 'spc_1',
    'version_id' => 'ver_1',
    'capabilities' => ['db.read', 'db.write'],
    'exp' => time() + 3600,
    'nbf' => time() - 5,
];

$verified = _stattic_functions_relay_claims($relayRoot, 'spc_1', $mintBundleToken($relayClaims));
check($verified !== null, 'relay token: a correctly scoped, correctly signed token verifies');
check(_stattic_functions_relay_grant($verified ?? [], SPACEFAST_FUNCTIONS_RELAY_BROKERS['database']['capabilities']) === ['db.read', 'db.write'], 'relay token: the grant comes from the credential, not from a lookup');

// Each scope claim is load-bearing. A credential that survived any of these
// changes would be ambient authority rather than a scoped grant.
foreach ([
    ['aud' => 'spacefast-functions-bundle'],
    ['space_id' => 'spc_other'],
    ['runtime_instance_id' => 'rti_other'],
    ['version_id' => 'ver_missing'],
    ['exp' => time() - 1],
] as $index => $tamper) {
    check(
        _stattic_functions_relay_claims($relayRoot, 'spc_1', $mintBundleToken(array_merge($relayClaims, $tamper))) === null,
        'relay token: refuses tampered claim set ' . $index
    );
}

// A token minted without an expiry is unbounded; requiring `exp` is what makes
// minting one by omission impossible.
$relayNoExp = $relayClaims;
unset($relayNoExp['exp']);
check(_stattic_functions_relay_claims($relayRoot, 'spc_1', $mintBundleToken($relayNoExp)) === null, 'relay token: refuses a token with no expiry');

// A forged or expired public request must be rejected before version
// storage is touched. The custom stream wrapper makes that filesystem effect
// observable without coupling the test to the verifier's implementation.
final class SpacefastRelayStatProbe
{
    public static int $calls = 0;

    public function url_stat(string $path, int $flags): array|false
    {
        self::$calls++;
        return false;
    }
}
check(stream_wrapper_register('sfrelaystat', SpacefastRelayStatProbe::class), 'relay token: installs the storage stat probe');
$expiredRelayClaims = array_merge($relayClaims, ['exp' => time() - 1]);
SpacefastRelayStatProbe::$calls = 0;
check(
    _stattic_functions_relay_claims('sfrelaystat://private', 'spc_1', $mintBundleToken($expiredRelayClaims)) === null,
    'relay token: refuses an expired token when storage is unavailable'
);
check(SpacefastRelayStatProbe::$calls === 0, 'relay token: refuses an expired token before probing version storage');
stream_wrapper_unregister('sfrelaystat');

// Signed by a key that is not the runtime's. Without this check every other
// assertion here is advisory.
$relayForged = _stattic_base64url_encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'runtime'], JSON_UNESCAPED_SLASHES))
    . '.' . _stattic_base64url_encode(json_encode($relayClaims, JSON_UNESCAPED_SLASHES));
$relayForged .= '.' . _stattic_base64url_encode(sodium_crypto_sign_detached($relayForged, sodium_crypto_sign_secretkey($forgedKeypair)));
check(_stattic_functions_relay_claims($relayRoot, 'spc_1', $relayForged) === null, 'relay token: refuses a token signed by a foreign key');

// A grant is only what the credential says. Anything else it might claim to
// authorize is not the relay's to serve.
foreach ([
    [null, []],
    ['db.read', []],
    // `next.cache` lives with the worker now: a credential claiming it gets no
    // relay authority from it.
    [['db.read', 'next.cache', 'fetch', 'log', ''], ['db.read']],
    [['db.write'], ['db.write']],
] as [$claimed, $expected]) {
    check(
        _stattic_functions_relay_grant(['capabilities' => $claimed], SPACEFAST_FUNCTIONS_RELAY_BROKERS['database']['capabilities']) === $expected,
        'relay grant: ' . json_encode($claimed) . ' authorizes ' . json_encode($expected)
    );
}
check(_stattic_functions_relay_grant([], SPACEFAST_FUNCTIONS_RELAY_BROKERS['database']['capabilities']) === [], 'relay grant: a credential that says nothing authorizes nothing');

@rmdir($relayRoot . '/spaces/spc_1/versions/ver_1');
@rmdir($relayRoot . '/spaces/spc_1/versions');
@rmdir($relayRoot . '/spaces/spc_1');
@rmdir($relayRoot . '/spaces');

putenv('SPACEFAST_RUNTIME_INSTANCE_ID');
putenv('SPACEFAST_RUNTIME_JWKS_B64');

$signed = $mintBundleToken($validClaims);
$parsed = _stattic_artifact_parse_bundle_path('/__spacefast/functions/b/' . $digest . '/' . $signed . '/bundle.json');
check(
    is_array($parsed) && $parsed['digest'] === $digest && $parsed['token'] === $signed,
    'bundle path: a well-formed signed URL parses into digest and token'
);

// The digest is concatenated into a filesystem path, so anything that is not
// exactly lowercase hex of the right length is refused before it gets there.
foreach ([
    '/__spacefast/functions/b/../../etc/passwd/a.b.c/bundle.json',
    '/__spacefast/functions/b/' . $digest . '/../bundle.json',
    '/__spacefast/functions/b/NOTHEX' . str_repeat('a', 58) . '/a.b.c/bundle.json',
    '/__spacefast/functions/b/' . str_repeat('a', 63) . '/a.b.c/bundle.json',
    '/__spacefast/functions/b/' . $digest . '/bundle.json',
    '/__spacefast/functions/b/' . $digest . '/a.b.c/extra/bundle.json',
    '/__spacefast/functions/b/' . $digest . '/a.b.c/other.json',
    // Not a compact JWS: refused lexically before any crypto runs.
    '/__spacefast/functions/b/' . $digest . '/notajwt/bundle.json',
    '/__spacefast/functions/b/' . $digest . '/a.b/bundle.json',
    '/__spacefast/functions/other/' . $digest . '/a.b.c/bundle.json',
    '/index.html',
] as $badPath) {
    check(
        _stattic_artifact_parse_bundle_path($badPath) === null,
        'bundle path: refuses ' . $badPath
    );
}

// ---------------------------------------------------------------------------------------
// Functions dispatch configuration: written beside the version's files, never inside them.

require_once __DIR__ . '/../engine/admin/management.php';

// The writer confines every path to private storage, so the fixture lives
// under a real `.stattic/storage` root rather than a bare temp directory.
// realpath'd: on macOS the temp dir is a symlink, and the confinement check
// compares a resolved ancestor against a lexically-derived root.
$configBase = realpath(sys_get_temp_dir()) . '/sf-fx-config-' . bin2hex(random_bytes(6)) . '/.stattic/storage';
$configRoot = $configBase . '/spaces/spc_fx/versions/ver_fx';
mkdir($configRoot . '/files', 0o777, true);

_stattic_runtime_write_functions_config_artifact($configRoot, [
    'runtimeKind' => 'functions',
    'artifact' => ['appName' => 'shop', 'mainModule' => 'index.js'],
    'host' => ['hostname' => 'fx.example', 'bundleUrl' => 'https://shop.example/b/x/t/bundle.json'],
    'grantedCapabilities' => ['db.read', 'db.write'],
    'relay' => ['url' => 'https://shop.example/__spacefast/functions/relay', 'token' => 'tok'],
    'usage' => ['url' => 'https://api.spacefast.app/v1/runtime/functions/usage', 'token' => 'usage-tok'],
    'purge' => ['token' => 'purge-tok'],
    'variables' => ['source' => 'spacefast-variables', 'names' => ['API_KEY']],
    'variableValues' => ['API_KEY' => 'k'],
    'inspect' => ['policy' => 'private'],
    // A key this engine does not know. It must not survive into the file the
    // origin reads to decide what a worker may do.
    'somethingNewer' => ['grant' => 'everything'],
]);

$writtenPath = $configRoot . '/functions/config.json';
check(is_file($writtenPath), 'functions config: written for a Functions finalize');
// Beside the file tree, not in it: a publish reaches files/ and nothing else,
// so no upload can create or amend the document that carries the grant.
check(!is_file($configRoot . '/files/functions/config.json'), 'functions config: never inside published content');
check(!is_file($configRoot . '/files/__spacefast/functions/config.json'), 'functions config: not reachable under the content prefix');

$written = json_decode((string) file_get_contents($writtenPath), true);
check(is_array($written), 'functions config: valid JSON');
check(($written['runtimeKind'] ?? null) === 'functions', 'functions config: records its runtime kind');
check(($written['host']['hostname'] ?? null) === 'fx.example', 'functions config: keeps the dispatch host');
check(($written['grantedCapabilities'] ?? null) === ['db.read', 'db.write'], 'functions config: keeps the grant verbatim');
check(($written['relay']['token'] ?? null) === 'tok', 'functions config: keeps the relay credential');
// The unknown-key drop below is exactly why this needs holding: a passthrough
// this writer does not name is a credential the origin never receives.
check(($written['usage']['token'] ?? null) === 'usage-tok', 'functions config: keeps the usage credential');
check(($written['purge']['token'] ?? null) === 'purge-tok', 'functions config: keeps the purge credential');
check(($written['variableValues']['API_KEY'] ?? null) === 'k', 'functions config: keeps resolved variable values');
check(!array_key_exists('somethingNewer', $written), 'functions config: drops keys this engine does not know');

// Fail closed on a malformed or truncated grant rather than anything.
foreach ([null, 'db.write', ['db.read', 5, ''], []] as $index => $badGrant) {
    $artifact = _stattic_runtime_functions_config_artifact(['grantedCapabilities' => $badGrant]);
    $expected = $index === 2 ? ['db.read'] : [];
    check($artifact['grantedCapabilities'] === $expected, 'functions config: grant ' . $index . ' fails closed');
    check($artifact['relay'] === null, 'functions config: absent relay reads as none, grant ' . $index);
}

// --- Functions routes: the compiled table is the only thing that dispatches ----------

// Subtree expands to the path plus its :splat descendant, invalid patterns and
// duplicates drop, and buckets mirror the Zero route shape.
$compiledRoutes = _stattic_runtime_functions_routes_artifact([
    ['method' => null, 'path' => '/api', 'subtree' => true],
    ['method' => 'post', 'path' => '/submit'],
    ['method' => null, 'path' => '/api', 'subtree' => true],
    ['method' => null, 'path' => 'no-leading-slash'],
    ['method' => null, 'path' => '/blog/:slug'],
    'not-a-route',
]);
check(($compiledRoutes['artifact_kind'] ?? null) === 'functions_routes', 'functions routes: compiled artifact kind');
check(
    array_map(static fn($entry) => $entry['pattern'], $compiledRoutes['exact']) === ['/api', '/submit'],
    'functions routes: literal paths land in the exact bucket once each'
);
check(($compiledRoutes['exact'][1]['method'] ?? null) === 'POST', 'functions routes: methods are normalized uppercase');
check(
    array_map(static fn($entry) => $entry['pattern'], $compiledRoutes['by_first_segment']['api'] ?? [])
        === ['/api/:splat'],
    'functions routes: subtree expands into a :splat descendant'
);
check(isset($compiledRoutes['by_first_segment']['blog']), 'functions routes: dynamic patterns bucket by first literal segment');
check($compiledRoutes['fallback'] === [], 'functions routes: invalid patterns are dropped, not compiled');

_stattic_runtime_write_functions_config_artifact($configRoot, [
    'artifact' => ['routes' => [
        ['method' => null, 'path' => '/api', 'subtree' => true],
        ['method' => 'POST', 'path' => '/submit'],
        ['method' => 'GET', 'path' => '/hello'],
        ['method' => 'PUT', 'path' => '/hello'],
    ]],
]);
check(is_file($configRoot . '/functions/routes.php'), 'functions routes: written beside the config, outside files/');
$fxRoutesVersionRoot = $configRoot . '/files';
check(
    _stattic_resolve_functions_route_action($fxRoutesVersionRoot, 'api/users/1', 'GET') === ['action' => 'dispatch_functions'],
    'functions routes: a subtree descendant dispatches'
);
check(
    _stattic_resolve_functions_route_action($fxRoutesVersionRoot, 'api', 'HEAD') === ['action' => 'dispatch_functions'],
    'functions routes: HEAD rides a method-free route'
);
check(
    _stattic_resolve_functions_route_action($fxRoutesVersionRoot, 'submit', 'POST') === ['action' => 'dispatch_functions'],
    'functions routes: a method-scoped route matches its method'
);
// A path the table owns at other methods is a router-built 405 whose Allow set
// is exactly the methods those entries declared — not a fall-through miss.
check(
    _stattic_resolve_functions_route_action($fxRoutesVersionRoot, 'submit', 'GET')
        === ['method_not_allowed' => true, 'allow' => ['POST']],
    'functions routes: a wrong method on a claimed path is a 405, not a miss'
);
check(
    _stattic_resolve_functions_route_action($fxRoutesVersionRoot, 'hello', 'DELETE')
        === ['method_not_allowed' => true, 'allow' => ['GET', 'HEAD', 'PUT']],
    'functions routes: the Allow set unions every method the path answers, GET carrying HEAD'
);
check(
    _stattic_resolve_functions_route_action($fxRoutesVersionRoot, 'nope', 'GET') === null,
    'functions routes: an unmatched path never dispatches'
);

array_map('unlink', glob($configRoot . '/functions/*') ?: []);
@rmdir($configRoot . '/functions');
@rmdir($configRoot . '/files');
@rmdir($configRoot);
@rmdir($configBase . '/spaces/spc_fx/versions');
@rmdir($configBase . '/spaces/spc_fx');
@rmdir($configBase . '/spaces');

// --- Functions dispatch: what the origin tells the host ------------------------------

$fxConfig = [
    'runtimeKind' => 'functions',
    'artifact' => [
        'mainModule' => 'index.js',
        'compatibilityDate' => '2026-07-01',
        'compatibilityFlags' => ['nodejs_compat', ''],
    ],
    'host' => ['hostname' => 'fx.example', 'bundleUrl' => 'https://shop.example/b/x/t/bundle.json'],
    'grantedCapabilities' => ['db.read', 'db.write'],
    'relay' => ['url' => 'https://shop.example/__spacefast/functions/relay', 'token' => 'relay-tok'],
    'usage' => ['url' => 'https://api.spacefast.app/v1/runtime/functions/usage', 'token' => 'usage-tok'],
    'purge' => ['token' => 'purge-tok'],
    'variableValues' => ['API_KEY' => 'k'],
];

$fxHeaders = _stattic_functions_dispatch_headers($fxConfig, 'spc_1', 'ver_1', 'fxr_abc', 'dispatch-secret', 'https://shop.example');
check($fxHeaders['sf-fx-bundle'] === 'https://shop.example/b/x/t/bundle.json', 'dispatch: carries the signed bundle URL');
check($fxHeaders['sf-fx-main'] === 'index.js', 'dispatch: carries the entry module');
check($fxHeaders['sf-fx-caps'] === 'db.read,db.write', 'dispatch: carries the grant the control plane decided');
check($fxHeaders['sf-fx-relay-token'] === 'relay-tok', 'dispatch: carries the relay credential');
check($fxHeaders['sf-fx-dispatch-token'] === 'dispatch-secret', 'dispatch: proves the dispatch came from a Spacefast origin');
// Empty flags are dropped rather than sent as a blank entry the runtime would reject.
check($fxHeaders['sf-fx-compat-flags'] === 'nodejs_compat', 'dispatch: drops empty compatibility flags');
check(base64_decode($fxHeaders['sf-fx-env']) === '{"API_KEY":"k"}', 'dispatch: carries resolved variables as base64 JSON');
check($fxHeaders['sf-fx-log'] === 'https://shop.example/__spacefast/functions/logs', 'dispatch: log intake is on the space origin');
// The second destination: counts go to the control plane, logs stay here.
check($fxHeaders['sf-fx-usage'] === 'https://api.spacefast.app/v1/runtime/functions/usage', 'dispatch: usage intake is on the control plane');
check($fxHeaders['sf-fx-usage-token'] === 'usage-tok', 'dispatch: usage carries its own credential, not the relay one');
// The purge lane is addressed like log intake — on whichever hostname the
// visitor hit — while its credential is its own, minted beside the relay's.
check($fxHeaders['sf-fx-purge'] === 'https://shop.example/__spacefast/functions/purge', 'dispatch: purge intake is on the request host origin');
check($fxHeaders['sf-fx-purge-token'] === 'purge-tok', 'dispatch: purge carries its own credential, not the relay one');

$fxEmptyEnv = $fxConfig;
$fxEmptyEnv['variableValues'] = [];
$fxEmptyEnvHeaders = _stattic_functions_dispatch_headers($fxEmptyEnv, 'spc_1', 'ver_1', 'r', 'd', 'https://shop.example');
check(base64_decode($fxEmptyEnvHeaders['sf-fx-env']) === '{}', 'dispatch: an empty environment remains a JSON object');

// A grant without a relay to serve it is dropped here, not sent and discovered
// as a mystery inside tenant code.
$fxNoRelay = $fxConfig;
$fxNoRelay['relay'] = null;
$fxHeadersNoRelay = _stattic_functions_dispatch_headers($fxNoRelay, 'spc_1', 'ver_1', 'r', 'd', 'https://shop.example');
check($fxHeadersNoRelay['sf-fx-caps'] === '', 'dispatch: database capabilities without a relay are not granted');
check(!isset($fxHeadersNoRelay['sf-fx-relay']), 'dispatch: no relay URL when there is no relay');
check(!isset($fxHeadersNoRelay['sf-fx-log']), 'dispatch: no log channel without the credential that authorizes it');
// Usage is not the relay's to gate: the credential is the control plane's, so a
// version that cannot log is still counted.
check($fxHeadersNoRelay['sf-fx-usage-token'] === 'usage-tok', 'dispatch: usage survives an unusable relay');
// Purge is not the relay's to gate either: a worker that can reach nothing
// else must still be able to evict the pages it rendered.
check($fxHeadersNoRelay['sf-fx-purge-token'] === 'purge-tok', 'dispatch: purge survives an unusable relay');

// A config predating purge credentials must still dispatch; its revalidates
// just wait out the CDN's own TTL.
$fxNoPurge = $fxConfig;
unset($fxNoPurge['purge']);
$fxHeadersNoPurge = _stattic_functions_dispatch_headers($fxNoPurge, 'spc_1', 'ver_1', 'r', 'd', 'https://shop.example');
check(!isset($fxHeadersNoPurge['sf-fx-purge']) && !isset($fxHeadersNoPurge['sf-fx-purge-token']), 'dispatch: no purge channel without a minted credential');

// A config predating usage reporting must still dispatch, uncounted.
$fxNoUsage = $fxConfig;
unset($fxNoUsage['usage']);
$fxHeadersNoUsage = _stattic_functions_dispatch_headers($fxNoUsage, 'spc_1', 'ver_1', 'r', 'd', 'https://shop.example');
check(!isset($fxHeadersNoUsage['sf-fx-usage']), 'dispatch: no usage intake without one configured');
check($fxHeadersNoUsage['sf-fx-bundle'] === 'https://shop.example/b/x/t/bundle.json', 'dispatch: a config without usage still dispatches');

// The platform's own surfaces are never the worker's to answer.
foreach (['/__spacefast/functions/relay', '/__spacefast/zero/run', '/__stattic/x', '/__span/y', '/__spacefast'] as $reserved) {
    check(!_stattic_functions_dispatchable($reserved, 'GET'), 'dispatch: refuses reserved path ' . $reserved);
}
foreach (['/', '/api/orders', '/posts/1', '/__spacefastish/ok'] as $ordinary) {
    check(_stattic_functions_dispatchable($ordinary, 'GET'), 'dispatch: allows ' . $ordinary);
}
check(!_stattic_functions_dispatchable('/', 'TRACE'), 'dispatch: refuses TRACE');

// A tenant's worker is as untrusted as a proxy origin, so it crosses the same
// shared relay: response headers that describe the hop we are terminating, that
// would let the worker reach outside its own response (x-accel-redirect serving
// an arbitrary internal path), or that would plant state on the space apex
// (Set-Cookie) do not survive. A public space's worker still owns its own
// caching while its response carries nothing that revokes the grant, so the
// lane decides no policy of its own and Cache-Control relays.
$fxPublicPolicy = _stattic_functions_response_cache_policy(false, [
    ['content-type', 'text/html'],
    ['vary', 'Accept-Encoding'],
    ['cache-control', 'public, s-maxage=30'],
]);
check($fxPublicPolicy['cache_control'] === null, 'dispatch cache: a clean worker response keeps worker-declared caching');
$fxRelayed = _stattic_relay_response_header_lines([
    ['content-length', '12'],
    ['transfer-encoding', 'chunked'],
    ['connection', 'keep-alive'],
    ['strict-transport-security', 'max-age=31536000'],
    ['content-encoding', 'br'],
    ['sf-fx-caps', 'db.read'],
    ['cf-ray', 'abc-DFW'],
    ['CF-Placement', 'local-DFW'],
    ['server', 'cloudflare'],
    ['nel', '{"report_to":"cf-nel","max_age":604800}'],
    ['report-to', '{"group":"cf-nel","endpoints":[{"url":"https://a.nel.cloudflare.com/report/v4"}]}'],
    ['set-cookie', 'tenant=value'],
    ['x-accel-redirect', '/.stattic/storage/spaces/spc_other/versions/ver_1/files/index.html'],
    ['x-sendfile', '/etc/passwd'],
    ['surrogate-control', 'max-age=86400'],
    ['content-type', 'text/html'],
    ['cache-control', 'public, s-maxage=30'],
    ['x-custom', 'value'],
], $fxPublicPolicy, _stattic_functions_relay_response_lane());
$fxRelayedNames = array_map(static fn (array $line): string => strtolower($line[0]), $fxRelayed);
foreach ([
    'content-length',
    'transfer-encoding',
    'connection',
    'strict-transport-security',
    'content-encoding',
    'sf-fx-caps',
    'cf-ray',
    'cf-placement',
    'server',
    'nel',
    'report-to',
    'set-cookie',
    'x-accel-redirect',
    'x-sendfile',
    'surrogate-control',
] as $stripped) {
    check(!in_array($stripped, $fxRelayedNames, true), 'dispatch: strips response header ' . $stripped);
}
check(in_array(['content-type', 'text/html'], $fxRelayed, true), 'dispatch: keeps the worker Content-Type');
check(in_array(['x-custom', 'value'], $fxRelayed, true), 'dispatch: keeps an ordinary worker header');
check(in_array(['cache-control', 'public, s-maxage=30'], $fxRelayed, true), 'dispatch: a public space keeps worker-declared caching');

// A Cloudflare-shaped value is what disqualifies these two, not the name.
$fxTenantReporting = _stattic_relay_response_header_lines([
    ['server', 'tenant-origin'],
    ['nel', '{"report_to":"tenant-errors","max_age":3600}'],
    ['report-to', '{"group":"tenant-errors","endpoints":[{"url":"https://errors.example/report"}]}'],
], $fxPublicPolicy, _stattic_functions_relay_response_lane());
check(count($fxTenantReporting) === 3, 'dispatch: a worker keeps its own Server and reporting endpoints');

// The wp.cloud edge keys a stored response on host+path+query and ignores
// Vary, so worker-declared caching survives only while the worker declares
// nothing a URL-keyed store cannot honor. `Vary: RSC` is the live case — a
// Next worker splitting flight and HTML payloads across one URL — and beside a
// public s-maxage it would be stored once and replayed to every variant, so
// every personalization or restriction signal revokes the grant to the proxy
// lane's exact no-store.
foreach ([
    [['vary', 'RSC']],
    [['vary', 'rsc, accept-encoding']],
    [['vary', '*']],
    [['set-cookie', 'session=tenant']],
    [['cache-control', 'private, max-age=30']],
    [['cache-control', 'no-cache']],
    [['pragma', 'no-cache']],
] as $fxRevokingResponse) {
    check(
        _stattic_functions_response_cache_policy(false, $fxRevokingResponse)['cache_control'] === STATTIC_CACHE_CONTROL_NO_STORE,
        'dispatch cache: worker response ' . json_encode($fxRevokingResponse) . ' revokes shared caching'
    );
}

// The full pipeline for the poisoned shape: the worker's public s-maxage never
// reaches the wire, the decided no-store replaces it exactly once, and Vary
// itself still relays — the visitor's own browser cache honors it even though
// the edge cannot.
$fxPoisonedHeaders = [
    ['content-type', 'text/x-component'],
    ['vary', 'RSC'],
    ['cache-control', 'public, s-maxage=600'],
];
$fxRevokedPolicy = _stattic_functions_response_cache_policy(false, $fxPoisonedHeaders);
$fxRevokedLines = _stattic_cache_policy_apply_lines(
    $fxRevokedPolicy,
    _stattic_relay_response_header_lines($fxPoisonedHeaders, $fxRevokedPolicy, _stattic_functions_relay_response_lane())
);
check(!in_array(['cache-control', 'public, s-maxage=600'], $fxRevokedLines, true), 'dispatch cache: a revoked worker Cache-Control never relays');
check(in_array(['Cache-Control', STATTIC_CACHE_CONTROL_NO_STORE], $fxRevokedLines, true), 'dispatch cache: the decided no-store replaces it');
check(count(array_filter($fxRevokedLines, static fn (array $line): bool => strtolower($line[0]) === 'cache-control')) === 1, 'dispatch cache: exactly one Cache-Control on a revoked relay');
check(in_array(['vary', 'RSC'], $fxRevokedLines, true), 'dispatch cache: Vary itself still relays for the visitor browser cache');

// Private spaces: the platform decides the policy, so the worker's cache
// metadata is replaced rather than relayed — a clean worker response included,
// because here the space's privacy is the revocation, not the worker's headers.
$fxPrivatePolicy = _stattic_functions_response_cache_policy(true, [['cache-control', 'public, s-maxage=30']]);
check($fxPrivatePolicy['cache_control'] === STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE, 'dispatch cache: a private space replaces worker cache policy outright');
$fxPrivateNames = array_map(
    static fn (array $line): string => strtolower($line[0]),
    _stattic_relay_response_header_lines(
        [['cache-control', 'public, s-maxage=30'], ['age', '9'], ['content-type', 'text/html']],
        $fxPrivatePolicy,
        _stattic_functions_relay_response_lane()
    )
);
check($fxPrivateNames === ['content-type'], 'dispatch: a private space drops worker cache metadata');

// Inbound: the platform's own dispatch headers can never be forged by a
// visitor, while Authorization belongs to the customer's application.
$GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] = [
    'Authorization' => 'Bearer tenant-api-key',
    'X-Sf-Authorization' => 'Bearer platform-secret',
    'Sf-Fx-Caps' => 'db.write',
    'Spacefast-Access-Sub' => 'user:forged',
    'Accept-Encoding' => 'gzip',
    'Host' => 'shop.example',
    'Content-Type' => 'application/json',
];
$fxForwarded = [];
foreach (_stattic_relay_request_headers(_stattic_functions_relay_request_lane()) as [$name, $value]) {
    $fxForwarded[strtolower($name)] = $value;
}
check($fxForwarded['authorization'] === 'Bearer tenant-api-key', 'dispatch: Authorization reaches the customer application unchanged');
check($fxForwarded['content-type'] === 'application/json', 'dispatch: ordinary request headers forward');
foreach (['x-sf-authorization', 'sf-fx-caps', 'spacefast-access-sub', 'accept-encoding', 'host'] as $denied) {
    check(!isset($fxForwarded[$denied]), 'dispatch: refuses to forward inbound ' . $denied);
}
$GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] = [
    'X-Injected' => "value\r\nX-Smuggled: yes",
];
$fxGuarded = _stattic_relay_request_headers(_stattic_functions_relay_request_lane());
check($fxGuarded === [['X-Injected', 'valueX-Smuggled: yes']], 'relay: one guard strips control characters from every forwarded value');
$GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] = [];

// A config that cannot produce a whole dispatch produces none: a half dispatch
// is a 502 where a 404 is the truth.
$fxRoot = realpath(sys_get_temp_dir()) . '/sf-fx-cfg-' . bin2hex(random_bytes(6));
mkdir($fxRoot . '/functions', 0o777, true);
mkdir($fxRoot . '/files', 0o777, true);
check(_stattic_functions_config($fxRoot . '/files') === null, 'dispatch config: absent when no config file exists');
check(!_stattic_version_has_functions($fxRoot . '/files'), 'dispatch config: a static version reports no worker');
foreach ([
    ['runtimeKind' => 'zero'],
    ['runtimeKind' => 'functions'],
    ['runtimeKind' => 'functions', 'host' => ['hostname' => 'fx.example'], 'artifact' => ['mainModule' => 'index.js', 'compatibilityDate' => '2026-07-01']],
    ['runtimeKind' => 'functions', 'host' => ['hostname' => 'fx.example', 'bundleUrl' => 'https://x/b'], 'artifact' => ['compatibilityDate' => '2026-07-01']],
] as $index => $incomplete) {
    file_put_contents($fxRoot . '/functions/config.json', (string) json_encode($incomplete));
    check(_stattic_functions_config($fxRoot . '/files') === null, 'dispatch config: incomplete config ' . $index . ' does not dispatch');
}
file_put_contents($fxRoot . '/functions/config.json', (string) json_encode($fxConfig));
check(_stattic_functions_config($fxRoot . '/files') !== null, 'dispatch config: a complete config dispatches');
check(_stattic_version_has_functions($fxRoot . '/files'), 'dispatch config: a Functions version reports a worker');
array_map('unlink', glob($fxRoot . '/functions/*') ?: []);
@rmdir($fxRoot . '/functions');
@rmdir($fxRoot . '/files');
@rmdir($fxRoot);

// The `__spacefast` namespace is private by default at the front door, so every
// visitor-facing Functions surface has to be named public or it silently 403s
// before reaching its handler.
check(_stattic_control_path_admits_visitor('/__spacefast/functions/relay'), 'front door: the relay is reachable');
check(_stattic_control_path_admits_visitor('/__spacefast/functions/logs'), 'front door: log intake is reachable');
check(_stattic_control_path_admits_visitor('/__spacefast/functions/b/' . str_repeat('a', 64) . '/t.o.k/bundle.json'), 'front door: signed bundle reads are reachable');
check(!_stattic_control_path_admits_visitor('/__spacefast/functions'), 'front door: the namespace root is not reachable');
// A tenant worker must never answer for the platform, whatever the spelling.
check(_stattic_path_is_reserved('/__spacefast/functions/relay'), 'front door: control paths stay reserved from tenant code');
check(_stattic_path_is_reserved('/__SF/redeem'), 'front door: a case-folded control namespace is still reserved');
check(!_stattic_path_is_reserved('/__spanish/page'), 'front door: reservation is whole-segment, not a string prefix');

// --- Append-only NDJSON log: the cap bounds a file, on_full decides what then --------

$logRoot = _stattic_job_runner_unit_temp_private_root();
$rotatingLog = _stattic_ndjson_log($logRoot . '/runtime/rotate.jsonl', [
    'max_bytes' => 64,
    'on_full' => STATTIC_NDJSON_LOG_ROTATE,
    'rotated' => $logRoot . '/runtime/rotate-%s.jsonl',
]);
_stattic_ndjson_log_append($rotatingLog, [['event' => 'first', 'pad' => str_repeat('p', 64)]]);
_stattic_ndjson_log_append($rotatingLog, [['event' => 'second']]);
$liveJournal = (string) file_get_contents($rotatingLog['path']);
check(
    str_contains($liveJournal, '"second"') && !str_contains($liveJournal, '"first"'),
    'ndjson log: a full file rolls aside and the append lands in a fresh one at the same path'
);
$rotatedGenerations = glob($logRoot . '/runtime/rotate-*.jsonl') ?: [];
check(
    count($rotatedGenerations) === 1
        && str_contains((string) file_get_contents($rotatedGenerations[0]), '"first"'),
    'ndjson log: the rolled-aside generation keeps every record it held'
);

// The tenant-facing setting: a worker in a log loop loses its newest lines
// instead of filling the space's disk.
$cappedLog = _stattic_ndjson_log($logRoot . '/runtime/capped.jsonl', ['max_bytes' => 64]);
_stattic_ndjson_log_append($cappedLog, [['event' => 'first', 'pad' => str_repeat('p', 64)]]);
_stattic_ndjson_log_append($cappedLog, [['event' => 'second']]);
check(
    !str_contains((string) file_get_contents($cappedLog['path']), '"second"'),
    'ndjson log: a full file with no rotation target refuses the append'
);

_stattic_ndjson_log_append(_stattic_ndjson_log($logRoot . '/runtime/empty.jsonl'), ['not a record', 42]);
check(!is_file($logRoot . '/runtime/empty.jsonl'), 'ndjson log: a batch carrying no record writes no file');

_stattic_job_runner_unit_rm_recursive(dirname(dirname($logRoot)));

// --- Runtime log writer: one line, into the stream the provider actually ships -------

// error_log() is the whole point of this writer, so the test redirects it and
// reads back what really landed rather than inspecting an intermediate.
$runtimeLogPath = sys_get_temp_dir() . '/sf-runtime-log-' . bin2hex(random_bytes(6)) . '.log';
$previousErrorLog = (string) ini_get('error_log');
ini_set('error_log', $runtimeLogPath);

/** @return list<array<string,mixed>> every record the writer put in the log, decoded. */
$runtimeLogRecords = static function () use ($runtimeLogPath): array {
    $records = [];
    foreach (explode("\n", (string) @file_get_contents($runtimeLogPath)) as $line) {
        $marker = strpos($line, SPACEFAST_RUNTIME_LOG_MARKER);
        if ($marker === false) {
            continue;
        }
        $decoded = json_decode(substr($line, $marker + strlen(SPACEFAST_RUNTIME_LOG_MARKER)), true);
        if (is_array($decoded)) {
            $records[] = $decoded;
        }
    }
    return $records;
};
$runtimeLogLast = static function () use ($runtimeLogRecords): array {
    $records = $runtimeLogRecords();
    return $records === [] ? [] : $records[count($records) - 1];
};
// `metadata` is the one field whose JSON *shape* matters — a caller reads it
// without a shape check — and assoc decoding erases the object/array
// distinction, so that one is asserted against the bytes on the line.
$runtimeLogLastLine = static function () use ($runtimeLogPath): string {
    $lines = array_values(array_filter(
        explode("\n", (string) @file_get_contents($runtimeLogPath)),
        static fn (string $line): bool => str_contains($line, SPACEFAST_RUNTIME_LOG_MARKER)
    ));
    return $lines === [] ? '' : $lines[count($lines) - 1];
};

_stattic_runtime_log_write(['level' => 'warn', 'message' => 'hello'], 'ver_writer');
$writtenRecord = $runtimeLogLast();
check(($writtenRecord['message'] ?? null) === 'hello', 'runtime log: the message survives the round trip through error_log');
check(($writtenRecord['level'] ?? null) === 'warn', 'runtime log: the level survives');
check(($writtenRecord['versionId'] ?? null) === 'ver_writer', 'runtime log: the origin stamps the version, the record never does');
check(is_string($writtenRecord['id'] ?? null) && $writtenRecord['id'] !== '', 'runtime log: every record is minted with an id');

// The version a worker claims is ignored: it may only file under the version
// its relay credential named.
_stattic_runtime_log_write(['message' => 'spoof', 'versionId' => 'ver_someone_else'], 'ver_writer');
check(($runtimeLogLast()['versionId'] ?? null) === 'ver_writer', 'runtime log: a record cannot file itself under another version');

// One record must be one line: the provider's shipper indexes by line, so a
// newline in tenant output would arrive as two documents.
_stattic_runtime_log_write(['message' => "first\nsecond\r\nthird"], 'ver_writer');
$multilineRecord = $runtimeLogLast();
check(($multilineRecord['message'] ?? null) === "first\nsecond\r\nthird", 'runtime log: a multi-line message keeps its newlines in the record');
check(count($runtimeLogRecords()) === 3, 'runtime log: a multi-line message is still exactly one log line');

foreach ([
    ['LOG', 'info', 'log aliases to info'],
    ['Warning', 'warn', 'warning aliases to warn'],
    ['ERROR', 'error', 'a shouted level still resolves'],
    ['trace', 'info', 'an unknown level defaults to info'],
    [42, 'info', 'a non-string level defaults to info'],
] as [$levelValue, $expectedLevel, $levelLabel]) {
    _stattic_runtime_log_write(['level' => $levelValue, 'message' => 'x'], 'ver_writer');
    check(($runtimeLogLast()['level'] ?? null) === $expectedLevel, "runtime log: {$levelLabel}");
}

// A logged object renders rather than vanishing into "Array".
_stattic_runtime_log_write(['message' => ['order' => 7]], 'ver_writer');
check(($runtimeLogLast()['message'] ?? null) === '{"order":7}', 'runtime log: a logged object renders rather than vanishing');

// Untrusted fields are dropped, never coerced, and metadata always answers as
// an object so a caller can read it without a shape check.
_stattic_runtime_log_write(['message' => 'x', 'metadata' => [1, 2], 'requestId' => 12, 'handlerName' => []], 'ver_writer');
$coercedRecord = $runtimeLogLast();
check(str_contains($runtimeLogLastLine(), '"metadata":{}'), 'runtime log: list metadata cannot answer as a JSON array');
check($coercedRecord['requestId'] === null && $coercedRecord['handlerName'] === null, 'runtime log: non-string ids are null, never coerced');

// Oversized metadata loses the decoration, never the message the author wrote.
_stattic_runtime_log_write(['message' => 'kept', 'metadata' => ['pad' => str_repeat('p', SPACEFAST_RUNTIME_LOG_METADATA_MAX_BYTES)]], 'ver_writer');
check(
    ($runtimeLogLast()['message'] ?? null) === 'kept' && str_contains($runtimeLogLastLine(), '"metadata":{}'),
    'runtime log: oversized metadata is dropped and the message survives'
);

// A message the shipper would truncate mid-JSON is one the reader can never
// parse, so the writer bounds it instead.
_stattic_runtime_log_write(['message' => str_repeat('m', SPACEFAST_RUNTIME_LOG_MESSAGE_MAX_BYTES * 2)], 'ver_writer');
check(strlen((string) ($runtimeLogLast()['message'] ?? '')) === SPACEFAST_RUNTIME_LOG_MESSAGE_MAX_BYTES, 'runtime log: an unbounded message is cut to the limit');

foreach ([
    [1753600000000, '2025-07-27T07:06:40.000Z', 'epoch milliseconds'],
    [1753600000, '2025-07-27T07:06:40.000Z', 'epoch seconds'],
    ['2026-07-27T10:11:12Z', '2026-07-27T10:11:12.000Z', 'an ISO instant'],
    ['2026-07-27T10:11:12+02:00', '2026-07-27T08:11:12.000Z', 'an offset instant normalized to UTC'],
] as [$timestampValue, $expectedTimestamp, $timestampLabel]) {
    _stattic_runtime_log_write(['message' => 'x', 'timestamp' => $timestampValue], 'ver_writer');
    check(($runtimeLogLast()['timestamp'] ?? null) === $expectedTimestamp, "runtime log: {$timestampLabel}");
}

// A broken clock must not cost the line.
_stattic_runtime_log_write(['message' => 'x', 'timestamp' => -5], 'ver_writer');
check(
    preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', (string) ($runtimeLogLast()['timestamp'] ?? '')) === 1,
    'runtime log: an impossible timestamp falls back to now rather than dropping the line'
);

ini_set('error_log', $previousErrorLog);
@unlink($runtimeLogPath);

$trustedProviderEnv = _stattic_zero_runner_base_env([
    'variableValues' => ['DATABASE_URL' => 'mysql://provider.internal/app'],
    'databaseUrlSource' => 'provider',
]);
check(
    _stattic_zero_internal_hosts_env('Management.Example.', 'https://API.Example/v1')
        === ['SPACEFAST_ZERO_INTERNAL_HOSTS' => 'management.example,api.example'],
    'tenant fetch: environment-specific Spacefast hosts reach the native egress guard'
);
check(
    ($trustedProviderEnv['SPACEFAST_ZERO_DATABASE_URL'] ?? null) === 'mysql://provider.internal/app'
        && ($trustedProviderEnv['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null) === 'provider',
    'db egress: trusted provider provenance preserves an internal provider URL'
);

$applicationEnv = _stattic_zero_runner_base_env([
    'variableValues' => ['DATABASE_URL' => 'mysql://8.8.8.8/app'],
    'databaseUrlSource' => 'untrusted',
]);
check(
    ($applicationEnv['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null) === 'application',
    'db egress: an unrecognized source cannot select provider authority'
);

// --- Canonical storage byte sink ----------------------------------------------------

$sinkPath = sys_get_temp_dir() . '/spacefast-stream-sink-' . bin2hex(random_bytes(8));
$sink = _stattic_runtime_stream_sink_open($sinkPath, 11, 5);
check(is_object($sink), 'stream sink opens a fresh staging file');
if (is_object($sink)) {
    check(_stattic_runtime_stream_sink_write($sink, 'hello ') === 6, 'stream sink accepts first chunk');
    check(_stattic_runtime_stream_sink_write($sink, 'world') === 5, 'stream sink accepts second chunk');
    $streamed = _stattic_runtime_stream_sink_finish($sink);
    check(($streamed['ok'] ?? false) === true, 'stream sink finishes successfully');
    check(($streamed['size'] ?? null) === 11, 'stream sink records exact byte size');
    check(($streamed['prefix'] ?? null) === 'hello', 'stream sink captures only the requested prefix');
    check(($streamed['sha256'] ?? null) === hash('sha256', 'hello world'), 'stream sink computes canonical sha256');
    check(file_get_contents($sinkPath) === 'hello world', 'stream sink writes the exact bytes');
    @unlink($sinkPath);
}

$overflowPath = sys_get_temp_dir() . '/spacefast-stream-overflow-' . bin2hex(random_bytes(8));
$overflowSink = _stattic_runtime_stream_sink_open($overflowPath, 3);
check(is_object($overflowSink), 'bounded stream sink opens');
if (is_object($overflowSink)) {
    check(_stattic_runtime_stream_sink_write($overflowSink, 'four') === false, 'bounded stream sink rejects overflow');
    $overflow = _stattic_runtime_stream_sink_finish($overflowSink);
    check(($overflow['reason'] ?? null) === 'too_large', 'bounded stream sink reports overflow reason');
    check(!file_exists($overflowPath), 'failed stream sink removes partial staging bytes');
}

// ---------------------------------------------------------------------------------------
// Zero database dump: which tables a management dump may address, and the exact
// SQL it issues for one. The rows themselves need a live MySQL (runtime/tests/
// zero-db-dump.test.ts covers that end); everything that decides what is
// reachable is decided from the version's own compiled artifacts, right here.

$dumpRoot = realpath(sys_get_temp_dir()) . '/sf-zero-dump-' . bin2hex(random_bytes(6));
mkdir($dumpRoot . '/zero/runs', 0o777, true);
mkdir($dumpRoot . '/zero/endpoints', 0o777, true);

$dumpRunArtifact = static function (array $tables): array {
    return [
        'format' => 'stattic.zero.run.v1',
        'runId' => 'query_notes',
        'kind' => 'run',
        'db' => ['schemaHash' => 'sha256:' . str_repeat('b', 64), 'migrationOperations' => [], 'tables' => $tables],
    ];
};
file_put_contents($dumpRoot . '/zero/runs-index.json', json_encode([
    'format' => 'stattic.zero.runs-index.v1',
    'artifact_kind' => 'zero_runs_index',
    'runs' => [
        'query_notes' => 'zero/runs/query_notes.json',
        // Index entries that do not resolve to a compiled run artifact inside
        // the version contribute nothing: a dump never follows a path out.
        'query_escape' => '../../../../etc/passwd',
        'query_absent' => 'zero/runs/gone.json',
    ],
], JSON_UNESCAPED_SLASHES));
file_put_contents($dumpRoot . '/zero/runs/query_notes.json', json_encode($dumpRunArtifact([
    'notes' => [
        'name' => 'notes',
        'physicalName' => 'sf_spc_zero_dump_notes_a7a32efcd0',
        'primaryKey' => 'id',
        'columns' => [
            'id' => ['physicalName' => 'note_id'],
            'createdAt' => ['physicalName' => 'note_created_at'],
            'title' => ['physicalName' => 'note_title'],
        ],
    ],
    // A logical name outside the contract's identifier shape: not a name a
    // caller could ask for, so it is dropped rather than reported.
    'not a name' => ['physicalName' => 'sf_spc_zero_dump_other_9ffe45e7b3'],
]), JSON_UNESCAPED_SLASHES));
file_put_contents($dumpRoot . '/zero/endpoints-index.json', json_encode([
    'format' => 'stattic.zero.endpoints-index.v1',
    'artifact_kind' => 'zero_endpoints_index',
    'endpoints' => ['GET /api/todos' => 'zero/endpoints/get_api_todos.json'],
], JSON_UNESCAPED_SLASHES));
file_put_contents($dumpRoot . '/zero/endpoints/get_api_todos.json', json_encode([
    'format' => 'stattic.zero.endpoint.v1',
    'endpointId' => 'GET /api/todos',
    'kind' => 'endpoint',
    // A capsule whose endpoint declares no primary key it can resolve.
    'db' => ['schemaHash' => 'sha256:placeholder', 'tables' => ['todos' => ['physicalName' => 'sf_spc_zero_dump_todos_b305997783']]],
], JSON_UNESCAPED_SLASHES));

$dumpArtifacts = _stattic_zero_db_program_artifacts($dumpRoot);
check(count($dumpArtifacts) === 2, 'zero dump: reads every compiled program artifact of the version');
$dumpScoped = _stattic_zero_db_scoped_tables('spc_zero_dump', $dumpArtifacts);
$dumpTables = $dumpScoped['tables'];
check(array_keys($dumpTables) === ['notes', 'todos'], 'zero dump: only well-formed declared tables are addressable');
check($dumpScoped['unscoped'] === [], 'zero dump: a capsule scoped to this space reports nothing out of scope');
check(($dumpTables['notes']['physical'] ?? null) === 'sf_spc_zero_dump_notes_a7a32efcd0', 'zero dump: table maps to its scoped physical name');
check(($dumpTables['notes']['orderBy'] ?? null) === 'note_id', 'zero dump: orders by the declared primary key column');
check(($dumpTables['notes']['createdAt'] ?? null) === 'note_created_at', 'zero export: maps the managed creation column to its legacy physical name');
check(($dumpTables['notes']['id'] ?? null) === 'note_id', 'zero export: maps the managed id column to its legacy physical name');
check($dumpTables['todos']['orderBy'] === null, 'zero dump: no declared primary key means no ORDER BY');

check(
    _stattic_zero_db_dump_operation($dumpTables['notes'], 25)
        === '{"sql":"SELECT * FROM `sf_spc_zero_dump_notes_a7a32efcd0` ORDER BY `note_id` LIMIT 25"}',
    'zero dump: issues a bounded, ordered read of the scoped table'
);
check(
    _stattic_zero_db_dump_operation($dumpTables['todos'], 1)
        === '{"sql":"SELECT * FROM `sf_spc_zero_dump_todos_b305997783` LIMIT 1"}',
    'zero dump: issues a bounded read without an order it cannot resolve'
);
check(
    _stattic_zero_db_export_operation($dumpTables['notes'], 2, ['createdAt' => '2026-01-01T00:00:00.000Z', 'id' => '7'])
        === '{"sql":"SELECT * FROM `sf_spc_zero_dump_notes_a7a32efcd0` WHERE (`note_created_at` > ? OR (`note_created_at` = ? AND `note_id` > ?)) ORDER BY `note_created_at` ASC, `note_id` ASC LIMIT 3","params":["2026-01-01T00:00:00.000Z","2026-01-01T00:00:00.000Z","7"]}',
    'zero export: keysets over declared legacy physical columns'
);

$persistedOnly = _stattic_zero_db_scoped_tables('spc_zero_dump', [], [
    'artifact' => [
        'server' => [
            'schema' => [
                'notes' => ['name' => 'notes', 'columns' => [], 'indexes' => []],
            ],
        ],
    ],
]);
check(array_keys($persistedOnly['tables']) === ['notes'], 'zero export: persisted capsule schema inventories a table with no programs');
check(
    ($persistedOnly['tables']['notes']['physical'] ?? null) === 'sf_spc_zero_dump_notes_a7a32efcd0',
    'zero export: persisted-only inventory derives the scoped physical table name'
);

// --- The scoping rule itself: what a physical name has to be to be addressed.
//
// The expected names below are the output of the canonical implementation,
// apps/control-plane/src/zero/db-scope.ts, for the same inputs — so these
// assertions are a parity pin between the two, not a restatement of the PHP.
check(
    _stattic_zero_db_expected_physical_name('spc_zero_dump', 'notes') === 'sf_spc_zero_dump_notes_a7a32efcd0',
    'zero dump: derives the control plane\'s scoped physical name'
);
check(
    _stattic_zero_db_expected_physical_name('spc_ZERO-Dump!!', 'Notes') === 'sf_spc_zero_dump_notes_c100dd526d',
    'zero dump: normalizes case and punctuation the way the control plane does'
);
check(
    _stattic_zero_db_expected_physical_name('spc_zero_dump', str_repeat('a', 80))
        === 'sf_spc_zero_dump_' . str_repeat('a', 36) . '_97b7ed23b4',
    'zero dump: truncates a long name to 64 characters, digest intact'
);
check(
    _stattic_zero_db_expected_physical_name('spc_other_space', 'notes') === 'sf_spc_other_space_notes_8d8f76bb72',
    'zero dump: another space derives a different name for the same table'
);

// A site's MySQL is shared. Every name a caller could hope to reach across the
// space boundary — a WordPress core table, a co-tenant's scoped table, a
// crafted fragment, a digest that does not belong to this table — is reported
// as out of scope rather than dumped, before any connection exists.
$dumpForeign = [[
    'format' => 'stattic.zero.run.v1',
    'db' => ['tables' => [
        'wp_users' => ['physicalName' => 'wp_users'],
        'neighbour' => ['physicalName' => 'sf_spc_other_space_notes_8d8f76bb72'],
        'crafted' => ['physicalName' => 'notes` UNION SELECT * FROM wp_users -- '],
        'notes' => ['physicalName' => 'sf_spc_zero_dump_notes_0000000000'],
    ]],
]];
$dumpForeignScoped = _stattic_zero_db_scoped_tables('spc_zero_dump', $dumpForeign);
check($dumpForeignScoped['tables'] === [], 'zero dump: no out-of-scope name is addressable');
check(
    $dumpForeignScoped['unscoped'] === ['crafted', 'neighbour', 'notes', 'wp_users'],
    'zero dump: every out-of-scope name is reported'
);

foreach ([['', 25], ['0', 25], ['abc', 25], ['-5', 25], ['7', 7], ['100', 100], ['5000', 100]] as [$raw, $expected]) {
    $_GET = $raw === '' ? [] : ['limit' => $raw];
    check(_stattic_zero_db_dump_limit() === $expected, "zero dump: limit '{$raw}' resolves to {$expected}");
}
$_GET = [];

// The digest travels in a typed contract field, so a placeholder that is not a
// sha256 is reported as absent rather than passed on to fail the caller's parse.
check(
    _stattic_zero_db_dump_schema_hash(['migrations' => ['schemaHash' => 'sha256:' . str_repeat('a', 64)]], $dumpArtifacts)
        === 'sha256:' . str_repeat('a', 64),
    'zero dump: reports the migration plan schema hash'
);
check(
    _stattic_zero_db_dump_schema_hash(['migrations' => ['schemaHash' => 'sha256:broker']], $dumpArtifacts)
        === 'sha256:' . str_repeat('b', 64),
    'zero dump: falls back to the compiled schema hash when the plan carries none it can report'
);
check(_stattic_zero_db_dump_schema_hash([], []) === null, 'zero dump: a version with no schema reports none');

array_map('unlink', glob($dumpRoot . '/zero/runs/*') ?: []);
array_map('unlink', glob($dumpRoot . '/zero/endpoints/*') ?: []);
array_map('unlink', glob($dumpRoot . '/zero/*.json') ?: []);
@rmdir($dumpRoot . '/zero/runs');
@rmdir($dumpRoot . '/zero/endpoints');
@rmdir($dumpRoot . '/zero');
@rmdir($dumpRoot);

// --- wp.cloud server-file offload ------------------------------------------------------

require_once __DIR__ . '/../engine/shared/server-file.php';

$serverFileRoot = sys_get_temp_dir() . '/stattic-server-file-' . getmypid();
@mkdir($serverFileRoot . '/spaces/space one/files', 0700, true);
@mkdir($serverFileRoot . '/runtime', 0700, true);
file_put_contents($serverFileRoot . '/spaces/space one/files/a b.bin', 'x');
file_put_contents($serverFileRoot . '/runtime/index.php', '<?php');
check(
    _stattic_server_file_uri($serverFileRoot . '/spaces/space one/files/a b.bin', $serverFileRoot)
        === '/.stattic/storage/spaces/space%20one/files/a%20b.bin',
    'server file: maps a confined storage file to the fixed provider URI'
);
check(
    _stattic_server_file_uri($serverFileRoot . '/spaces/space one/../../etc/passwd', $serverFileRoot) === null,
    'server file: rejects paths outside the private root'
);
check(
    _stattic_server_file_uri($serverFileRoot . '/spaces/space one/files/missing.bin', $serverFileRoot) === null,
    'server file: rejects missing files'
);
check(
    _stattic_server_file_uri($serverFileRoot . '/runtime/index.php', $serverFileRoot) === null,
    'server file: rejects PHP-like storage paths'
);
check(
    _stattic_server_file_uri($serverFileRoot . '/spaces', $serverFileRoot) === null,
    'server file: rejects a directory target'
);
@unlink($serverFileRoot . '/spaces/space one/files/a b.bin');
@unlink($serverFileRoot . '/runtime/index.php');
@rmdir($serverFileRoot . '/spaces/space one/files');
@rmdir($serverFileRoot . '/spaces/space one');
@rmdir($serverFileRoot . '/spaces');
@rmdir($serverFileRoot . '/runtime');
@rmdir($serverFileRoot);

// --- DB broker value encoding -------------------------------------------------------

// These are the exact strings serde_json 1.0.151 prints for the same doubles,
// captured from the encoder db.rs feeds. PHP agrees on the shortest
// round-tripping digits but not the layout (it prints 1.0 as `1`, -0.0 as `-0`
// and 1e25 as `1.0e+25`), so the reformatter is pinned here rather than only
// through the MySQL differential.
foreach ([
    ['0.0', 0.0],
    ['-0.0', -0.0],
    ['1.0', 1.0],
    ['100.0', 100.0],
    ['0.1', 0.1],
    ['1.5', 1.5],
    ['0.30000000000000004', 0.30000000000000004],
    ['1000000000000000.0', 1e15],
    ['1e+16', 1e16],
    ['1e+21', 1e21],
    ['1e+25', 1e25],
    ['0.0001', 1e-4],
    ['0.00001', 1e-5],
    ['1e-6', 1e-6],
    ['1e-7', 1e-7],
    ['1.7976931348623157e+308', 1.7976931348623157e308],
    ['5e-324', 5e-324],
    ['2.2250738585072014e-308', 2.2250738585072014e-308],
    ['-1.25e-9', -1.25e-9],
    ['1234567890.12345', 1234567890.12345],
    ['9007199254740992.0', 9007199254740992.0],
    ['0.10000000149011612', 0.10000000149011612],
    ['3.4028234663852886e+38', 3.4028234663852886e38],
] as [$expected, $value]) {
    $actual = _stattic_db_broker_float_literal($value);
    check($actual === $expected, "db broker float: {$expected} (got {$actual})");
}
// serde_json cannot hold a non-finite float and writes null in its place.
check(_stattic_db_broker_float_literal(INF) === 'null', 'db broker float: infinity becomes null');
check(_stattic_db_broker_float_literal(NAN) === 'null', 'db broker float: NaN becomes null');

// mysqlnd renders only the fractional digits a column declares; the wire value
// always carries six, which is what db.rs formats.
foreach ([
    ['2024-01-15', '2024-01-15T00:00:00.000000Z'],
    ['2024-01-15 10:20:30', '2024-01-15T10:20:30.000000Z'],
    ['2024-01-15 10:20:30.123456', '2024-01-15T10:20:30.123456Z'],
    ['2024-01-15 10:20:30.500', '2024-01-15T10:20:30.500000Z'],
    ['0000-00-00', '0000-00-00T00:00:00.000000Z'],
] as [$input, $expected]) {
    check(_stattic_db_broker_datetime($input) === $expected, "db broker datetime: {$input}");
}

// MySQL sends TIME as days plus an hour remainder; mysqlnd flattens it to total
// hours, and db.rs prints the split form.
foreach ([
    ['10:20:30.123456', '0 10:20:30.123456'],
    ['10:20:30', '0 10:20:30.000000'],
    ['100:20:30', '4 04:20:30.000000'],
    ['-00:00:01', '-0 00:00:01.000000'],
    ['-100:20:30', '-4 04:20:30.000000'],
] as [$input, $expected]) {
    check(_stattic_db_broker_time($input) === $expected, "db broker time: {$input}");
}

// Bytes become a string when they are valid UTF-8 and standard base64 when they
// are not, which is the fallback db.rs takes when String::from_utf8 fails.
check(_stattic_db_broker_bytes_json('abc') === '"abc"', 'db broker bytes: ascii stays a string');
check(_stattic_db_broker_bytes_json('') === '""', 'db broker bytes: empty stays a string');
check(_stattic_db_broker_bytes_json("\0") === '"\u0000"', 'db broker bytes: NUL is a valid UTF-8 string');
check(_stattic_db_broker_bytes_json("h\xc3\xa9llo") === "\"h\xc3\xa9llo\"", 'db broker bytes: UTF-8 passes through raw');
check(_stattic_db_broker_bytes_json("\xff") === '"/w=="', 'db broker bytes: invalid UTF-8 becomes base64');
check(_stattic_db_broker_bytes_json("\xc3\x28") === '"wyg="', 'db broker bytes: truncated sequence becomes base64');

// BIT(M) arrives from mysqlnd as an integer; db.rs sees the ceil(M/8) wire
// bytes, so they are rebuilt before encoding.
check(_stattic_db_broker_bit_bytes(1, 1) === "\x01", 'db broker bit: BIT(1)');
check(_stattic_db_broker_bit_bytes(255, 8) === "\xff", 'db broker bit: BIT(8)');
check(_stattic_db_broker_bit_bytes(65537, 17) === "\x01\x00\x01", 'db broker bit: BIT(17)');
check(
    _stattic_db_broker_bit_bytes('18446744073709551615', 64) === str_repeat("\xff", 8),
    'db broker bit: BIT(64) delivered as digits'
);

// mysqli reports "no rows" as -1 where the wire carried u64::MAX.
check(_stattic_db_broker_unsigned_literal(-1) === '18446744073709551615', 'db broker u64: -1 is u64::MAX');
check(_stattic_db_broker_unsigned_literal(0) === '0', 'db broker u64: zero');
check(_stattic_db_broker_unsigned_literal(42) === '42', 'db broker u64: small value');
check(
    _stattic_db_broker_unsigned_literal('18446744073709551615') === '18446744073709551615',
    'db broker u64: digits pass through'
);

// PHP's own trim() eats NUL, which is the one byte the SQL guard exists to
// catch; Rust's str::trim does not.
check(_stattic_db_broker_trim('  SELECT 1  ') === 'SELECT 1', 'db broker trim: ascii whitespace');
check(_stattic_db_broker_trim("SELECT 1\0") === "SELECT 1\0", 'db broker trim: a trailing NUL survives');
check(
    _stattic_db_broker_trim("\xc2\xa0SELECT 1\xe2\x80\xa8") === 'SELECT 1',
    'db broker trim: Unicode whitespace'
);

// The response envelopes are assembled as text, so their key order is fixed at
// the sorted order serde_json's BTreeMap-backed maps produce.
check(
    _stattic_db_broker_execute('not json')
        === '{"code":"zero_db_operation_invalid","message":"Zero DB operation could not be parsed.","ok":false}',
    'db broker envelope: error keys are sorted and ok is last'
);
check(
    _stattic_db_broker_execute(str_repeat('x', STATTIC_DB_OPERATION_MAX_BYTES + 1))
        === '{"code":"zero_db_operation_too_large","message":"Zero DB operation exceeded the request size limit.","ok":false}',
    'db broker envelope: the size limit is checked before parsing'
);

// Query variants normalize to one path, but only pure assets use URI purge.
// Mutable document keys widen to domain purge because one POP accepting a URI
// invalidation does not prove every visitor-facing key converged.
check(
    _stattic_runtime_purge_path_list([
        '/page?utm=a',
        '/page?utm=b#section',
        '/assets/app.css?v=2',
        'not-a-path',
        "/bad\npath",
    ]) === ['/page', '/assets/app.css'],
    'purge planner strips query and fragment before deduplication'
);
check(
    _stattic_runtime_purge_scope(['https://one.test/page']) === 'domain'
        && _stattic_runtime_purge_scope(['https://one.test/index.html']) === 'domain'
        && _stattic_runtime_purge_scope(['https://one.test/data.json']) === 'domain',
    'purge planner widens mutable document paths to the domain lane'
);
check(
    _stattic_runtime_purge_scope(['https://one.test/assets/app.css']) === 'urls',
    'purge planner keeps pure assets in the URI lane'
);
check(
    _stattic_runtime_purge_scope(array_fill(0, STATTIC_RUNTIME_PURGE_URL_MAX, 'https://one.test/app.js')) === 'urls',
    'purge planner accepts the provider 300 URL boundary'
);
check(
    _stattic_runtime_purge_scope(array_fill(0, STATTIC_RUNTIME_PURGE_URL_MAX + 1, 'https://one.test/app.js')) === 'domain',
    'purge planner escalates above the provider 300 URL boundary'
);
check(
    _stattic_runtime_purge_scope([]) === 'domain',
    'purge planner fails closed when no addressable URL exists'
);
check(
    _stattic_runtime_purge_provider_accepted(true)
        && !_stattic_runtime_purge_provider_accepted(false)
        && !_stattic_runtime_purge_provider_accepted(null),
    'purge provider receipt accepts only an explicit true result'
);

// --- Purge scope derivation ----------------------------------------------------------
//
// A content activation purges ONLY the live route's serving hostnames. The
// version-pinned hosts serve immutable bytes that an activation cannot change,
// so purging them would only evict warm copies; the full-alias sweep is owed
// exactly once, on the public→private access edge, where every alias may hold
// year-TTL copies of formerly-public HTML.
$scopeIntent = [
    'space_id' => 'spc_scope',
    'routes' => [
        ['hostname' => 'v3--site.view.fast', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'version', 'version_id' => 'ver_c'], 'options' => ['noindex' => true]],
        ['hostname' => 'site.view.fast', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'route', 'route_name' => 'production'], 'options' => ['noindex' => false]],
        ['hostname' => 'v12--site.view.fast', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'version', 'version_id' => 'ver_l'], 'options' => ['noindex' => true]],
        ['hostname' => 'custom.example', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'route', 'route_name' => 'production'], 'options' => ['noindex' => false]],
        ['hostname' => 'ver-legacy--site.view.fast', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'version', 'version_id' => 'ver_x'], 'options' => ['noindex' => true]],
        ['hostname' => 'www.custom.example', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'host_redirect', 'destination' => 'https://custom.example', 'status' => 308], 'options' => ['noindex' => false]],
        ['hostname' => 'proxy.example', 'path_prefix' => '/', 'mount' => 'strip_prefix', 'target' => ['type' => 'host_proxy', 'upstream' => 'https://upstream.example'], 'options' => ['noindex' => false]],
    ],
];
$scopeRoot = realpath(sys_get_temp_dir()) . '/sf-purge-scope-' . bin2hex(random_bytes(6)) . '/.stattic/storage';
mkdir(_stattic_space_root($scopeRoot, 'spc_scope'), 0o777, true);
file_put_contents(
    _stattic_space_root($scopeRoot, 'spc_scope') . '/hostname-intent.json',
    json_encode($scopeIntent)
);
check(
    _stattic_runtime_affected_intent_hostnames($scopeRoot, 'spc_scope', 'production')
        === ['site.view.fast', 'custom.example', 'www.custom.example', 'proxy.example'],
    'activation purge scope keeps production serving hosts and never a version-pinned host'
);
check(
    _stattic_runtime_affected_intent_hostnames($scopeRoot, 'spc_scope', null, 'ver_l')
        === ['v12--site.view.fast'],
    'version-scoped derivation names only that version\'s own pinned host'
);

$scopePublic = ['v' => 4, 'public' => true, 'authorizationDigest' => str_repeat('a', 64)];
$scopePrivate = ['public' => false] + $scopePublic;
check(
    _stattic_runtime_exposure_became_private($scopePublic, $scopePrivate)
        && _stattic_runtime_exposure_became_private($scopePublic, null),
    'public→private (and public→unknown, fail-closed) triggers the access sweep'
);
check(
    !_stattic_runtime_exposure_became_private($scopePrivate, $scopePublic)
        && !_stattic_runtime_exposure_became_private($scopePublic, $scopePublic)
        && !_stattic_runtime_exposure_became_private($scopePrivate, $scopePrivate)
        && !_stattic_runtime_exposure_became_private(null, $scopePrivate),
    'no other exposure transition triggers the access sweep'
);

check(
    _stattic_runtime_access_sweep_hostnames($scopeIntent, [
        'hostnames' => ['retired.example', 'custom.example'],
    ]) === [
        // Live serving hosts lead, version hosts follow newest→oldest, the
        // underivable legacy shape and redirect/proxy hosts after, tombstoned
        // hostnames last — a tombstone duplicating a served host keeps its
        // serving-order slot.
        'site.view.fast',
        'custom.example',
        'v12--site.view.fast',
        'v3--site.view.fast',
        'ver-legacy--site.view.fast',
        'www.custom.example',
        'proxy.example',
        'retired.example',
    ],
    'access sweep covers every hostname, ordered live-first then newest→oldest'
);
check(
    _stattic_runtime_access_sweep_hostnames(null, null) === []
        && _stattic_runtime_access_sweep_hostnames(null, ['hostnames' => ['t.example']]) === ['t.example'],
    'access sweep tolerates a space with no stored intent'
);
_stattic_job_runner_unit_rm_recursive(dirname(dirname($scopeRoot)));

// --- Version cache retirement -------------------------------------------------------
//
// A version id is written exactly once, so the catalog and the blob gate's sha
// map are cached pool-wide for their full TTL. Deleting a version is the one
// event that breaks that promise: without an explicit retirement, a blob-gate
// link minted a moment earlier keeps serving the deleted version's bytes (which
// outlive the directory in the space's shared CAS) on every worker that cached
// it. This section proves the pool-visible state, which is the state that
// outlives the deleting request — the per-process memos are per request.
//
// APCu is a pool cache, not something a CLI process has: this stands in an
// in-memory triple with the same semantics when the extension is absent, and
// uses the real one when it is there. Declared LAST in this file on purpose, so
// nothing above it changes behavior.
if (!function_exists('apcu_fetch')) {
    $GLOBALS['sf_test_apcu'] = [];
    function apcu_fetch(string $key, &$success = null): mixed
    {
        $success = array_key_exists($key, $GLOBALS['sf_test_apcu']);
        return $success ? $GLOBALS['sf_test_apcu'][$key] : false;
    }
    function apcu_store(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['sf_test_apcu'][$key] = $value;
        return true;
    }
    function apcu_delete(string $key): bool
    {
        unset($GLOBALS['sf_test_apcu'][$key]);
        return true;
    }
    function sf_test_apcu_has(string $key): bool
    {
        return array_key_exists($key, $GLOBALS['sf_test_apcu']);
    }
} else {
    function sf_test_apcu_has(string $key): bool
    {
        apcu_fetch($key, $hit);
        return $hit === true;
    }
}

$catalogRoot = realpath(sys_get_temp_dir()) . '/sf-version-cache-' . bin2hex(random_bytes(6)) . '/.stattic/storage';
$catalogSpaceId = 'spc_cache_' . bin2hex(random_bytes(4));
$catalogVersionId = 'ver_cache_' . bin2hex(random_bytes(4));
$catalogVersionRoot = _stattic_version_root($catalogRoot, $catalogSpaceId, $catalogVersionId);
mkdir($catalogVersionRoot, 0o777, true);
$sourceSha = str_repeat('a', 64);
$servedSha = str_repeat('b', 64);
$variantSha = str_repeat('c', 64);
file_put_contents($catalogVersionRoot . '/metadata.json', json_encode([
    'spaceId' => $catalogSpaceId,
    'versionId' => $catalogVersionId,
    STATTIC_RUNTIME_VERSION_CATALOG_KEY => [
        'format' => STATTIC_RUNTIME_VERSION_CATALOG_FORMAT,
        'spaceId' => $catalogSpaceId,
        'versionId' => $catalogVersionId,
        'paths' => [
            'index.html' => [
                'source' => ['sha256' => $sourceSha, 'size' => 12, 'contentType' => 'text/html'],
                'served' => ['sha256' => $servedSha, 'size' => 20, 'contentType' => 'text/html'],
                'public' => true,
            ],
        ],
        'variants' => [
            'preview' => [
                'index.html' => ['sha256' => $variantSha, 'size' => 30, 'contentType' => 'text/html'],
            ],
        ],
    ],
]));

$catalogKey = _stattic_runtime_version_catalog_cache_key($catalogSpaceId, $catalogVersionId);
$blobShasKey = _stattic_runtime_version_blob_shas_cache_key($catalogSpaceId, $catalogVersionId);
$lanes = _stattic_runtime_version_blob_shas($catalogRoot, $catalogSpaceId, $catalogVersionId);
check(
    $lanes[STATTIC_RUNTIME_VERSION_BLOB_BASE_LANE] === [$sourceSha => 'text/html', $servedSha => 'text/html'],
    'gate sha map: the base lane holds both identities of every catalogued path'
);
check(
    $lanes['preview'] === [$variantSha => 'text/html'],
    'gate sha map: a channel lane holds that channel\'s variant objects'
);
check(
    _stattic_runtime_version_blob_shas($catalogRoot, $catalogSpaceId, 'ver_absent') === null,
    'gate sha map: a version with no readable catalog resolves nothing'
);
// APCu loaded but disabled for CLI (the CI runner image) makes the real
// apcu_store a silent no-op the polyfill cannot shadow — extension functions
// are not redeclarable. Probe whether stores actually land; the positive
// cache assertions are only provable when they do. FPM (where the engine
// serves) always has APCu enabled; this gate is about the test environment.
apcu_store('sf_test_store_probe', 1);
$apcuStores = sf_test_apcu_has('sf_test_store_probe');
apcu_delete('sf_test_store_probe');
check(!$apcuStores || sf_test_apcu_has($catalogKey), 'reading a catalog caches it pool-wide');
check(
    !$apcuStores || sf_test_apcu_has($blobShasKey),
    'every gate lane rides in ONE pool entry per version, so no channel-keyed entry can outlive it'
);

_stattic_runtime_forget_version_caches($catalogSpaceId, $catalogVersionId);
check(
    !sf_test_apcu_has($catalogKey) && !sf_test_apcu_has($blobShasKey),
    'deleting a version retires both of its pool entries, so a minted link stops resolving'
);
_stattic_job_runner_unit_rm_recursive(dirname(dirname($catalogRoot)));

// --- Host-level purge dispatch (_stattic_runtime_purge_hosts_now) ----------------------
// Order matters: the fallback paths are proven BEFORE the Edge_Cache stub class
// exists, because class_exists() cannot be undone within one process.

$purgeStubEdge = new class {
    public array $uris = [];
    public function purge_uris_now(array $urls, string $reason): bool
    {
        $this->uris[] = $urls;
        return true;
    }
};
check(
    _stattic_runtime_purge_hosts_now($purgeStubEdge, [], 'route_updated') === true
        && $purgeStubEdge->uris === [],
    'host purge: an empty hostname set is accepted without any provider call'
);
check(
    _stattic_runtime_purge_hosts_now($purgeStubEdge, ['a.example', 'b.example'], 'route_updated') === true
        && $purgeStubEdge->uris === [['https://a.example/', 'https://b.example/']],
    'host purge: without the provider library, hostnames fall back to root-URI purges'
);
$bareEdge = new class {};
check(
    _stattic_runtime_purge_hosts_now($bareEdge, ['a.example'], 'route_updated') === false,
    'host purge: a plugin variant with no usable method reports non-acceptance, never silent success'
);

// Conditional declaration: an unconditional top-level class binds at compile
// time and would make class_exists() true during the fallback checks above.
if (!class_exists('Edge_Cache')) {
    class Edge_Cache
    {
        const HOST_CACHE = 'edge_cache_host';
        public static array $batches = [];
        public static bool $accept = true;
        public static function edge_cache_purge(array $pathsPerBuckets, array $logTags = []): bool
        {
            self::$batches[] = $pathsPerBuckets;
            return self::$accept;
        }
    }
}
$manyHosts = array_map(static fn(int $i): string => "v{$i}--space.example", range(1, 120));
check(
    _stattic_runtime_purge_hosts_now($purgeStubEdge, $manyHosts, 'access_transition') === true
        && count(Edge_Cache::$batches) === 3
        && array_keys(Edge_Cache::$batches[0]) === ['edge_cache_host']
        && count(Edge_Cache::$batches[0]['edge_cache_host']) === STATTIC_RUNTIME_PURGE_HOST_BATCH_SIZE
        && Edge_Cache::$batches[2]['edge_cache_host'] === array_slice($manyHosts, 100),
    'host purge: the provider library takes host-bucket batches, chunked, preserving caller order'
);
Edge_Cache::$accept = false;
Edge_Cache::$batches = [];
check(
    _stattic_runtime_purge_hosts_now($purgeStubEdge, ['a.example'], 'route_updated') === false,
    'host purge: a rejected batch reports non-acceptance so the durable record retries'
);

// ---------------------------------------------------------------------------------------

// The purge child env strips the FastCGI request variables FPM exports (a
// leaked REQUEST_METHOD makes the loader hijack the CLI worker) while keeping
// the host-supplied values wp-config needs.
putenv('REQUEST_METHOD=POST');
putenv('HTTP_HOST=example.test');
putenv('QUERY_STRING=a=1');
putenv('SPACEFAST_TEST_HOST_VALUE=kept');
$purgeChildEnv = _stattic_runtime_purge_child_env();
check(!array_key_exists('REQUEST_METHOD', $purgeChildEnv), 'purge child env: strips REQUEST_METHOD');
check(!array_key_exists('HTTP_HOST', $purgeChildEnv), 'purge child env: strips HTTP_ vars');
check(!array_key_exists('QUERY_STRING', $purgeChildEnv), 'purge child env: strips QUERY_STRING');
check(($purgeChildEnv['SPACEFAST_TEST_HOST_VALUE'] ?? null) === 'kept', 'purge child env: keeps host-supplied values');
check(array_key_exists('PATH', $purgeChildEnv) || getenv('PATH') === false, 'purge child env: keeps PATH');
putenv('REQUEST_METHOD');
putenv('HTTP_HOST');
putenv('QUERY_STRING');
putenv('SPACEFAST_TEST_HOST_VALUE');

if ($failures !== []) {
    fwrite(STDERR, "unit.php FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}
echo "unit.php ok ({$assertions} assertions)\n";
