import { expect, test } from "bun:test";
// The route index and its pointer swap (contracts §3). v4 has no route
// generations directory and no hardlink forwarding: shard files are immutable
// and content-addressed, so an untouched shard keeps its NAME across a pointer
// write (same content -> same sha16 -> same file) and dedupe is a property of
// the addressing rather than a reuse pass. What is left to pin here is the swap
// itself — gen monotonicity, which shards a mutation rewrites, previous.json as
// the grace anchor, shard GC by (unreferenced AND aged), and the cross-space
// correctness the incremental update's owners map exists to preserve.
import { chmodSync, existsSync, unlinkSync, utimesSync } from "node:fs";

import {
  api,
  apiJson,
  deploy,
  get,
  hostEntry,
  previousRoutePointer,
  problemDocument,
  publicAccessConfig,
  putRoute,
  routePointer,
  type RoutePointer,
  type Runtime,
  sha256,
  spaceOverlay,
  spacePointer,
  startRuntime,
  storagePath,
} from "./harness";

function shardFor(host: string): string {
  return sha256(host).slice(0, 2);
}

function hostInShard(prefix: string, shard: string): string {
  for (let i = 0; ; i += 1) {
    const candidate = `${prefix}-${i}.test`;
    if (shardFor(candidate) === shard) return candidate;
  }
}

function hostOutsideShard(prefix: string, shard: string): string {
  for (let i = 0; ; i += 1) {
    const candidate = `${prefix}-${i}.test`;
    if (shardFor(candidate) !== shard) return candidate;
  }
}

/** Every artifact a pointer names: host shards, the wildcard shard, the owners map. */
function pointerFiles(pointer: RoutePointer): string[] {
  return [...Object.values(pointer.shards), pointer.wildcards, pointer.owners].filter(
    (name): name is string => typeof name === "string" && name !== "",
  );
}

function readPointer(rt: Runtime): RoutePointer {
  const pointer = routePointer(rt);
  if (pointer === null) throw new Error("routes/current.json missing");
  return pointer;
}

function readPreviousPointer(rt: Runtime): RoutePointer {
  const pointer = previousRoutePointer(rt);
  if (pointer === null) throw new Error("routes/previous.json missing");
  return pointer;
}

function shardFile(pointer: RoutePointer, shard: string): string {
  const name = pointer.shards[shard];
  if (name === undefined) throw new Error(`route pointer names no shard ${shard}`);
  return name;
}

// Hostnames engineered into DIFFERENT shards, so "only the owning shard was
// rewritten" is observable rather than accidental.
const HOST_A = "space-a.test";
const SHARD_A = shardFor(HOST_A);
const HOST_B = hostOutsideShard("space-b", SHARD_A);
const SHARD_B = shardFor(HOST_B);
// A second B hostname inside B's own shard: adding it must change that one
// shard's bytes without changing the shard KEY set.
const HOST_B_EXTRA = hostInShard("space-b-extra", SHARD_B);

/** The route PUT body (also the finalize `activate` payload, plus route_name). */
function productionRoute(host: string | string[], versionId?: string) {
  return {
    ...(versionId ? { version_id: versionId } : {}),
    config: publicAccessConfig(),
    production_hostnames: Array.isArray(host) ? host : [host],
    version_hostnames: [],
  };
}

function productionActivation(host: string | string[]) {
  return { route_name: "production", ...productionRoute(host) };
}

async function seedTwoSpaces(rt: Runtime): Promise<void> {
  await deploy(rt, {
    spaceId: "spc_idx_a",
    versionId: "ver_idx_a1",
    files: { "index.html": "a1" },
    activate: productionActivation(HOST_A),
  });
  await deploy(rt, {
    spaceId: "spc_idx_b",
    versionId: "ver_idx_b1",
    files: { "index.html": "b1" },
    activate: productionActivation(HOST_B),
  });
}

