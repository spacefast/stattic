import { expect, test } from "bun:test";
import { mkdir, mkdtemp, realpath, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";

const engine = path.resolve(import.meta.dir, "../engine");

async function fixture() {
  const temporary = await realpath(await mkdtemp(path.join(os.tmpdir(), "sf-edge-purge-")));
  const root = path.join(temporary, ".stattic/storage");
  await mkdir(root, { recursive: true });
  let available = false;
  let duringDelivery: (() => Promise<void>) | undefined;
  const delivered: string[] = [];
  const server = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      await request.text();
      if (!available) return Response.json({ message: "Unavailable" }, { status: 503 });
      delivered.push(new URL(request.url).pathname);
      const concurrent = duringDelivery;
      duringDelivery = undefined;
      await concurrent?.();
      return Response.json({ message: "OK", data: [] });
    },
  });
  const spawn = (code: string) =>
    Bun.spawn(
      [
        "php",
        "-r",
        [
          "define('ATOMIC_SITE_ID', '123');",
          "define('ATOMIC_SITE_API_KEY', 'test-purge-key');",
          "define('SPACEFAST_EDGE_CACHE_API_BASE', $argv[2]);",
          "require $argv[3] . '/shared/purge.php';",
          "require $argv[3] . '/admin/jobs.php';",
          "$root = $argv[1];",
          code,
        ].join("\n"),
        root,
        `http://127.0.0.1:${server.port}/api/v1.0`,
        engine,
      ],
      { stdin: "pipe", stdout: "pipe", stderr: "pipe" },
    );
  const run = async (code: string) => {
    const process = spawn(code);
    const [stdout, stderr, exitCode] = await Promise.all([
      new Response(process.stdout).text(),
      new Response(process.stderr).text(),
      process.exited,
    ]);
    return { stdout, stderr, exitCode };
  };
  return {
    enableGateway() {
      available = true;
    },
    onDelivery(callback: () => Promise<void>) {
      duringDelivery = callback;
    },
    delivered,
    run,
    spawn,
    async [Symbol.asyncDispose]() {
      await server.stop(true);
      await rm(temporary, { recursive: true, force: true });
    },
  };
}

test("accepted purges survive request death and provider failure without acknowledging newer work", async () => {
  await using f = await fixture();
  const accepted = await f.run(`
    function fastcgi_finish_request(): void {}
    $receipt = _stattic_runtime_purge_now($root, ['hostnames' => ['purge.test'], 'reason' => 'publish']);
    echo json_encode($receipt); fflush(STDOUT);
    posix_kill(getmypid(), SIGKILL);
  `);
  expect(accepted.stdout, accepted.stderr).toBe('{"status":"queued","mode":"domain"}');
  expect(accepted.exitCode).not.toBe(0);
  expect(f.delivered).toEqual([]);

  const failed = await f.run(`
    $pass = _stattic_runtime_job_maintenance_tick($root, [], microtime(true) + 20);
    echo json_encode($pass['complete']);
  `);
  expect(failed.exitCode, failed.stderr).toBe(0);
  expect(failed.stdout).toBe("false");

  f.enableGateway();
  const drain = `echo json_encode(_stattic_runtime_purge_drain($root, microtime(true) + 20, time() + 3600));`;
  const retried = await f.run(drain);
  expect(retried.exitCode, retried.stderr).toBe(0);
  expect(retried.stdout).toBe("true");
  expect(f.delivered).toEqual(["/api/v1.0/edge-cache/123/purge/purge.test"]);
  expect((await f.run(drain)).stdout).toBe("true");
  expect(f.delivered).toHaveLength(1);

  const enqueue = `_stattic_runtime_purge_enqueue($root, ['purge.test'], 'new_publish');`;
  expect((await f.run(enqueue)).exitCode).toBe(0);
  f.onDelivery(async () => {
    expect((await f.run(enqueue)).exitCode).toBe(0);
  });
  const raced = await f.run(drain);
  expect(raced.exitCode, raced.stderr).toBe(0);
  expect(raced.stdout).toBe("false");
  expect((await f.run(drain)).stdout).toBe("true");
  expect(f.delivered).toHaveLength(3);
});

