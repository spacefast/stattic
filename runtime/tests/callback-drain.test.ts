// The event pull lane (contracts §10, D53): `runtime/journal.jsonl` is the ONLY
// event sink. There is no callback record store, no push transport, no lease and
// no callback credential. The control plane blocks on the drain response, so a
// page of journal records comes back in the body and
// `runtime/journal-cursor.json` (advanced by ACK, never backwards) makes the
// next page start where this one stopped.
//
// This file owns the drain/ack seam: which records reach the wire, how far one
// page reaches, and what an acknowledgement may commit. That management
// operations write the records at all is held where those operations live
// (uploads.test.ts, route-index.test.ts).
import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { appendFileSync, mkdirSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { watch } from "node:fs/promises";
import path from "node:path";

import { runtimeSecurityTelemetrySchema } from "../../packages/common/src/contracts/security-telemetry.ts";
import {
  ackEvents,
  api,
  deploy,
  drainEvents,
  errorCode,
  get,
  journalRecords,
  publicAccessConfig,
  PHP_BINARY,
  RUNTIME_HTTP_API_BASE,
  storagePath,
  startRuntime,
  type DrainResponse,
  type Runtime,
} from "./harness";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => {
  rt?.stop();
});

function journalPath(): string {
  return storagePath(rt, "runtime", "journal.jsonl");
}

// Each test owns the whole sink. The drain reads journal.jsonl from the
// persisted cursor, so both are reset to a fresh site's state.
beforeEach(() => {
  const runtimeDir = storagePath(rt, "runtime");
  mkdirSync(runtimeDir, { recursive: true });
  rmSync(path.join(runtimeDir, "journal-cursor.json"), { force: true });
  for (const name of readdirSync(runtimeDir)) {
    if (name === "journal.jsonl" || /^journal-.*\.jsonl$/.test(name)) {
      rmSync(path.join(runtimeDir, name), { force: true });
    }
  }
});

/** Append raw journal lines in the sink's own NDJSON format (shared/storage.php). */
function seedJournal(records: Array<Record<string, unknown>>): void {
  mkdirSync(path.dirname(journalPath()), { recursive: true });
  appendFileSync(journalPath(), records.map((record) => `${JSON.stringify(record)}\n`).join(""));
}

function managementRecord(eventId: string): Record<string, unknown> {
  return {
    event: "route_updated",
    space_id: "spc_journal",
    hostnames: [`${eventId}.test`],
    operation_id: `op_${eventId}`,
    operation_action: "update_route",
    event_id: eventId,
  };
}

function eventIds(page: DrainResponse): string[] {
  return page.events.map((entry) => entry.event_id);
}

function ackCursor(page: DrainResponse) {
  return ackEvents(rt, { cursor: page.cursor });
}

function drainRaw(body: unknown) {
  return api(rt, "POST", `${RUNTIME_HTTP_API_BASE}/events/drain`, "drain_events", {}, body);
}

function ackRaw(body: unknown) {
  return api(rt, "POST", `${RUNTIME_HTTP_API_BASE}/events/ack`, "ack_events", {}, body);
}

test("a publish journals only diagnostics: nothing reaches the wire, the cursor walks past", async () => {
  await deploy(rt, {
    spaceId: "spc_drain_live",
    versionId: "ver_drain_live",
    files: { "index.html": "<h1>drained</h1>" },
    activate: {
      route_name: "production",
      config: publicAccessConfig(),
      production_hostnames: ["drain-live.test"],
      version_hostnames: [],
    },
  });

  // A publish's receipt rides its own management responses (activation event
  // id, purge status), so both halves land in the journal as operator
  // diagnostics: attributed to their operation, carrying no event_id, owed to
  // no drain.
  const written = journalRecords(rt);
  const names = written.map((record) => record.event);
  expect(names).toContain("version_finalized");
  expect(names).toContain("route_updated");
  for (const record of written) {
    expect(record.event_id).toBeUndefined();
  }
  const finalized = written.find((record) => record.event === "version_finalized");
  expect(finalized?.operation_id).toBeString();

  // The drain page is empty, and its cursor still reaches past the publish's
  // records: acknowledging it consumes them instead of wedging the lane.
  const page = await drainEvents(rt);
  expect(page.events).toEqual([]);
  expect(page.returned_count).toBe(0);
  expect(page.pending_count).toBe(0);
  expect(page.cursor.file).toBe("journal.jsonl");
  expect(page.cursor.offset).toBeGreaterThan(0);

  await ackCursor(page);
  const drained = await drainEvents(rt);
  expect(drained.events).toEqual([]);
  expect(drained.cursor).toEqual(page.cursor);
});