test("activating a new version swaps the space overlay and leaves every route shard addressed the same", async () => {
  const rt = await startRuntime();
  try {
    await seedTwoSpaces(rt);
    const before = readPointer(rt);
    const overlayBefore = spacePointer(rt, "spc_idx_b");

    await deploy(rt, {
      spaceId: "spc_idx_b",
      versionId: "ver_idx_b2",
      files: { "index.html": "b2" },
      activate: productionActivation(HOST_B),
    });

    // §3/§4: a production host follows its ROUTE, so the version lives in the
    // overlay and nothing about the host->space mapping changed. The shard bytes
    // are identical, so their content addresses are identical, and an identical
    // index skips the pointer write entirely — gen counts real index changes.
    const after = readPointer(rt);
    expect(after.gen).toBe(before.gen);
    expect(after.shards).toEqual(before.shards);
    expect(after.wildcards).toBe(before.wildcards);
    expect(hostEntry(rt, HOST_B)).toMatchObject({
      space_id: "spc_idx_b",
      route_name: "production",
      version_id: null,
    });
    expect(spacePointer(rt, "spc_idx_b")?.gen).toBeGreaterThan(overlayBefore?.gen ?? 0);
    expect(spaceOverlay(rt, "spc_idx_b")?.versions).toMatchObject({
      production: "ver_idx_b2",
      live: "ver_idx_b2",
    });

    const servedA = await get(rt, HOST_A, "/");
    expect(servedA.status).toBe(200);
    expect(await servedA.text()).toBe("a1");
    const servedB = await get(rt, HOST_B, "/");
    expect(servedB.status).toBe(200);
    expect(await servedB.text()).toBe("b2");
  } finally {
    rt.stop();
  }
});

test("an overlay sync aborts instead of publishing versions derived from an unreadable route pointer", async () => {
  const rt = await startRuntime();
  const spaceId = "spc_idx_overlay_read";
  const versionId = "ver_idx_overlay_read_1";
  const host = "overlay-read.test";
  try {
    await deploy(rt, {
      spaceId,
      versionId,
      files: { "index.html": "overlay stays complete" },
      activate: productionActivation(host),
    });
    const seededTombstones = await api(
      rt,
      "PUT",
      `/__spacefast/api.php/spaces/${spaceId}/tombstones`,
      "update_tombstones",
      { space_id: spaceId },
      { hostnames: [], mode: "replace" },
    );
    expect(seededTombstones.status).toBe(200);
    const pointerBefore = spacePointer(rt, spaceId);
    const overlayBefore = spaceOverlay(rt, spaceId);
    const routePath = storagePath(rt, "spaces", spaceId, "routes", "production.json");

    chmodSync(routePath, 0o000);
    try {
      const failedRoute = await api(
        rt,
        "PUT",
        `/__spacefast/api.php/spaces/${spaceId}/routes/production`,
        "update_route",
        { space_id: spaceId, route_name: "production" },
        productionRoute(host, versionId),
      );
      expect(failedRoute.status).toBe(500);

      const failed = await api(
        rt,
        "PUT",
        `/__spacefast/api.php/spaces/${spaceId}/hostname-intent`,
        "update_hostname_intent",
        { space_id: spaceId },
        { production_hostnames: [host], version_hostnames: [] },
      );
      expect(failed.status).toBe(500);
    } finally {
      chmodSync(routePath, 0o644);
    }

    expect(spacePointer(rt, spaceId)).toEqual(pointerBefore);
    expect(spaceOverlay(rt, spaceId)).toEqual(overlayBefore);
    const stillServed = await get(rt, host, "/");
    expect(stillServed.status).toBe(200);
    expect(await stillServed.text()).toBe("overlay stays complete");

    const tombstonesPath = storagePath(rt, "spaces", spaceId, "tombstones.json");
    chmodSync(tombstonesPath, 0o000);
    try {
      const failed = await api(
        rt,
        "PUT",
        `/__spacefast/api.php/spaces/${spaceId}/routes/production`,
        "update_route",
        { space_id: spaceId, route_name: "production" },
        productionRoute(host, versionId),
      );
      expect(failed.status).toBe(500);
      expect(spacePointer(rt, spaceId)).toEqual(pointerBefore);
      expect(spaceOverlay(rt, spaceId)).toEqual(overlayBefore);
    } finally {
      chmodSync(tombstonesPath, 0o644);
    }

    const healed = await api(
      rt,
      "PUT",
      `/__spacefast/api.php/spaces/${spaceId}/hostname-intent`,
      "update_hostname_intent",
      { space_id: spaceId },
      { production_hostnames: [host], version_hostnames: [] },
    );
    expect(healed.status).toBe(200);
  } finally {
    rt.stop();
  }
});

