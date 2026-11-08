// Real WordPress/MariaDB acceptance of the managed CLI worker. No browser or loopback cron.
import { test } from "bun:test";
import assert from "node:assert/strict";
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import {
  runtimeStateResponseSchema,
  runtimeZeroWorkerStatusSchema,
} from "../../packages/common/src/contracts/runtime-api.ts";
import {
  api,
  createDeclaredSession,
  finalize,
  publicAccessConfig,
  putRoute,
  startRuntime,
  uploadSessionBlobs,
} from "./harness.ts";
import { MYSQL_SETUP_TIMEOUT_MS, startMysqlContainer } from "./mysql-container.ts";
import { installWordpress } from "./zero-wordpress.ts";

const resultSchema = z.union([
  runtimeZeroWorkerStatusSchema,
  z.object({
    space_id: z.string(),
    status: z.literal("busy"),
    processed: z.literal(0),
  }),
]);
const hookSchema = z.object({
  event: z.string(),
  data: z.object({ row: z.object({ label: z.string(), scope: z.string() }) }),
});

test(
  "managed worker delivers scoped outbox and scheduled jobs, excludes concurrent runners and skips idle boots",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-zero-worker-"));
    const db = await startMysqlContainer({
      namePrefix: "sf-zero-worker",
      database: "wordpress",
      rootPassword: "disposable-zero-v2-proof",
      flavor: "mariadb",
    });
    const rt = await startRuntime({
      env: {
        PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}`,
        SPACEFAST_ZERO_DATABASE_URL: db.url,
      },
    });
    const { promise: enteredSink, resolve: entered } = Promise.withResolvers<void>();
    const { promise: releaseSink, resolve: release } = Promise.withResolvers<void>();
    let hold = true;
    const deliveries: Array<{ scope: string; label: string }> = [];
    const sink = Bun.serve({
      port: 0,
      hostname: "127.0.0.1",
      async fetch(request) {
        const body = await request.text();
        const signature = request.headers.get("zero-signature") ?? "";
        const match = /^t=(\d+),v1=([a-f0-9]+)$/.exec(signature);
        assert.ok(match);
        assert.equal(
          match[2],
          new Bun.CryptoHasher("sha256", "local-worker-secret")
            .update(`${match[1]}.${body}`)
            .digest("hex"),
        );
        const hook = hookSchema.parse(JSON.parse(body));
        assert.equal(new URL(request.url).pathname, `/${hook.data.row.scope}`);
        assert.equal(hook.event, "records.created");
        deliveries.push(hook.data.row);
        if (hold) {
          entered();
          await releaseSink;
        }
        return new Response("ok");
      },
    });
    const workers: Array<ReturnType<typeof Bun.spawn>> = [];
    try {
      const wp = await installWordpress(rt, work, db, "worker-a.test");
      const marker = path.join(work, "worker-boots.txt");
      writeFileSync(
        path.join(rt.root, "wp-content/mu-plugins/worker-proof.php"),
        `<?php if (defined('WP_CLI') && WP_CLI && isset($GLOBALS['SPACEFAST_ZERO_V2_ENVIRONMENT'])) file_put_contents(${JSON.stringify(marker)}, $GLOBALS['SPACEFAST_CONTENT_SPACE_ID']."\\n", FILE_APPEND); add_action('worker_core_proof', static function () { update_option('worker_core_proof', $GLOBALS['SPACEFAST_CONTENT_SPACE_ID']); }); add_action('worker_as_proof', static function () { update_option('worker_as_proof', $GLOBALS['SPACEFAST_CONTENT_SPACE_ID']); });`,
      );
      // The Space's real mail path ends in wp_mail(); capture it there, on the sender domain Atomic names.
      const mailCapture = path.join(work, "mail-capture.jsonl");
      writeFileSync(
        path.join(rt.root, "wp-content/mu-plugins/mail-capture.php"),
        `<?php if (!defined('DOMAIN_NAME')) define('DOMAIN_NAME', 'worker-a.test'); add_filter('pre_wp_mail', static function ($short, array $atts) { file_put_contents(${JSON.stringify(mailCapture)}, json_encode(['to' => $atts['to'], 'subject' => $atts['subject'], 'headers' => $atts['headers'], 'host' => $_SERVER['HTTP_HOST'] ?? null]) . "\\n", FILE_APPEND); return true; }, 10, 2);`,
      );
      const files = {
        "zero.config.ts": `
import { defineConfig, table, text, action, job, jobs, db, env, userProjection, email, mail } from 'zero';
const records = table({ label: text().notNull(), scope: text().notNull() }, { access: { read: 'anyone' } });
export default defineConfig({ name: 'worker-proof', tables: { records }, users: { members: userProjection({ email: email().notNull().unique().max(191), name: text().notNull().default('') }) },
  webhooks: [{ url: env('HOOK_URL'), secret: env('HOOK_SECRET'), events: ['records.created'] }],
  jobs: { crash: job({ input: {}, retries: 3, handler: () => { throw new Error('Expected worker failure'); } }), stamp: job({ input: { label: text().notNull() }, handler: async (input) => { await db.insert(records).values({ label: input.label, scope: (env('SCOPE') ?? 'missing') }); } }) },
  actions: { notify: action({ input: { from: text(), to: text().notNull(), cc: text() }, access: 'anyone', handler: async ({ from, to, cc }) => { if (from === null) return (await mail.send({ to, cc, subject: 'Welcome', text: 'Hi' })).id; return (await mail.send({ from, to, cc, subject: 'Welcome', text: 'Hi' })).id; } }), failEnqueue: action({ access: 'anyone', handler: async () => { return await jobs.crash.enqueue({}); } }), enqueue: action({ access: 'anyone', handler: async () => {
    await db.insert(records).values({ label: 'immediate', scope: (env('SCOPE') ?? 'missing') });
    return await jobs.stamp.enqueue({ label: 'scheduled' }, { delay: 3600 });
  } }) }
});
`,
        "public/index.html": "<!doctype html><title>Worker proof</title>",
      };
      const prefix = (space: string) =>
        `sfz_${new Bun.CryptoHasher("sha256").update(space).digest("hex").slice(0, 24)}_`;
      const a = "spc_worker_a";
      const b = "spc_worker_b";
      for (const [space, scope] of [
        [a, "a"],
        [b, "b"],
      ]) {
        const session = await createDeclaredSession(rt, space, "ver_worker_live", files);
        await uploadSessionBlobs(rt, session, files);
        const response = await finalize(rt, space, "ver_worker_live", {
          upload_id: session.uploadId,
          zero_generation: "none",
          zero_v2: {
            variableValues: {
              HOOK_URL: `http://127.0.0.1:${sink.port}/${scope}`,
              HOOK_SECRET: "local-worker-secret",
              SCOPE: scope,
            },
          },
        });
        assert.equal(response.status, 200, await response.clone().text());
        assert.equal("zero_seed" in (await response.json()), false);
        await putRoute(rt, space, "production", {
          version_id: "ver_worker_live",
          hostnames: [`worker-${scope}.test`],
          production_hostnames: [`worker-${scope}.test`],
          config: publicAccessConfig({ zero_generation: "v2" }),
        });
        const queued = await fetch(`${rt.baseUrl}/wp-json/zero/v1/actions/enqueue`, {
          method: "POST",
          headers: {
            host: `worker-${scope}.test`,
            "content-type": "application/json",
          },
          body: "{}",
        });
        assert.equal(queued.status, 200, await queued.clone().text());
      }
      assert.deepEqual(deliveries, []);
      // Recreate the committed-but-not-dispatched ticket to prove recovery from that crash boundary.
      db.exec(
        `INSERT INTO ${prefix(a)}zero__outbox (id, job, input, \`key\`, runAt, createdAt) SELECT id, job, input, \`key\`, runAt, createdAt FROM ${prefix(a)}zero__jobs WHERE job='zero.webhook'`,
      );
      const bootstrap = (space: string) =>
        `require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/context.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/storage.php"))};require_once ${JSON.stringify(path.join(rt.engineRoot, "shared/zero-v2.php"))};_stattic_zero_v2_select(${JSON.stringify(rt.storageRoot)},${JSON.stringify(space)},"ver_worker_live",["zero_generation"=>"v2"]);`;
      for (const space of [a, b]) {
        assert.ok(
          Number(
            wp(
              `--exec=${bootstrap(space)}`,
              "eval",
              "echo wp_next_scheduled('zero_identity_maintenance');",
            ),
          ) > 0,
        );
        db.exec(
          `INSERT INTO ${prefix(space)}zero_identity_limits (id, hits, expires_at) VALUES ('worker-expired',1,1)`,
        );
      }
      wp(
        `--exec=${bootstrap(a)}`,
        "eval",
        "wp_schedule_single_event(time()-1,'worker_core_proof'); as_schedule_single_action(time()-1,'worker_as_proof'); wp_clear_scheduled_hook('zero_identity_maintenance'); wp_schedule_event(time()-1,'hourly','zero_identity_maintenance');",
      );
      const bBefore = db.exec(`SELECT * FROM ${prefix(b)}zero__jobs ORDER BY id`);
      const worker = (space?: string, extra: string[] = [], expectedExitCode = 0) => {
        const proc = Bun.spawn({
          cmd: [
            "php",
            "-d",
            "auto_prepend_file=",
            path.join(rt.engineRoot, "entrypoints/zero-worker.php"),
            `--private-root=${rt.storageRoot}`,
            "--environment=production",
            ...(space ? [`--space=${space}`] : []),
            ...extra,
          ],
          env: {
            ...process.env,
            PATH: `${path.join(work, "bin")}:${process.env.PATH ?? ""}`,
            SPACEFAST_ZERO_DATABASE_URL: db.url,
          },
          stdout: "pipe",
          stderr: "pipe",
        });
        workers.push(proc);
        const output = (async () => {
          const [code, out, err] = await Promise.all([
            proc.exited,
            new Response(proc.stdout).text(),
            new Response(proc.stderr).text(),
          ]);
          assert.equal(code, expectedExitCode, `${out}\n${err}`);
          return out
            .trim()
            .split("\n")
            .map((line) => resultSchema.parse(JSON.parse(line)));
        })();
        return { proc, result: () => output };
      };
      const first = worker(a);
      // Signal from the real delivery, with a bounded timeout so a failed boot is diagnosed promptly.
      await Promise.race([
        enteredSink,
        first.result().then((result) => {
          throw new Error(`Worker exited before delivery: ${JSON.stringify(result)}`);
        }),
        new Promise<never>((_, reject) => {
          const signal = AbortSignal.timeout(30000);
          signal.addEventListener("abort", () => reject(new Error("Worker delivery deadline")), {
            once: true,
          });
        }),
      ]);
      assert.deepEqual(await worker(a).result(), [{ space_id: a, status: "busy", processed: 0 }]);
      assert.equal(db.exec(`SELECT * FROM ${prefix(b)}zero__jobs ORDER BY id`), bBefore);
      assert.deepEqual(deliveries, [{ label: "immediate", scope: "a" }]);
      hold = false;
      release();
      const [firstResult] = await first.result();
      assert.equal(firstResult?.status, "ran");
      assert.ok(firstResult && "failures" in firstResult);
      assert.equal(firstResult.failures, 0);
      assert.equal(db.exec(`SELECT COUNT(*) FROM ${prefix(a)}zero__outbox`), "0");
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(a)}zero_records WHERE label='scheduled'`),
        "0",
      );
      assert.equal(
        db.exec(
          `SELECT option_value FROM ${prefix(a)}options WHERE option_name='worker_core_proof'`,
        ),
        a,
      );
      assert.equal(
        db.exec(`SELECT option_value FROM ${prefix(a)}options WHERE option_name='worker_as_proof'`),
        a,
      );
      assert.equal(
        db.exec(
          `SELECT COUNT(*) FROM ${prefix(b)}options WHERE option_name IN ('worker_core_proof','worker_as_proof')`,
        ),
        "0",
      );
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(a)}zero_identity_limits WHERE id='worker-expired'`),
        "0",
      );
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(b)}zero_identity_limits WHERE id='worker-expired'`),
        "1",
      );
      const boxResults = await worker().result();
      assert.equal(boxResults.find((r) => r.space_id === b)?.status, "ran");
      assert.deepEqual(
        deliveries.map(({ scope }) => scope),
        ["a", "b"],
      );
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(b)}zero_records WHERE label='scheduled'`),
        "0",
      );
      // Advance only A's persisted schedule to the due boundary; no wall-clock sleeps.
      db.exec(
        `UPDATE ${prefix(a)}zero__jobs SET runAt='2000-01-01T00:00:00.000Z' WHERE job='stamp'`,
      );
      const due = await worker(a).result();
      assert.equal(due[0]?.status, "ran");
      assert.equal(
        db.exec(`SELECT scope FROM ${prefix(a)}zero_records WHERE label='scheduled'`),
        "a",
      );
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(b)}zero_records WHERE label='scheduled'`),
        "0",
      );
      const beforeBoots = existsSync(marker) ? readFileSync(marker, "utf8") : "";
      const [idle] = await worker(a).result();
      assert.equal(idle?.status, "idle");
      assert.ok(idle && "wordpress_booted" in idle);
      assert.equal(idle.wordpress_booted, false);
      assert.equal(readFileSync(marker, "utf8"), beforeBoots);
      const stateResponse = await api(rt, "GET", "/__spacefast/api.php/state", "read_state");
      assert.equal(stateResponse.status, 200, await stateResponse.clone().text());
      const state = runtimeStateResponseSchema.parse(await stateResponse.json());
      assert.deepEqual(state.spaces?.find((row) => row.space_id === a)?.zero_worker, idle);
      // Bound recovery and receipt retention as well as delivery; unfinished pages retry later.
      db.exec(
        `UPDATE ${prefix(b)}zero__jobs SET state='running', lockedAt='2000-01-01T00:00:00.000Z' WHERE job='stamp'`,
      );
      for (const key of ["old-1", "old-2", "old-3"]) {
        db.exec(
          `INSERT INTO ${prefix(b)}zero__receipts (scope, \`key\`, userId, inputHash, state, createdAt) VALUES ('worker-test','${key}',0,REPEAT('0',64),'done','2000-01-01T00:00:00.000Z')`,
        );
      }
      wp(
        `--exec=${bootstrap(b)}`,
        "eval",
        "as_schedule_single_action(time()-1,'zero_jobs_maintain',[],'zero');",
      );
      const [maintenance] = await worker(b, ["--limit=1"]).result();
      assert.equal(maintenance?.status, "ran");
      assert.equal(maintenance?.processed, 1);
      assert.equal(
        db.exec(`SELECT state FROM ${prefix(b)}zero__jobs WHERE job='stamp'`),
        "pending",
      );
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(b)}zero__receipts WHERE scope='worker-test'`),
        "2",
      );
      assert.equal(
        db.exec(
          `SELECT COUNT(*) FROM ${prefix(b)}actionscheduler_actions WHERE hook='zero_jobs_maintain' AND status='pending'`,
        ),
        "2",
      );
      const [recovered] = await worker(b, ["--limit=1"]).result();
      assert.equal(recovered?.status, "ran");
      assert.equal(recovered?.processed, 1);
      assert.equal(
        db.exec(`SELECT scope FROM ${prefix(b)}zero_records WHERE label='scheduled'`),
        "b",
      );
      const failQueue = await fetch(`${rt.baseUrl}/wp-json/zero/v1/actions/failEnqueue`, {
        method: "POST",
        headers: {
          host: "worker-a.test",
          "content-type": "application/json",
        },
        body: "{}",
      });
      assert.equal(failQueue.status, 200, await failQueue.clone().text());
      const [failure] = await worker(a, [], 1).result();
      assert.equal(failure?.status, "failed");
      assert.ok(failure && "failures" in failure);
      assert.equal(failure.failures, 1);
      assert.equal(
        db.exec(`SELECT CONCAT(state,':',attempts) FROM ${prefix(a)}zero__jobs WHERE job='crash'`),
        "failed:1",
      );
      assert.equal(
        db.exec(
          `SELECT COUNT(*) FROM ${prefix(a)}zero__jobs WHERE job='crash' AND runAt > DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%dT%H:%i:%s.000Z')`,
        ),
        "1",
      );
      // A full direct-job page still gives AS and core maintenance their turn.
      for (const index of [0, 1, 2]) {
        db.exec(
          `INSERT INTO ${prefix(a)}zero__jobs (id,job,input,state,runAt,createdAt) VALUES ('fair-${index}','stamp','{"label":"fair-${index}"}','pending','2000-01-01T00:00:00.000Z','2000-01-01T00:00:00.000Z')`,
        );
      }
      db.exec(
        `INSERT INTO ${prefix(a)}zero__receipts (scope, \`key\`, userId, inputHash, state, createdAt) VALUES ('fairness','old',0,REPEAT('0',64),'done','2000-01-01T00:00:00.000Z')`,
      );
      db.exec(`DELETE FROM ${prefix(a)}options WHERE option_name='worker_core_proof'`);
      wp(
        `--exec=${bootstrap(a)}`,
        "eval",
        "wp_schedule_single_event(time()-10,'worker_core_proof'); as_schedule_single_action(time()-10,'zero_jobs_maintain',[],'zero');",
      );
      const [fair] = await worker(a, ["--limit=4"]).result();
      assert.equal(fair?.status, "ran");
      assert.equal(fair?.processed, 4);
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(a)}zero_records WHERE label LIKE 'fair-%'`),
        "2",
      );
      assert.equal(
        db.exec(
          `SELECT COUNT(*) FROM ${prefix(a)}zero__jobs WHERE id LIKE 'fair-%' AND state='pending'`,
        ),
        "1",
      );
      assert.equal(
        db.exec(`SELECT COUNT(*) FROM ${prefix(a)}zero__receipts WHERE scope='fairness'`),
        "0",
      );
      assert.equal(
        db.exec(
          `SELECT option_value FROM ${prefix(a)}options WHERE option_name='worker_core_proof'`,
        ),
        a,
      );
      // mail.send leaves through the Space's mail path: the broker's sender-domain check, then
      // space-mail.php's daily recipient allowance and wp_mail(). No second transport exists.
      await putRoute(rt, a, "production", {
        version_id: "ver_worker_live",
        // The Space also answers on a second hostname that is not its sending domain.
        hostnames: ["worker-a.test", "alias-a.test"],
        production_hostnames: ["worker-a.test", "alias-a.test"],
        config: publicAccessConfig({
          zero_generation: "v2",
          billing: { emailRecipientsDaily: 2 },
          email: { senderDomain: "worker-a.test" },
        }),
      });
      const notify = async (
        input: { from?: string; to: string; cc?: string },
        host = "worker-a.test",
      ) => {
        const response = await fetch(`${rt.baseUrl}/wp-json/zero/v1/actions/notify`, {
          method: "POST",
          headers: { host, "content-type": "application/json" },
          body: JSON.stringify(input),
        });
        assert.equal(response.status, 200, await response.clone().text());
        return z.object({ data: z.string() }).parse(await response.json()).data;
      };
      const sent = await notify({
        from: "hello@worker-a.test",
        to: "visitor@example.test",
      });
      const foreign = await notify({
        from: "hello@other.test",
        to: "visitor@example.test",
      });
      // Queued with the action's write; nothing reaches the transport before the worker runs.
      assert.equal(existsSync(mailCapture), false);
      const mailJob = (id: string) =>
        db.exec(
          `SELECT CONCAT(state,':',COALESCE(lastError,'')) FROM ${prefix(a)}zero__jobs WHERE job='zero.mail' AND id='${id}'`,
        );
      assert.equal((await worker(a, [], 1).result())[0]?.status, "failed");
      assert.equal(mailJob(sent), "done:");
      assert.match(mailJob(foreign), /^failed:.*email_sender_unverified/);
      // One recipient is spent today; two more exceed the allowance of two, so nothing is sent.
      const overAllowance = await notify({
        from: "hello@worker-a.test",
        to: "second@example.test",
        cc: "third@example.test",
      });
      assert.equal((await worker(a, [], 1).result())[0]?.status, "failed");
      assert.match(mailJob(overAllowance), /^failed:.*did not accept/);
      // Without `from`, the default sender is noreply@ the route's sending domain, not the host the
      // request came in on, so a message queued on the alias still passes the broker's domain check.
      await putRoute(rt, a, "production", {
        version_id: "ver_worker_live",
        hostnames: ["worker-a.test", "alias-a.test"],
        production_hostnames: ["worker-a.test", "alias-a.test"],
        config: publicAccessConfig({
          zero_generation: "v2",
          billing: { emailRecipientsDaily: 4 },
          email: { senderDomain: "worker-a.test" },
        }),
      });
      const defaulted = await notify({ to: "fourth@example.test" }, "alias-a.test");
      assert.equal((await worker(a).result())[0]?.status, "ran");
      assert.equal(mailJob(defaulted), "done:");
      const captured = readFileSync(mailCapture, "utf8")
        .trim()
        .split("\n")
        .map((line) =>
          z
            .object({
              to: z.array(z.string()),
              headers: z.array(z.string()),
              host: z.string(),
            })
            .parse(JSON.parse(line)),
        );
      assert.deepEqual(
        captured.map(({ to, headers, host }) => ({
          to,
          from: headers[0],
          host,
        })),
        [
          {
            to: ["visitor@example.test"],
            from: "From: hello@worker-a.test",
            host: "worker-a.test",
          },
          {
            to: ["fourth@example.test"],
            from: "From: noreply@worker-a.test",
            host: "worker-a.test",
          },
        ],
      );
      assert.equal(
        JSON.parse(readFileSync(path.join(rt.storageRoot, "spaces", a, "email-usage.json"), "utf8"))
          .recipients,
        2,
      );
      // Generation authority closes the worker before any legacy WordPress boot, even with due work.
      await putRoute(rt, b, "production", {
        version_id: "ver_worker_live",
        hostnames: ["worker-b.test"],
        production_hostnames: ["worker-b.test"],
        config: publicAccessConfig({ zero_generation: "legacy_0_8" }),
      });
      const legacyBefore = db.exec(`SELECT * FROM ${prefix(b)}zero__jobs ORDER BY id`);
      const [legacy] = await worker(b).result();
      assert.equal(legacy?.status, "inactive");
      assert.ok(legacy && "wordpress_booted" in legacy);
      assert.equal(legacy.wordpress_booted, false);
      assert.equal(db.exec(`SELECT * FROM ${prefix(b)}zero__jobs ORDER BY id`), legacyBefore);
    } finally {
      hold = false;
      release();
      for (const worker of workers) {
        worker.kill();
        await worker.exited;
      }
      sink.stop(true);
      rt.stop();
      db.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 180_000,
);