test("one page spans the records it does not return: diagnostics and non-target events", async () => {
  // Only management records carry an event_id. Operator diagnostics (job
  // lifecycle, GC, purge receipts) share the sink and must never reach the wire,
  // yet the page cursor still has to reach past them, or a diagnostic run wedges
  // the lane forever.
  seedJournal([
    { event: "job_started", job: "maintenance" },
    managementRecord("evt_first"),
    { event: "purge_completed", hostnames: ["evt_first.test"] },
    managementRecord("evt_second"),
    { event: "gc_swept", removed: 3 },
  ]);

  const page = await drainEvents(rt);
  expect(eventIds(page)).toEqual(["evt_first", "evt_second"]);
  expect(page.returned_count).toBe(2);
  expect(page.pending_count).toBe(0);

  // Each event carries the position to ack THROUGH itself, so a per-event
  // cursor stops at its own record. The page reached past the trailing
  // diagnostic; the last event's cursor did not.
  const [firstEvent, secondEvent] = page.events;
  if (!firstEvent || !secondEvent) {
    throw new Error("expected two drained events");
  }
  expect(firstEvent.cursor.file).toBe(page.cursor.file);
  expect(firstEvent.cursor.inode).toBe(page.cursor.inode);
  expect(firstEvent.cursor.offset).toBeLessThan(secondEvent.cursor.offset);
  expect(secondEvent.cursor.offset).toBeLessThan(page.cursor.offset);

  // A targeted drain narrows the same page to one event; the cursor is
  // unchanged, so acknowledging it commits everything the page covered.
  const targeted = await drainEvents(rt, { target_event_id: "evt_second" });
  expect(eventIds(targeted)).toEqual(["evt_second"]);
  expect(targeted.returned_count).toBe(1);
  expect(targeted.cursor).toEqual(page.cursor);

  await ackCursor(targeted);
  expect((await drainEvents(rt)).events).toEqual([]);
});

test("the response cap bounds one page and the remainder is reported, then drained", async () => {
  const ids = Array.from({ length: 103 }, (_, index) => `evt_${String(index).padStart(3, "0")}`);
  seedJournal(ids.map((id) => managementRecord(id)));

  const first = await drainEvents(rt);
  expect(first.returned_count).toBe(100);
  expect(eventIds(first)).toEqual(ids.slice(0, 100));
  // Not a count: the lane only reports whether another page exists at all.
  expect(first.pending_count).toBe(1);

  await ackCursor(first);
  const second = await drainEvents(rt);
  expect(eventIds(second)).toEqual(["evt_100", "evt_101", "evt_102"]);
  expect(second.pending_count).toBe(0);

  await ackCursor(second);
  expect((await drainEvents(rt)).events).toEqual([]);
});

