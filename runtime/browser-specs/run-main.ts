// The launcher itself, loaded by run.ts once it has checked the namespace.
import { randomBytes } from "node:crypto";
import { accessSync, constants, existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { requireLoopbackOnly } from "./confinement.ts";

/**
 * The launcher. Importing this module loads only Node built-ins and the local
 * check, and runs nothing. run.ts calls this after its own check, and
 * this checks again first; every package it needs is loaded only after that.
 */
export async function main(): Promise<void> {
  await requireLoopbackOnly();
  // Packages load only now, past the check: importing this module resolves none.
  const { z } = await import("zod");
  const { createOwnedTestDatabase, dropOwnedTestDatabase } =
    await import("../../apps/control-plane/src/test/owned-test-database.ts");
  const { TOOLKIT_PHAR_PATH, toolkitPharReady } =
    await import("../../scripts/fetch-wp-php-toolkit.mjs");

  // Nothing is built or downloaded here: with no network, every input must
  // already be in the checkout. Each missing one is named with the command that
  // makes it, before any database or fixture starts.
  const repoPath = (relative: string) =>
    fileURLToPath(new URL(`../../${relative}`, import.meta.url));
  function executable(file: string | undefined) {
    if (!file) return false;
    try {
      accessSync(file, constants.X_OK);
      return true;
    } catch {
      return false;
    }
  }
  const missing = [
    ...((await toolkitPharReady())
      ? []
      : [
          `the WordPress PHP toolkit at ${TOOLKIT_PHAR_PATH} is missing or does not match its pinned digest: run \`node scripts/fetch-wp-php-toolkit.mjs\` (or \`bash runtime/tests/prepare.sh\`)`,
        ]),
    ...(executable(process.env.SPACEFAST_RUNTIME_BIN)
      ? []
      : [
          "the native runtime binary is missing: run `bash runtime/tests/prepare.sh`, or set SPACEFAST_RUNTIME_BIN to one built from this checkout",
        ]),
    ...(existsSync(repoPath("runtime/wordpress/zero-dashboard/zero-dashboard.php"))
      ? []
      : ["the Zero dashboard plugin is missing: run `bash runtime/tests/prepare.sh`"]),
    ...(existsSync(repoPath("apps/my/dist/client/_shell.html"))
      ? []
      : ["the hosted dashboard build is missing: run `bun run --cwd apps/my build:hosted`"]),
  ];
  if (missing.length > 0) {
    for (const line of missing) console.error(`browser proof input: ${line}`);
    process.exit(2);
  }

  // Each proof is a fixture process (the control plane in process, the engine
  // under the runtime harness) and the spec that drives a browser against it.
  const PROOFS = [
    { fixture: "./control-plane-access-fixture.ts", spec: "./origin-null-access.test.ts" },
    { fixture: "./sign-in-flow-fixture.ts", spec: "./sign-in-flow.test.ts" },
  ].map(({ fixture, spec }) => ({
    fixture: fileURLToPath(new URL(fixture, import.meta.url)),
    spec: fileURLToPath(new URL(spec, import.meta.url)),
  }));

  // The run's own database. The configured URL is only where it is made: the
  // local test Postgres (confined.sh refuses any other endpoint, and this checks
  // again at the point of use). The run creates a database under a fresh name,
  // migrates it, hands it to the fixtures, and drops it by that name once every
  // fixture has exited. It drops nothing else: never a database it found, never
  // one matched by pattern or fingerprint. `create database` fails on a name
  // already taken, so a database it did not create is never treated as its own.
  const adminUrl = z
    .string()
    .regex(
      /^postgres(?:ql)?:\/\/[A-Za-z0-9._~%-]+(?::[A-Za-z0-9._~%-]*)?@127\.0\.0\.1:(?:25432|5432)\/[A-Za-z0-9_]+$/,
      "SPACEFAST_TEST_DATABASE_ADMIN_URL must be postgres://<user>[:<password>]@127.0.0.1:<25432|5432>/<database>",
    )
    .parse(process.env.SPACEFAST_TEST_DATABASE_ADMIN_URL);
  // The fixtures check this shape before they import the control plane.
  const runDatabase = `sf_test_browser_${randomBytes(6).toString("hex")}`;
  const fixtureDatabaseUrl = Object.assign(new URL(adminUrl), {
    pathname: `/${runDatabase}`,
  }).toString();
  /** Set once `create database` succeeded: the one database this run may drop. */
  let created: string | undefined;

  async function createRunDatabase() {
    await createOwnedTestDatabase({
      adminUrl,
      name: runDatabase,
      migrationsFolder: fileURLToPath(new URL("../../apps/control-plane/drizzle", import.meta.url)),
      onCreated: (name) => {
        created = name;
      },
    });
    console.log(`browser proof run database: ${runDatabase}`);
  }

  /**
   * Drops the database this run created, and says so only once it is gone. The
   * run gives up the name before it tries: a drop that fails part-way is never
   * retried, so a database someone else made under the name since is left alone
   * (a possible orphan for a person to drop, never a replacement dropped).
   */
  async function dropRunDatabase() {
    const name = created;
    if (name === undefined) return;
    created = undefined;
    await dropOwnedTestDatabase(adminUrl, name);
    console.log(`browser proof run database dropped: ${name}`);
  }

  // A fixture that never says it is ready, or never finishes cleaning up, would
  // otherwise hang past every test timeout: the spec has not started yet, or has
  // already exited.
  const FIXTURE_READY_DEADLINE_MS = 240_000;
  const FIXTURE_CLEANUP_DEADLINE_MS = 60_000;

  function withDeadline<T>(work: Promise<T>, ms: number, what: string): Promise<T> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const expired = new Promise<never>((_, reject) => {
      timer = setTimeout(() => reject(new Error(`${what} did not finish within ${ms} ms`)), ms);
    });
    return Promise.race([work, expired]).finally(() => clearTimeout(timer));
  }

  async function readFixture(fixtureProcess: ReturnType<typeof spawnFixture>): Promise<string> {
    const reader = fixtureProcess.stdout.getReader();
    const decoder = new TextDecoder();
    let buffered = "";
    for (;;) {
      // oxlint-disable-next-line no-await-in-loop -- stream chunks are sequential by definition.
      const chunk = await reader.read();
      if (chunk.done) throw new Error("browser proof fixture exited before becoming ready");
      buffered += decoder.decode(chunk.value, { stream: true });
      const newline = buffered.indexOf("\n");
      if (newline >= 0) return buffered.slice(0, newline);
    }
  }

  // Each fixture runs in its own PID namespace, so it and everything it starts
  // (PHP workers in their own process groups included) die with it: a forced
  // kill leaves nothing behind. The fixture's own exit code comes through.
  function spawnFixture(fixture: string) {
    return Bun.spawn(
      [
        "unshare",
        "--pid",
        "--fork",
        "--kill-child",
        "--mount-proc",
        process.execPath,
        "--no-install",
        fixture,
      ],
      {
        env: { ...process.env, SPACEFAST_TEST_DATABASE_URL: fixtureDatabaseUrl },
        stdin: "ignore",
        stdout: "pipe",
        stderr: "inherit",
      },
    );
  }

  /**
   * Asks the fixture itself (its namespace's first process) to clean up. A
   * fixture that already exited has nothing to ask; its exit status still counts.
   */
  function stopFixture(fixtureProcess: ReturnType<typeof spawnFixture>) {
    let children: number[];
    try {
      children = readFileSync(
        `/proc/${fixtureProcess.pid}/task/${fixtureProcess.pid}/children`,
        "utf8",
      )
        .trim()
        .split(" ")
        .filter(Boolean)
        .map(Number);
    } catch {
      return;
    }
    for (const child of children) {
      try {
        process.kill(child, "SIGTERM");
      } catch {
        // Exited between the read and the signal.
      }
    }
    if (children.length === 0) fixtureProcess.kill("SIGTERM");
  }

  // One proof at a time: each fixture owns PHP servers and a browser. A local
  // run may name one spec file (`bash runtime/browser-specs/run-ci.sh sign-in-flow`);
  // CI names none and runs every proof.
  const [selected, ...testArgs] =
    process.argv[2]?.startsWith("-") === false
      ? [process.argv[2], ...process.argv.slice(3)]
      : [undefined, ...process.argv.slice(2)];
  const proofs = PROOFS.filter((proof) => selected === undefined || proof.spec.includes(selected));
  if (proofs.length === 0) throw new Error(`no browser proof matches ${selected}`);
  let exitCode = 0;
  // A launcher stopped from outside still drops its database: the fixture it
  // is running gets the signal, and the finally below runs once the current
  // proof unwinds. A launcher killed outright (SIGKILL, host loss) cannot: its
  // database stays, named `sf_test_browser_<id>`, for a person to drop.
  let interrupted = false;
  /** The proof that is running: its fixture, and its spec once started. */
  type RunningProof = {
    fixture?: ReturnType<typeof spawnFixture>;
    test?: ReturnType<typeof Bun.spawn>;
  };
  let current: RunningProof = {};
  for (const signal of ["SIGINT", "SIGTERM"] as const)
    process.on(signal, () => {
      interrupted = true;
      current.test?.kill("SIGTERM");
      if (current.fixture) stopFixture(current.fixture);
    });
  try {
    await createRunDatabase();
    for (const proof of proofs) {
      if (interrupted) break;
      const fixtureProcess = spawnFixture(proof.fixture);
      current = { fixture: fixtureProcess };
      try {
        // oxlint-disable-next-line no-await-in-loop -- proofs run one after another.
        const fixture = await withDeadline(
          readFixture(fixtureProcess),
          FIXTURE_READY_DEADLINE_MS,
          `${proof.fixture} startup`,
        );
        const test = Bun.spawn(
          [
            process.execPath,
            "--no-install",
            "test",
            proof.spec,
            "--timeout",
            "120000",
            ...testArgs,
          ],
          {
            env: { ...process.env, SPACEFAST_BROWSER_FIXTURE_JSON: fixture },
            stdin: "ignore",
            stdout: "inherit",
            stderr: "inherit",
          },
        );
        current.test = test;
        // oxlint-disable-next-line no-await-in-loop -- proofs run one after another.
        if ((await test.exited) !== 0) exitCode = 1;
      } finally {
        stopFixture(fixtureProcess);
        // oxlint-disable-next-line no-await-in-loop -- each fixture cleans up before the next starts.
        const cleanupCode = await withDeadline(
          fixtureProcess.exited,
          FIXTURE_CLEANUP_DEADLINE_MS,
          `${proof.fixture} cleanup`,
        ).catch(async (error: Error) => {
          console.error(error.message);
          // The fixture's namespace takes every process it started with it.
          fixtureProcess.kill("SIGKILL");
          await fixtureProcess.exited;
          return 1;
        });
        if (cleanupCode !== 0) {
          console.error(`${proof.fixture} exited ${cleanupCode} at cleanup`);
          exitCode = 1;
        }
      }
    }
  } catch (error) {
    console.error(error);
    exitCode = 1;
  } finally {
    // After every fixture tree has exited (each is awaited above).
    await dropRunDatabase().catch((error: Error) => {
      console.error(`could not drop the run database ${runDatabase}: ${error.message}`);
      exitCode = 1;
    });
    if (interrupted) exitCode = exitCode || 130;
  }

  process.exit(exitCode);
}
