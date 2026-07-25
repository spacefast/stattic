<?php
declare(strict_types=1);

// Pure-function unit tests for runtime policy modules, run directly with
// `php runtime/tests/unit.php` (no server, no network — literal IPs only).

require_once __DIR__ . '/../engine/shared/context.php'; // engine identity + config_value helpers
require_once __DIR__ . '/../engine/shared/egress.php';
require_once __DIR__ . '/../engine/shared/safety.php';
require_once __DIR__ . '/../engine/runtime/headers.php'; // also loads runtime/rules.php
require_once __DIR__ . '/../engine/runtime/proxy.php';
require_once __DIR__ . '/../engine/runtime/php-manifest.php';
require_once __DIR__ . '/../engine/admin/upload-policy.php';
require_once __DIR__ . '/../engine/admin/upload.php';
require_once __DIR__ . '/../engine/runtime/access-rules.php'; // unified Rule matchers (pure helpers)
require_once __DIR__ . '/../engine/shared/storage.php';
require_once __DIR__ . '/../engine/admin/jobs.php'; // job runner policy (§22) — pure functions only here
require_once __DIR__ . '/../engine/admin/transfer.php';

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
check($sourceUrl['host'] === '1.1.1.1', 'source URL policy records normalized host');
check($sourceUrl['port'] === 443, 'source URL policy defaults HTTPS port');
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
    ['kind' => 'set', 'name' => 'X-File', 'value' => 'file=:file'],   // placeholder expansion
], ['file' => 'app.js']);
$headers = [];
foreach ($applied as $entry) {
    $headers[$entry['name']] = $entry['value'];
}
check($headers === ['X-One' => 'a,b', 'X-File' => 'file=app.js'], 'header ops: ' . json_encode($headers));

foreach (array_keys(SPACEFAST_PLATFORM_MANAGED_HEADERS) as $denied) {
    $applied = [];
    _stattic_apply_header_operations($applied, [['kind' => 'set', 'name' => $denied, 'value' => 'x']], []);
    check($applied === [], "denylist blocks header: {$denied}");
}

check(_stattic_expand_template('/to/:splat?q=:value', ['splat' => 'a/b', 'value' => '1']) === '/to/a/b?q=1', 'template expansion');

// --- Upload path policy --------------------------------------------------------------

foreach ([
    '.htaccess' => 'static_control_file_not_supported',
    'a/.htaccess' => 'static_control_file_not_supported',
    '.user.ini' => 'static_control_file_not_supported',
    '__spacefast/x.txt' => 'static_runtime_control_path_not_supported',
    'a/__SPACEFAST/x.txt' => 'static_runtime_control_path_not_supported',
    '__spacefast_generated/theme.css' => 'static_runtime_control_path_not_supported',
    '__stattic/x.txt' => 'static_runtime_control_path_not_supported',
    '__stattic_probe' => 'static_runtime_control_path_not_supported',
    'a/__STATTIC_probe/x.txt' => 'static_runtime_control_path_not_supported',
    '.spacefast/storage/x' => 'static_runtime_control_path_not_supported',
    '.spacefast/engine/init.php' => 'static_runtime_control_path_not_supported',
    '.stattic/storage/x' => 'static_runtime_control_path_not_supported',
    '.stattic/engine/init.php' => 'static_runtime_control_path_not_supported',
    '.well-known/spacefast-runtime' => 'static_runtime_control_path_not_supported',
    '.well-known/stattic-runtime' => 'static_runtime_control_path_not_supported',
    'custom-redirects.php' => 'static_runtime_control_path_not_supported', // engine alias name
    'init.php' => 'static_runtime_control_path_not_supported',
    '../escape.txt' => 'invalid_file_path',
    'a//b.txt' => 'invalid_file_path',
    'a/./b.txt' => 'invalid_file_path',
    "nul\0.txt" => 'invalid_file_path',
    'trailing./x' => 'invalid_file_path',
    '/absolute.txt' => 'invalid_file_path',
] as $path => $code) {
    $violation = _stattic_static_upload_path_violation((string) $path);
    check(is_array($violation) && $violation['code'] === $code, "path policy rejects: " . addcslashes((string) $path, "\0"));
}
foreach ([
    'index.html',
    'index.php', // explicitly not reserved: served as inert bytes
    '.well-known/security.txt',
    '.env.example',
    'nested/deep/file.txt',
    'docs/installer.php',
    'src/runtime/README.md',
    '_redirects',
    'my__stattic_thing.txt', // "__stattic" mid-segment, not a segment prefix
    'assets/app__spacefast.js',
] as $path) {
    check(_stattic_static_upload_path_violation($path) === null, "path policy allows: {$path}");
}

// --- PHP-like safety policy ----------------------------------------------------------