test("an ack commits per delivery, validates the whole batch first, and never rewinds", async () => {
  seedJournal([
    managementRecord("evt_ack_a"),
    managementRecord("evt_ack_b"),
    managementRecord("evt_ack_c"),
  ]);
  const page = await drainEvents(rt);
  const [first, second, third] = page.events;
  if (!first || !second || !third) {
    throw new Error("expected three drained events");
  }

  // A pre-telemetry reader must neither receive nor acknowledge this journal.
  const oldDrain = await drainRaw({ session_id: "ses_old_reader", page_id: "page_old" });
  expect(oldDrain.status).toBe(409);
  expect(await errorCode(oldDrain)).toBe("runtime_event_reader_incompatible");
  const oldAck = await ackRaw({ cursor: page.cursor });
  expect(oldAck.status).toBe(409);
  expect(await errorCode(oldAck)).toBe("runtime_event_reader_incompatible");
  expect(eventIds(await drainEvents(rt))).toEqual(["evt_ack_a", "evt_ack_b", "evt_ack_c"]);

  // The page ends on an event here, so the last event's cursor IS the page
  // cursor. Both name the end of the same sequence.
  expect(third.cursor).toEqual(page.cursor);

  // Nothing commits until the whole batch is well-formed: a valid delivery
  // riding along with a malformed one must not advance the cursor past itself.
  const malformedBatches: unknown[] = [
    [{ delivery_id: first.delivery_id }, { delivery_id: 42 }],
    [{ delivery_id: first.delivery_id }, "not-an-object"],
    { delivery_id: first.delivery_id },
    Array.from({ length: 101 }, () => ({ delivery_id: first.delivery_id })),
  ];
  for (const deliveries of malformedBatches) {
    const rejected = await ackRaw({
      security_telemetry: true,
      session_id: "ses_ack_batch",
      deliveries,
    });
    expect(rejected.status).toBe(422);
    expect(await errorCode(rejected)).toBe("runtime_event_ack_invalid");
    expect(eventIds(await drainEvents(rt))).toEqual(["evt_ack_a", "evt_ack_b", "evt_ack_c"]);
  }

  // A mid-page event cursor is a first-class commit: acking it redelivers
  // exactly the suffix after that event, no delivery id decoded.
  expect(await ackEvents(rt, { cursor: first.cursor })).toMatchObject({ cursor: first.cursor });
  expect(eventIds(await drainEvents(rt))).toEqual(["evt_ack_b", "evt_ack_c"]);

  // A delivery id carries the same coordinate, so a partially processed page
  // commits exactly as far as the control plane got.
  expect(await ackEvents(rt, { deliveries: [{ delivery_id: second.delivery_id }] })).toMatchObject({
    acknowledged_count: 1,
    stale_count: 0,
  });
  expect(eventIds(await drainEvents(rt))).toEqual(["evt_ack_c"]);

  // Re-acknowledging an earlier delivery (a replayed page) must not re-deliver
  // everything after it.
  expect(await ackEvents(rt, { deliveries: [{ delivery_id: first.delivery_id }] })).toMatchObject({
    acknowledged_count: 1,
  });
  expect(eventIds(await drainEvents(rt))).toEqual(["evt_ack_c"]);

  // A delivery id that decodes to nothing is stale, not fatal: the rest of the
  // batch still commits.
  expect(
    await ackEvents(rt, {
      deliveries: [{ delivery_id: "!!not-a-cursor!!" }, { delivery_id: third.delivery_id }],
    }),
  ).toMatchObject({ acknowledged_count: 1, stale_count: 1 });
  expect((await drainEvents(rt)).events).toEqual([]);
});

test("a malformed drain request is rejected without moving the lane", async () => {
  seedJournal([managementRecord("evt_schema_guard")]);
  const valid = { session_id: "ses_schema", page_id: "page_schema" };
  const malformedBodies: unknown[] = [
    {},
    { session_id: [], page_id: "page_schema" },
    { session_id: "ses_schema" },
    { ...valid, page_id: {} },
    { ...valid, finish_session: "yes" },
    ...[[], {}, true, 42].map((target_event_id) => Object.assign(valid, { target_event_id })),
  ];
  for (const body of malformedBodies) {
    const rejected = await drainRaw(body);
    expect(rejected.status).toBe(422);
    expect(await errorCode(rejected)).toBe("runtime_event_drain_invalid");
  }

  // Finishing a session is a no-op page (there is no lease to release), and the
  // event is still there for a real drain.
  const finished = await drainEvents(rt, { finish_session: true, page_id: undefined });
  expect(finished.events).toEqual([]);
  expect(eventIds(await drainEvents(rt))).toEqual(["evt_schema_guard"]);
});

