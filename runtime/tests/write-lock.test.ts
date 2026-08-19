// Runtime write-lock hardening (management.php _stattic_runtime_with_write_lock /
// _stattic_runtime_with_space_write_lock). Before this change ONE site-wide
// flock serialized every mutating management route across every space on a
// many-space shared site (production incident: commit a06d0571c "ride out
// write-lock contention; serialize same-site CI deploys"). Exactly
// Config-shaped routes (update_route, update_hostname_intent,
// update_tombstones, update_retention_policy) take a per-space lock file so
// unrelated spaces stop contending, and after the
// finalize-family write-audit so did finalize_version and delete_version:
// their writes are space-confined (version trees, per-space blob CAS, pointer
// flip) or independently serialized (journal append, the always-innermost
// routes/index.lock, one-shot content/randomly-addressed spool files).
// repair_space is now the ONLY site-wide row (see admin/api.php's lock-scope
// classification comment): it rebuilds the full cross-space route index, and
// serializing it against every mutation is the point. delete_space moved to a
// per-space lock file outside the tree it deletes, so it no longer contends on
// the site-wide lock. The event drain/ack pair took the site lock back when it
// was a push lane with leases to hand out; it is now a cursor read over
// journal.jsonl (D53), whose two inputs — the append-only journal and the
// cursor file — serialize themselves, so it takes no write lock at all and must
// not queue behind a publish.
//
// Behavioral only: every assertion races real management requests against a
// REAL flock held externally on the exact lock file path, each request run
// as its own OS process through the SSH management dispatcher
// (admin/dispatch.php — the same transport runtime/tests/dispatch.test.ts
// exercises). Two independent `php` CLI processes give genuine OS-level
// concurrency; racing HTTP requests against a single `php -S` dev server
// instead was tried and is flaky (its worker-pool scheduling under
// PHP_CLI_SERVER_WORKERS does not reliably hand two near-simultaneous
// connections to two different workers, which produces false "serialized"
// timings unrelated to the runtime's own locking).
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import { mkdirSync } from "node:fs";
import path from "node:path";

import {
  createDeclaredSession,
  deploy,
  dispatchCli,
  dispatchEnvelope,
  publicAccessConfig,
  RUNTIME_HTTP_API_BASE,
  startRuntime,
  storagePath,
  uploadSessionBlobs,
  type Runtime,
} from "./harness.ts";

let rt: Runtime;

const SPACE_A = "spc_lock_a";
const SPACE_B = "spc_lock_b";
const HOLD_MS = 700;
// Slack below HOLD_MS accounts for the runtime's own retry backoff
// (shared/lock.php, capped at SPACEFAST_LOCK_RETRY_MAX_US = 100ms).
const BLOCKED_FLOOR_MS = HOLD_MS - 150;

const PHP_BINARY = process.env.PHP_BINARY ?? "php";

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE_A,
    versionId: "ver_lock_a1",
    files: { "index.html": "a" },
  });
  await deploy(rt, {
    spaceId: SPACE_B,
    versionId: "ver_lock_b1",
    files: { "index.html": "b" },
  });
}, 30000);

afterAll(() => rt?.stop());

function siteLockPath(): string {
  return storagePath(rt, "runtime", "write.lock");
}

// Mirrors `_stattic_space_write_lock_path`: outside the space tree, so
// `delete_space`'s recursive removal cannot unlink the lock it depends on.
function spaceLockPath(spaceId: string): string {
  return storagePath(rt, "runtime", "locks", "spaces", `${spaceId}.lock`);
}

// The one site-wide artifact a space-scoped write still touches, and the
// always-innermost lock in the ordering.
function routeIndexLockPath(): string {
  return storagePath(rt, "routes", "index.lock");
}