foreach (['x.php', 'x.PHP', 'x.php5', 'x.phtml', 'x.phar', 'a/b.php8'] as $path) {
    check(_stattic_path_is_php_like($path), "php-like: {$path}");
}
foreach (['x.html', 'x.php.txt', 'php', '.php'] as $path) {
    check(!_stattic_path_is_php_like($path), "not php-like: {$path}");
}
check(_stattic_safe_content_type('x.php', 'application/x-php') === 'text/plain; charset=utf-8', 'php served as text');
check(_stattic_safe_content_type('x.phar', 'whatever') === 'application/octet-stream', 'phar served as octet-stream');
check(_stattic_safe_content_type('x.css', 'text/css; charset=utf-8') === 'text/css; charset=utf-8', 'non-php mime untouched');

// --- Management route query decoding ------------------------------------------------

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

// --- PHP manifest contract ----------------------------------------------------------

$phpManifest = [
    'format' => 'stattic.php.manifest.v1',
    'versionId' => 'ver_manifest',
    'routes' => [
        [
            'action' => 'serve_static',
            'pattern' => '/',
            'file' => 'index.html',
            'contentType' => 'text/html',
            'etag' => 'sha256:abc',
        ],
        [
            'action' => 'redirect',
            'pattern' => '/old',
            'destination' => '/new',
            'status' => 301,
            'cacheControl' => 'public, max-age=31536000, immutable',
        ],
        [
            'action' => 'invoke_zero',
            'pattern' => '/api/status',
            'method' => 'GET',
            'endpointId' => 'GET:/api/status',
            'zeroPackPath' => '.stattic/runtime/zero-pack.szc',
            'capabilities' => ['db' => false, 'fetch' => false, 'auth' => false, 'env' => false],
        ],
        [
            'action' => 'invoke_zero',
            'pattern' => '/api/current',
            'method' => 'POST',
            'endpointId' => 'POST:/api/current',
            'zeroArtifact' => 'zero/endpoints/post_api_current.json',
            'schemaHash' => 'sha256:current',
            'capabilities' => ['db' => false, 'fetch' => false, 'auth' => false, 'env' => false, 'realtime' => false],
        ],
        [
            'action' => 'invoke_zero',
            'pattern' => '/api/dynamic/:id',
            'method' => 'GET',
            'endpointId' => 'GET:/api/dynamic/:id',
            'zeroArtifact' => 'zero/endpoints/get_api_dynamic_id.json',
            'capabilities' => ['db' => false, 'fetch' => false, 'auth' => false, 'env' => false, 'realtime' => false],
        ],
    ],
];
check(_stattic_php_manifest_valid($phpManifest), 'php manifest accepts valid serve/zero records');
check(
    _stattic_runtime_route_pattern_valid('/generated/src/pages/[...slug].astro'),
    'php manifest accepts literal route segments containing consecutive dots'
);
check(
    !_stattic_runtime_route_pattern_valid('/generated/src/pages/../secrets'),
    'php manifest rejects an exact parent traversal segment'
);
check(
    _stattic_runtime_relative_artifact_path_valid('generated/src/pages/[...slug].astro'),
    'php manifest accepts literal artifact segments containing consecutive dots'
);
check(
    !_stattic_runtime_relative_artifact_path_valid('generated/src/pages/../secrets'),
    'php manifest rejects an exact parent traversal artifact segment'
);
check(
    _stattic_resolve_php_manifest_lookup($phpManifest, '', 'GET')['record'] === $phpManifest['routes'][0],
    'php manifest resolves root static route'
);
$manifestRedirectAction = _stattic_php_manifest_lookup_result($phpManifest, '/old', 'GET')['action'];
check(
    is_array($manifestRedirectAction)
        && ($manifestRedirectAction['action'] ?? null) === 'redirect'
        && ($manifestRedirectAction['destination'] ?? null) === '/new'
        && ($manifestRedirectAction['status'] ?? null) === 301
        && ($manifestRedirectAction['cache_control'] ?? null) === 'public, max-age=31536000, immutable',
    'php manifest converts redirect records to lookup actions'
);
check(
    _stattic_resolve_php_manifest_lookup($phpManifest, '/api/status', 'HEAD')['record'] === $phpManifest['routes'][2],
    'php manifest resolves HEAD to GET zero route'
);
check(
    _stattic_resolve_php_manifest_lookup($phpManifest, '/api/status', 'POST')['record'] === null,
    'php manifest rejects wrong zero method'
);
$wrongMethodResult = _stattic_php_manifest_lookup_result($phpManifest, '/api/dynamic/todo_123', 'POST');
check(
    ($wrongMethodResult['action'] ?? null) === null && ($wrongMethodResult['method_not_allowed'] ?? null) === true,
    'php manifest reports dynamic zero method mismatch'
);
$manifestZeroAction = _stattic_php_manifest_lookup_result($phpManifest, '/api/current', 'POST')['action'];
check(
    is_array($manifestZeroAction)
        && ($manifestZeroAction['action'] ?? null) === 'invoke_zero'
        && ($manifestZeroAction['endpoint_id'] ?? null) === 'POST:/api/current'
        && ($manifestZeroAction['zero_artifact'] ?? null) === 'zero/endpoints/post_api_current.json'
        && ($manifestZeroAction['schema_hash'] ?? null) === 'sha256:current',
    'php manifest converts current zero artifact records to invoke actions'
);
$dynamicManifestZeroAction = _stattic_php_manifest_lookup_result($phpManifest, '/api/dynamic/todo_123', 'GET')['action'];
check(
    is_array($dynamicManifestZeroAction)
        && ($dynamicManifestZeroAction['endpoint_id'] ?? null) === 'GET:/api/dynamic/:id'
        && ($dynamicManifestZeroAction['params'] ?? null) === ['id' => 'todo_123'],
    'php manifest resolves dynamic zero params'
);
$invalidManifest = $phpManifest;
$invalidManifest['routes'][0]['file'] = '../index.html';
check(!_stattic_php_manifest_valid($invalidManifest), 'php manifest rejects escaping file paths');
$functionManifest = $phpManifest;
$functionManifest['routes'][] = [
    'action' => 'invoke_function',
    'pattern' => '/api/function',
    'method' => 'POST',
    'functionId' => 'fn_123',
];
check(!_stattic_php_manifest_valid($functionManifest), 'php manifest rejects non-zero function actions');

