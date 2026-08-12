// The event pull lane (contracts §10, D53): `runtime/journal.jsonl` is the ONLY
// event sink. There is no callback record store, no push transport, no lease and
// no callback credential — the control plane is blocked on the drain response,
// so a page of journal records is handed back in the body and
// `runtime/journal-cursor.json` (advanced by ACK, never backwards) is what makes
// the next page start where this one stopped.
//
// Everything here is the drain/ack seam itself: which records reach the wire,
// how far one page reaches, and what an acknowledgement is allowed to commit.
// That management operations write the journal records in the first place is
// held where those operations live (uploads.test.ts, route-index.test.ts).
import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { appendFileSync, mkdirSync, readdirSync, rmSync } from "node:fs";
import path from "node:path";

import {
  ackEvents,
  api,
  deploy,
  drainEvents,
  errorCode,
  journalRecords,
  publicAccessConfig,
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

// Each test owns the whole sink: the drain's contract is "read journal.jsonl
// from the persisted cursor", so both are reset to the state of a fresh site.
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

/** Append raw journal lines — the sink's own NDJSON format (shared/ndjson-log.php). */
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

test("a drain hands back the journal records a real publish wrote, and an ack consumes them", async () => {
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

  // The wire page must be exactly the journal's own management records, in the
  // journal's order, payload for payload.
  const written = journalRecords(rt).filter(
    (record) => typeof record.event_id === "string" && record.event_id !== "",
  );
  const page = await drainEvents(rt);

  expect(page.events.map((entry) => entry.event)).toEqual(written);
  expect(eventIds(page)).toEqual(written.map((record) => String(record.event_id)));
  expect(page.returned_count).toBe(page.events.length);
  expect(page.pending_count).toBe(0);
  // Both halves of a publish announce themselves, carrying the operation that
  // caused them so the control plane can correlate the drain with its own call.
  const names = page.events.map((entry) => entry.event.event);
  expect(names).toContain("version_finalized");
  expect(names).toContain("route_updated");
  expect(page.events.map((entry) => entry.operation_id)).toEqual(
    written.map((record) => String(record.operation_id)),
  );
  expect(page.cursor.file).toBe("journal.jsonl");
  expect(page.cursor.offset).toBeGreaterThan(0);

  // Draining is not consuming: only the acknowledged cursor moves the lane on.
  expect(eventIds(await drainEvents(rt))).toEqual(eventIds(page));
  expect(await ackCursor(page)).toMatchObject({ acknowledged_count: 0, stale_count: 0 });

  const drained = await drainEvents(rt);
  expect(drained.events).toEqual([]);
  expect(drained.returned_count).toBe(0);
  expect(drained.pending_count).toBe(0);
});

test("one page spans the records it does not return: diagnostics and non-target events", async () => {
  // Only management records carry an event_id. Operator diagnostics (job
  // lifecycle, GC, purge receipts) share the sink and must never reach the wire
  // — but the page cursor still has to reach past them, or a diagnostic run
  // would wedge the lane forever.
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
  // cursor stops at its own record: the page reached past the trailing
  // diagnostic, the last event's cursor did not.
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

  // The page ends on an event here, so the last event's cursor IS the page
  // cursor — the page-level field is the end of the same sequence.
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
    const rejected = await ackRaw({ session_id: "ses_ack_batch", deliveries });
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