// Acquires a real, blocking, exclusive flock on `lockPath` in a detached PHP
// child process and holds it for `holdMs` before releasing. Resolves once the
// lock is confirmed held (the child prints "locked" right after flock()
// returns), so the caller never races the hold's own setup.
function holdFlockExternally(lockPath: string, holdMs: number): Promise<void> {
  mkdirSync(path.dirname(lockPath), { recursive: true });
  return new Promise((resolve, reject) => {
    const child = spawn(PHP_BINARY, [
      "-r",
      [
        '$f = fopen($argv[1], "c");',
        'if ($f === false || !flock($f, LOCK_EX)) { fwrite(STDERR, "lock_failed\\n"); exit(1); }',
        'fwrite(STDOUT, "locked\\n"); fflush(STDOUT);',
        "usleep((int) $argv[2] * 1000);",
        "flock($f, LOCK_UN); fclose($f);",
      ].join(" "),
      lockPath,
      String(holdMs),
    ]);
    let settled = false;
    child.stdout.on("data", (chunk: Buffer) => {
      if (!settled && chunk.toString().includes("locked")) {
        settled = true;
        resolve();
      }
    });
    child.on("error", (error) => {
      if (!settled) {
        settled = true;
        reject(error);
      }
    });
    child.on("exit", (code) => {
      if (!settled) {
        settled = true;
        reject(new Error(`flock helper exited early with code ${code}`));
      }
    });
  });
}

// Holds a real lock until the caller explicitly releases it. This proves a
// request does not acquire that lock without comparing unrelated process
// startup/finalizer latency against a timing threshold.
function holdFlockUntilReleased(lockPath: string): Promise<() => Promise<void>> {
  mkdirSync(path.dirname(lockPath), { recursive: true });
  return new Promise((resolve, reject) => {
    const child = spawn(PHP_BINARY, [
      "-r",
      [
        '$f = fopen($argv[1], "c");',
        'if ($f === false || !flock($f, LOCK_EX)) { fwrite(STDERR, "lock_failed\\n"); exit(1); }',
        'fwrite(STDOUT, "locked\\n"); fflush(STDOUT);',
        "fgets(STDIN);",
        "flock($f, LOCK_UN); fclose($f);",
      ].join(" "),
      lockPath,
    ]);
    let settled = false;
    let exitResolve: (() => void) | undefined;
    let exitReject: ((error: Error) => void) | undefined;
    const exited = new Promise<void>((resolveExit, rejectExit) => {
      exitResolve = resolveExit;
      exitReject = rejectExit;
    });
    child.stdout.on("data", (chunk: Buffer) => {
      if (!settled && chunk.toString().includes("locked")) {
        settled = true;
        resolve(async () => {
          child.stdin.end("release\n");
          await exited;
        });
      }
    });
    child.on("error", (error) => {
      if (!settled) {
        settled = true;
        reject(error);
      } else {
        exitReject?.(error);
      }
    });
    child.on("exit", (code) => {
      if (!settled) {
        settled = true;
        reject(new Error(`flock helper exited early with code ${code}`));
      } else if (code === 0) {
        exitResolve?.();
      } else {
        exitReject?.(new Error(`flock helper exited with code ${code}`));
      }
    });
  });
}

// Runs one management request through the shared CLI dispatcher driver as its
// own OS process — genuine process-level concurrency between two calls, unlike
// racing fetch() against a shared dev-server connection pool. `elapsedMs` is
// measured around the child, which the contention-window assertions below need.
async function dispatchTimed(
  request: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown>; elapsedMs: number; stderr: string }> {
  const result = await dispatchCli(rt, JSON.stringify(request));
  if (result.exitCode !== 0) {
    throw new Error(`dispatch exited ${result.exitCode} with stderr:\n${result.stderr}`);
  }
  const envelope = JSON.parse(result.stdout) as {
    status: number;
    body: Record<string, unknown>;
  };
  return {
    status: envelope.status,
    body: envelope.body,
    elapsedMs: result.elapsedMs,
    stderr: result.stderr,
  };
}