test("managed PHP HTTP telemetry drains safely, accounts for overflow and repeats until acknowledged", async () => {
  const upstream = Bun.serve({
    port: 0,
    fetch: () => new Response("private-response", { status: 418 }),
  });
  const root = storagePath(rt);
  const source = path.resolve(import.meta.dir, "../engine/runtime/access-rules.php");
  try {
    const script = `
      require $argv[1]; require dirname($argv[1]) . '/../shared/http.php';
      _stattic_access_private_root($argv[2]);
      $GLOBALS['SPACEFAST_PAGE_SERVING'] = ['space_id' => 'spc_telemetry_drain', 'version_id' => 'ver_telemetry_drain'];
      $store = _stattic_security_budget_store($argv[2]);
      _stattic_record_store_ensure($store);
      _stattic_record_store_put($store, hash('sha256', 'spc_telemetry_drain'), ['space_id' => 'spc_telemetry_drain', 'window' => intdiv(time(), 3600), 'admitted' => STATTIC_SECURITY_TELEMETRY_HOURLY_LIMIT - 3, 'dropped' => 0, 'reported' => 0]);
      $a = _stattic_http_request(['url' => $argv[3] . '/private-path?token=private-query', 'method' => 'POST', 'body' => 'private-body', 'headers' => ['Authorization: Bearer private-header'], 'schemes' => ['http']]);
      $b = _stattic_http_request(['url' => 'ftp://destination.example/private-path']);
      $c = _stattic_http_request(['url' => 'http://127.0.0.1:1/private-path', 'timeout_ms' => 100, 'schemes' => ['http']]);
      _stattic_security_outbound('https://denied.example/private-path', 'GET', false, null, 0);
      _stattic_security_outbound('https://denied.example/private-path', 'GET', false, null, 0);
      echo json_encode([$a['status'], $b['status'], $c['status']]);
    `;
    const process = Bun.spawn(
      [PHP_BINARY, "-r", script, source, root, `http://127.0.0.1:${upstream.port}`],
      { stdout: "pipe", stderr: "pipe" },
    );
    const [status, stdout, stderr] = await Promise.all([
      process.exited,
      new Response(process.stdout).text(),
      new Response(process.stderr).text(),
    ]);
    expect(status).toBe(0);
    expect(stderr).toBe("");
    expect(JSON.parse(stdout)).toEqual([418, 0, 0]);
    const page = await drainEvents(rt);
    const observations = page.events.map(
      (entry) => runtimeSecurityTelemetrySchema.parse(entry.event).payload,
    );
    expect(
      observations.map((event) => (event.kind === "outbound" ? event.outcome : event.kind)),
    ).toEqual(["http_error", "denied", "failed", "dropped"]);
    expect(observations.find((event) => event.kind === "dropped")).toMatchObject({ count: 2 });
    expect(observations[0]).toMatchObject({
      host: "127.0.0.1",
      method: "POST",
      requestBytes: 12,
      responseBytes: 16,
    });
    const serialized = JSON.stringify(page);
    for (const secret of [
      "private-path",
      "private-query",
      "private-body",
      "private-header",
      "private-response",
    ])
      expect(serialized).not.toContain(secret);
    expect(eventIds(await drainEvents(rt))).toEqual(eventIds(page));
    await ackCursor(page);
    expect((await drainEvents(rt)).events).toEqual([]);
    const budgetRoot = path.join(root, "runtime/security-telemetry-budget");
    rmSync(budgetRoot, { recursive: true });
    writeFileSync(budgetRoot, "blocked directory");
    const unavailable = Bun.spawn(
      [
        PHP_BINARY,
        "-r",
        `require $argv[1]; _stattic_access_private_root($argv[2]); $GLOBALS['SPACEFAST_PAGE_SERVING'] = ['space_id' => 'spc_telemetry_drain']; _stattic_security_outbound('https://destination.example/private', 'GET', false, null, 0); echo 'continued';`,
        source,
        root,
      ],
      { stdout: "pipe", stderr: "pipe" },
    );
    const [exit, output, diagnostic] = await Promise.all([
      unavailable.exited,
      new Response(unavailable.stdout).text(),
      new Response(unavailable.stderr).text(),
    ]);
    expect(exit).toBe(0);
    expect(output).toBe("continued");
    expect(diagnostic).toContain("security.telemetry_storage_failed dropped=1");
    expect(diagnostic).not.toContain(root);
    rmSync(budgetRoot);
  } finally {
    upstream.stop(true);
  }
});

