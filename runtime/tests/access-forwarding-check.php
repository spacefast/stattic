<?php
declare(strict_types=1);

// Behavioral fixture for the §3.2 identity-forwarding boundary: runs the REAL
// enforcement (engine/runtime/access-rules.php) and the REAL proxy header
// collection (engine/runtime/proxy.php) against one request spec supplied as
// JSON on stdin, then prints the outbound proxy header list as JSON on stdout.
//
// Spec shape:
//   {
//     "server":          { $_SERVER overlays (HTTP_AUTHORIZATION, PHP_AUTH_PW, ...) },
//     "inbound_headers": { raw request headers as the web SAPI's getallheaders()
//                          would present them — this is where spoofed
//                          Spacefast-Access-* headers are injected },
//     "serving":         { policy/secrets serving document },
//     "host": "...", "path": "...", "uri": "...",
//     "route_headers":   { static proxy route headers },
//     "forward_headers": [ proxy forwardHeaders allowlist ]
//   }
//
// Only satisfiable specs are valid — an unsatisfied challenge/deny renders and
// exits inside the enforcer, which this CLI fixture does not model.

$rawSpec = stream_get_contents(STDIN);
$spec = is_string($rawSpec) ? json_decode($rawSpec, true) : null;
if (!is_array($spec)) {
    fwrite(STDERR, "invalid spec\n");
    exit(2);
}

$GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'] = is_array($spec['inbound_headers'] ?? null) ? $spec['inbound_headers'] : [];

// The CLI SAPI has no getallheaders(); provide the spec's raw inbound headers
// through the same seam proxy.php reads in the web SAPI.
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        return $GLOBALS['SPACEFAST_TEST_INBOUND_HEADERS'];
    }
}

$_SERVER['REQUEST_METHOD'] = 'GET';
foreach (is_array($spec['server'] ?? null) ? $spec['server'] : [] as $key => $value) {
    if (is_string($key) && is_string($value)) {
        $_SERVER[$key] = $value;
    }
}

require_once __DIR__ . '/../engine/shared/context.php';
require_once __DIR__ . '/../engine/runtime/access-rules.php';
require_once __DIR__ . '/../engine/runtime/proxy.php';

// Mirror the serve order exactly (serve.php): strip the edge-owned inbound
// headers FIRST, then enforce (a token-satisfied allow arms the forwarding
// seam), then collect the outbound proxy headers.
_spacefast_strip_untrusted_edge_headers();
$serving = is_array($spec['serving'] ?? null) ? $spec['serving'] : [];
$protected = _stattic_enforce_unified_access_rules(
    $serving,
    (string) ($spec['host'] ?? ''),
    (string) ($spec['path'] ?? '/'),
    (string) ($spec['uri'] ?? ($spec['path'] ?? '/'))
);
$headers = _stattic_collect_proxy_request_headers(
    is_array($spec['route_headers'] ?? null) ? $spec['route_headers'] : [],
    is_array($spec['forward_headers'] ?? null) ? $spec['forward_headers'] : []
);
echo json_encode(['protected' => $protected, 'headers' => $headers], JSON_UNESCAPED_SLASHES), "\n";
