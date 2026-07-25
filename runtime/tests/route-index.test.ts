import { expect, test } from "bun:test";
// Incremental route-index updates (provider edge mitigation): one space's
// mutation must touch only that space's hostname entries — untouched host
// shards are hardlinked forward from the previous generation instead of being
// recompiled from every space on the site. These tests pin both correctness
// (serving parity, cross-space tombstone collisions) and the O(1) reuse shape
// (inode-identical shard files, built-generation manifest in current.php).
import { readFileSync, statSync, utimesSync } from "node:fs";
import path from "node:path";

import { api, deploy, get, putRoute, sha256, startRuntime, type Runtime } from "./harness";

function shardForHost(host: string): string {
  return sha256(host).slice(0, 2);
}

// Hostnames engineered into DIFFERENT shards so reuse is observable.
const HOST_A = "space-a.test";
const HOST_B = (() => {
  for (let i = 0; ; i += 1) {
    const candidate = `space-b-${i}.test`;
    if (shardForHost(candidate) !== shardForHost(HOST_A)) {
      return candidate;
    }
  }
})();

function readCurrentRouteStateFile(filePath: string): {
  generation: string;
  shards: Record<string, string>;
} {
  const source = readFileSync(filePath, "utf8");
  const generation = /^\s+'generation' => '([^']+)',\s*$/m.exec(source)?.[1];
  if (!generation) {
    throw new Error(`route current generation missing: ${filePath}`);
  }
  const shardsSource = /^\s+'shards' =>\s*\n\s+array \(\n(?<body>[\s\S]*?)^\s+\),\s*$/m.exec(source)
    ?.groups?.body;
  if (shardsSource === undefined) {
    throw new Error(`route current shards missing: ${filePath}`);
  }
  const shards: Record<string, string> = {};
  for (const match of shardsSource.matchAll(
    /^\s+(?:'([0-9a-f]{2})'|([0-9]{2})) => '([^']+)',\s*$/gm,
  )) {
    shards[match[1] ?? match[2] ?? ""] = match[3] ?? "";
  }
  return { generation, shards };
}

function currentRouteState(runtime: Runtime) {
  const current = readCurrentRouteStateFile(path.join(runtime.storageRoot, "routes/current.php"));
  return {
    generation: current.generation,
    shards: current.shards,
    root: path.join(runtime.storageRoot, "routes/generations", current.generation),
  };
}

test("a space mutation reuses untouched host shards by hardlink and only rebuilds its own", async () => {
  const rt = await startRuntime();
  try {
    await deploy(rt, {
      spaceId: "spc_idx_a",
      versionId: "ver_idx_a1",
      files: { "index.html": "a1" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [HOST_A],
        version_hostnames: [],
      },
    });
    await deploy(rt, {
      spaceId: "spc_idx_b",
      versionId: "ver_idx_b1",
      files: { "index.html": "b1" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [HOST_B],
        version_hostnames: [],
      },
    });

    const before = currentRouteState(rt);
    const shardA = shardForHost(HOST_A);
    const shardB = shardForHost(HOST_B);
    const inodeA = statSync(path.join(before.root, "hosts", `${shardA}.php`)).ino;

    // Mutate ONLY space B: publish + activate a second version.
    await deploy(rt, {
      spaceId: "spc_idx_b",
      versionId: "ver_idx_b2",
      files: { "index.html": "b2" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [HOST_B],
        version_hostnames: [],
      },
    });

    const after = currentRouteState(rt);
    expect(after.generation).not.toBe(before.generation);
    // B's shard was rebuilt in the new generation; A's shard kept the generation
    // stamp it was built in and is the SAME file (hardlink), not a recompile.
    expect(after.shards[shardB]).toBe(after.generation);
    expect(after.shards[shardA]).toBe(before.shards[shardA]);
    expect(statSync(path.join(after.root, "hosts", `${shardA}.php`)).ino).toBe(inodeA);

    // Serving parity: both hosts resolve, B flipped to the new version.
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

test("cross-space hostname collisions stay correct through incremental updates", async () => {
  const rt = await startRuntime();
  const sharedHost = "shared-claim.test";
  try {
    // Space T serves the hostname, then retires it: tombstones + space delete.
    await deploy(rt, {
      spaceId: "spc_idx_t",
      versionId: "ver_idx_t1",
      files: { "index.html": "t1" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [sharedHost],
        version_hostnames: [],
      },
    });
    await api(
      rt,
      "PUT",
      "/__spacefast/api.php/spaces/spc_idx_t/tombstones",
      "update_tombstones",
      { space_id: "spc_idx_t" },
      { hostnames: [sharedHost], mode: "replace" },
    );
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
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [sharedHost],
        version_hostnames: [],
      },
    });
    const claimed = await get(rt, sharedHost, "/");
    expect(claimed.status).toBe(200);
    expect(await claimed.text()).toBe("s1");

    // Space S releases the hostname: T's tombstone must resurface (the owners
    // map makes the incremental update recompute the host from BOTH spaces).
    await putRoute(rt, "spc_idx_s", "production", {
      version_id: "ver_idx_s1",
      production_hostnames: [],
      version_hostnames: [],
    });
    const resurfaced = await get(rt, sharedHost, "/");
    expect(resurfaced.status).toBe(404);
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

    const response = await api(
      rt,
      "PUT",
      "/__spacefast/api.php/spaces/spc_idx_intent/hostname-intent",
      "update_hostname_intent",
      { space_id: "spc_idx_intent" },
      {
        version_hostnames: [{ hostname: host, version_id: "ver_idx_intent_1" }],
      },
    );
    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ space_id: "spc_idx_intent", route_count: 1 });

    const served = await get(rt, host, "/");
    expect(served.status).toBe(200);
    expect(await served.text()).toBe("intent index");
  } finally {
    rt.stop();
  }
});