test("telemetry refuses damaged budgets and failed reservations without waiting for contended locks", async () => {
  const root = storagePath(rt);
  const source = path.resolve(import.meta.dir, "../engine/runtime/access-rules.php");
  const script = `
    require $argv[1];
    _stattic_access_private_root($argv[2]);
    $space = 'spc_telemetry_failures';
    $GLOBALS['SPACEFAST_PAGE_SERVING'] = ['space_id' => $space];
    $store = _stattic_security_budget_store($argv[2]);
    _stattic_record_store_ensure($store);
    $id = hash('sha256', $space);
    $path = _stattic_record_store_path($store, $id);
    $emit = static fn () => _stattic_security_outbound('https://destination.example/private', 'GET', false, null, 0);
    $results = [];
    foreach (['{broken', '{"space_id":"spc_telemetry_failures","admitted":-1}'] as $damaged) {
      file_put_contents($path, $damaged);
      $emit();
      $results[] = file_get_contents($path) === $damaged;
    }
    $initial = ['space_id' => $space, 'window' => intdiv(time(), 3600), 'admitted' => 0, 'dropped' => 0, 'reported' => 0];
    _stattic_record_store_put($store, $id, $initial);
    $stripe = _stattic_lock_stripe_path($store['root'], $id);
    // Block atomic staging while the budget remains readable and the journal
    // directory remains writable. No filesystem permission assumptions.
    $staging = dirname(_sf_atomic_temp_path($path));
    rename($staging, $staging . '-held');
    file_put_contents($staging, 'blocked atomic staging');
    try { $emit(); } finally {
      unlink($staging);
      rename($staging . '-held', $staging);
    }
    $results[] = _stattic_record_store_read($store, $id)['record'] === $initial;
    $results[] = !is_file($argv[2] . '/runtime/journal.jsonl');
    $zeroSpace = 'spc_zero_overflow_contention';
    $zeroStripe = _stattic_lock_stripe_path($store['root'], hash('sha256', $zeroSpace));
    foreach ([$argv[2] . '/runtime/journal.lock', $stripe, $zeroStripe] as $lock) {
      $holder = proc_open([PHP_BINARY, '-r', '$h = fopen($argv[1], "c+"); flock($h, LOCK_EX); fwrite(STDOUT, "ready\\n"); fgets(STDIN);', $lock], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
      if (!is_resource($holder) || fgets($pipes[1]) !== "ready\n") throw new RuntimeException('lock holder unavailable');
      try {
        if ($lock === $zeroStripe) {
          _stattic_security_zero_outbound(['outboundDropped' => 3], ['spaceId' => $zeroSpace]);
        } else {
          $emit(); if ($lock === $stripe) $emit();
        }
      } finally {
        fwrite($pipes[0], "release\n");
        foreach ($pipes as $pipe) fclose($pipe);
        if (proc_close($holder) !== 0) throw new RuntimeException('lock holder failed');
      }
      $results[] = _stattic_record_store_read($store, $id)['record'];
    }
    $emit();
    $results[] = _stattic_record_store_read($store, $id)['record'];
    echo json_encode($results);
  `;
  const process = Bun.spawn([PHP_BINARY, "-r", script, source, root], {
    stdout: "pipe",
    stderr: "pipe",
    timeout: 4000,
  });
  const [status, stdout, stderr] = await Promise.all([
    process.exited,
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
  ]);
  expect(status).toBe(0);
  const results = JSON.parse(stdout);
  expect(results.slice(0, 4)).toEqual([true, true, true, true]);
  expect(stderr.match(/security\.telemetry_storage_failed dropped=1/g)).toHaveLength(3);
  expect(results.slice(4)).toEqual([
    expect.objectContaining({ admitted: 1, dropped: 1 }),
    expect.objectContaining({ admitted: 1, dropped: 1 }),
    expect.objectContaining({ admitted: 1, dropped: 1 }),
    expect.objectContaining({ admitted: 2, dropped: 1 }),
  ]);
  const page = await drainEvents(rt);
  expect(page.events).toHaveLength(4);
  expect(
    page.events
      .map((entry) => runtimeSecurityTelemetrySchema.parse(entry.event))
      .filter((entry) => entry.payload.kind === "gap")
      .map((entry) => entry.space_id)
      .toSorted(),
  ).toEqual(["spc_telemetry_failures", "spc_zero_overflow_contention"]);
  expect(
    page.events.map((entry) => runtimeSecurityTelemetrySchema.parse(entry.event).payload),
  ).toEqual(
    expect.arrayContaining([
      expect.objectContaining({ kind: "outbound", host: "destination.example" }),
      expect.objectContaining({ kind: "gap", minimumCount: 1, reason: "budget_contention" }),
      expect.objectContaining({ kind: "dropped", count: 1 }),
    ]),
  );
  await ackCursor(page);
  expect((await drainEvents(rt)).events).toEqual([]);
});