// --- Unified Rule matchers (packages/common/src/contracts/access.ts) -----------------

// Path glob: exact, single-segment `*`, recursive `**`.
check(_stattic_unified_path_glob_matches('/x.html', '/x.html'), 'glob exact match');
check(!_stattic_unified_path_glob_matches('/x.html', '/y.html'), 'glob exact mismatch');
check(_stattic_unified_path_glob_matches('/docs/*', '/docs/intro'), 'glob single-segment match');
check(!_stattic_unified_path_glob_matches('/docs/*', '/docs/a/b'), 'glob single-segment does not cross slash');
check(_stattic_unified_path_glob_matches('/docs/**', '/docs/a/b/c'), 'glob recursive match');
check(_stattic_unified_path_glob_matches('/docs/**', '/docs/'), 'glob recursive matches shallow');
check(!_stattic_unified_path_glob_matches('/docs/**', '/blog/x'), 'glob recursive anchored at prefix');
check(_stattic_unified_path_glob_matches('/a.*.js', '/a.12345678.js'), 'glob mid-segment star');
check(!_stattic_unified_path_glob_matches('/a*', '/b'), 'glob is anchored at start');

// CIDR containment (v4 + v6), family mismatch, bare address.
check(_stattic_unified_ip_in_cidr('10.0.0.5', '10.0.0.0/24'), 'cidr v4 in range');
check(!_stattic_unified_ip_in_cidr('10.0.1.5', '10.0.0.0/24'), 'cidr v4 out of range');
check(_stattic_unified_ip_in_cidr('192.168.1.1', '192.168.1.1'), 'cidr v4 bare address exact');
check(_stattic_unified_ip_in_cidr('10.0.0.250', '10.0.0.128/25'), 'cidr v4 /25 high half');
check(!_stattic_unified_ip_in_cidr('10.0.0.5', '10.0.0.128/25'), 'cidr v4 /25 low half excluded');
check(_stattic_unified_ip_in_cidr('2001:db8::1', '2001:db8::/32'), 'cidr v6 in range');
check(!_stattic_unified_ip_in_cidr('2001:db9::1', '2001:db8::/32'), 'cidr v6 out of range');
check(!_stattic_unified_ip_in_cidr('10.0.0.5', '2001:db8::/32'), 'cidr family mismatch never matches');
check(_stattic_unified_ip_in_any_cidr('10.0.0.5', ['192.0.2.0/24', '10.0.0.0/8']), 'cidr any-of match');
check(!_stattic_unified_ip_in_any_cidr('', ['10.0.0.0/8']), 'cidr empty ip never matches');

// Agent substring matcher (case-insensitive).
check(_stattic_unified_agent_matches('GPTBot', 'Mozilla/5.0 GPTBot/1.0'), 'agent substring match');
check(_stattic_unified_agent_matches('gptbot', 'Mozilla GPTBot/1.0'), 'agent match is case-insensitive');
check(!_stattic_unified_agent_matches('Googlebot', 'Mozilla GPTBot/1.0'), 'agent substring mismatch');

// Grant glob intersection + namespace filtering.
check(_stattic_unified_grants_intersect(['email:a@acme.com'], ['email:a@acme.com']), 'grant exact intersect');
check(_stattic_unified_grants_intersect(['email:bob@acme.com'], ['email:*@acme.com']), 'grant domain glob intersect');
check(!_stattic_unified_grants_intersect(['email:bob@evil.com'], ['email:*@acme.com']), 'grant domain glob no-cross');
check(!_stattic_unified_grants_intersect([], ['email:*@acme.com']), 'grant empty set no intersect');
check(_stattic_unified_grants_intersect(['team:eng:member', 'user:carol'], ['user:carol']), 'grant multi any-of');
check(
    _spacefast_jwt_filter_grants(['ext:conn1:vip', 'email:x@y.com'], ['ext:conn1:'])
        === ['ext:conn1:vip'],
    'namespace filter drops out-of-namespace grants'
);
check(
    _spacefast_jwt_filter_grants(['svc:ci', 'user:a'], ['svc:', 'user:'])
        === ['svc:ci', 'user:a'],
    'namespace filter keeps in-namespace grants'
);

