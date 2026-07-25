// Runtime write-lock hardening (management.php _stattic_runtime_with_write_lock /
// _stattic_runtime_with_space_write_lock). Before this change ONE site-wide
// flock serialized every mutating management route across every space on a
// many-space shared site (production incident: commit a06d0571c "ride out
// write-lock contention; serialize same-site CI deploys"). Exactly
// revoke_grant/unrevoke_grant — the instant-revoke-racing-deploys pair the
// incident was about — take a per-space lock file so unrelated spaces stop
// contending; the config-shaped routes (update_route, update_hostname_intent,
// update_tombstones, update_retention_policy) joined them, and after the
// finalize-family write-audit so did finalize_version and delete_version:
// their writes are space-confined (version trees, per-space blob CAS, pointer
// flip) or independently serialized (journal append, the always-innermost
// routes/index.lock, one-shot content/randomly-addressed spool files). The
// delete_space/repair_space/transfer family keeps the site-wide lock (space
// delete removes its own lock file, repair is the site-wide recovery hammer,
// transfers carry their space in the body not the lockable URL scope), and
// the import steps' owning space comes from the job record, not the request
// scope the dispatcher could lock on — see management.php's lock-scope
// classification comment. Handlers that replace config.revocations
// additionally take the target space's per-space lock around the
// revocations.json read-modify-write, so every writer of that file serializes
// on the same lock (the lost-revocation race: a revoke_grant landing between
// a replace's load and store would otherwise be silently dropped).
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
import { mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  api,
  apiJson,
  createDeclaredSession,
  deploy,
  dispatchCli,
  managementToken,
  putFile,
  runtimeHttpPath,
  startRuntime,
  type Runtime,
} from "./harness.ts";

let rt: Runtime;

const SPACE_A = "spc_lock_a";
const SPACE_B = "spc_lock_b";
const HOLD_MS = 700;
// Slack below HOLD_MS accounts for the runtime's own retry poll interval
// (SPACEFAST_RUNTIME_WRITE_LOCK_RETRY_US = 100ms).
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
  return path.join(rt.storageRoot, "runtime", "write.lock");
}

function spaceLockPath(spaceId: string): string {
  return path.join(rt.storageRoot, "spaces", spaceId, "write.lock");
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
): Promise<{ status: number; body: Record<string, unknown>; elapsedMs: number }> {
  const result = await dispatchCli(rt, JSON.stringify(request));
  if (result.exitCode !== 0) {
    throw new Error(`dispatch exited ${result.exitCode} with stderr:\n${result.stderr}`);
  }
  const envelope = JSON.parse(result.stdout) as {
    status: number;
    body: Record<string, unknown>;
  };
  return { status: envelope.status, body: envelope.body, elapsedMs: result.elapsedMs };
}

// On a status mismatch surface the dispatch envelope body too — a bare status
// diff (like this suite's CI-only 404s) is undebuggable from CI logs alone.
function expectDispatchStatus(
  result: { status: number; body: Record<string, unknown> },
  expected: number,
): void {
  if (result.status !== expected) {
    throw new Error(
      `dispatch returned ${result.status} (expected ${expected}): ${JSON.stringify(result.body)}`,
    );
  }
}