test("an interrupted admitted observation recovers into the journal with its original identity", async () => {
  const root = storagePath(rt);
  const budgetRoot = path.join(root, "runtime/security-telemetry-budget");
  mkdirSync(budgetRoot, { recursive: true });
  const spaceId = "spc_interrupted_telemetry";
  const recordName = `${new Bun.CryptoHasher("sha256").update(spaceId).digest("hex")}.json`;
  // Opening a FIFO for append blocks after the real budget reservation, before
  // any journal bytes can be written. Wait on the atomic record's rename.
  const fifo = Bun.spawn(["mkfifo", journalPath()], { stdout: "pipe", stderr: "pipe" });
  expect(await fifo.exited).toBe(0);
  const changes = watch(budgetRoot, { signal: AbortSignal.timeout(4000) });
  const reserved = (async () => {
    for await (const change of changes) if (change.filename === recordName) return;
    throw new Error("reservation was not written");
  })();
  const child = Bun.spawn(
    [
      PHP_BINARY,
      "-r",
      `require $argv[1]; _stattic_security_record($argv[2], $argv[3], null, ['kind' => 'session', 'id' => str_repeat('e', 32), 'observedAt' => gmdate('c'), 'lifecycle' => 'issued', 'sessionId' => str_repeat('f', 64), 'previousSessionId' => null, 'requestId' => null]);`,
      path.resolve(import.meta.dir, "../engine/shared/security-telemetry.php"),
      root,
      spaceId,
    ],
    { stdout: "pipe", stderr: "pipe" },
  );
  try {
    await reserved;
  } finally {
    child.kill(9);
    await child.exited;
    rmSync(journalPath(), { force: true });
  }
  const reservation = await Bun.file(path.join(budgetRoot, recordName)).text();
  expect(JSON.parse(reservation)).toMatchObject({
    admitted: 1,
    pending: { payload: { id: "e".repeat(32) } },
  });
  const recovered = await drainEvents(rt);
  expect(recovered.events).toHaveLength(1);
  expect(runtimeSecurityTelemetrySchema.parse(recovered.events[0]?.event).payload).toMatchObject({
    id: "e".repeat(32),
    kind: "session",
  });
  await ackCursor(recovered);
  // The other crash window leaves the slot behind after a successful append.
  // Replaying that slot keeps the same ID, which intake already deduplicates.
  writeFileSync(path.join(budgetRoot, recordName), reservation);
  const replayed = await drainEvents(rt);
  expect(eventIds(replayed)).toEqual(eventIds(recovered));
  await ackCursor(replayed);
  expect((await drainEvents(rt)).events).toEqual([]);
});

test("native Zero outbound metadata crosses PHP into the authenticated journal drain", async () => {
  await deploy(rt, {
    spaceId: "spc_zero_telemetry",
    versionId: "ver_zero_telemetry",
    metadata: { mode: "website", title: "Outbound metadata fixture" },
    files: { "index.html": "fixture" },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/observe",
          capabilities: { db: false, fetch: true },
          source: `globalThis.__statticFetchHost(JSON.stringify({url: 'https://127.0.0.1/private-path?token=private-query', method: 'GET'})); globalThis.__statticZeroResult = JSON.stringify({status: 200, body: 'ok'});`,
        },
      ],
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: ["zero-telemetry.test"],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const response = await get(rt, "zero-telemetry.test", "/api/observe");
  expect(response.status).toBe(200);
  expect(await response.text()).toBe("ok");
  const page = await drainEvents(rt);
  const receipts = page.events
    .filter((entry) => entry.event.event === "security.telemetry")
    .map((entry) => runtimeSecurityTelemetrySchema.parse(entry.event));
  expect(receipts).toHaveLength(1);
  expect(receipts[0]).toMatchObject({
    space_id: "spc_zero_telemetry",
    version_id: "ver_zero_telemetry",
    payload: { host: "127.0.0.1", method: "GET", permitted: false, outcome: "denied" },
  });
  const serialized = JSON.stringify(receipts);
  expect(serialized).not.toContain("private-path");
  expect(serialized).not.toContain("private-query");
  await ackCursor(page);
  expect((await drainEvents(rt)).events).toEqual([]);
});