// On a status mismatch surface the dispatch envelope body too — a bare status
// diff (like this suite's CI-only 404s) is undebuggable from CI logs alone.
function expectDispatchStatus(
  result: { status: number; body: Record<string, unknown>; stderr?: string },
  expected: number,
): void {
  if (result.status !== expected) {
    throw new Error(
      [
        `dispatch returned ${result.status} (expected ${expected}): ${JSON.stringify(result.body)}`,
        result.stderr?.trim(),
      ]
        .filter(Boolean)
        .join("\n"),
    );
  }
}

function updateTombstonesDispatch(spaceId: string) {
  return dispatchTimed(
    dispatchEnvelope(
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [] },
    ),
  );
}

test("a per-space write lock does not serialize mutations on a different space", async () => {
  const releaseSpaceALock = await holdFlockUntilReleased(spaceLockPath(SPACE_A));
  const resultAPromise = updateTombstonesDispatch(SPACE_A);

  // Space B's lock is a different file entirely: its mutation completes
  // while A's lock is still held. Release only after that real completion
  // signal, avoiding process-startup timing as a proxy for lock independence.
  const resultB = await updateTombstonesDispatch(SPACE_B).finally(releaseSpaceALock);
  const resultA = await resultAPromise;

  expectDispatchStatus(resultA, 200);
  expectDispatchStatus(resultB, 200);
});

