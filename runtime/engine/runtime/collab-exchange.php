<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/response.php';

function _stattic_comments_request_origin(string $requestHost): ?string
{
    $host = _stattic_normalize_hostname($requestHost);
    if ($host === '' || preg_match('/[\\x00-\\x20\\x7f\\/\\\\]/', $host) === 1) {
        return null;
    }
    // The edge terminates TLS, so PHP may never see https: only the explicit
    // dev/test flag selects http (same contract as _stattic_cookies_secure).
    return (_stattic_cookies_secure() ? 'https' : 'http') . '://' . $host;
}

function _stattic_comments_render_json(int $status, array $body): never
{
    _stattic_json_response(
        $status,
        $body,
        $status >= 400 ? STATTIC_PROBLEM_MEDIA_TYPE : 'application/json',
        _stattic_private_content_response_headers([
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]),
    );
}

// There is no second cookie: the unified host session carries the Comments
// identity, and the anonymous id stays server-owned. The page never names it.
function _stattic_comments_visitor_identity(
    array $serving,
    string $requestHost,
    ?array $sessionIdentity
): array {
    $fromSession = _stattic_access_identity_comments($sessionIdentity);
    if ($fromSession !== null) {
        return $fromSession;
    }
    // No pseudonym yet. Writing one also settles the session id: a stateless
    // session mints its own on that write, a recorded one already has it.
    $remembered = _stattic_access_session_remember(
        $serving,
        $requestHost,
        $sessionIdentity,
        ['anonymousId' => _stattic_collab_mint_anonymous_id()]
    );
    $visitor = $remembered === null
        ? null
        : _stattic_access_identity_comments(['sessionRecord' => $remembered]);
    if ($visitor === null) {
        _stattic_render_json_unauthenticated('comments_unavailable');
    }
    return $visitor;
}

// Legacy Zero 0.8 compatibility: frozen capsule clients baked the pre-cutover
// `/__stattic/zero/realtime/ticket` path instead of the canonical `/__zero`
// spelling. Delete once legacy Zero 0.8 capsules are retired.
function _stattic_is_legacy_zero_realtime_ticket_path(string $requestPath): bool
{
    return $requestPath === STATTIC_ZERO_REALTIME_TICKET_PATH;
}