// Issuer selection by kid.
check(
    _spacefast_jwt_issuer_for_kid([['kid' => 'k1', 'publicKey' => 'p1'], ['kid' => 'k2', 'publicKey' => 'p2']], 'k2')
        === ['kid' => 'k2', 'publicKey' => 'p2'],
    'issuer selected by kid'
);
check(
    _spacefast_jwt_issuer_for_kid([['kid' => 'k1', 'publicKey' => 'p1']], 'nope') === null,
    'issuer kid miss returns null'
);

// --- Visitor verify: per-request signature memo (shared/jwt.php) ---------------------

// The memo primitive itself: identical (key material, token) skips the
// verifier; different key material re-verifies; negative verdicts memoize too.
$memoCalls = 0;
$memoVerify = function () use (&$memoCalls): bool {
    $memoCalls++;
    return true;
};
check(_spacefast_jwt_signature_valid_memo('fp-a', 'tok-1', $memoVerify) === true, 'sig memo: first call runs the verifier');
check(_spacefast_jwt_signature_valid_memo('fp-a', 'tok-1', $memoVerify) === true && $memoCalls === 1, 'sig memo: warm call skips the verifier');
check(_spacefast_jwt_signature_valid_memo('fp-b', 'tok-1', $memoVerify) === true && $memoCalls === 2, 'sig memo: different key material re-verifies the same token');
check(_spacefast_jwt_signature_valid_memo('fp-a', 'tok-2', $memoVerify) === true && $memoCalls === 3, 'sig memo: different token re-verifies under the same key');
$memoDenies = 0;
$memoDeny = function () use (&$memoDenies): bool {
    $memoDenies++;
    return false;
};
check(_spacefast_jwt_signature_valid_memo('fp-c', 'tok-1', $memoDeny) === false, 'sig memo: negative verdict is returned');
check(_spacefast_jwt_signature_valid_memo('fp-c', 'tok-1', $memoDeny) === false && $memoDenies === 1, 'sig memo: negative verdict is memoized without re-running');

// End-to-end through _spacefast_visitor_verify: the memo must never change a
// verdict, and everything time/state-dependent must keep running per call.
$jwtSignKeypair = sodium_crypto_sign_keypair();
$jwtIssuerA = [
    'alg' => 'EdDSA',
    'kid' => 'unit-k1',
    'publicKey' => _spacefast_base64url_encode(sodium_crypto_sign_publickey($jwtSignKeypair)),
    'grantNamespaces' => ['user:'],
];
$jwtOtherKeypair = sodium_crypto_sign_keypair();
$jwtIssuerB = [
    'alg' => 'EdDSA',
    'kid' => 'unit-k1',
    'publicKey' => _spacefast_base64url_encode(sodium_crypto_sign_publickey($jwtOtherKeypair)),
    'grantNamespaces' => ['user:'],
];
$mintEdDSA = static function (array $claims, string $secretKey): string {
    $header = _spacefast_base64url_encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => 'unit-k1'], JSON_UNESCAPED_SLASHES));
    $body = _spacefast_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $header . '.' . $body;
    return $signingInput . '.' . _spacefast_base64url_encode(sodium_crypto_sign_detached($signingInput, $secretKey));
};
$jwtToken = $mintEdDSA(
    ['sub' => 'user:carol', 'grants' => ['user:carol'], 'exp' => time() + 600],
    sodium_crypto_sign_secretkey($jwtSignKeypair),
);

$jwtFirst = _spacefast_visitor_verify($jwtToken, ['issuers' => [$jwtIssuerA]]);
check(is_array($jwtFirst) && $jwtFirst['sub'] === 'user:carol' && $jwtFirst['grants'] === ['user:carol'], 'visitor verify accepts a valid EdDSA token');
check(_spacefast_visitor_verify($jwtToken, ['issuers' => [$jwtIssuerA]]) === $jwtFirst, 'memo-warm re-verify returns the identical verdict');
check(_spacefast_visitor_verify($jwtToken, ['issuers' => [$jwtIssuerB]]) === null, 'memo is keyed by key material: the same token fails a different issuer key');
check(_spacefast_visitor_verify($jwtToken, ['issuers' => [$jwtIssuerA]]) !== null, 'cross-key rejection does not poison the original verdict');

$jwtForged = $mintEdDSA(
    ['sub' => 'user:mallory', 'grants' => ['user:mallory'], 'exp' => time() + 600],
    sodium_crypto_sign_secretkey($jwtOtherKeypair),
);
check(_spacefast_visitor_verify($jwtForged, ['issuers' => [$jwtIssuerA]]) === null, 'wrong-key token is rejected');
check(_spacefast_visitor_verify($jwtForged, ['issuers' => [$jwtIssuerA]]) === null, 'wrong-key token stays rejected on the memo-warm path');