test("a full route-index rebuild refuses an unavailable spaces directory", async () => {
  const rt = await startRuntime();
  const spaceId = "spc_idx_rebuild_read";
  const host = "rebuild-read.test";
  try {
    await deploy(rt, {
      spaceId,
      versionId: "ver_idx_rebuild_read_1",
      files: { "index.html": "index remains published" },
      activate: productionActivation(host),
    });
    const pointerBefore = readPointer(rt);
    const spacesRoot = storagePath(rt, "spaces");
    // Execute permission keeps the exact space path stat-able for the repair
    // preflight, while lack of read permission makes enumeration unavailable.
    chmodSync(spacesRoot, 0o111);
    try {
      const failed = await api(
        rt,
        "POST",
        `/__spacefast/api.php/spaces/${spaceId}/repair`,
        "repair_space",
        { space_id: spaceId },
      );
      expect(failed.status).toBe(500);
    } finally {
      chmodSync(spacesRoot, 0o775);
    }

    expect(readPointer(rt)).toEqual(pointerBefore);
    expect(await (await get(rt, host, "/")).text()).toBe("index remains published");
  } finally {
    rt.stop();
  }
});

test("a hostname change rewrites only the owning shard and keeps the retired pointer as previous.json", async () => {
  const rt = await startRuntime();
  try {
    await seedTwoSpaces(rt);
    const before = readPointer(rt);

    // Mutate ONLY space B's hostname set.
    await putRoute(
      rt,
      "spc_idx_b",
      "production",
      productionRoute([HOST_B, HOST_B_EXTRA], "ver_idx_b1"),
    );

    const after = readPointer(rt);
    expect(after.gen).toBe(before.gen + 1);
    expect(Object.keys(after.shards)).toEqual(Object.keys(before.shards));
    expect(shardFile(after, SHARD_B)).not.toBe(shardFile(before, SHARD_B));
    expect(shardFile(after, SHARD_A)).toBe(shardFile(before, SHARD_A));

    // The retired pointer — and therefore the superseded shard file — stays
    // reachable for requests already mid-flight over the swap.
    expect(readPreviousPointer(rt)).toEqual(before);
    expect(existsSync(storagePath(rt, "routes", shardFile(before, SHARD_B)))).toBe(true);

    for (const host of [HOST_B, HOST_B_EXTRA]) {
      const served = await get(rt, host, "/");
      expect(served.status).toBe(200);
      expect(await served.text()).toBe("b1");
    }
    const servedA = await get(rt, HOST_A, "/");
    expect(servedA.status).toBe(200);
    expect(await servedA.text()).toBe("a1");
  } finally {
    rt.stop();
  }
});

