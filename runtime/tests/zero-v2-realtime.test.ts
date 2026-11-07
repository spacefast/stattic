// The engine's Zero v2 notice delivery, run in PHP: one request's notices merge into one
// delivery, collapse to the receiver's limits instead of being dropped, and a slow or
// unreachable control plane costs the worker no more than the 250 ms budget.
import { expect, test } from "bun:test";
import { createServer, type Socket } from "node:net";

import { z } from "zod";

import { zeroV2ChangeNoticeSchema } from "../../packages/common/src/contracts/zero-v2-realtime.ts";

const ENGINE = new URL("../engine/", import.meta.url).pathname;

/** Runs PHP with the engine's shared context, the egress and HTTP helpers and the realtime module loaded. */
async function php(program: string) {
  const proc = Bun.spawn({
    cmd: [
      "php",
      "-r",
      `require ${JSON.stringify(ENGINE + "shared/context.php")};
       require ${JSON.stringify(ENGINE + "shared/bootstrap-config.php")};
       require ${JSON.stringify(ENGINE + "runtime/zero-v2-realtime.php")};
       ${program}`,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  const [code, stdout, stderr] = await Promise.all([
    proc.exited,
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
  ]);
  return { code, stdout, stderr };
}

const config = `['space_id' => 'spc_a', 'version_id' => 'ver_a']`;

test("a request's transactions merge into one batch; past the limits it collapses, never drops", async () => {
  const run = await php(`
    $batch = [];
    $batch = _stattic_zero_v2_realtime_merge($batch, [['collection' => 'todos', 'ops' => ['create'], 'ids' => [1, 2]]]);
    $batch = _stattic_zero_v2_realtime_merge($batch, [['collection' => 'todos', 'ops' => ['update'], 'ids' => [2, 3]]]);
    // 101 distinct ids: past the id limit the collection is listed as "some rows changed".
    $batch = _stattic_zero_v2_realtime_merge($batch, [['collection' => 'notes', 'ops' => ['create'], 'ids' => range(1, 101)]]);
    // An id longer than the receiver accepts collapses the same way.
    $batch = _stattic_zero_v2_realtime_merge($batch, [['collection' => 'tags', 'ops' => ['delete'], 'ids' => [str_repeat('x', 192)]]]);
    // 70 more collections: more than one notice carries them.
    foreach (range(1, 70) as $n) $batch = _stattic_zero_v2_realtime_merge($batch, [['collection' => 'c' . $n, 'ops' => ['create'], 'ids' => [$n]]]);
    // A big batch with ids: over 64 KB with ids, it ships without them.
    $big = _stattic_zero_v2_realtime_merge([], array_map(fn ($n) => ['collection' => 'big' . $n, 'ops' => ['create'], 'ids' => array_map(fn ($i) => str_repeat('i', 180) . $i, range(1, 10))], range(1, 50)));
    echo json_encode(['split' => _stattic_zero_v2_realtime_bodies(${config}, $batch, '2026-11-07T10:00:00.000Z'), 'big' => _stattic_zero_v2_realtime_bodies(${config}, $big, '2026-11-07T10:00:00.000Z')]);
  `);
  expect(run.stderr).toBe("");
  const out: { split: string[]; big: string[] } = JSON.parse(run.stdout);
  const notices = (bodies: string[]) =>
    bodies.map((body) => {
      expect(body.length).toBeLessThanOrEqual(65_536);
      const event = JSON.parse(body).event;
      expect(event).toMatchObject({
        event: "zero.changes",
        space_id: "spc_a",
        version_id: "ver_a",
      });
      // The receiver's schema accepts every notice this box sends.
      return zeroV2ChangeNoticeSchema.parse(event.payload);
    });
  const split = notices(out.split);
  expect(split.length).toBe(2);
  const changes = split.flatMap((n) => n.changes);
  expect(changes.length).toBe(73);
  expect(changes.find((c) => c.collection === "todos")).toEqual({
    collection: "todos",
    ops: ["create", "update"],
    ids: [1, 2, 3],
  });
  expect(changes.find((c) => c.collection === "notes")?.ids).toBeNull();
  expect(changes.find((c) => c.collection === "tags")?.ids).toBeNull();
  const big = notices(out.big);
  expect(big.length).toBe(1);
  expect(big[0].changes.length).toBe(50);
  expect(big[0].changes.every((c) => c.ids === null)).toBe(true);
});

/** A listener that accepts a connection and never answers: the slow control plane. */
async function stalledServer() {
  const sockets: Socket[] = [];
  const server = createServer((socket) => sockets.push(socket));
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  const { port } = z.object({ port: z.number() }).parse(server.address());
  return {
    url: `http://127.0.0.1:${port}/v1/runtime/events/callback`,
    close: () => {
      for (const s of sockets) s.destroy();
      server.close();
    },
  };
}

const timedPost = (url: string) =>
  php(`
    $started = microtime(true);
    _stattic_zero_v2_realtime_post(${JSON.stringify(url)}, 'token', '{}');
    echo json_encode(['ms' => (int) round((microtime(true) - $started) * 1000)]);
  `);

test("a slow control plane holds the worker no longer than the 250 ms budget", async () => {
  const slow = await stalledServer();
  try {
    const run = await timedPost(slow.url);
    expect(run.stderr).toMatch(/Zero realtime notice was not accepted: .*timed out/);
    const { ms } = JSON.parse(run.stdout);
    expect(ms).toBeGreaterThanOrEqual(200);
    expect(ms).toBeLessThan(400);
  } finally {
    slow.close();
  }
});

test("an unreachable control plane holds the worker no longer than the 250 ms budget", async () => {
  // TEST-NET-1 (RFC 5737) is never routed: the SYN goes unanswered, so only the connect
  // timeout can end the attempt. HTTPS, because platform egress refuses plain HTTP off loopback.
  const run = await timedPost("https://192.0.2.1/v1/runtime/events/callback");
  // The lost notice is logged; clients recover it on their next safety poll.
  expect(run.stderr).toMatch(/Zero realtime notice was not accepted: .*timed out/);
  const { ms } = JSON.parse(run.stdout);
  expect(ms).toBeGreaterThanOrEqual(200);
  expect(ms).toBeLessThan(400);
});

test("a delivery that starts after its deadline sends nothing", async () => {
  const slow = await stalledServer();
  try {
    const run = await php(`
      $started = microtime(true);
      _stattic_zero_v2_realtime_post(${JSON.stringify(slow.url)}, 'token', '{}', microtime(true) - 0.001);
      echo json_encode(['ms' => (int) round((microtime(true) - $started) * 1000)]);
    `);
    expect(JSON.parse(run.stdout).ms).toBeLessThan(20);
  } finally {
    slow.close();
  }
});