// Time/session/revocation checks stay OUTSIDE the memo: with the signature
// verdict already warm, each of these must still be enforced per call.
check(_spacefast_visitor_verify($jwtToken, ['issuers' => [$jwtIssuerA], 'sessionVersion' => 3]) === null, 'sv mismatch rejects despite a warm signature memo');
check(
    _spacefast_visitor_verify($jwtToken, [
        'issuers' => [$jwtIssuerA],
        'revocations' => ['grants' => [], 'subs' => ['user:carol' => true]],
    ]) === null,
    'revoked sub rejects despite a warm signature memo'
);
_spacefast_revocations_unavailable_flag(false);
check(
    _spacefast_visitor_verify($jwtToken, [
        'issuers' => [$jwtIssuerA],
        'revocations' => ['grants' => [], 'subs' => [], 'available' => false],
    ]) === null,
    'unavailable revocation state fails closed despite a warm signature memo'
);
check(_spacefast_revocations_unavailable_flag() === true, 'unavailable revocation state raises the sticky flag on the memo-warm path');
_spacefast_revocations_unavailable_flag(false);
$jwtExpired = $mintEdDSA(
    ['sub' => 'user:carol', 'grants' => ['user:carol'], 'exp' => time() - 3600],
    sodium_crypto_sign_secretkey($jwtSignKeypair),
);
check(_spacefast_visitor_verify($jwtExpired, ['issuers' => [$jwtIssuerA]]) === null, 'expired token is rejected (exp check runs outside the memo)');

// Space-local HS256 lane: the derived-key hash keys the memo, so a rotated
// key (password change / sv bump re-derivation) always re-verifies and fails.
$pwKey = _spacefast_local_pw_key('rule1', 'verifier-secret', 0);
$pwToken = _spacefast_mint_local_pw_token('rule1', $pwKey, 'site.example.com', 0, 600);
$pwOptions = [
    'host' => 'site.example.com',
    'localPwResolver' => static fn (string $ruleId): ?string => $ruleId === 'rule1' ? $pwKey : null,
];
$pwFirst = _spacefast_visitor_verify($pwToken, $pwOptions);
check(is_array($pwFirst) && $pwFirst['grants'] === ['pw:rule1'], 'local pw token verifies');
check(_spacefast_visitor_verify($pwToken, $pwOptions) === $pwFirst, 'local pw memo-warm re-verify returns the identical verdict');
$pwRotatedKey = _spacefast_local_pw_key('rule1', 'rotated-secret', 0);
check(
    _spacefast_visitor_verify($pwToken, [
        'host' => 'site.example.com',
        'localPwResolver' => static fn (string $ruleId): ?string => $ruleId === 'rule1' ? $pwRotatedKey : null,
    ]) === null,
    'rotated pw key rejects the old pass despite a warm memo for the old key'
);

// Secret comparison (raw vs hash) and ref resolution.
check(_spacefast_secret_equals('swordfish', 'swordfish'), 'raw secret equal');
check(!_spacefast_secret_equals('swordfish', 'nope'), 'raw secret unequal');
check(_spacefast_secret_equals(password_hash('swordfish', PASSWORD_BCRYPT), 'swordfish'), 'hashed secret verify');
check(
    _spacefast_resolve_secret('secret:site_pw', ['secrets' => ['site_pw' => 'swordfish']]) === 'swordfish',
    'secret-ref resolves from serving secrets'
);
check(
    _spacefast_resolve_secret('secret:absent', ['secrets' => []]) === null,
    'secret-ref unresolved returns null'
);

// --- Clean-URL knob resolution (W7.1, shared/artifacts.php) --------------------------

require_once __DIR__ . '/../engine/shared/artifacts.php';

// Explicit config wins in both directions, regardless of the fallback shape.
check(
    _stattic_serving_clean_urls_enabled(['serving_config' => ['clean_urls' => true, 'fallback' => ['path' => 'index.html', 'status' => 200]]]),
    'clean urls: explicit true wins over an SPA fallback'
);
check(
    !_stattic_serving_clean_urls_enabled(['serving_config' => ['clean_urls' => false, 'fallback' => null]]),
    'clean urls: explicit false wins on a plain static site'
);

// Default: ON for plain static sites (no fallback, or a 404-status custom page).
check(
    _stattic_serving_clean_urls_enabled(['serving_config' => ['fallback' => null]]),
    'clean urls: default on without a fallback'
);
check(
    _stattic_serving_clean_urls_enabled(['serving_config' => ['fallback' => ['path' => '404.html', 'status' => 404]]]),
    'clean urls: default on with a 404-status fallback'
);
// Default: OFF when a 200-status SPA fallback owns extensionless routes.
check(
    !_stattic_serving_clean_urls_enabled(['serving_config' => ['fallback' => ['path' => 'index.html', 'status' => 200]]]),
    'clean urls: default off behind a 200-status SPA fallback'
);
// Versions finalized before serving_config carried the knob read as default-on.
check(
    _stattic_serving_clean_urls_enabled([]),
    'clean urls: missing serving_config reads as default on'
);

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