test("an invalid fresh shard leaves the active route-index pointer and served version unchanged", async () => {
  const rt = await startRuntime();
  const host = "invalid-generation-index.test";
  try {
    await deploy(rt, {
      spaceId: "spc_idx_invalid_generation",
      versionId: "ver_idx_valid_1",
      files: { "index.html": "still live" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [host],
        version_hostnames: [],
      },
    });
    const before = currentRouteState(rt);

    const rejected = Bun.spawnSync([
      "php",
      "-r",
      [
        "define('STATTIC_RUNTIME_DISPATCH_CLI', true);",
        "require $argv[2] . '/shared/storage.php';",
        "require $argv[2] . '/admin/generate.php';",
        "_stattic_runtime_write_route_generation($argv[1], ['..' => ['hostnames' => ['invalid-shard.test' => []], 'host_routes' => []]], [], ['fresh' => ['hostnames' => [], 'host_routes' => []]], [], gmdate('c'));",
      ].join(" "),
      rt.storageRoot,
      path.join(rt.root, ".stattic/engine"),
    ]);
    expect(rejected.exitCode).toBe(0);
    expect(JSON.parse(rejected.stdout.toString())).toEqual({
      status: 422,
      body: {
        error: {
          code: "runtime_route_generation_validation_failed",
          message: "Runtime route generation validation failed.",
          details: { artifact: "hosts" },
        },
      },
    });
    expect(currentRouteState(rt).generation).toBe(before.generation);
    const served = await get(rt, host, "/");
    expect(served.status).toBe(200);
    expect(served.headers.get("x-spacefast-version")).toBe("ver_idx_valid_1");
    expect(await served.text()).toBe("still live");
  } finally {
    rt.stop();
  }
});

test("old route generations are pruned after the grace window", async () => {
  const rt = await startRuntime();
  try {
    await deploy(rt, {
      spaceId: "spc_idx_b",
      versionId: "ver_idx_b1",
      files: { "index.html": "b1" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [HOST_B],
        version_hostnames: [],
      },
    });
    await deploy(rt, {
      spaceId: "spc_idx_b",
      versionId: "ver_idx_b2",
      files: { "index.html": "b2" },
      activate: {
        route_name: "production",
        config: {},
        production_hostnames: [HOST_B],
        version_hostnames: [],
      },
    });

    const generationsRoot = path.join(rt.storageRoot, "routes/generations");
    const { generation: liveGeneration } = currentRouteState(rt);
    const stale = new Date(Date.now() - 60 * 60 * 1000);
    const { readdirSync } = await import("node:fs");
    for (const name of readdirSync(generationsRoot)) {
      if (name !== liveGeneration) {
        utimesSync(path.join(generationsRoot, name), stale, stale);
      }
    }

    // Any space mutation publishes a new generation and prunes expired ones.
    await putRoute(rt, "spc_idx_b", "production", {
      version_id: "ver_idx_b1",
      production_hostnames: [HOST_B],
      version_hostnames: [],
    });

    const { generation: nextGeneration } = currentRouteState(rt);
    const remaining = readdirSync(generationsRoot);
    expect(remaining).toContain(nextGeneration);
    // Everything stale was pruned; only the new generation plus the immediately
    // previous one (still inside the grace window) may remain.
    expect(remaining.length).toBeLessThanOrEqual(2);

    const servedB = await get(rt, HOST_B, "/");
    expect(servedB.status).toBe(200);
    expect(await servedB.text()).toBe("b1");
  } finally {
    rt.stop();
  }
});