function revokeGrantDispatch(spaceId: string, grant: string) {
  return dispatchTimed({
    method: "POST",
    path: runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/access/revocations`),
    authorization: `Bearer ${managementToken("revoke_grant", { space_id: spaceId })}`,
    body: JSON.stringify({ grant }),
  });
}

test("a per-space write lock does not serialize mutations on a different space", async () => {
  const releaseSpaceALock = await holdFlockUntilReleased(spaceLockPath(SPACE_A));
  const resultAPromise = revokeGrantDispatch(SPACE_A, "link:lock_cross_a");

  // Space B's write.lock is a different file entirely: its mutation completes
  // while A's lock is still held. Release only after that real completion
  // signal, avoiding process-startup timing as a proxy for lock independence.
  const resultB = await revokeGrantDispatch(SPACE_B, "link:lock_cross_b").finally(
    releaseSpaceALock,
  );
  const resultA = await resultAPromise;

  expectDispatchStatus(resultA, 200);
  expectDispatchStatus(resultB, 200);
});

test("same-space mutations still serialize on the shared per-space lock", async () => {
  await holdFlockExternally(spaceLockPath(SPACE_A), HOLD_MS);

  const [first, second] = await Promise.all([
    revokeGrantDispatch(SPACE_A, "link:lock_same_1"),
    revokeGrantDispatch(SPACE_A, "link:lock_same_2"),
  ]);

  // Both requests target space A's write.lock — the same file the external
  // holder locked — so both must wait out the hold, proving revoke_grant
  // really does acquire a stable, shared-by-space lock file rather than (say)
  // a unique-per-request path that would let concurrent same-space writes
  // race each other.
  expectDispatchStatus(first, 200);
  expect(first.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
  expectDispatchStatus(second, 200);
  expect(second.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

test("revoke_grant is unaffected by the site-wide write lock", async () => {
  const releaseSiteLock = await holdFlockUntilReleased(siteLockPath());

  // revoke_grant is space-scoped: it completes while runtime/write.lock stays
  // held, proving it never acquires the site-wide lock.
  const result = await revokeGrantDispatch(SPACE_A, "link:lock_site_unrelated").finally(
    releaseSiteLock,
  );

  expectDispatchStatus(result, 200);
});

function replaceRevocationsDispatch(spaceId: string, versionId: string, grants: string[]) {
  return dispatchTimed({
    method: "PUT",
    path: runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/routes/production`),
    authorization: `Bearer ${managementToken("update_route", {
      space_id: spaceId,
      route_name: "production",
    })}`,
    body: JSON.stringify({ version_id: versionId, config: { revocations: grants } }),
  });
}

function storedRevocations(spaceId: string): {
  grants: Record<string, number>;
  subs: Record<string, number>;
} {
  return JSON.parse(
    readFileSync(path.join(rt.storageRoot, "spaces", spaceId, "revocations.json"), "utf8"),
  ) as { grants: Record<string, number>; subs: Record<string, number> };
}

test("incremental revocation updates retain old tombstones until explicit unrevoke", async () => {
  const oldTimestamp = Math.floor(Date.now() / 1000) - 40 * 24 * 60 * 60;
  const revocationsPath = path.join(rt.storageRoot, "spaces", SPACE_A, "revocations.json");
  writeFileSync(
    revocationsPath,
    JSON.stringify({
      grants: { "svc:stk_long_lived": oldTimestamp },
      subs: { "user:departed": oldTimestamp },
      updatedAt: oldTimestamp,
    }),
  );

  const unrelatedRevoke = await revokeGrantDispatch(SPACE_A, "link:lnk_unrelated");
  expectDispatchStatus(unrelatedRevoke, 200);
  const retained = storedRevocations(SPACE_A);
  expect(retained.grants["svc:stk_long_lived"]).toBe(oldTimestamp);
  expect(retained.subs["user:departed"]).toBe(oldTimestamp);

  const explicitUnrevoke = await api(
    rt,
    "DELETE",
    `/__spacefast/api.php/spaces/${SPACE_A}/access/revocations`,
    "unrevoke_grant",
    { space_id: SPACE_A },
    { sub: "user:departed" },
  );
  expect(explicitUnrevoke.status).toBe(200);
  const afterUnrevoke = storedRevocations(SPACE_A);
  expect(afterUnrevoke.grants["svc:stk_long_lived"]).toBe(oldTimestamp);
  expect(afterUnrevoke.subs["user:departed"]).toBeUndefined();
});