test("cross-space hostname collisions stay correct through incremental pointer swaps", async () => {
  const rt = await startRuntime();
  const sharedHost = "shared-claim.test";
  try {
    // Space T serves the hostname, then retires it: tombstones + space delete.
    await deploy(rt, {
      spaceId: "spc_idx_t",
      versionId: "ver_idx_t1",
      files: { "index.html": "t1" },
      activate: productionActivation(sharedHost),
    });
    const takedown = await api(
      rt,
      "PUT",
      "/__spacefast/api.php/spaces/spc_idx_t/tombstones",
      "update_tombstones",
      { space_id: "spc_idx_t" },
      { hostnames: [sharedHost], mode: "replace" },
    );
    expect(takedown.status).toBe(200);

    // A takedown for the space that currently owns the live route is
    // authoritative immediately; it must not wait for a later space delete to
    // remove the serve contribution.
    const sameSpaceTakedown = await get(rt, sharedHost, "/");
    expect(sameSpaceTakedown.status).toBe(404);
    expect(await sameSpaceTakedown.text()).not.toBe("t1");

    // Retrying the same transition is idempotent and cannot resurrect the live
    // contribution after the first request reported success.
    const retry = await api(
      rt,
      "PUT",
      "/__spacefast/api.php/spaces/spc_idx_t/tombstones",
      "update_tombstones",
      { space_id: "spc_idx_t" },
      { hostnames: [sharedHost], mode: "replace" },
    );
    expect(retry.status).toBe(200);
    expect((await get(rt, sharedHost, "/")).status).toBe(404);

    const intentPath = storagePath(rt, "spaces", "spc_idx_t", "hostname-intent.json");
    chmodSync(intentPath, 0o000);
    try {
      const failedDelete = await api(
        rt,
        "POST",
        "/__spacefast/api.php/spaces/spc_idx_t/delete",
        "delete_space",
        { space_id: "spc_idx_t" },
      );
      expect(failedDelete.status).toBe(500);
      expect(existsSync(storagePath(rt, "spaces", "spc_idx_t"))).toBe(true);
      expect((await get(rt, sharedHost, "/")).status).toBe(404);
    } finally {
      chmodSync(intentPath, 0o644);
    }

    await api(rt, "POST", "/__spacefast/api.php/spaces/spc_idx_t/delete", "delete_space", {
      space_id: "spc_idx_t",
    });
    const tombstoned = await get(rt, sharedHost, "/");
    expect(tombstoned.status).toBe(404);

    // Space S claims the same hostname: serve must win over the tombstone.
    await deploy(rt, {
      spaceId: "spc_idx_s",
      versionId: "ver_idx_s1",
      files: { "index.html": "s1" },
      activate: productionActivation(sharedHost),
    });
    const claimed = await get(rt, sharedHost, "/");
    expect(claimed.status).toBe(200);
    expect(await claimed.text()).toBe("s1");

    // Space S releases the hostname: T's tombstone must resurface (the owners
    // map is what makes the incremental update recompute the host from BOTH
    // spaces instead of only the mutating one).
    await putRoute(rt, "spc_idx_s", "production", productionRoute([], "ver_idx_s1"));
    const resurfaced = await get(rt, sharedHost, "/");
    expect(resurfaced.status).toBe(404);
  } finally {
    rt.stop();
  }
});

test("a takedown removes only its space's live claim regardless of space-id ordering", async () => {
  const rt = await startRuntime();
  try {
    // Both orderings, because the merge walks contributors by space id: each row
    // fails on its own if the tombstone is applied by position rather than by
    // which space owns the claim.
    for (const [suffix, tombstonedSpace, survivingSpace] of [
      ["tombstone-sorts-last", "spc_idx_z", "spc_idx_a"],
      ["tombstone-sorts-first", "spc_idx_b", "spc_idx_y"],
    ] as const) {
      const host = `${suffix}.test`;
      await deploy(rt, {
        spaceId: tombstonedSpace,
        versionId: `ver_${suffix}_tombstoned`,
        files: {
          "index.html": `tombstoned-${suffix}`,
          _redirects: "/stale /retired-space 302\n",
        },
        activate: productionActivation(host),
      });
      await deploy(rt, {
        spaceId: survivingSpace,
        versionId: `ver_${suffix}_surviving`,
        files: { "index.html": `surviving-${suffix}` },
        activate: productionActivation(host),
      });

      const takedown = await api(
        rt,
        "PUT",
        `/__spacefast/api.php/spaces/${tombstonedSpace}/tombstones`,
        "update_tombstones",
        { space_id: tombstonedSpace },
        { hostnames: [host], mode: "replace" },
      );
      expect(takedown.status).toBe(200);

      const response = await get(rt, host, "/");
      expect(response.status).toBe(200);
      expect(await response.text()).toBe(`surviving-${suffix}`);

      const staleRoute = await get(rt, host, "/stale");
      expect(staleRoute.status).toBe(404);
      expect(staleRoute.headers.get("location")).toBeNull();
    }
  } finally {
    rt.stop();
  }
});

test("intent-only hostname update rebuilds the route index", async () => {
  const rt = await startRuntime();
  const host = "intent-only-index.test";
  try {
    await deploy(rt, {
      spaceId: "spc_idx_intent",
      versionId: "ver_idx_intent_1",
      files: { "index.html": "intent index" },
    });
    await putRoute(rt, "spc_idx_intent", "production", productionRoute([], "ver_idx_intent_1"));

    const response = await api(
      rt,
      "PUT",
      "/__spacefast/api.php/spaces/spc_idx_intent/hostname-intent",
      "update_hostname_intent",
      { space_id: "spc_idx_intent" },
      { production_hostnames: [host], version_hostnames: [] },
    );
    expect(response.status).toBe(200);
    expect(await response.json()).toMatchObject({
      space_id: "spc_idx_intent",
      route_count: 1,
    });

    expect(hostEntry(rt, host)).toMatchObject({ space_id: "spc_idx_intent" });
    const served = await get(rt, host, "/");
    expect(served.status).toBe(200);
    expect(await served.text()).toBe("intent index");
  } finally {
    rt.stop();
  }
});