test("update_tombstones takes the per-space lock: free of the site lock, serialized on its own space", async () => {
  // update_tombstones writes its own space's tombstones.json and rebuilds the
  // shared cross-space route index — but the index has its own always-innermost
  // lock now (routes/index.lock, covered by the index-lock tests), so the call
  // no longer rides the site-wide lock. A held SITE lock must not delay it; a
  // held lock on ITS OWN space still serializes it.
  const releaseSiteLock = await holdFlockUntilReleased(siteLockPath());
  const underSiteLock = await updateTombstonesDispatch(SPACE_A).finally(releaseSiteLock);
  expectDispatchStatus(underSiteLock, 200);

  await holdFlockExternally(spaceLockPath(SPACE_A), HOLD_MS);
  const underOwnSpaceLock = await updateTombstonesDispatch(SPACE_A);
  expectDispatchStatus(underOwnSpaceLock, 200);
  expect(underOwnSpaceLock.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

function updateRouteDispatch(
  spaceId: string,
  versionId: string,
  changedPaths?: string[],
  hostnames: string[] = [],
) {
  return dispatchTimed(
    dispatchEnvelope(
      "PUT",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/routes/production`,
      "update_route",
      { space_id: spaceId, route_name: "production" },
      {
        version_id: versionId,
        config: publicAccessConfig({ mode: "website" }),
        production_hostnames: hostnames,
        version_hostnames: [],
        ...(changedPaths ? { changed_paths: changedPaths } : {}),
      },
    ),
  );
}

// update_route is the Config-shaped route the incident was actually about: the
// deploy that could not flip its pointer because an unrelated space held the
// site-wide lock.
test("update_route takes the per-space lock: free of the site lock, serialized on its own space", async () => {
  const releaseSiteLock = await holdFlockUntilReleased(siteLockPath());
  const underSiteLock = await updateRouteDispatch(SPACE_A, "ver_lock_a1").finally(releaseSiteLock);
  expectDispatchStatus(underSiteLock, 200);

  await holdFlockExternally(spaceLockPath(SPACE_A), HOLD_MS);
  const underOwnSpaceLock = await updateRouteDispatch(SPACE_A, "ver_lock_a1");
  expectDispatchStatus(underOwnSpaceLock, 200);
  expect(underOwnSpaceLock.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

test("a foreign space's lock leaves update_route alone; the shared route index still gates it", async () => {
  const releaseForeignLock = await holdFlockUntilReleased(spaceLockPath(SPACE_A));
  const underForeignLock = await updateRouteDispatch(SPACE_B, "ver_lock_b1").finally(
    releaseForeignLock,
  );
  expectDispatchStatus(underForeignLock, 200);

  // `changed_paths` forces a real pointer write: an unchanged replay returns
  // before it ever reaches the shared index, and would pass this vacuously.
  await holdFlockExternally(routeIndexLockPath(), HOLD_MS);
  const underIndexLock = await updateRouteDispatch(
    SPACE_B,
    "ver_lock_b1",
    ["/index.html"],
    ["lock-b.test"],
  );
  expectDispatchStatus(underIndexLock, 200);
  expect(underIndexLock.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

// Stages a finalizable version: declared session + every blob negotiated and
// PUT by sha (the ingest half of a v4 publish, §9). Returns the upload id the
// finalize call must present. Deliberately NOT the harness's deploy(): finalize
// is the call under test and has to be dispatched separately, timed.
async function stageFinalizableVersion(spaceId: string, versionId: string): Promise<string> {
  const files = { "index.html": `${spaceId}/${versionId}` };
  const session = await createDeclaredSession(rt, spaceId, versionId, files);
  await uploadSessionBlobs(rt, session, files);
  return session.uploadId;
}

function finalizeDispatch(spaceId: string, versionId: string, uploadId: string) {
  return dispatchTimed(
    dispatchEnvelope(
      "POST",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/versions/${versionId}/finalize`,
      "finalize_version",
      { space_id: spaceId, version_id: versionId },
      { upload_id: uploadId },
    ),
  );
}

function deleteVersionDispatch(spaceId: string, versionId: string) {
  return dispatchTimed(
    dispatchEnvelope(
      "POST",
      `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/versions/${versionId}/delete`,
      "delete_version",
      { space_id: spaceId, version_id: versionId },
    ),
  );
}

test("finalize_version takes the per-space lock: free of the site lock, serialized on its own space", async () => {
  // The finalize-family write-audit (management.php classification comment):
  // every finalize write is version-tree/space-confined or independently
  // serialized, so a long site-locked mutation elsewhere must not delay it —
  // this was the minutes-long hold that queued every other space's config PUT.
  const spaceId = "spc_lock_fin_scope";
  const siteUpload = await stageFinalizableVersion(spaceId, "ver_lock_fin_site");
  const releaseSiteLock = await holdFlockUntilReleased(siteLockPath());
  const underSiteLock = await finalizeDispatch(spaceId, "ver_lock_fin_site", siteUpload).finally(
    releaseSiteLock,
  );
  expectDispatchStatus(underSiteLock, 200);

  // Same-space finalizes still serialize on the space's own lock file.
  const ownUpload = await stageFinalizableVersion(spaceId, "ver_lock_fin_own");
  await holdFlockExternally(spaceLockPath(spaceId), HOLD_MS);
  const underOwnSpaceLock = await finalizeDispatch(spaceId, "ver_lock_fin_own", ownUpload);
  expectDispatchStatus(underOwnSpaceLock, 200);
  expect(underOwnSpaceLock.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

test("concurrent finalizes on different spaces do not serialize", async () => {
  const blockedSpace = "spc_lock_fin_cross_a";
  const freeSpace = "spc_lock_fin_cross_b";
  const blockedUpload = await stageFinalizableVersion(blockedSpace, "ver_lock_fin_cross_a");
  const freeUpload = await stageFinalizableVersion(freeSpace, "ver_lock_fin_cross_b");

  const releaseBlockedLock = await holdFlockUntilReleased(spaceLockPath(blockedSpace));
  const blockedPromise = finalizeDispatch(blockedSpace, "ver_lock_fin_cross_a", blockedUpload);

  // The free space completes while the other space's lock remains held. That
  // completion is the release signal, so unrelated process latency cannot
  // masquerade as shared-lock contention.
  const free = await finalizeDispatch(freeSpace, "ver_lock_fin_cross_b", freeUpload).finally(
    releaseBlockedLock,
  );
  const blocked = await blockedPromise;

  expectDispatchStatus(blocked, 200);
  expectDispatchStatus(free, 200);
});

test("delete_version takes the per-space lock: free of the site lock, serialized on its own space", async () => {
  const spaceId = "spc_lock_del_scope";
  await deploy(rt, {
    spaceId,
    versionId: "ver_lock_del_site",
    files: { "index.html": "delete under site lock" },
  });
  await deploy(rt, {
    spaceId,
    versionId: "ver_lock_del_own",
    files: { "index.html": "delete under own lock" },
  });

  const releaseSiteLock = await holdFlockUntilReleased(siteLockPath());
  const underSiteLock = await deleteVersionDispatch(spaceId, "ver_lock_del_site").finally(
    releaseSiteLock,
  );
  expectDispatchStatus(underSiteLock, 200);

  await holdFlockExternally(spaceLockPath(spaceId), HOLD_MS);
  const underOwnSpaceLock = await deleteVersionDispatch(spaceId, "ver_lock_del_own");
  expectDispatchStatus(underOwnSpaceLock, 200);
  expect(underOwnSpaceLock.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

test("repair alone serializes on the site-wide write lock; space delete and the event drain do not", async () => {
  const deleteSpace = "spc_lock_delete_space";
  await deploy(rt, {
    spaceId: deleteSpace,
    versionId: "ver_lock_delete_space_1",
    files: { "index.html": "delete space" },
  });

  await holdFlockExternally(siteLockPath(), HOLD_MS);
  const [repair, deleted, drained] = await Promise.all([
    // repair_space is the last site-wide row: the recovery hammer rebuilds the
    // whole cross-space route index, so serializing it against every mutation
    // on the site is the point.
    dispatchTimed(
      dispatchEnvelope(
        "POST",
        `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE_A}/repair`,
        "repair_space",
        {
          space_id: SPACE_A,
        },
      ),
    ),
    // Space deletion is NOT on that list any more. Its lock file used to live
    // inside the tree it deletes, which made a per-space lock unsafe and forced
    // it onto the site lock; the lock now lives at
    // runtime/locks/spaces/{spaceId}.lock, so the delete contends with its OWN
    // space's finalize (which is the pairing that matters) and with nothing
    // else. Held site lock, unrelated space: it must go straight through.
    dispatchTimed(
      dispatchEnvelope(
        "POST",
        `${RUNTIME_HTTP_API_BASE}/spaces/${deleteSpace}/delete`,
        "delete_space",
        { space_id: deleteSpace },
      ),
    ),
    // Neither is the event drain, any more. It took the site lock as a push
    // lane handing out leases; the pull lane (D53) only reads journal.jsonl
    // from the persisted cursor, and both of those serialize themselves — so it
    // holds no write lock and a site-locked repair must never queue it.
    dispatchTimed(
      dispatchEnvelope(
        "POST",
        `${RUNTIME_HTTP_API_BASE}/events/drain`,
        "drain_events",
        {},
        {
          session_id: "ses_lock_drain",
          page_id: "page_lock_drain",
        },
      ),
    ),
  ]);

  expectDispatchStatus(repair, 200);
  expectDispatchStatus(deleted, 200);
  expectDispatchStatus(drained, 200);
  expect(repair?.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
  expect(deleted?.elapsedMs).toBeLessThan(BLOCKED_FLOOR_MS);
  expect(drained?.elapsedMs).toBeLessThan(BLOCKED_FLOOR_MS);
  expect(repair?.body).toMatchObject({ space_id: SPACE_A, status: "repaired" });
  expect(deleted?.body).toMatchObject({ space_id: deleteSpace, status: "deleted" });
  // The drain really ran the journal read rather than short-circuiting: it hands
  // back a page-end cursor over runtime/journal.jsonl.
  expect(drained?.body.cursor).toMatchObject({ offset: expect.any(Number) });
});
