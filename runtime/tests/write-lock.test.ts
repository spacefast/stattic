// Runtime write-lock scoping (management.php _stattic_runtime_with_write_lock /
// _stattic_runtime_with_space_write_lock).
//
// The Config-shaped routes (update_route, update_hostname_intent,
// update_tombstones) plus finalize_version and delete_version take a per-space
// lock file, so unrelated spaces do not
// contend: their writes are space-confined (version trees, per-space blob CAS,
// pointer flip) or independently serialized (journal append, the
// always-innermost routes/index.lock, one-shot randomly-addressed spool files).
// repair_space is the only site-wide row (see admin/api.php's lock-scope
// classification comment): it rebuilds the full cross-space route index, and
// serializing it against every mutation is the point. delete_space takes a
// per-space lock file outside the tree it deletes. The event drain/ack pair is
// a cursor read over journal.jsonl (D53), whose two inputs serialize
// themselves, so it takes no write lock and must not queue behind a publish.
//
// Every assertion races real management requests against a real flock held
// externally on the exact lock file path, each request run as its own OS
// process through the SSH management dispatcher (admin/dispatch.php, the same
// transport runtime/tests/dispatch.test.ts exercises). Two independent `php`
// CLI processes give real OS-level concurrency. Racing HTTP requests against a
// single `php -S` dev server is flaky instead: its worker-pool scheduling under
// PHP_CLI_SERVER_WORKERS does not reliably hand two near-simultaneous
// connections to two different workers, which fakes serialized timings.
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

// Holds a real exclusive flock on `lockPath` from a PHP child process for
// `holdMs`. Resolves once the child confirms the lock is held, so the caller
// never races the hold's own setup.
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

// Holds a real lock until the caller releases it. Proves a request does not
// take that lock without weighing unrelated process latency against a timing
// threshold.
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

// Runs one management request through the shared CLI dispatcher as its own OS
// process, giving real concurrency between two calls. `elapsedMs` is measured
// around the child, which the contention-window assertions below need.
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

// On a status mismatch, surface the dispatch envelope body: a bare status diff
// is undebuggable from CI logs alone.
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

  // Space B's lock is a different file, so its mutation completes while A's
  // lock is still held. Releasing on that completion avoids using
  // process-startup timing as a proxy for lock independence.
  const resultB = await updateTombstonesDispatch(SPACE_B).finally(releaseSpaceALock);
  const resultA = await resultAPromise;

  expectDispatchStatus(resultA, 200);
  expectDispatchStatus(resultB, 200);
});

test("update_tombstones takes the per-space lock: free of the site lock, serialized on its own space", async () => {
  // update_tombstones writes its own space's tombstones.json and rebuilds the
  // shared cross-space route index, which has its own always-innermost lock
  // (routes/index.lock). A held site lock must not delay it. A held lock on its
  // own space still serializes it.
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
  // before reaching the shared index and would pass vacuously.
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

// Stages a finalizable version: a declared session plus every blob negotiated
// and PUT by sha. Returns the upload id finalize must present. Not the
// harness's deploy(), because finalize is the call under test and has to be
// dispatched separately and timed.
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
  // Every finalize write is version-tree/space-confined or independently
  // serialized (management.php classification comment), so a long site-locked
  // mutation elsewhere must not delay it.
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

  // The free space completes while the other space's lock is still held. That
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
    // repair_space rebuilds the whole cross-space route index, so serializing
    // it against every mutation on the site is the point.
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
    // Space deletion locks runtime/locks/spaces/{spaceId}.lock, outside the
    // tree it deletes, so it contends only with its own space's finalize. A
    // held site lock on an unrelated space must not delay it.
    dispatchTimed(
      dispatchEnvelope(
        "POST",
        `${RUNTIME_HTTP_API_BASE}/spaces/${deleteSpace}/delete`,
        "delete_space",
        { space_id: deleteSpace },
      ),
    ),
    // The event drain holds no write lock: the pull lane (D53) only reads
    // journal.jsonl from the persisted cursor, and both of those serialize
    // themselves. A site-locked repair must never queue it.
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
  // The drain really read the journal instead of short-circuiting: it hands
  // back a page-end cursor over runtime/journal.jsonl.
  expect(drained?.body.cursor).toMatchObject({ offset: expect.any(Number) });
});