test("a replayed route activation repairs a stale route pointer before returning its receipt", async () => {
  const rt = await startRuntime();
  const host = "replayed-activation-index.test";
  const spaceId = "spc_idx_replay";
  const versionId = "ver_idx_replay_1";
  const activation = productionRoute(host, versionId);
  try {
    await deploy(rt, { spaceId, versionId, files: { "index.html": "replayed activation" } });
    await putRoute(rt, spaceId, "production", activation);

    // The durable state left by an engine dying after it wrote the activation
    // receipt but before it swapped routes/current.json.
    unlinkSync(storagePath(rt, "routes", "current.json"));

    const replay = await putRoute(rt, spaceId, "production", activation);
    expect(await replay.json()).toMatchObject({ unchanged: true });

    expect(routePointer(rt)).not.toBeNull();
    const served = await get(rt, host, "/");
    expect(served.status).toBe(200);
    expect(await served.text()).toBe("replayed activation");
  } finally {
    rt.stop();
  }
});

test("an invalid fresh shard leaves the active route pointer and served version unchanged", async () => {
  const rt = await startRuntime();
  const host = "invalid-shard-index.test";
  try {
    await deploy(rt, {
      spaceId: "spc_idx_invalid_shard",
      versionId: "ver_idx_valid_1",
      files: { "index.html": "still live" },
      activate: productionActivation(host),
    });
    const before = readPointer(rt);

    // A shard is immutable once addressable, so a malformed one would be served
    // until some later mutation happened to replace the pointer naming it: the
    // writer must reject it before anything is written or swapped.
    const rejected = Bun.spawnSync([
      "php",
      "-r",
      [
        "define('STATTIC_RUNTIME_DISPATCH_CLI', true);",
        "require $argv[2] . '/shared/storage.php';",
        "require $argv[2] . '/admin/generate.php';",
        "_stattic_runtime_write_route_index($argv[1], ['3f' => ['hostnames' => ['invalid-shard-entry.test' => []], 'host_routes' => []]], [], ['fresh' => ['hostnames' => [], 'host_routes' => []]], []);",
      ].join(" "),
      rt.storageRoot,
      rt.engineRoot,
    ]);
    expect(rejected.exitCode).toBe(0);
    expect(JSON.parse(rejected.stdout.toString())).toEqual({
      status: 422,
      body: problemDocument(
        422,
        "runtime_route_index_validation_failed",
        "Runtime route index validation failed.",
        { details: { artifact: "hosts-3f" } },
      ),
    });

    expect(readPointer(rt)).toEqual(before);
    const served = await get(rt, host, "/");
    expect(served.status).toBe(200);
    expect(await served.text()).toBe("still live");
  } finally {
    rt.stop();
  }
});

const GC_HOST_1 = "shard-gc.test";
const SHARD_GC = shardFor(GC_HOST_1);
// All three in ONE shard, so every hostname change rewrites the same shard and
// leaves its predecessor behind as a GC candidate.
const GC_HOST_2 = hostInShard("shard-gc-b", SHARD_GC);
const GC_HOST_3 = hostInShard("shard-gc-c", SHARD_GC);

async function maintenanceTick(rt: Runtime, spaceId: string, run = "1"): Promise<void> {
  const claims = { space_id: spaceId, operation_id: "op_shard_gc" };
  const created = await apiJson<{ job: { id: string } }>(
    rt,
    "POST",
    "/__spacefast/api.php/jobs",
    "create_engine_job",
    claims,
    { type: "maintenance_tick", payload: {}, idempotency_key: `idem-shard-gc-${run}` },
    201,
  );
  const ran = await apiJson<{ job: { status: string } | null }>(
    rt,
    "POST",
    `/__spacefast/api.php/jobs/tick?lane=bulk&budget_ms=5000&job_id=${created.job.id}`,
    "tick_engine_jobs",
    {},
  );
  expect(ran.job?.status).toBe("complete");
}