test("a config.revocations replace serializes on the same per-space lock as revoke_grant", async () => {
  // The lost-revocation race: update_route holds the SITE lock while its
  // config.revocations path read-modify-writes the same revocations.json a
  // concurrent revoke_grant mutates under the per-space lock. The fix nests
  // the per-space acquire inside the site-locked replace — proven here by
  // holding space A's per-space lock externally and observing the route PUT
  // block on it (it acquires the free site lock instantly; only the nested
  // per-space acquire can make it wait).
  await holdFlockExternally(spaceLockPath(SPACE_A), HOLD_MS);

  const result = await replaceRevocationsDispatch(SPACE_A, "ver_lock_a1", [
    "link:lock_replace_gate",
  ]);

  expectDispatchStatus(result, 200);
  expect(result.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

test("a concurrent revoke_grant is never lost under a racing config.revocations replace (both orderings)", async () => {
  for (const revokeFirst of [true, false]) {
    const suffix = revokeFirst ? "rf" : "pf";
    const revokedGrant = `link:lock_race_revoked_${suffix}`;
    const replacedGrant = `link:lock_race_replaced_${suffix}`;
    rmSync(path.join(rt.storageRoot, "spaces", SPACE_A, "revocations.json"), { force: true });

    // Two independent OS processes racing the same space's revocations.json:
    // whichever wins the per-space lock, the final file must contain BOTH the
    // instant revoke (fresh timestamp — the replace's grace window carries it
    // when the replace runs second; the revoke merges on top when it runs
    // second) and the replace's own authoritative grant.
    const launches = [
      () => revokeGrantDispatch(SPACE_A, revokedGrant),
      () => replaceRevocationsDispatch(SPACE_A, "ver_lock_a1", [replacedGrant]),
    ];
    if (!revokeFirst) {
      launches.reverse();
    }
    const [first, second] = await Promise.all([launches[0](), launches[1]()]);
    expectDispatchStatus(first, 200);
    expectDispatchStatus(second, 200);

    const stored = storedRevocations(SPACE_A);
    expect(typeof stored.grants[revokedGrant]).toBe("number");
    expect(typeof stored.grants[replacedGrant]).toBe("number");
  }
});

test("update_tombstones takes the per-space lock: free of the site lock, serialized on its own space", async () => {
  // update_tombstones writes its own space's tombstones.json and rebuilds the
  // shared cross-space route index — but the index has its own always-innermost
  // lock now (routes/index.lock, covered by the index-lock tests), so the call
  // no longer rides the site-wide lock. A held SITE lock must not delay it; a
  // held lock on ITS OWN space still serializes it.
  const releaseSiteLock = await holdFlockUntilReleased(siteLockPath());
  const underSiteLock = await dispatchTimed({
    method: "PUT",
    path: runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE_A}/tombstones`),
    authorization: `Bearer ${managementToken("update_tombstones", { space_id: SPACE_A })}`,
    body: JSON.stringify({ hostnames: [] }),
  }).finally(releaseSiteLock);
  expectDispatchStatus(underSiteLock, 200);

  await holdFlockExternally(spaceLockPath(SPACE_A), HOLD_MS);
  const underOwnSpaceLock = await dispatchTimed({
    method: "PUT",
    path: runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE_A}/tombstones`),
    authorization: `Bearer ${managementToken("update_tombstones", { space_id: SPACE_A })}`,
    body: JSON.stringify({ hostnames: [] }),
  });
  expectDispatchStatus(underOwnSpaceLock, 200);
  expect(underOwnSpaceLock.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
});

// Stages a finalizable version: declared session + the one uploaded file.
// Returns the upload id the finalize call must present.
async function stageFinalizableVersion(spaceId: string, versionId: string): Promise<string> {
  const files = { "index.html": `${spaceId}/${versionId}` };
  const session = await createDeclaredSession(rt, spaceId, versionId, files);
  const uploaded = await putFile(
    rt,
    session.uploadId,
    session.token,
    "index.html",
    files["index.html"],
  );
  expect(uploaded.status).toBe(200);
  return session.uploadId;
}

function finalizeDispatch(spaceId: string, versionId: string, uploadId: string) {
  return dispatchTimed({
    method: "POST",
    path: runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/finalize`),
    authorization: `Bearer ${managementToken("finalize_version", {
      space_id: spaceId,
      version_id: versionId,
    })}`,
    body: JSON.stringify({ upload_id: uploadId }),
  });
}

function deleteVersionDispatch(spaceId: string, versionId: string) {
  return dispatchTimed({
    method: "POST",
    path: runtimeHttpPath(`/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/delete`),
    authorization: `Bearer ${managementToken("delete_version", {
      space_id: spaceId,
      version_id: versionId,
    })}`,
  });
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

test("space delete, repair, and import mutations serialize on the site-wide write lock", async () => {
  const deleteSpace = "spc_lock_delete_space";
  await deploy(rt, {
    spaceId: deleteSpace,
    versionId: "ver_lock_delete_space_1",
    files: { "index.html": "delete space" },
  });

  let exportStatus = await apiJson<{ status: string; export_id?: string }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE_A}/exports`,
    "start_space_export",
    { space_id: SPACE_A },
    {},
    201,
  );
  expect(typeof exportStatus.export_id).toBe("string");
  if (typeof exportStatus.export_id !== "string") {
    throw new Error("space export response omitted export_id");
  }
  for (let steps = 0; exportStatus.status !== "complete"; steps += 1) {
    if (steps > 20) throw new Error(`space export did not complete: ${exportStatus.status}`);
    exportStatus = await apiJson<{ status: string; export_id?: string }>(
      rt,
      "POST",
      `/__spacefast/api.php/exports/${exportStatus.export_id}/step`,
      "step_space_export",
      { space_id: SPACE_A, export_id: exportStatus.export_id },
    );
  }
  const downloadedExport = await api(
    rt,
    "GET",
    `/__spacefast/api.php/exports/${exportStatus.export_id}/archive`,
    "download_space_export",
    { space_id: SPACE_A, export_id: exportStatus.export_id },
  );
  expect(downloadedExport.status).toBe(200);
  const exportArchive = Buffer.from(await downloadedExport.arrayBuffer());

  const importSpace = "spc_lock_import";
  const startedImport = await api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${importSpace}/imports`,
    "start_space_import",
    { space_id: importSpace },
    {
      install_access_policy: false,
      version_id_map: { ver_lock_a1: "ver_lock_import_1" },
    },
  );
  expect(startedImport.status).toBe(201);
  const importStatus = (await startedImport.json()) as { import_id?: unknown };
  expect(typeof importStatus.import_id).toBe("string");
  if (typeof importStatus.import_id !== "string") {
    throw new Error("space import response omitted import_id");
  }
  const uploadedImport = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(
      `/__spacefast/api.php/imports/${importStatus.import_id}/archive`,
    )}`,
    {
      method: "PUT",
      headers: {
        authorization: `Bearer ${managementToken("upload_space_import", {
          space_id: importSpace,
          import_id: importStatus.import_id,
        })}`,
      },
      body: exportArchive,
    },
  );
  expect(uploadedImport.status).toBe(200);

  await holdFlockExternally(siteLockPath(), HOLD_MS);
  const results = await Promise.all([
    dispatchTimed({
      method: "POST",
      path: runtimeHttpPath(`/__spacefast/api.php/spaces/${deleteSpace}/delete`),
      authorization: `Bearer ${managementToken("delete_space", { space_id: deleteSpace })}`,
    }),
    dispatchTimed({
      method: "POST",
      path: runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE_A}/repair`),
      authorization: `Bearer ${managementToken("repair_space", { space_id: SPACE_A })}`,
    }),
    dispatchTimed({
      method: "POST",
      path: runtimeHttpPath(`/__spacefast/api.php/imports/${importStatus.import_id}/step`),
      authorization: `Bearer ${managementToken("step_space_import", {
        space_id: importSpace,
        import_id: importStatus.import_id,
      })}`,
    }),
  ]);

  expect(results.map((result) => result.status)).toEqual([200, 200, 200]);
  for (const result of results) {
    expect(result.elapsedMs).toBeGreaterThanOrEqual(BLOCKED_FLOOR_MS);
  }
  expect(results[1]?.body).toMatchObject({ space_id: SPACE_A, status: "repaired" });
  expect(results[2]?.body).toMatchObject({ import_id: importStatus.import_id });
});
