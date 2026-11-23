// SpacePress preview versions run WordPress on an isolated SQLite database
// through the SQLite Database Integration plugin; live versions run on the
// box's MySQL or MariaDB. This suite boots real WordPress core on all three,
// runs the same statements through `$wpdb` on each, and asserts the preview
// answers as live does (spacefast/monorepo#6002).
import { afterAll, beforeAll, describe, expect, test } from "bun:test";
import { existsSync, mkdirSync, mkdtempSync, renameSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";

import { z } from "zod";

import {
  MYSQL_SETUP_TIMEOUT_MS,
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";
import { WORDPRESS_IMAGE } from "./zero-wordpress.ts";

const DRIVER = path.resolve(import.meta.dir, "fixtures/spacepress-preview-parity.php");
const ZERO_RUNTIME = path.resolve(import.meta.dir, "../../crates/zero/runtime/Zero");
const CONTAINER_PREFIX = "spacefast-preview-parity";
const ROOT_PASSWORD = "disposable-preview-parity";
const DB_NAME = "parity";

// The translator SpacePress previews load, and the Action Scheduler the suites
// already pin (zero-wordpress.ts).
const SQLITE_PLUGIN = {
  url: "https://downloads.wordpress.org/plugin/sqlite-database-integration.3.1.3.zip",
  sha256: "f6ce35e8419d0b9326221a907627d905e25c2a5ba2afc86492811f14c851efe0",
  dir: "sqlite-database-integration",
};
const ACTION_SCHEDULER = {
  url: "https://downloads.wordpress.org/plugin/action-scheduler.4.2.0.zip",
  sha256: "a360712a5adb066a0d60db76cbe2768234731cbcc363ba7dc2d51bf4559e4551",
  dir: "action-scheduler",
};

// Downloads and core bytes are reused across runs; each entry is keyed by its
// digest and published by rename, so a killed run never leaves half an entry.
const CACHE = path.join(tmpdir(), "spacefast-preview-parity-cache");

const LIVE = ["mysql", "mariadb"] as const;
const ENGINES = [...LIVE, "sqlite"] as const;
type Live = (typeof LIVE)[number];
type Engine = (typeof ENGINES)[number];

let work = "";
let core = "";
let sqlitePlugin = "";
let actionScheduler = "";
const servers: Partial<Record<Live, MysqlContainer>> = {};

beforeAll(async () => {
  work = mkdtempSync(path.join(tmpdir(), "preview-parity-"));
  mkdirSync(CACHE, { recursive: true });
  const [mysql, mariadb, coreDir, sqliteDir, schedulerDir] = await Promise.all([
    startMysqlContainer({
      namePrefix: CONTAINER_PREFIX,
      database: DB_NAME,
      rootPassword: ROOT_PASSWORD,
    }),
    startMysqlContainer({
      namePrefix: CONTAINER_PREFIX,
      database: DB_NAME,
      rootPassword: ROOT_PASSWORD,
      flavor: "mariadb",
    }),
    wordpressCore(),
    pluginDir(SQLITE_PLUGIN),
    pluginDir(ACTION_SCHEDULER),
  ]);
  servers.mysql = mysql;
  servers.mariadb = mariadb;
  core = coreDir;
  sqlitePlugin = path.join(sqliteDir, SQLITE_PLUGIN.dir);
  actionScheduler = path.join(schedulerDir, ACTION_SCHEDULER.dir);

  for (const engine of ENGINES) {
    mkdirSync(path.join(work, engine, "wp-content"), { recursive: true });
  }
  // The preview connection as the engine opens it: the stock plugin's WP_SQLite_DB, from the
  // plugin's own files, with no collation or functions of ours (spacefast/monorepo#5951).
  const plugin = JSON.stringify(sqlitePlugin);
  writeFileSync(
    path.join(work, "sqlite", "wp-content", "db.php"),
    `<?php
define('DB_ENGINE', 'sqlite');
define('SQLITE_JOURNAL_MODE', 'WAL');
require_once ${plugin} . '/wp-includes/database/version.php';
require_once ${plugin} . '/constants.php';
require_once ${plugin} . '/wp-includes/database/load.php';
require_once ${plugin} . '/wp-includes/sqlite/class-wp-sqlite-db.php';
require_once ${plugin} . '/wp-includes/sqlite/install-functions.php';
new WP_SQLite_DB(DB_NAME);
`,
  );

  await Promise.all(
    ENGINES.map(async (engine) => {
      const installed = z
        .object({ installed: z.literal(true) })
        .parse(await driver(engine, { action: "install" }));
      expect(installed.installed).toBe(true);
    }),
  );
}, MYSQL_SETUP_TIMEOUT_MS + 120_000);

afterAll(() => {
  stopMysqlContainers(CONTAINER_PREFIX);
  if (work) rmSync(work, { recursive: true, force: true });
}, 60_000);

// ============================================================ known differences

// Where the stock preview driver answers differently from live today. Each
// entry names the statement, both answers, the root cause and the fix: an
// engine-side registration on the preview connection (proven by the reference
// patch on spacefast/monorepo#6002) or a change upstream. The parity tests
// below compare everything else and leave out exactly these paths, which are
// asserted here with each side's exact answer, so a fix (or a regression) on
// either side fails a test instead of passing silently.
type KnownDifference = {
  row: number;
  what: string;
  case: string;
  path: string[];
  live: Json;
  sqlite: Json;
};

const duplicate = { result: false, error: "duplicate" };
const inserted = { result: 1, error: null };
const KNOWN_DIFFERENCES: KnownDifference[] = [
  // Rows 1-3, 5-7. Cause: the plugin declares every text column COLLATE NOCASE, which folds ASCII
  // case only, without PAD SPACE. Fix (engine): register NOCASE as ICU root at primary strength
  // over right-trimmed strings, and a matching `like`, on the Pdo\Sqlite before the driver
  // configures it. Every connection that writes the file must register them.
  {
    row: 1,
    what: "unicode case variant of a UNIQUE email",
    case: "unique",
    path: ["unicodeCase", "emailCaseVariant"],
    live: duplicate,
    sqlite: inserted,
  },
  {
    row: 1,
    what: "unicode case variant of a UNIQUE slug",
    case: "unique",
    path: ["unicodeCase", "slugCaseVariant"],
    live: duplicate,
    sqlite: inserted,
  },
  {
    row: 2,
    what: "accent variant of a UNIQUE email",
    case: "unique",
    path: ["accent", "emailAccentVariant"],
    live: duplicate,
    sqlite: inserted,
  },
  {
    row: 2,
    what: "accent variant of a UNIQUE slug",
    case: "unique",
    path: ["accent", "slugAccentVariant"],
    live: duplicate,
    sqlite: inserted,
  },
  {
    row: 2,
    what: "WHERE email = an accent variant",
    case: "unique",
    path: ["accent", "emailLookup"],
    live: ["chloé@example.com"],
    sqlite: ["chloe@example.com"],
  },
  {
    row: 3,
    what: "trailing-space variant of a UNIQUE email",
    case: "unique",
    path: ["trailingSpace", "emailPaddedVariant"],
    live: duplicate,
    sqlite: inserted,
  },
  {
    row: 3,
    what: "WHERE email = the value with trailing spaces",
    case: "unique",
    path: ["trailingSpace", "emailLookup"],
    live: ["pad@example.com"],
    sqlite: [],
  },
  {
    row: 5,
    what: "LIKE folds unicode case",
    case: "search",
    path: ["wordpressUnicode", "likeUnicodeLower"],
    live: ["Émile Zola"],
    sqlite: [],
  },
  {
    row: 5,
    what: "= folds unicode case",
    case: "search",
    path: ["wordpressUnicode", "equalsUnicodeCase"],
    live: ["Émile Zola"],
    sqlite: [],
  },
  {
    row: 5,
    what: "LIKE folds accents",
    case: "search",
    path: ["wordpressAccent", "likeAccent"],
    live: ["Émile Zola"],
    sqlite: [],
  },
  {
    row: 5,
    what: "= folds accents",
    case: "search",
    path: ["wordpressAccent", "equalsAccent"],
    live: ["Émile Zola"],
    sqlite: [],
  },
  {
    row: 6,
    what: "ORDER BY a text column",
    case: "search",
    path: ["wordpressOrder"],
    live: [
      "100% sure",
      "ADA BYRON",
      "Ada Lovelace",
      "Émile Zola",
      "Grace Hopper",
      "under_score",
      "underXscore",
    ],
    sqlite: [
      "100% sure",
      "ADA BYRON",
      "Ada Lovelace",
      "Grace Hopper",
      "under_score",
      "underXscore",
      "Émile Zola",
    ],
  },
  {
    row: 7,
    what: "= ignores trailing spaces (PAD SPACE)",
    case: "search",
    path: ["wordpressTrailingSpace"],
    live: ["Grace Hopper"],
    sqlite: [],
  },
  // Cause: the plugin ignores a column's declared collation, so utf8mb4_bin text is NOCASE too.
  // Fix (upstream plugin): honor the column collation (its "@TODO: Respect the actual column and
  // index collation" in WP_MySQL_On_SQLite), mapping *_bin to BINARY.
  {
    row: 4,
    what: "case variant in a utf8mb4_bin UNIQUE column",
    case: "unique",
    path: ["binaryCollation", "caseVariant"],
    live: inserted,
    sqlite: duplicate,
  },
  {
    row: 4,
    what: "WHERE on a utf8mb4_bin column ignores case",
    case: "unique",
    path: ["binaryCollation", "lookup"],
    live: [],
    sqlite: ["Token"],
  },
  // Cause: ZERO_ILIKE (crates/zero, Wp\Connection) folds case but not accents. Fix (upstream
  // Zero): strip combining marks after NFD inside ZERO_ILIKE.
  {
    row: 8,
    what: "Zero ilike folds accents",
    case: "search",
    path: ["zeroAccent", "ilikeAccent"],
    live: ["Émile Zola"],
    sqlite: [],
  },
  // Cause: the plugin's busy timeout defaults to 10 s; InnoDB waits 50 s for a row lock, so a
  // wait live rides out fails on the preview with "database is locked". Fix (engine): define
  // SQLITE_BUSY_TIMEOUT 50000 before the connection opens.
  {
    row: 9,
    what: "seconds a session waits for a held lock",
    case: "lock-wait-budget",
    path: ["seconds"],
    live: 50,
    sqlite: 10,
  },
  // Rows 10-15. Cause: the translator reports PDO SQLite's last_insert_rowid() and changes(): an
  // INSERT that stores nothing keeps the previous id, and upserts and UPDATEs count matched
  // rows. Fix (engine): report MySQL's numbers from the driver (insert_id 0 when nothing was
  // inserted; 2 per changed upserted row with that row's id; 0 per unchanged row), observing
  // changed rows with a temporary AFTER UPDATE trigger. Rows 10-11 break Action Scheduler's
  // unique actions: a duplicate enqueue returns another action's id, and a new action is lost.
  {
    row: 10,
    what: "a duplicate unique Action Scheduler enqueue returns",
    case: "action-scheduler",
    path: ["scheduled", "uniqueAgain"],
    live: 0,
    sqlite: 2,
  },
  {
    row: 11,
    what: "a unique action after a terminal row kept its key is stored",
    case: "action-scheduler",
    path: ["staleUniqueKey"],
    live: {
      secondIsNew: true,
      rows: [
        { status: "canceled", keyed: "0" },
        { status: "pending", keyed: "1" },
      ],
    },
    sqlite: { secondIsNew: true, rows: [{ status: "canceled", keyed: "1" }] },
  },
  {
    row: 12,
    what: "insert_id after INSERT IGNORE of a duplicate",
    case: "misc",
    path: ["insertIgnoreDuplicate", "write", "insertId"],
    live: 0,
    sqlite: 1,
  },
  {
    row: 12,
    what: "insert_id after INSERT ... SELECT of no rows",
    case: "misc",
    path: ["insertSelectNothing", "insertId"],
    live: 0,
    sqlite: 6,
  },
  {
    row: 13,
    what: "affected rows of an upsert that updates",
    case: "misc",
    path: ["onDuplicateKeyUpdate", "updated", "result"],
    live: 2,
    sqlite: 1,
  },
  {
    row: 13,
    what: "affected rows of an upsert after another insert",
    case: "misc",
    path: ["onDuplicateKeyUpdate", "updatedAfterOtherInsert", "result"],
    live: 2,
    sqlite: 1,
  },
  {
    row: 13,
    what: "insert_id of an upsert names the updated row",
    case: "misc",
    path: ["onDuplicateKeyUpdate", "updatedAfterOtherInsert", "insertIdIsUpdatedRow"],
    live: true,
    sqlite: false,
  },
  {
    row: 14,
    what: "affected rows of an upsert that changes nothing",
    case: "misc",
    path: ["onDuplicateKeyUpdate", "unchanged", "result"],
    live: 0,
    sqlite: 1,
  },
  {
    row: 14,
    what: "insert_id of an upsert that changes nothing",
    case: "misc",
    path: ["onDuplicateKeyUpdate", "unchanged", "insertId"],
    live: 0,
    sqlite: 3,
  },
  {
    row: 15,
    what: "affected rows of an UPDATE that changes nothing",
    case: "misc",
    path: ["updateAffectedRows", "unchanged", "result"],
    live: 0,
    sqlite: 1,
  },
  // Cause: the translator has no LAST_INSERT_ID(). Fix (engine): register it on the connection.
  {
    row: 16,
    what: "SELECT LAST_INSERT_ID()",
    case: "misc",
    path: ["insert", "lastInsertId"],
    live: { value: 1, error: null },
    sqlite: { value: null, error: "error" },
  },
  // Cause: the plugin answers GET_LOCK with the text '1=1' and locks nothing. Fix (engine):
  // register GET_LOCK/RELEASE_LOCK backed by flock files beside DB_PATH (1, or 0 on timeout).
  {
    row: 17,
    what: "GET_LOCK, then the same lock from a second session",
    case: "misc",
    path: ["namedLock"],
    live: { first: "1", secondSession: "0" },
    sqlite: { first: "1=1", secondSession: "1=1" },
  },
  // Cause: SQLite TEXT has no length. Fix (upstream plugin): truncate in non-strict mode, or
  // reject in strict mode, a value longer than the declared VARCHAR length.
  {
    row: 18,
    what: "a 16-character value in VARCHAR(10), strict mode off",
    case: "misc",
    path: ["nonStrictCoercion", "storedString"],
    live: "abcdefghij",
    sqlite: "abcdefghijklmnop",
  },
  // Cause: DATETIME(x, '+1 MONTH') rolls Feb 31 forward. Fix (upstream plugin): clamp to the
  // month's last day (SQLite 3.46's 'floor' modifier, or computed in PHP).
  {
    row: 19,
    what: "DATE_ADD(Jan 31, INTERVAL 1 MONTH)",
    case: "misc",
    path: ["datetime", "addMonthClamps"],
    live: "2026-02-28 10:00:00",
    sqlite: "2026-03-03 10:00:00",
  },
  // Cause: literals compare under SQLite's BINARY default, not the connection's _ci collation.
  // Fix (upstream plugin): compare string literals with the connection collation.
  {
    row: 20,
    what: "SELECT 'abc' = 'ABC'",
    case: "misc",
    path: ["literalComparisonIgnoresCase"],
    live: "1",
    sqlite: "0",
  },
];

describe("known SQLite preview differences (tracked upstream)", () => {
  for (const known of KNOWN_DIFFERENCES) {
    test(`row ${known.row}: ${known.what}`, async () => {
      for (const live of LIVE) {
        expect(at(await result(known.case)(live), ...known.path)).toEqual(known.live);
      }
      expect(at(await result(known.case)("sqlite"), ...known.path)).toEqual(known.sqlite);
    });
  }
});

/**
 * Asserts the preview answers each scenario of case `name` as each live
 * engine does, apart from the known differences asserted above.
 */
function parity(name: string, scenarios: string[]): void {
  for (const scenario of scenarios) {
    test.each(LIVE)(`${scenario}: SQLite preview matches %s`, async (live) => {
      const known = KNOWN_DIFFERENCES.filter((k) => k.case === name && k.path[0] === scenario).map(
        (k) => k.path.slice(1),
      );
      const [preview, server] = await Promise.all([result(name)("sqlite"), result(name)(live)]);
      expect(without(at(preview, scenario), known)).toEqual(without(at(server, scenario), known));
    });
  }
}

// ============================================================ case 1

describe("case-insensitive UNIQUE keys (utf8mb4_unicode_ci, dbDelta)", () => {
  test("live rejects an ASCII case variant and finds the row by any case", async () => {
    for (const live of LIVE) {
      const unique = await result("unique")(live);
      expect(at(unique, "ascii", "emailCaseVariant", "error")).toBe("duplicate");
      expect(at(unique, "ascii", "slugCaseVariant", "error")).toBe("duplicate");
      expect(at(unique, "ascii", "emailLookup")).toEqual(["Ada@Example.com"]);
    }
  });

  parity("unique", ["ascii", "unicodeCase", "accent", "binaryCollation", "trailingSpace"]);
});

// ============================================================ case 2

describe("LIKE and search", () => {
  test("live LIKE folds case and LIKE BINARY does not", async () => {
    for (const live of LIVE) {
      const search = await result("search")(live);
      expect(at(search, "wordpress", "likeAscii")).toEqual(["Ada Lovelace", "ADA BYRON"]);
      expect(at(search, "wordpress", "likeBinary")).toEqual(["Ada Lovelace"]);
      expect(at(search, "wordpress", "likeBinaryOtherCase")).toEqual([]);
      expect(at(search, "zero", "ilikeAscii")).toEqual(["Ada Lovelace", "ADA BYRON"]);
      expect(at(search, "zero", "likeCaseSensitive")).toEqual(["Ada Lovelace"]);
    }
  });

  parity("search", [
    "wordpress",
    "wordpressUnicode",
    "wordpressAccent",
    "wordpressOrder",
    "wordpressTrailingSpace",
    "zero",
    "zeroAccent",
  ]);
});

// ============================================================ case 3

describe("transactions with SELECT ... FOR UPDATE", () => {
  test.each(ENGINES)(
    "%s: a second read-modify-write waits for the first and reads its commit",
    async (engine) => {
      await driver(engine, { action: "case", case: "counter-setup" });
      const [a, b] = await Promise.all([Worker.start(engine), Worker.start(engine)]);
      try {
        a.send("lock");
        await a.next("locking");
        expect(await a.next("locked")).toMatchObject({ n: 0, error: null });

        if (engine === "sqlite") {
          b.send("try-lock");
          expect(await b.next("probed")).toMatchObject({
            result: false,
            error: expect.stringContaining("database is locked"),
          });
        }
        b.send("lock");
        await b.next("locking");
        const bLocked = b.next("locked");
        if (engine !== "sqlite") {
          expect(await Promise.race([waiting(engine), bLocked.then(() => "acquired")])).toBe(
            "waiting",
          );
        }

        a.send("commit");
        expect(await a.next("committed")).toMatchObject({ error: null });
        expect(await bLocked).toMatchObject({ n: 1, error: null });
        b.send("commit");
        expect(await b.next("committed")).toMatchObject({ error: null });

        expect(await counter(engine)).toEqual({ n: 2, other: 0 });
      } finally {
        a.stop();
        b.stop();
      }
    },
    60_000,
  );

  test.each(ENGINES)(
    "%s: two sessions racing 100 increments each lose no update and raise no error",
    async (engine) => {
      await driver(engine, { action: "case", case: "counter-setup" });
      const [a, b] = await Promise.all([Worker.start(engine), Worker.start(engine)]);
      const rounds = 100;
      try {
        a.send(`loop ${rounds}`);
        b.send(`loop ${rounds}`);
        const [ra, rb] = (await Promise.all([a.next("looped"), b.next("looped")])).map((event) =>
          loopSchema.parse(event),
        );
        if (!ra || !rb) throw new Error("both workers must report");
        expect([...ra.errors, ...rb.errors]).toEqual([]);
        // Every increment read a distinct committed value: none was lost.
        const reads = [...ra.iterations, ...rb.iterations]
          .map(([read]) => read)
          .toSorted((x, y) => x - y);
        expect(reads).toEqual(Array.from({ length: 2 * rounds }, (_, index) => index));
        // The two sessions really ran at the same time.
        const span = (r: z.infer<typeof loopSchema>) => [
          Math.min(...r.iterations.map(([, attempt]) => attempt)),
          Math.max(...r.iterations.map(([, , , committed]) => committed)),
        ];
        const [aStart = 0, aEnd = 0] = span(ra);
        const [bStart = 0, bEnd = 0] = span(rb);
        expect(Math.min(aEnd, bEnd)).toBeGreaterThan(Math.max(aStart, bStart));
        expect(await counter(engine)).toEqual({ n: 2 * rounds, other: 0 });
      } finally {
        a.stop();
        b.stop();
      }
    },
    120_000,
  );

  test.each(ENGINES)(
    "%s: a write to another row completes without error while a row lock is held",
    async (engine) => {
      await driver(engine, { action: "case", case: "counter-setup" });
      const [a, b] = await Promise.all([Worker.start(engine), Worker.start(engine)]);
      try {
        a.send("lock");
        await a.next("locking");
        await a.next("locked");
        // InnoDB locks only the row, so this write completes at once. SQLite's
        // START TRANSACTION is BEGIN IMMEDIATE, a database-wide write lock, so
        // there it waits for A's commit; either way it must not fail.
        b.send("touch");
        await b.next("touching");
        const touched = b.next("touched");
        a.send("commit");
        expect(await a.next("committed")).toMatchObject({ error: null });
        expect(await touched).toMatchObject({ error: null });
        expect(await counter(engine)).toEqual({ n: 1, other: 1 });
      } finally {
        a.stop();
        b.stop();
      }
    },
    60_000,
  );
});

// ============================================================ case 4

describe("Action Scheduler 4.2.0", () => {
  test("live installs its tables, enqueues, claims and runs", async () => {
    for (const live of LIVE) {
      const scheduler = await result("action-scheduler")(live);
      expect(at(scheduler, "tablesExist")).toBe(true);
      expect(at(scheduler, "createdTables")).toEqual([
        "actionscheduler_actions",
        "actionscheduler_claims",
        "actionscheduler_groups",
        "actionscheduler_logs",
      ]);
      expect(at(scheduler, "claimed")).toEqual(["parity_due", "parity_unique"]);
      expect(at(scheduler, "ran")).toEqual(["parity_due:7", "parity_unique"]);
    }
  });

  parity("action-scheduler", [
    "createdTables",
    "tablesExist",
    "schema",
    "redelta",
    "scheduled",
    "enqueued",
    "claimed",
    "whileClaimed",
    "processedAtLeast",
    "ran",
    "afterRun",
    "hasLogs",
    "staleUniqueKey",
  ]);
});

// ============================================================ case 5

describe("write reporting, defaults and locks", () => {
  test("live reports MySQL's affected rows", async () => {
    for (const live of LIVE) {
      const misc = await result("misc")(live);
      expect(at(misc, "onDuplicateKeyUpdate", "updated", "result")).toBe(2);
      expect(at(misc, "updateAffectedRows", "unchanged", "result")).toBe(0);
      expect(at(misc, "namedLock")).toEqual({ first: "1", secondSession: "0" });
    }
  });

  parity("misc", [
    "insert",
    "insertIgnoreDuplicate",
    "insertSelectNothing",
    "onDuplicateKeyUpdate",
    "updateAffectedRows",
    "nonStrictCoercion",
    "datetime",
    "foundRows",
    "literalComparisonIgnoresCase",
    "namedLock",
  ]);
});

// Every case above reopened the preview file in a new process.
test("the SQLite preview database passes PRAGMA integrity_check", async () => {
  expect(await driver("sqlite", { action: "case", case: "integrity" })).toEqual({
    integrity: ["ok"],
  });
});

// ============================================================ harness

/** What the PHP driver runs: an install, one named case, or a worker session. */
type Action = { action: "install" | "worker" } | { action: "case"; case: string };

/** A driver result: JSON, so every engine's answer compares structurally. */
type Json = string | number | boolean | null | Json[] | { [key: string]: Json };
const json: z.ZodType<Json> = z.lazy(() =>
  z.union([
    z.string(),
    z.number(),
    z.boolean(),
    z.null(),
    z.array(json),
    z.record(z.string(), json),
  ]),
);

function request(engine: Engine, action: Action): string {
  const base = {
    engine: engine === "sqlite" ? "sqlite" : "mysql",
    site: path.join(work, engine),
    core,
    dbName: DB_NAME,
    zeroRuntime: ZERO_RUNTIME,
    actionScheduler,
  };
  if (engine === "sqlite") {
    return JSON.stringify({
      ...base,
      ...action,
      dbPath: path.join(work, engine, "wp-content", "parity.sqlite"),
    });
  }
  const server = servers[engine];
  if (!server) throw new Error(`${engine} server is not running`);
  return JSON.stringify({ ...base, ...action, dbUrl: server.url });
}

function phpCommand(engine: Engine, extra: Action): string[] {
  return [
    "php",
    "-d",
    "display_errors=stderr",
    "-d",
    "error_reporting=E_ALL & ~E_DEPRECATED",
    "-d",
    "memory_limit=512M",
    DRIVER,
    request(engine, extra),
  ];
}

const envelope = z.union([z.object({ data: json }), z.object({ error: z.string() })]);

async function driver(engine: Engine, extra: Action): Promise<Json> {
  const proc = Bun.spawn(phpCommand(engine, extra), { stdout: "pipe", stderr: "pipe" });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);
  const line = stdout.trim().split("\n").at(-1) ?? "";
  let parsed: z.infer<typeof envelope>;
  try {
    parsed = envelope.parse(JSON.parse(line));
  } catch {
    throw new Error(`${engine} driver exited ${exitCode}:\n${stdout}\n${stderr}`);
  }
  if ("error" in parsed) throw new Error(`${engine}: ${parsed.error}\n${stderr}`);
  return parsed.data;
}

const results = new Map<string, Promise<Json>>();

/** Each case runs once per engine; its tests compare parts of the result. */
function result(name: string): (engine: Engine) => Promise<Json> {
  return (engine) => {
    const key = `${name}:${engine}`;
    let pending = results.get(key);
    if (!pending) {
      pending = driver(engine, { action: "case", case: name });
      results.set(key, pending);
    }
    return pending;
  };
}

/** `value` without the parts at `paths`, each relative to it; an empty path drops it whole. */
function without(value: Json, paths: string[][]): Json {
  if (paths.some((p) => p.length === 0)) return null;
  if (value === null || Array.isArray(value) || !(value instanceof Object)) return value;
  const kept: { [key: string]: Json } = {};
  for (const [key, child] of Object.entries(value)) {
    const below = paths.filter((p) => p[0] === key).map((p) => p.slice(1));
    kept[key] = below.length === 0 ? child : without(child, below);
  }
  return kept;
}

/** The part of a result at `keys`; a missing part fails the test rather than comparing as absent. */
function at(value: Json, ...keys: string[]): Json {
  let current = value;
  for (const key of keys) {
    const next =
      current !== null && !Array.isArray(current) && current instanceof Object
        ? current[key]
        : undefined;
    if (next === undefined) throw new Error(`missing ${keys.join(".")}`);
    current = next;
  }
  return current;
}

async function counter(engine: Engine): Promise<{ n: number; other: number }> {
  return z
    .object({ n: z.number(), other: z.number() })
    .parse(await driver(engine, { action: "case", case: "counter-read" }));
}

const eventSchema = z.object({ event: z.string() }).passthrough();
const loopSchema = z.object({
  event: z.literal("looped"),
  iterations: z.array(z.tuple([z.number(), z.number(), z.number(), z.number()])),
  errors: z.array(z.string()),
});

/** One WordPress session in its own PHP process, driven line by line. */
class Worker {
  private buffered: z.infer<typeof eventSchema>[] = [];
  private waiters: ((event: z.infer<typeof eventSchema>) => void)[] = [];
  private stderr = "";

  private constructor(
    private readonly proc: Bun.Subprocess<"pipe", "pipe", "pipe">,
    readonly engine: Engine,
  ) {}

  static async start(engine: Engine): Promise<Worker> {
    const proc = Bun.spawn(phpCommand(engine, { action: "worker" }), {
      stdin: "pipe",
      stdout: "pipe",
      stderr: "pipe",
    });
    const worker = new Worker(proc, engine);
    void worker.pump();
    void new Response(proc.stderr).text().then((text) => {
      worker.stderr = text;
    });
    await worker.next("ready");
    return worker;
  }

  send(command: string): void {
    this.proc.stdin.write(`${command}\n`);
    this.proc.stdin.flush();
  }

  /** The next event, which must be `name`. */
  async next(name: string): Promise<z.infer<typeof eventSchema>> {
    const event =
      this.buffered.shift() ??
      (await new Promise<z.infer<typeof eventSchema>>((resolve) => this.waiters.push(resolve)));
    if (event.event !== name) {
      throw new Error(
        `${this.engine} worker sent ${JSON.stringify(event)}, expected ${name}\n${this.stderr}`,
      );
    }
    return event;
  }

  stop(): void {
    this.proc.kill("SIGKILL");
  }

  private async pump(): Promise<void> {
    const decoder = new TextDecoder();
    let pending = "";
    for await (const chunk of this.proc.stdout) {
      pending += decoder.decode(chunk, { stream: true });
      let newline = pending.indexOf("\n");
      while (newline !== -1) {
        const line = pending.slice(0, newline);
        pending = pending.slice(newline + 1);
        newline = pending.indexOf("\n");
        let event: z.infer<typeof eventSchema>;
        try {
          event = eventSchema.parse(JSON.parse(line));
        } catch {
          event = { event: "unparsable", line };
        }
        const waiter = this.waiters.shift();
        if (waiter) waiter(event);
        else this.buffered.push(event);
      }
    }
    const exited = { event: "exited", code: await this.proc.exited, stderr: this.stderr };
    for (const waiter of this.waiters.splice(0)) waiter(exited);
    this.buffered.push(exited);
  }
}

/** Wait for InnoDB to report the second session's lock wait. */
async function waiting(engine: Live): Promise<"waiting"> {
  const server = servers[engine];
  if (!server) throw new Error(`${engine} server is not running`);
  const deadline = Date.now() + 20_000;
  for (;;) {
    const waits = server.exec(
      "SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'",
    );
    if (Number(waits) > 0) return "waiting";
    if (Date.now() > deadline) throw new Error(`${engine} worker never blocked`);
    await Bun.sleep(5);
  }
}

// ============================================================ fixtures

async function wordpressCore(): Promise<string> {
  const digest = WORDPRESS_IMAGE.split("sha256:")[1]?.slice(0, 16) ?? "unknown";
  const target = path.join(CACHE, `wordpress-${digest}`);
  if (existsSync(path.join(target, "wp-settings.php"))) return target;
  const staging = mkdtempSync(path.join(CACHE, "wordpress-staging-"));
  const extract = Bun.spawn({
    cmd: [
      "docker",
      "run",
      "--rm",
      "--entrypoint",
      "tar",
      WORDPRESS_IMAGE,
      "-C",
      "/usr/src/wordpress",
      "-cf",
      "-",
      ".",
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  const untar = Bun.spawn({
    cmd: ["tar", "-xf", "-", "-C", staging],
    stdin: extract.stdout,
    stderr: "pipe",
  });
  const [extractCode, untarCode, extractErr] = await Promise.all([
    extract.exited,
    untar.exited,
    new Response(extract.stderr).text(),
  ]);
  if (extractCode !== 0 || untarCode !== 0)
    throw new Error(`wordpress core extraction failed:\n${extractErr}`);
  // The image ships a wp-config for its entrypoint; the driver defines its own.
  rmSync(path.join(staging, "wp-config.php"), { force: true });
  publish(staging, target);
  return target;
}

async function pluginDir(plugin: { url: string; sha256: string }): Promise<string> {
  const target = path.join(CACHE, `plugin-${plugin.sha256.slice(0, 16)}`);
  if (existsSync(path.join(target, ".complete"))) return target;
  const bytes = await download(plugin.url);
  const actual = new Bun.CryptoHasher("sha256").update(bytes).digest("hex");
  if (actual !== plugin.sha256)
    throw new Error(`${plugin.url}: sha256 ${actual}, expected ${plugin.sha256}`);
  const staging = mkdtempSync(path.join(CACHE, "plugin-staging-"));
  const archive = path.join(staging, "plugin.zip");
  writeFileSync(archive, bytes);
  const unzip = Bun.spawnSync({
    cmd: ["unzip", "-q", archive, "-d", staging],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (unzip.exitCode !== 0)
    throw new Error(`unzip ${plugin.url} failed:\n${unzip.stderr.toString()}`);
  rmSync(archive);
  writeFileSync(path.join(staging, ".complete"), plugin.sha256);
  publish(staging, target);
  return target;
}

function publish(staging: string, target: string): void {
  try {
    renameSync(staging, target);
  } catch (error) {
    // A concurrent run published the same digest first.
    rmSync(staging, { recursive: true, force: true });
    if (!existsSync(target)) throw error;
  }
}

// downloads.wordpress.org answers the odd request with a 5xx; the pinned
// digest checks whatever finally arrives.
async function download(url: string): Promise<Uint8Array> {
  let failure = "";
  for (let attempt = 1; attempt <= 4; attempt += 1) {
    try {
      const response = await fetch(url, { signal: AbortSignal.timeout(30_000) });
      if (response.status === 200) return new Uint8Array(await response.arrayBuffer());
      failure = `HTTP ${response.status}`;
      await response.body?.cancel();
      if (response.status < 500) break;
    } catch (cause) {
      failure = String(cause);
    }
    await Bun.sleep(attempt * 1_000);
  }
  throw new Error(`${url}: ${failure}`);
}