test("the maintenance tick reclaims only shards no pointer names and only past the grace window", async () => {
  const rt = await startRuntime();
  const spaceId = "spc_idx_gc";
  try {
    await deploy(rt, {
      spaceId,
      versionId: "ver_idx_gc_1",
      files: { "index.html": "gc" },
      activate: productionActivation(GC_HOST_1),
    });
    const first = readPointer(rt);
    // Two more hostname changes: after the second, `first`'s artifacts are named
    // by neither current.json nor previous.json, which is when the writer stamps
    // them as unlink candidates (D71/D93 — the tick deletes, it never marks).
    await putRoute(
      rt,
      spaceId,
      "production",
      productionRoute([GC_HOST_1, GC_HOST_2], "ver_idx_gc_1"),
    );
    await putRoute(
      rt,
      spaceId,
      "production",
      productionRoute([GC_HOST_1, GC_HOST_2, GC_HOST_3], "ver_idx_gc_1"),
    );

    const referenced = new Set([
      ...pointerFiles(readPointer(rt)),
      ...pointerFiles(readPreviousPointer(rt)),
    ]);
    // The retired host shard and the retired owners map: enough to hold one back
    // inside the grace window while the other ages out.
    const [withinGrace, ...aged] = pointerFiles(first).filter((name) => !referenced.has(name));
    expect(withinGrace).toBeDefined();
    expect(aged.length).toBeGreaterThanOrEqual(1);

    const staleAt = new Date(Date.now() - 900_000);
    for (const name of aged) {
      utimesSync(storagePath(rt, "routes", name), staleAt, staleAt);
    }

    await maintenanceTick(rt, spaceId);

    for (const name of aged) {
      expect(existsSync(storagePath(rt, "routes", name))).toBe(false);
    }
    // Unreferenced but freshly marked: still inside the 300 s grace, so a
    // request mid-flight over the swap can still include it.
    expect(existsSync(storagePath(rt, "routes", String(withinGrace)))).toBe(true);
    for (const name of referenced) {
      expect(existsSync(storagePath(rt, "routes", name))).toBe(true);
    }

    for (const host of [GC_HOST_1, GC_HOST_2, GC_HOST_3]) {
      const served = await get(rt, host, "/");
      expect(served.status).toBe(200);
      expect(await served.text()).toBe("gc");
    }

    // The referenced set must be PROVEN: with current.json unreadable, the GC
    // must treat the live set as unknown and delete nothing — inferring
    // "nothing is referenced" from the failed read would unlink every live
    // shard and 503 the whole site until the next route write.
    chmodSync(storagePath(rt, "routes", "current.json"), 0o000);
    try {
      utimesSync(storagePath(rt, "routes", String(withinGrace)), staleAt, staleAt);
      await maintenanceTick(rt, spaceId, "unreadable-pointer");
      expect(existsSync(storagePath(rt, "routes", String(withinGrace)))).toBe(true);
      for (const name of referenced) {
        expect(existsSync(storagePath(rt, "routes", name))).toBe(true);
      }
    } finally {
      chmodSync(storagePath(rt, "routes", "current.json"), 0o644);
    }
  } finally {
    rt.stop();
  }
});

test("an unreadable route pointer answers unavailable even when stat cannot traverse its directory", async () => {
  const rt = await startRuntime();
  const host = "unreadable-pointer.test";
  try {
    await deploy(rt, {
      spaceId: "spc_idx_unreadable",
      versionId: "ver_idx_unreadable_1",
      files: { "index.html": "still published" },
      activate: productionActivation(host),
    });

    // The route swap's apcu_delete leaves the pointer cache cold. Remove
    // traversal permission from its parent so both the read and stat fail: that
    // still means unknown, not verified ENOENT.
    chmodSync(storagePath(rt, "routes"), 0o000);
    try {
      const failed = await get(rt, host, "/");
      expect(failed.status).toBe(503);
      expect(failed.headers.get("x-spacefast-unavailable")).toBe("route_pointer_unreadable");
      expect(failed.headers.get("cache-control")).toBe("no-store");
      expect(await failed.text()).not.toContain("hasn't been published");
    } finally {
      chmodSync(storagePath(rt, "routes"), 0o775);
    }

    const healed = await get(rt, host, "/");
    expect(healed.status).toBe(200);
    expect(await healed.text()).toBe("still published");
  } finally {
    rt.stop();
  }
});