test("purge delivery waits for the serving mutation and the provider tick recovers a deleted Space", async () => {
  await using f = await fixture();
  f.enableGateway();
  const writer = f.spawn(`
    _stattic_space_write_lock_with($root, 'spc_deleted', STATTIC_LOCK_WAIT, null, static function () use ($root): void {
      $space = _stattic_space_root($root, 'spc_deleted');
      _stattic_runtime_mkdir($space);
      _stattic_runtime_prepare_purge($root, 'spc_deleted', ['deleted.test'], 'space_deleted');
      echo 'prepared'; fflush(STDOUT);
      fgets(STDIN);
      _stattic_runtime_rm_recursive($space);
      posix_kill(getmypid(), SIGKILL);
    });
  `);
  try {
    const ready = await writer.stdout.getReader().read();
    expect(new TextDecoder().decode(ready.value)).toBe("prepared");
    // An unrelated purge on the same host must retain the writer barrier.
    expect(
      (await f.run(`_stattic_runtime_purge_enqueue($root, ['deleted.test'], 'functions');`))
        .exitCode,
    ).toBe(0);
    const blocked = await f.run(
      `echo json_encode(_stattic_runtime_purge_drain($root, microtime(true) + 20));`,
    );
    expect(blocked.exitCode, blocked.stderr).toBe(0);
    expect(blocked.stdout).toBe("false");
    expect(f.delivered).toEqual([]);
    writer.stdin.write("delete\n");
    writer.stdin.end();
    expect(await writer.exited).not.toBe(0);
    const recovered = await f.run(`
      $argv = ['edge-purge.php', '--private-root=' . $root];
      require '${engine}/entrypoints/edge-purge.php';
    `);
    expect(recovered.exitCode, recovered.stderr).toBe(0);
    expect(JSON.parse(recovered.stdout)).toEqual({ complete: true });
    expect(f.delivered).toEqual(["/api/v1.0/edge-cache/123/purge/deleted.test"]);
  } finally {
    writer.kill();
    await writer.exited;
  }
});

test("content redirect publication refuses a serving write when its purge cannot be persisted", async () => {
  await using f = await fixture();
  const setup = await f.run(`
    $space = _stattic_space_root($root, 'spc_redirects');
    _stattic_runtime_mkdir($space);
    _stattic_runtime_write_json_atomic($space . '/hostname-intent.json', ['routes' => [['hostname' => 'redirects.test']]]);
    file_put_contents($space . '/content-redirects.json', 'old projection');
    _stattic_runtime_mkdir($root . '/runtime');
    file_put_contents($root . '/runtime/edge-purges', 'blocked');
  `);
  expect(setup.exitCode, setup.stderr).toBe(0);

  const publish = await f.run(`
    class WP_Error {}
    function spacefast_content_require_space_id(): string { return 'spc_redirects'; }
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = $root;
    require '${engine}/wordpress/content-admin-api.php';
    spacefast_content_redirect_publish([[
      'enabled' => true,
      'supported' => true,
      'source' => '/old',
      'destination' => '/new',
      'status' => 301,
      'requiresPublishedDestination' => false,
    ]]);
  `);
  expect(publish.stdout).toContain("runtime_mkdir_failed");
  const read = await f.run(
    `echo file_get_contents(_stattic_space_root($root, 'spc_redirects') . '/content-redirects.json');`,
  );
  expect(read.exitCode, read.stderr).toBe(0);
  expect(read.stdout).toBe("old projection");
});

test("provider web requests refuse a missing edge purge credential", () => {
  const attempt = Bun.spawnSync([
    "php",
    "-r",
    `require $argv[1];
    try { _stattic_runtime_require_edge_purge_endpoint('fpm-fcgi'); }
    catch (RuntimeException $error) { echo $error->getMessage(); }`,
    path.join(engine, "shared/purge.php"),
  ]);
  expect(attempt.exitCode, attempt.stderr.toString()).toBe(0);
  expect(attempt.stdout.toString()).toBe("edge_purge_endpoint_unavailable");
});

test("engine revisions invalidate unversioned platform responses once per installed release", async () => {
  await using f = await fixture();
  f.enableGateway();
  const prepare = await f.run(`
    $space = _stattic_space_root($root, 'spc_engine');
    _stattic_runtime_mkdir($space);
    _stattic_runtime_write_json_atomic($space . '/hostname-intent.json', ['routes' => [['hostname' => 'engine.test']]]);
  `);
  expect(prepare.exitCode, prepare.stderr).toBe(0);
  const update = (revision: string) =>
    f.run(`
    require '${engine}/admin/engine-update.php';
    _stattic_engine_update_purge($root, '${revision}');
  `);
  const first = await update("first");
  expect(first.exitCode, first.stderr).toBe(0);
  expect(f.delivered).toHaveLength(1);
  expect((await update("first")).exitCode).toBe(0);
  expect(f.delivered).toHaveLength(1);
  expect((await update("second")).exitCode).toBe(0);
  expect(f.delivered).toHaveLength(2);
});
