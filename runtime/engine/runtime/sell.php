<?php
declare(strict_types=1);

function _stattic_sell_handle_client(): never
{
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (_stattic_runtime_request_method() !== 'HEAD') readfile(__DIR__ . '/sell-client.js');
    exit;
}

function _stattic_sell_render_json(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: ' . ($status >= 400 ? 'application/problem+json' : 'application/json'));
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function _stattic_sell_refuse(int $status, string $code, string $message): never
{
    _stattic_sell_render_json($status, _stattic_problem_document($status, $code, $message));
}

function _stattic_sell_handle_exchange(string $privateRoot, array $serving, string $requestHost, string $requestPath): never
{
    require_once __DIR__ . '/access-rules.php';
    if (_stattic_runtime_request_method() !== 'POST') {
        _stattic_method_not_allowed('POST');
    }
    if (!_stattic_access_same_origin_post($requestHost)) {
        _stattic_sell_refuse(403, 'access_denied', 'Checkout must start from this page.');
    }
    $raw = _stattic_request_body_contents();
    $input = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true) : null;
    $isProduct = $requestPath === STATTIC_SELL_PRODUCT_PATH;
    $keys = $isProduct ? ['pagePath', 'productKey'] : ['pagePath', 'productKey', 'attemptKey', 'attemptCreatedAt'];
    if (!is_array($input) || count($input) !== count($keys) || array_diff(array_keys($input), $keys) !== []) {
        _stattic_sell_refuse(422, 'validation_error', 'Checkout input is invalid.');
    }
    foreach ($keys as $key) {
        if (!is_string($input[$key] ?? null) || $input[$key] === '') {
            _stattic_sell_refuse(422, 'validation_error', 'Checkout input is invalid.');
        }
    }
    $pagePath = _stattic_scope_path($input['pagePath']);
    if ($pagePath === null || strlen($pagePath) > 1024) {
        _stattic_sell_refuse(422, 'validation_error', 'Checkout page path is invalid.');
    }
    _stattic_admission_acquire_access_lane($privateRoot, $serving);
    if (_stattic_enforce_scoped_admission($serving, $requestHost, $pagePath, true) === null) {
        _stattic_sell_refuse(403, 'access_denied', 'This page is private.');
    }
    // Version and Space come from host resolution. No browser selector can
    // cross into another deployment, seller, mode, price, or redirect origin.
    $versionId = $serving['version_id'] ?? null;
    $exchange = _stattic_access_page_exchange($serving);
    $url = is_array($exchange) ? ($exchange[$isProduct ? 'sellProductUrl' : 'sellCheckoutUrl'] ?? null) : null;
    if (!is_string($versionId) || $versionId === '' || !is_string($url) || $url === '') {
        _stattic_sell_refuse(503, 'provider_error', 'Checkout is unavailable. Try again shortly.');
    }
    $inputPayload = [
        'deploymentId' => $versionId,
        'productKey' => $input['productKey'],
    ];
    if (!$isProduct) {
        $inputPayload['attemptKey'] = $input['attemptKey'];
        $inputPayload['attemptCreatedAt'] = $input['attemptCreatedAt'];
    }
    $payload = json_encode($inputPayload, JSON_UNESCAPED_SLASHES);
    $context = _stattic_access_context($serving, $requestHost, $pagePath);
    $headers = _stattic_access_exchange_headers($exchange, $context, 'application/json');
    $headers[] = 'Spacefast-Visitor-Origin: ' . _stattic_runtime_request_origin($requestHost);
    $result = is_string($payload) ? _stattic_access_exchange_post(
        $url,
        [],
        $headers,
        $payload
    ) : null;
    if ($result === null || !is_array($result['body'] ?? null)) {
        _stattic_sell_refuse(503, 'provider_error', 'Checkout is unavailable. Retry this purchase shortly.');
    }
    // Only the control plane's receipt returns. Upstream cookies and private
    // exchange headers never become browser credentials.
    _stattic_sell_render_json($result['status'], $result['body']);
}


/** The runtime forwards only the locator; it never treats a redirect as payment proof. */
function _stattic_sell_handle_status(array $serving): never
{
    if (_stattic_runtime_request_method() !== 'GET') _stattic_method_not_allowed('GET');
    $session = $_GET['sessionId'] ?? null;
    $token = $_GET['purchaseToken'] ?? null;
    if (!is_string($session) || preg_match('/^cs_[A-Za-z0-9_]+$/', $session) !== 1
        || !is_string($token) || $token === '' || strlen($token) > 2048) {
        _stattic_sell_refuse(422, 'validation_error', 'Purchase locator is invalid.');
    }
    $exchange = _stattic_access_page_exchange($serving);
    $productUrl = is_array($exchange) ? ($exchange['sellProductUrl'] ?? null) : null;
    if (!is_string($productUrl)) _stattic_sell_refuse(503, 'provider_error', 'Payment status is unavailable.');
    $parts = parse_url($productUrl);
    $origin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $result = _stattic_access_exchange_post($origin . '/sell/checkout/status', [],
        ['Accept: application/json'], json_encode(['sessionId' => $session, 'purchaseToken' => $token]));
    if (!is_array($result) || !is_array($result['body'] ?? null)) _stattic_sell_refuse(503, 'provider_error', 'Payment status is unavailable.');
    _stattic_sell_render_json($result['status'], $result['body']);
}

/** Authored routes win; these defaults run only after no page/function matches. */
function _stattic_sell_page_fallback(array $serving, string $path): void
{
    $id = match (rtrim($path, '/')) { '/sell/return' => 'checkout', '/sell/cancel' => 'checkout-cancel', default => null };
    if ($id === null) return;
    if (!in_array(_stattic_runtime_request_method(), ['GET', 'HEAD'], true)) _stattic_method_not_allowed('GET, HEAD');
    require_once __DIR__ . '/../shared/errors.php';
    _stattic_serve_page($id, [
        'status' => 200, 'customizable' => true, 'serving' => $serving, 'private' => true,
        'headers' => ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'],
    ]);
    exit;
}