foreach ([
    'space_transfer_push' => 'bulk',
    'space_transfer_install' => 'bulk',
    'tier_demote' => 'bulk',
    'tier_promote' => 'bulk',
    'tier_cancel' => 'interactive',
    'blob_report' => 'bulk',
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
$claims = ['operation_id' => 'op_test', 'space_id' => 'spc_unit', 'callback_url' => 'https://example.invalid/cb', 'callback_token' => 'tok'];
$created = _stattic_runtime_job_create($jobsPrivateRoot, 'test_counter', 'idem-1', ['target' => 3], $claims);
check($created['type'] === 'test_counter', 'job_create: stamps the requested type');
check($created['lane'] === 'bulk', 'job_create: lane derived from type');
check($created['status'] === 'pending', 'job_create: starts pending');
check($created['space_id'] === 'spc_unit', 'job_create: lifts space_id from claims onto the record');
check($created['operation_id'] === 'op_test', 'job_create: lifts operation_id from claims onto the record');
check($created['payload']['target'] === 3, 'job_create: keeps job-specific payload fields');
check($created['payload']['_claims']['callback_token'] === 'tok', 'job_create: stashes claims under payload._claims');
check(
    is_file(_stattic_runtime_job_path($jobsPrivateRoot, $created['id'])),
    'job_create: writes the queue record to disk'
);

$journalPath = $jobsPrivateRoot . '/runtime/journal.jsonl';
$journalLines = is_file($journalPath) ? array_filter(explode("\n", (string) file_get_contents($journalPath))) : [];
$createdEvents = array_filter($journalLines, static fn ($line) => str_contains($line, '"job_created"'));
check(count($createdEvents) === 1, 'job_create: journals exactly one job_created event');

$again = _stattic_runtime_job_create($jobsPrivateRoot, 'test_counter', 'idem-1', ['target' => 999], $claims);
check($again['id'] === $created['id'], 'job_create: upserts by idempotency_key instead of duplicating');
check($again['payload']['target'] === 3, 'job_create: upsert returns the ORIGINAL record, not a re-created one');
$queueFiles = glob(_stattic_runtime_jobs_queue_dir($jobsPrivateRoot) . '/*.json') ?: [];
check(count($queueFiles) === 1, 'job_create: idempotency key collision creates no second file');

$publicResponse = _stattic_runtime_job_public_response($created);
check(!array_key_exists('_claims', $publicResponse['payload']), 'job public response: strips payload._claims');
check($publicResponse['payload']['target'] === 3, 'job public response: keeps non-secret payload fields');

// --- Transfer/CAS pure helpers (§23/§26) ---------------------------------------------

$blobSource = $jobsPrivateRoot . '/runtime/blob-source.txt';
_stattic_runtime_mkdir(dirname($blobSource));
file_put_contents($blobSource, 'blob bytes');
$blobSha = hash('sha256', 'blob bytes');
_stattic_runtime_blob_put($jobsPrivateRoot, 'spc_blob_unit', $blobSource, $blobSha);
check(_stattic_runtime_blob_has($jobsPrivateRoot, 'spc_blob_unit', $blobSha), 'blob store: put makes a sha addressable in the space store');
check(!is_file($blobSource), 'blob store: put renames the tmp source away');

$linked = $jobsPrivateRoot . '/spaces/spc_blob_unit/versions/ver_a/files/index.html';
_stattic_runtime_blob_link($jobsPrivateRoot, 'spc_blob_unit', $blobSha, $linked);
check(is_file($linked) && file_get_contents($linked) === 'blob bytes', 'blob store: link materializes blob bytes at the destination');
_stattic_runtime_blob_link($jobsPrivateRoot, 'spc_blob_unit', $blobSha, $linked);
check(is_file($linked) && file_get_contents($linked) === 'blob bytes', 'blob store: link is EEXIST-safe for identical bytes');
check(_stattic_transfer_blob_key('spc_blob_unit', $blobSha) === 'spaces/spc_blob_unit/blobs/' . substr($blobSha, 0, 2) . '/' . $blobSha, 'transfer blob key: matches the wire object layout');

// blob_link fallback semantics (contract A3): a TRANSIENT link() failure must
// not flip the process into copy-mode — only "link() unsupported, detected
// once" may. Force one failure via an unwritable destination dir, then prove
// the next link on a healthy destination is still a real hardlink.
$blockedDir = $jobsPrivateRoot . '/spaces/spc_blob_unit/versions/ver_blocked/files';
mkdir($blockedDir, 0775, true);
chmod($blockedDir, 0555);
set_error_handler(static fn (): bool => true);
_stattic_runtime_blob_link($jobsPrivateRoot, 'spc_blob_unit', $blobSha, $blockedDir . '/index.html');
restore_error_handler();
chmod($blockedDir, 0775);
$healthy = $jobsPrivateRoot . '/spaces/spc_blob_unit/versions/ver_b/files/index.html';
_stattic_runtime_blob_link($jobsPrivateRoot, 'spc_blob_unit', $blobSha, $healthy);
$healthyStat = stat($healthy);
$blobStat = stat($jobsPrivateRoot . '/spaces/spc_blob_unit/blobs/' . substr($blobSha, 0, 2) . '/' . $blobSha);
check(
    is_array($healthyStat) && is_array($blobStat) && $healthyStat['ino'] === $blobStat['ino'],
    'blob store: one transient link() failure does not degrade later links to copies'
);

$refs = [
    ['v' => 0, 's' => '00'],
    ['v' => 0, 's' => '7f'],
    ['v' => 1, 's' => '00'],
];
check(_stattic_transfer_cursor_index($refs, []) === 0, 'transfer cursor: empty cursor starts at the first shard');
check(_stattic_transfer_cursor_index($refs, ['v' => 0, 's' => '7f']) === 1, 'transfer cursor: existing cursor resumes at the matching shard');
check(_stattic_transfer_cursor_index($refs, ['v' => 9, 's' => 'ff']) === 3, 'transfer cursor: unknown cursor parks at completion');
check(_stattic_transfer_next_cursor($refs, 2) === ['v' => 1, 's' => '00'], 'transfer cursor: next cursor is the next shard pair');
check(_stattic_transfer_next_cursor($refs, 3) === ['complete' => true], 'transfer cursor: past the last shard marks completion');
check(_stattic_transfer_bundle_path('versions/ver_a/file-shards/ab.php') === 'versions/ver_a/file-shards/ab.php', 'transfer bundle path: allows version artifacts');
check(_stattic_transfer_bundle_path('routes/production.json') === 'routes/production.json', 'transfer bundle path: allows route pointers');
// Version file maps carry runtime-generated artifacts (theme-json commits
// __spacefast_generated/theme.css): moving committed bytes must not trip the
// user-intake reservation on the __spacefast*/__stattic* namespaces.
check(_stattic_transfer_safe_disk_path('__spacefast_generated/theme.css') === '__spacefast_generated/theme.css', 'transfer disk path: allows runtime-generated artifacts');
check(_stattic_transfer_safe_disk_path('assets/app.css') === 'assets/app.css', 'transfer disk path: allows ordinary version files');

// Tier demote grace (§17): a reader holding the pre-rewrite shard array has
// no remote locator to fall back to, so the DEFAULT grace must be > 0 — the
// window has to cover lookup→fopen without relying on job-tick latency.
require_once __DIR__ . '/../engine/admin/tier.php';
check(getenv('SPACEFAST_TIER_DEMOTE_GRACE_SECONDS') === false, 'tier grace: unit env leaves the knob unset');
check(_stattic_tier_grace_seconds() >= 1, 'tier grace: default grace window is non-zero seconds');

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
check($sharedPolicy === STATTIC_PROXY_SHARED_CACHE_POLICY, 'proxy cache: explicit safe GET response gets the bounded shared policy');
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
check(in_array(['Cache-Control', STATTIC_PROXY_PRIVATE_CACHE_POLICY], $protectedLines, true), 'proxy relay: access-protected responses pin private, no-store');
check(count(array_keys($protectedNames, 'cache-control', true)) === 1, 'proxy relay: protected responses carry exactly one Cache-Control');
check(!in_array('expires', $protectedNames, true), 'proxy relay: protected responses drop origin Expires');
check(!in_array('age', $protectedNames, true), 'proxy relay: protected responses drop origin Age');
check(!in_array('set-cookie', $protectedNames, true), 'proxy relay: protected responses still never relay Set-Cookie');
check(in_array(['ETag', '"abc123"'], $protectedLines, true), 'proxy relay: protected responses keep validators (inert under no-store)');

$platformOverride = _stattic_proxy_response_header_lines(
    [['Cache-Control', 'public, max-age=600'], ['X-Origin', 'up']],
    ['cache-control' => true, 'x-origin' => true],
    [['Cache-Control', 'no-cache']],
    STATTIC_PROXY_EDGE_CACHE_POLICY
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
        _stattic_proxy_response_cache_policy(true, 200, $unsafeOrigin, false) === STATTIC_PROXY_EDGE_CACHE_POLICY,
        'proxy cache: personalized or restrictive upstream metadata revokes shared caching'
    );
}
check(
    _stattic_proxy_response_cache_policy(false, 200, [], false) === STATTIC_PROXY_EDGE_CACHE_POLICY,
    'proxy cache: a request the route never made shared-eligible stays no-store'
);

// Request-side eligibility (the boolean the serving path hands the classifier).
check(
    _stattic_proxy_cache_request_eligible('shared', [], [], 'GET', 'GET', false, false),
    'proxy cache: an opted anonymous safe GET is shared-cache eligible'
);
check(
    !_stattic_proxy_cache_request_eligible(null, [], [], 'GET', 'GET', false, false),
    'proxy cache: an unopted route stays no-store'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', [], [], 'POST', 'POST', false, false),
    'proxy cache: unsafe request methods stay no-store'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', ['Authorization' => 'Bearer configured'], [], 'GET', 'GET', false, false),
    'proxy cache: a static Authorization route header revokes shared caching'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', ['Cookie' => 'session=configured'], [], 'GET', 'GET', false, false),
    'proxy cache: a static Cookie route header revokes shared caching'
);
check(
    !_stattic_proxy_cache_request_eligible('shared', [], [], 'GET', 'GET', false, true),
    'proxy cache: a forwarded verified identity revokes shared caching'
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
// upstreams — see tests/access-forwarding.test.ts), so the streaming decisions
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

// --- Zero endpoint cache-policy relay (runtime/zero.php) -----------------------------

require_once __DIR__ . '/../engine/runtime/zero.php';

$zeroDeclared = _stattic_zero_response_cache_plan([], ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=60'], false);
check(in_array(['Cache-Control', 'public, max-age=60'], $zeroDeclared['lines'], true), 'zero cache: a runner-declared Cache-Control is relayed to the client');
check(in_array(['CDN-Cache-Control', 'public, max-age=60'], $zeroDeclared['lines'], true), 'zero cache: the declared policy mirrors to CDN-Cache-Control');
check(in_array(['Surrogate-Control', 'public, max-age=60'], $zeroDeclared['lines'], true), 'zero cache: the declared policy mirrors to Surrogate-Control');
check(in_array('cache-control', $zeroDeclared['suppress'], true), 'zero cache: the raw Cache-Control relay is suppressed in favor of the computed policy');

$zeroDefault = _stattic_zero_response_cache_plan([], ['Content-Type' => 'application/json'], false);
check(in_array(['Cache-Control', STATTIC_CACHE_CONTROL_NO_STORE], $zeroDefault['lines'], true), 'zero cache: no declared policy forces the explicit no-store default');
check(in_array(['CDN-Cache-Control', STATTIC_CACHE_CONTROL_NO_STORE], $zeroDefault['lines'], true), 'zero cache: the forced default mirrors to the edge tier too');

$zeroBase = _stattic_zero_response_cache_plan(['Cache-Control' => 'max-age=30'], [], false);
check(in_array(['Cache-Control', 'max-age=30'], $zeroBase['lines'], true), 'zero cache: a _headers-rule Cache-Control counts as a declared policy');
$zeroRunnerWins = _stattic_zero_response_cache_plan(['Cache-Control' => 'max-age=30'], ['Cache-Control' => 'no-cache'], false);
check(in_array(['Cache-Control', 'no-cache'], $zeroRunnerWins['lines'], true), 'zero cache: the runner-declared policy outranks the _headers rule');
check(!in_array(['Cache-Control', 'max-age=30'], $zeroRunnerWins['lines'], true), 'zero cache: exactly one Cache-Control policy line survives');

$zeroExpiresOnly = _stattic_zero_response_cache_plan([], ['Expires' => 'Thu, 02 Jul 2026 00:00:00 GMT'], false);
check($zeroExpiresOnly['lines'] === [], 'zero cache: an Expires-only declaration is not stomped by the no-store default');
check(in_array('cache-control', $zeroExpiresOnly['suppress'], true), 'zero cache: Expires-only responses still emit no ambient Cache-Control');

$zeroOwnEdgeHeader = _stattic_zero_response_cache_plan([], ['Cache-Control' => 'public, max-age=60', 'CDN-Cache-Control' => 'max-age=5'], false);
check(!in_array(['CDN-Cache-Control', 'public, max-age=60'], $zeroOwnEdgeHeader['lines'], true), 'zero cache: a runner-declared CDN-Cache-Control is respected, not mirror-stomped');
check(in_array(['Surrogate-Control', 'public, max-age=60'], $zeroOwnEdgeHeader['lines'], true), 'zero cache: the other edge mirror still fills in');

$zeroListValue = _stattic_zero_response_cache_plan([], ['Cache-Control' => ['public', 'max-age=120']], false);
check(in_array(['Cache-Control', 'public, max-age=120'], $zeroListValue['lines'], true), 'zero cache: a list-valued declaration joins into one policy field');

$zeroPrivate = _stattic_zero_response_cache_plan([], ['Cache-Control' => 'public, max-age=600', 'Expires' => 'Thu, 02 Jul 2026 00:00:00 GMT'], true);
check(in_array(['Cache-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE], $zeroPrivate['lines'], true), 'zero cache: access-protected paths pin private, no-store over any declared policy');
check(!in_array(['Cache-Control', 'public, max-age=600'], $zeroPrivate['lines'], true), 'zero cache: the declared shared policy is discarded on protected paths');
check(in_array('expires', $zeroPrivate['suppress'], true) && in_array('age', $zeroPrivate['suppress'], true), 'zero cache: protected responses drop declared Expires/Age');
check(in_array('cdn-cache-control', $zeroPrivate['suppress'], true) && in_array('surrogate-control', $zeroPrivate['suppress'], true), 'zero cache: protected responses drop runner-addressed edge headers');
check(in_array(['CDN-Cache-Control', STATTIC_CACHE_CONTROL_PRIVATE_NO_STORE], $zeroPrivate['lines'], true), 'zero cache: the private pin mirrors to the edge tier');

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

// ---------------------------------------------------------------------------------------

if ($failures !== []) {
    fwrite(STDERR, "unit.php FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}
echo "unit.php ok ({$assertions} assertions)\n";
