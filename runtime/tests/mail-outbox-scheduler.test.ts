import { expect, test } from "bun:test";
import { cp, mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";

import { runtimeEngineMailOutboxCommand } from "../../apps/control-plane/src/runtime/engine-files.js";
import { startMysqlContainer } from "./mysql-container.js";

test("mail scheduler proves absent storage locally and refuses unavailable storage", async () => {
  const mysql = await startMysqlContainer({
    namePrefix: "mail-scheduler",
    database: "mail_scheduler",
    rootPassword: "disposable-mail-test",
  });
  const root = await mkdtemp(path.join(tmpdir(), "mail-scheduler-'"));
  const install = path.join(root, ".stattic");
  const release = path.join(install, "releases", "test");
  const run = async (url = mysql.url) => {
    const proc = Bun.spawn(["sh", "-c", runtimeEngineMailOutboxCommand(root)], {
      env: { ...process.env, SPACEFAST_ZERO_DATABASE_URL: url },
      stdout: "pipe",
      stderr: "pipe",
    });
    const [stdout, stderr, code] = await Promise.all([
      new Response(proc.stdout).text(),
      new Response(proc.stderr).text(),
      proc.exited,
    ]);
    return { stdout, stderr, code };
  };
  try {
    await mkdir(release, { recursive: true });
    await mkdir(path.join(install, "storage"), { recursive: true });
    await cp(path.resolve(import.meta.dir, "../engine"), path.join(release, "engine"), {
      recursive: true,
    });
    await writeFile(path.join(install, "active-release"), "releases/test\n");
    const absent = await run();
    expect(absent.code, absent.stderr).toBe(0);
    expect(JSON.parse(absent.stdout)).toEqual({
      claimed: 0,
      delivered: 0,
      retried: 0,
      dead: 0,
      lost: 0,
      unavailable: false,
    });
    mysql.exec("CREATE TABLE _spacefast_email_outbox (message_id VARCHAR(80) PRIMARY KEY)");
    const provisioned = await run();
    expect(provisioned.code).toBe(1);
    expect(JSON.parse(provisioned.stdout).unavailable).toBe(true);
    const unreachable = new URL(mysql.url);
    unreachable.port = "1";
    expect((await run(unreachable.toString())).code).toBe(1);
    await writeFile(path.join(install, "install-transaction.json"), "{}");
    expect((await run()).code).toBe(1);
  } finally {
    mysql.stop();
    await rm(root, { recursive: true, force: true });
  }
}, 600_000);