function _stattic_comments_handle_exchange(
    string $privateRoot,
    array $serving,
    string $requestHost,
    string $requestPath
): void {
    // Required here rather than trusting the dispatcher: a missing include must
    // not turn a Comments boot into a fatal instead of a typed refusal.
    require_once __DIR__ . '/access-rules.php';
    if (_stattic_runtime_request_method() !== 'POST') {
        _stattic_method_not_allowed('POST');
    }
    if (!_stattic_access_same_origin_post($requestHost)) {
        _stattic_render_json_unauthenticated('comments_origin_invalid');
    }
    // 8 KiB leaves headroom for the principal/authorities envelope added before
    // re-encoding against the control plane's 16 KiB payload cap.
    $raw = _stattic_request_body_contents();
    $input = is_string($raw) && strlen($raw) <= 8192 ? json_decode($raw, true) : null;
    $rawPath = is_array($input) && is_string($input['pagePath'] ?? null)
        ? _stattic_canonical_request_path($input['pagePath'])
        : null;
    // The control plane decodes the forwarded path once more. A percent sign
    // left after this decode would name a different room there, so refuse it.
    $pagePath = is_string($rawPath) && !str_contains($rawPath, '%')
        ? _stattic_scope_path($rawPath)
        : null;
    // The canonical route budget is 2048 UTF-8 bytes on every Comments lane.
    if ($pagePath === null || strlen($pagePath) > STATTIC_ACCESS_SCOPE_PATH_MAX_BYTES) {
        _stattic_render_json_unauthenticated('comments_path_invalid');
    }
    if (_stattic_enforce_scoped_admission($serving, $requestHost, $pagePath, true) === null) {
        _stattic_render_json_unauthenticated('comments_denied');
    }
    // Every lane here MINTS something and so reaches the control plane: a ticket
    // is auth, and auth is not this host's to issue. Configuration is not minted
    // — it is served as the collaboration manifest.
    $isTicket = $requestPath === STATTIC_COMMENTS_TICKET_PATH;
    $isVersionUrls = $requestPath === STATTIC_COMMENTS_VERSION_URLS_PATH;
    $isMentionable = $requestPath === STATTIC_COMMENTS_MENTIONABLE_PATH;
    // Canonical `/__zero` realtime ticket path plus the fenced legacy Zero 0.8
    // spelling baked into frozen capsule clients. Missing either sends the
    // request down the Comments ticket lane and mints a ticket Cast will not
    // accept.
    $isZeroRealtimeTicket = $requestPath === STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH
        || _stattic_is_legacy_zero_realtime_ticket_path($requestPath);
    // A SpacePress / TypedPress build publishes no Zero realtime notices, so it
    // has no room to join: its pages poll (zero-v2-realtime.php serves them no
    // transport). Zero 0.8's per-version rooms and v2's shared room stay.
    if ($isZeroRealtimeTicket && in_array($serving['zero_generation'] ?? null, ['tp_v1', 'spacepress'], true)) {
        _stattic_render_json_unauthenticated('zero_realtime_unavailable');
    }
    $exchange = _stattic_access_page_exchange($serving);
    $exchangeKey = match (true) {
        $isZeroRealtimeTicket => 'zeroRealtimeTicketUrl',
        $isVersionUrls => 'commentsVersionUrlsUrl',
        $isMentionable => 'commentsMentionableUrl',
        default => 'commentsTicketUrl',
    };
    $exchangeUrl = is_array($exchange) ? (string) ($exchange[$exchangeKey] ?? '') : '';
    $origin = _stattic_comments_request_origin($requestHost);
    if ($exchangeUrl === '' || $origin === null) {
        _stattic_render_json_unauthenticated('comments_unavailable');
    }
    $identity = _stattic_verify_cookie_identity($serving, $requestHost);
    $authorities = [];
    // A missing sessionVersion degrades to a guest ticket rather than failing
    // control-plane validation with an impossible negative version.
    $sessionVersion = _stattic_session_version($serving);
    if ($sessionVersion >= 0 && is_array($identity) && is_array($identity['authorityEntries'] ?? null)) {
        foreach ($identity['authorityEntries'] as $entry) {
            $authorities[] = [
                'authorityReference' => $entry['reference'],
                'authorityGeneration' => $entry['generation'],
                'sessionVersion' => $sessionVersion,
                'emailVerifiedUntil' => $entry['emailVerifiedUntil'] ?? null,
                // A Space-origin session's cap travels with its authority, so
                // the ticket is decided on the same terms as the page.
                ...(is_array($entry['capabilities'] ?? null) ? ['capabilities' => $entry['capabilities']] : []),
            ];
        }
    }
    $visitor = $isZeroRealtimeTicket
        ? null
        : _stattic_comments_visitor_identity($serving, $requestHost, $identity);
    $payload = $isZeroRealtimeTicket ? [
        'origin' => $origin,
        'pagePath' => $pagePath,
        'versionId' => is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '',
    ] : [
        'origin' => $origin,
        'pagePath' => $pagePath,
        'visitorSessionId' => $visitor['sessionId'],
        // The mint derives actorKind from the principal alone, never from the
        // authorities below, which say only what the session may do.
        'principal' => _stattic_access_identity_principal($identity),
        ...(!empty($serving['immutable']) && is_string($serving['version_id'] ?? null)
            ? ['versionId' => $serving['version_id']]
            : []),
        'authorities' => $authorities,
    ];
    if ($isVersionUrls) {
        $ids = [];
        foreach (is_array($input) && is_array($input['ids'] ?? null) ? $input['ids'] : [] as $id) {
            if (is_string($id) && $id !== '' && strlen($id) <= 160) {
                $ids[$id] = true;
            }
            if (count($ids) >= STATTIC_COMMENTS_VERSION_URLS_MAX_IDS) {
                break;
            }
        }
        if ($ids === []) {
            _stattic_render_json_unauthenticated('comments_versions_invalid');
        }
        $payload['ids'] = array_keys($ids);
    }
    if ($isMentionable) {
        $query = is_array($input) && is_string($input['query'] ?? null) ? trim($input['query']) : '';
        if (strlen($query) > 160) {
            _stattic_render_json_unauthenticated('comments_query_invalid');
        }
        $payload['query'] = $query;
    }
    if ($isTicket) {
        $browserIdentity = is_array($input) && is_array($input['identity'] ?? null)
            ? $input['identity']
            : null;
        $name = is_array($browserIdentity) && is_string($browserIdentity['name'] ?? null)
            ? trim($browserIdentity['name'])
            : '';
        // 640 bytes is the widest UTF-8 encoding of the control plane's 160
        // character cap; exact character-length enforcement stays schema-side.
        if (
            $name === ''
            || strlen($name) > 640
            || !is_bool($browserIdentity['namedByUser'] ?? null)
        ) {
            _stattic_render_json_unauthenticated('comments_identity_invalid');
        }
        // The name is the visitor's claim. The anonymous subject comes from the
        // session record, never from the page.
        $payload['identity'] = [
            'anonymousId' => $visitor['anonymousId'],
            'name' => $name,
            'namedByUser' => $browserIdentity['namedByUser'],
        ];
        $overlay = _stattic_comments_local_config($privateRoot, $serving, $requestHost);
        $payload['notices'] = ($overlay['features']['notices'] ?? null) === true;
    }
    $context = _stattic_access_context($serving, $requestHost, $pagePath);
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    require_once __DIR__ . '/../shared/admission.php';
    _stattic_admission_acquire_blocking_lane($privateRoot, $serving, 'comments_exchange', 'rate_limited');
    $result = is_string($encoded)
        ? _stattic_access_exchange_post(
            $exchangeUrl,
            ['payload' => $encoded],
            _stattic_access_exchange_headers($exchange, $context, 'application/json')
        )
        : null;
    if ($result === null || !is_array($result['body'] ?? null)) {
        $unavailableCode = $isZeroRealtimeTicket
            ? 'zero_realtime_ticket_unavailable'
            : 'comments_exchange_unavailable';
        _stattic_comments_render_json(502, _stattic_problem_document(
            502,
            $unavailableCode,
            $isZeroRealtimeTicket
                ? 'Zero realtime could not reach Spacefast. Try again.'
                : 'Comments could not reach Spacefast. Try again.'
        ));
    }
    _stattic_comments_render_json($result['status'], $result['body']);
}
