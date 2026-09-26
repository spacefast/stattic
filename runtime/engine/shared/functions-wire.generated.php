<?php
declare(strict_types=1);

// GENERATED FILE — DO NOT EDIT.
// Source of truth: packages/common/src/contracts/functions.ts
// Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy
// apps/control-plane/src/runtime/php-policy-parity.test.ts compares every value
// here with the TypeScript constants the Functions host reads.

// Dispatch instructions the origin sends the host. Inbound, the whole prefix is
// stripped, so a visitor can never send one of their own.
const SPACEFAST_FUNCTIONS_DISPATCH_HEADER_PREFIX = 'sf-fx-';
const SPACEFAST_FUNCTIONS_DISPATCH_HEADERS = [
    'bundleUrl' => 'sf-fx-bundle',
    'mainModule' => 'sf-fx-main',
    'compatibilityDate' => 'sf-fx-compat-date',
    'compatibilityFlags' => 'sf-fx-compat-flags',
    'capabilities' => 'sf-fx-caps',
    'egress' => 'sf-fx-egress',
    'relayUrl' => 'sf-fx-relay',
    'relayToken' => 'sf-fx-relay-token',
    'env' => 'sf-fx-env',
    'd1' => 'sf-fx-d1',
    'visitor' => 'sf-fx-visitor',
    'logUrl' => 'sf-fx-log',
    'logToken' => 'sf-fx-log-token',
    'usageUrl' => 'sf-fx-usage',
    'usageToken' => 'sf-fx-usage-token',
    'purgeUrl' => 'sf-fx-purge',
    'purgeToken' => 'sf-fx-purge-token',
    'seedUrl' => 'sf-fx-seed',
    'spaceId' => 'sf-fx-space',
    'versionId' => 'sf-fx-version',
    'requestId' => 'sf-fx-request',
    'dispatchToken' => 'sf-fx-dispatch-token',
];

// The $_SERVER spellings of the headers the host's gateway sets on a relay call.
const SPACEFAST_FUNCTIONS_RELAY_SERVER_VARS = [
    'broker' => 'HTTP_SF_FX_BROKER',
    'invocation' => 'HTTP_SF_FX_INVOCATION',
    'visitorHost' => 'HTTP_SF_FX_VISITOR_HOST',
    'visitorCookie' => 'HTTP_SF_FX_VISITOR_COOKIE',
];
// The purge credential the host presents at the origin purge route.
const SPACEFAST_FUNCTIONS_PURGE_TOKEN_SERVER_VAR = 'HTTP_SF_PURGE_TOKEN';

// One signing key mints every Functions token; the audience is the authority.
const SPACEFAST_FUNCTIONS_TOKEN_AUDIENCES = [
    'dispatch' => 'spacefast-functions-dispatch',
    'bundle' => 'spacefast-functions-bundle',
    'seed' => 'spacefast-functions-seed',
    'relay' => 'spacefast-functions-relay',
    'usage' => 'spacefast-functions-usage',
    'purge' => 'spacefast-functions-purge',
];

// Capabilities that never transit the origin relay.
const SPACEFAST_FUNCTIONS_RELAY_FREE_CAPABILITIES = ['log', 'next.cache'];

// Broker lane => the capabilities that admit it. The host's gateway names the
// lane in the broker header; the relay narrows the token's grant to it.
const SPACEFAST_FUNCTIONS_BROKERS = [
    'database' => ['db.read', 'db.write'],
    'zero' => ['zero.call'],
    'services' => ['gravatar.profile', 'spam.check', 'email.send', 'connectors.call'],
    'storage' => ['storage.read', 'storage.write'],
    'next-cache' => ['next.cache'],
];

// The env var the native service-broker executor reads its grant from.
const SPACEFAST_FUNCTIONS_SERVICE_BROKER_GRANT_ENV = 'SPACEFAST_SERVICE_BROKER_GRANT';
