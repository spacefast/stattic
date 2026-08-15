<?php
declare(strict_types=1);

// Replays the shared grant-decision corpus
// (packages/common/src/utils/grant-decision.fixtures.json) through the real
// serving-engine decision function. Two callers: runtime/tests/unit.php (the
// committed corpus) and packages/common/scripts/fuzz-grant-decision-parity.ts
// (random cases, opt-in). Nothing here re-implements the vocabulary — it only
// translates the contract's request shape into the runtime's.
//
// Run standalone (what the fuzzer does):
//   php runtime/tests/grant-conformance.php <fixture.json>
// prints one JSON object per case: {compiled, capabilities, matchedGrantIds}.

require_once __DIR__ . '/../engine/runtime/access-rules.php';

/**
 * The opaque authority references a session would carry for this principal.
 * Built with the engine's own audience→reference mapping so the corpus cannot
 * drift from it.
 */
function _stattic_test_grant_authorities(array $principal): array
{
    $audiences = [];
    foreach (($principal['teamIds'] ?? []) as $id) {
        $audiences[] = ['kind' => 'team', 'teamId' => $id];
    }
    foreach (($principal['personIds'] ?? []) as $id) {
        $audiences[] = ['kind' => 'person', 'personId' => $id];
    }
    foreach (($principal['linkIds'] ?? []) as $id) {
        $audiences[] = ['kind' => 'link', 'linkId' => $id];
    }
    foreach (($principal['passwordCredentialIds'] ?? []) as $id) {
        $audiences[] = ['kind' => 'password', 'credentialId' => $id];
    }
    foreach (($principal['machineIds'] ?? []) as $id) {
        $audiences[] = ['kind' => 'machine', 'machineId' => $id];
    }
    foreach (($principal['externalIdentities'] ?? []) as $identity) {
        $audiences[] = [
            'kind' => 'external',
            'issuer' => $identity['issuer'],
            'subject' => $identity['subject'],
        ];
    }
    $references = [];
    foreach ($audiences as $audience) {
        $reference = _stattic_grant_audience_reference($audience);
        if (is_string($reference)) {
            $references[] = $reference;
        }
    }
    return $references;
}

/**
 * @return array{compiled: bool, capabilities: list<string>, matchedGrantIds: list<string>}
 */
function _stattic_test_grant_conformance_case(array $case, int $now): array
{
    $request = $case['request'];
    $principal = is_array($request['principal'] ?? null) ? $request['principal'] : [];
    $projection = _stattic_compile_authorization_projection([
        'generation' => 1,
        'sessionVersion' => 0,
        'fence' => 'none',
        'acquireUrl' => 'https://access.spacefast.test/acquire/conformance',
        'spaceClaimed' => $case['spaceClaimed'],
        // `generation` rides on the projection's Grant shape, not the Grant
        // vocabulary (contracts/runtime-api.ts) — the control plane stamps it
        // per Grant so a session can drop one rotated authority.
        'grants' => array_map(
            static fn (array $grant): array => ['generation' => 1, ...$grant],
            $case['grants']
        ),
    ]);
    if (!is_array($projection)) {
        return ['compiled' => false, 'capabilities' => [], 'matchedGrantIds' => []];
    }
    $authorities = _stattic_test_grant_authorities($principal);
    $matched = [];
    $decision = _stattic_grant_decision(
        $projection,
        $request['resource'],
        $request['target'],
        $authorities,
        ($principal['verifiedEmail'] ?? false) === true ? $authorities : [],
        (string) ($principal['country'] ?? ''),
        (string) ($principal['userAgent'] ?? ''),
        ($request['resourceKind'] ?? 'route') === 'artifact',
        false,
        $now,
        $matched
    );
    $capabilities = $decision['capabilities'];
    sort($capabilities);
    sort($matched);
    return [
        'compiled' => true,
        'capabilities' => array_values($capabilities),
        'matchedGrantIds' => array_values($matched),
    ];
}

/** @return array{now: int, cases: list<array>} */
function _stattic_test_grant_conformance_fixture(string $path): array
{
    $fixture = json_decode((string) file_get_contents($path), true);
    if (!is_array($fixture) || !is_array($fixture['cases'] ?? null)) {
        throw new RuntimeException("unreadable grant conformance fixture: {$path}");
    }
    $now = strtotime((string) $fixture['now']);
    if ($now === false) {
        throw new RuntimeException("grant conformance fixture has no usable clock: {$path}");
    }
    return ['now' => $now, 'cases' => $fixture['cases']];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $fixture = _stattic_test_grant_conformance_fixture($argv[1] ?? '');
    echo json_encode(array_map(
        static fn (array $case): array =>
            _stattic_test_grant_conformance_case($case, $fixture['now']),
        $fixture['cases']
    ));
}
