// Space data (admin/space-db.php) against the real PHP engine and a real
// database: which tables a Space's data routes serve, what they keep secret,
// and how rows are read and changed by primary key.
//
// REQUIRES: docker for the database servers, and a PHP built with mysqli. The
// first two tests need neither.
import { afterAll, beforeAll, describe, expect, test } from "bun:test";
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  spaceDbRowsCreatedSchema,
  spaceDbRowsDeletedSchema,
  spaceDbRowsPageSchema,
  spaceDbRowsUpdatedSchema,
  spaceDbTablesSchema,
} from "../../packages/common/src/contracts/space-db.ts";
import { api, startRuntime, type Runtime } from "./harness.ts";
import { startMysqlContainer, stopMysqlContainers } from "./mysql-container.ts";

const SPACE_ID = "spc_space_db";
const ZERO_V2 = `sfz_${createHash("sha256").update(SPACE_ID).digest("hex").slice(0, 24)}_`;
const OTHER_ZERO_V2 = `sfz_${createHash("sha256").update("spc_other").digest("hex").slice(0, 24)}_`;
const D1_TABLE = `sf_d1_${"a".repeat(40)}`;
const D1_LEDGER = `sf_d1_m_${"b".repeat(40)}`;
const CONTENT_HASH = "c".repeat(16);

const runtimes: Runtime[] = [];

const jsonSchema = z.json();
type Json = z.infer<typeof jsonSchema>;
const problemSchema = z.object({ code: z.string(), pointer: z.string().optional() }).loose();
const ruleSchema = z.object({ match: z.string(), name: z.string() });

afterAll(() => {
  for (const runtime of runtimes) runtime.stop();
  stopMysqlContainers();
});

function call(rt: Runtime, method: string, route: string, action: string, body?: Json) {
  return api(
    rt,
    method,
    `/__spacefast/api.php/spaces/${SPACE_ID}/db/${route}`,
    action,
    { space_id: SPACE_ID },
    body,
  );
}

async function ok<T>(response: Response, schema: z.ZodType<T>): Promise<T> {
  const text = await response.text();
  if (response.status !== 200) throw new Error(`${response.status}: ${text}`);
  return schema.parse(JSON.parse(text));
}

/** The refusal's status and code, and its pointer when it names one. */
async function problem(response: Response) {
  const body = problemSchema.parse(await response.json());
  return body.pointer === undefined
    ? { status: response.status, code: body.code }
    : { status: response.status, code: body.code, pointer: body.pointer };
}

const query = (rt: Runtime, table: string, extra: { [key: string]: Json } = {}) =>
  call(rt, "POST", "rows/query", "space_db_rows_query", { table, ...extra });

test("the secret names mirror TypedPress's unreadable options", () => {
  const read = (file: string) =>
    JSON.parse(readFileSync(path.resolve(import.meta.dirname, "..", "..", file), "utf8"));
  const guard = z
    .object({ options: z.array(ruleSchema.extend({ read: z.boolean().optional() }).loose()) })
    .loose()
    .parse(read("crates/typedpress/runtime/TypedPress/Wp/guard.json"));
  const secrets = z
    .object({ names: z.array(ruleSchema) })
    .loose()
    .parse(read("runtime/engine/admin/space-db-secrets.json"));
  expect(secrets.names).toEqual(
    guard.options.filter((rule) => rule.read !== true).map(({ match, name }) => ({ match, name })),
  );
});

test("a token for another action or Space is refused before any database work", async () => {
  const rt = await startRuntime();
  runtimes.push(rt);
  expect(await problem(await call(rt, "GET", "tables", "space_db_rows_query"))).toMatchObject({
    status: 403,
    code: "runtime_action_forbidden",
  });
  const otherSpace = await api(
    rt,
    "GET",
    `/__spacefast/api.php/spaces/${SPACE_ID}/db/tables`,
    "space_db_tables",
    {
      space_id: "spc_other",
    },
  );
  expect(otherSpace.status).toBe(403);
});

for (const flavor of ["mysql", "mariadb"] as const) {
  describe(`on ${flavor}`, () => {
    let rt: Runtime;
    let mysql: Awaited<ReturnType<typeof startMysqlContainer>>;

    // Shared by every test below, so any one of them runs on its own.
    beforeAll(async () => {
      mysql = await startMysqlContainer({
        namePrefix: "stattic-space-db",
        database: "space_db_test",
        rootPassword: "space-db-secret-pw",
        flavor,
      });
      rt = await startRuntime({ env: { SPACEFAST_ZERO_DATABASE_URL: mysql.url } });
      runtimes.push(rt);
      mysql.exec(
        [
          "CREATE TABLE wp_options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'yes')",
          "INSERT INTO wp_options (option_name, option_value) VALUES ('blogname', 'My Space'), ('auth_key', 'k3y-value'), ('jetpack_private_options', 'p'), ('siteurl', 'https://example.test')",
          "CREATE TABLE wp_users (ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_login VARCHAR(60) NOT NULL, user_pass VARCHAR(255) NOT NULL, user_email VARCHAR(100) NOT NULL, user_activation_key VARCHAR(255) NOT NULL DEFAULT '')",
          "INSERT INTO wp_users (user_login, user_pass, user_email) VALUES ('ada', '$P$hash', 'ada@example.test')",
          "CREATE TABLE wp_usermeta (umeta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(255), meta_value LONGTEXT)",
          "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'nickname', 'ada'), (1, 'session_tokens', 'tok'), (1, 'api_token', NULL), (2, NULL, 'tok'), (3, CONCAT('nickname', CHAR(9)), 'tabbed')",
          "CREATE TABLE wp_sfi_sessions (id INT PRIMARY KEY, secret_hash VARCHAR(64))",
          "CREATE TABLE wp_tp__jobs (id INT PRIMARY KEY)",
          "CREATE TABLE wp_tp_identity_accounts (id INT PRIMARY KEY)",
          "CREATE TABLE wp_tp_items (id INT PRIMARY KEY)",
          `CREATE TABLE wp_zero_${CONTENT_HASH}_migrations (revision VARCHAR(71) NOT NULL PRIMARY KEY)`,
          `CREATE TABLE wp_zero_${CONTENT_HASH}_projects (id INT PRIMARY KEY)`,
          "CREATE TABLE _spacefast_application_journal (entry_id VARCHAR(64) PRIMARY KEY)",
          `CREATE TABLE ${D1_TABLE} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(20) NOT NULL, body BLOB, doubled INT GENERATED ALWAYS AS (CHAR_LENGTH(title) * 2) VIRTUAL)`,
          `INSERT INTO ${D1_TABLE} (title) VALUES ('one'), ('two'), ('three')`,
          `CREATE TABLE ${D1_LEDGER} (name VARBINARY(255) PRIMARY KEY)`,
          `CREATE TABLE ${ZERO_V2}options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL UNIQUE, option_value LONGTEXT NOT NULL)`,
          `INSERT INTO ${ZERO_V2}options (option_name, option_value) VALUES ('nonce_salt', 's4lt')`,
          `CREATE TABLE ${ZERO_V2}zero__outbox (id INT PRIMARY KEY)`,
          `CREATE TABLE ${OTHER_ZERO_V2}posts (id INT PRIMARY KEY)`,
          "CREATE TABLE notes_raw (body TEXT)",
          "CREATE TABLE wp_zero_identity_sessions (id INT PRIMARY KEY, secret_hash VARCHAR(64))",
          `CREATE TABLE ${ZERO_V2}zero_identity_accounts (wp_user_id INT PRIMARY KEY, status VARCHAR(20))`,
          "CREATE TABLE wp_zero_spacefast_migrations (space_id VARCHAR(64) PRIMARY KEY)",
          "CREATE TABLE wp_tp_spacefast_migrations (space_id VARCHAR(64) PRIMARY KEY)",
          "CREATE TABLE uuid_items (id BINARY(16) NOT NULL PRIMARY KEY, title VARCHAR(20) NOT NULL)",
          "INSERT INTO uuid_items (id, title) VALUES (UNHEX('00000000000000000000000000000001'), 'a'), (UNHEX('ff000000000000000000000000000002'), 'b'), (UNHEX('ff000000000000000000000000000003'), 'c')",
          "INSERT INTO notes_raw (body) VALUES ('a'), ('b'), ('c')",
          "CREATE TABLE legacy_myisam (id INT PRIMARY KEY, v INT) ENGINE=MyISAM",
          "CREATE TABLE `123` (id INT PRIMARY KEY)",
          "CREATE TABLE codes (code VARCHAR(10) NOT NULL PRIMARY KEY, v INT)",
          "INSERT INTO codes (code, v) VALUES ('1', 1), ('01', 2)",
          "CREATE TABLE docs (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, body JSON)",
          // MySQL only: a primary key `SELECT *` would leave out.
          ...(flavor === "mysql"
            ? [
                "CREATE TABLE ghost (id INT NOT NULL PRIMARY KEY INVISIBLE, v VARCHAR(5))",
                "INSERT INTO ghost (id, v) VALUES (1, 'a'), (2, 'b')",
              ]
            : []),
          "CREATE TABLE audited (id INT PRIMARY KEY)",
          "CREATE TRIGGER audited_ai AFTER INSERT ON audited FOR EACH ROW SET @audited = NEW.id",
          "CREATE TABLE bigkeys (id BIGINT NOT NULL PRIMARY KEY, v INT)",
          "INSERT INTO bigkeys (id, v) VALUES (9007199254740993, 1), (5, 2)",
          "CREATE TABLE longkeys (k VARCHAR(3000) CHARACTER SET ascii NOT NULL PRIMARY KEY)",
          "INSERT INTO longkeys (k) VALUES (CONCAT(REPEAT(CHAR(92), 1900), 'a')), (CONCAT(REPEAT(CHAR(92), 1900), 'b')), (CONCAT(REPEAT(CHAR(92), 1900), 'c'))",
          "CREATE TABLE wp_lockedopts (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(64) NOT NULL DEFAULT 'auth_key', option_value TEXT)",
          "CREATE TABLE wp_vkopts (option_name VARCHAR(64), option_value VARCHAR(64) NOT NULL PRIMARY KEY)",
          "CREATE TABLE wp_decopts (k DECIMAL(5,2) NOT NULL PRIMARY KEY, option_name VARCHAR(64) NOT NULL DEFAULT 'auth_key', option_value TEXT)",
          "CREATE TABLE wp_binopts (option_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARBINARY(64) NOT NULL, option_value TEXT)",
          "INSERT INTO wp_binopts (option_name, option_value) VALUES ('blogname', 'My Space'), ('auth_key', 'k3y-value')",
        ].join("; "),
      );
    }, 180_000);

    test("lists the Space's WordPress and app tables and never Spacefast's own", async () => {
      const { tables } = await ok(
        await call(rt, "GET", "tables", "space_db_tables"),
        spaceDbTablesSchema,
      );
      const byName = (a: readonly unknown[], b: readonly unknown[]) =>
        String(a[0]) < String(b[0]) ? -1 : 1;
      expect(
        tables.map((table) => [table.name, table.kind, table.editable]).toSorted(byName),
      ).toEqual(
        [
          [D1_TABLE, "app", true],
          ["notes_raw", "app", false],
          ["uuid_items", "app", false],
          [`${ZERO_V2}options`, "wordpress", true],
          ["wp_options", "wordpress", true],
          ["wp_tp_items", "app", true],
          ["wp_usermeta", "wordpress", true],
          ["wp_users", "wordpress", true],
          [`wp_zero_${CONTENT_HASH}_projects`, "app", true],
          ["legacy_myisam", "app", false],
          ["123", "app", true],
          ["codes", "app", true],
          ["docs", "app", true],
          ...(flavor === "mysql" ? [["ghost", "app", true]] : []),
          ["audited", "app", false],
          ["bigkeys", "app", true],
          ["longkeys", "app", true],
          ["wp_lockedopts", "wordpress", true],
          ["wp_vkopts", "wordpress", false],
          ["wp_decopts", "wordpress", true],
          ["wp_binopts", "wordpress", false],
        ].toSorted(byName),
      );
      expect(tables.find((table) => table.name === "legacy_myisam")?.readOnlyReason).toMatch(
        /storage engine/,
      );
      const d1 = tables.find((table) => table.name === D1_TABLE);
      expect(d1?.primaryKey).toEqual(["id"]);
      expect(
        d1?.columns.map((column) => [
          column.name,
          column.editable,
          column.autoIncrement,
          column.generated,
        ]),
      ).toEqual([
        ["id", true, true, false],
        ["title", true, false, false],
        ["body", false, false, false],
        ["doubled", false, false, true],
      ]);
      expect(tables.find((table) => table.name === "notes_raw")?.readOnlyReason).toMatch(
        /no primary key/,
      );
      const users = tables.find((table) => table.name === "wp_users");
      expect(
        users?.columns.filter((column) => !column.editable).map((column) => column.name),
      ).toEqual(["user_pass", "user_activation_key"]);
    });

    test("reads rows with secrets redacted, and never confirms a guessed secret", async () => {
      const options = await ok(await query(rt, "wp_options"), spaceDbRowsPageSchema);
      expect(options.columns).toEqual(["option_id", "option_name", "option_value", "autoload"]);
      expect(options.rows.map((row) => [row.option_name, row.option_value])).toEqual([
        ["blogname", "My Space"],
        ["auth_key", "[redacted]"],
        ["jetpack_private_options", "[redacted]"],
        ["siteurl", "https://example.test"],
      ]);
      expect(options.redactedColumns).toEqual(["option_value"]);

      const scoped = await ok(await query(rt, `${ZERO_V2}options`), spaceDbRowsPageSchema);
      expect(scoped.rows[0]?.option_value).toBe("[redacted]");

      const users = await ok(await query(rt, "wp_users"), spaceDbRowsPageSchema);
      expect(users.rows[0]).toMatchObject({
        user_login: "ada",
        user_pass: "[redacted]",
        user_activation_key: "",
      });
      expect(users.redactedColumns).toEqual(["user_pass"]);

      const meta = await ok(
        await query(rt, "wp_usermeta", { where: { user_id: 1 } }),
        spaceDbRowsPageSchema,
      );
      expect(meta.rows.map((row) => row.meta_value)).toEqual(["ada", "[redacted]", "[redacted]"]);
      // NULL is a guess too: a secret row's NULL never confirms a match.
      const nullGuess = await ok(
        await query(rt, "wp_usermeta", { where: { meta_value: null } }),
        spaceDbRowsPageSchema,
      );
      expect(nullGuess.rows).toEqual([]);
      // The same guess on a value both a secret row and an unnamed row hold:
      // the secret row stays out, and a row with no name is still a match.
      const tokGuess = await ok(
        await query(rt, "wp_usermeta", { where: { meta_value: "tok" } }),
        spaceDbRowsPageSchema,
      );
      expect(tokGuess.rows.map((row) => [row.user_id, row.meta_key])).toEqual([[2, null]]);
      // The secret row is left out by the page query itself, so it takes no
      // place in a limited page and leaves no cursor pointing past it. There
      // is no separate scan for a concurrent write to slip between.
      const tokPage = await ok(
        await query(rt, "wp_usermeta", { where: { meta_value: "tok" }, limit: 1 }),
        spaceDbRowsPageSchema,
      );
      expect([tokPage.rows.map((row) => row.user_id), tokPage.cursor]).toEqual([[2], null]);

      const blogname = await ok(
        await query(rt, "wp_options", { where: { option_name: "blogname" } }),
        spaceDbRowsPageSchema,
      );
      expect(blogname.rows.map((row) => row.option_value)).toEqual(["My Space"]);
      const guessed = await ok(
        await query(rt, "wp_options", { where: { option_value: "k3y-value" } }),
        spaceDbRowsPageSchema,
      );
      expect(guessed.rows).toEqual([]);
      expect(
        await problem(await query(rt, "wp_users", { where: { user_pass: "$P$hash" } })),
      ).toEqual({
        status: 403,
        code: "space_db_value_protected",
        pointer: "/where/user_pass",
      });
    });

    test("reads tables with numeric names and invisible keys", async () => {
      const numeric = await ok(await query(rt, "123"), spaceDbRowsPageSchema);
      expect(numeric.columns).toEqual(["id"]);
      if (flavor !== "mysql") return;
      const first = await ok(await query(rt, "ghost", { limit: 1 }), spaceDbRowsPageSchema);
      expect(first.rows).toEqual([{ id: 1, v: "a" }]);
      const second = await ok(
        await query(rt, "ghost", { limit: 1, cursor: first.cursor }),
        spaceDbRowsPageSchema,
      );
      expect(second.rows).toEqual([{ id: 2, v: "b" }]);
    });

    test("writes refuse tables that can't roll back, keep key types, and keep JSON objects", async () => {
      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: "legacy_myisam",
            rows: [{ id: 1, v: 1 }],
          }),
        ),
      ).toMatchObject({ status: 422, code: "space_db_table_not_editable" });

      // A numeric key on a string column is compared as a string: `1` is not '01'.
      const deleted = await ok(
        await call(rt, "POST", "rows/delete", "space_db_rows_delete", {
          table: "codes",
          keys: [{ code: 1 }],
        }),
        spaceDbRowsDeletedSchema,
      );
      expect(deleted).toEqual({ table: "codes", deleted: 1, missing: [] });
      const left = await ok(await query(rt, "codes"), spaceDbRowsPageSchema);
      expect(left.rows).toEqual([{ code: "01", v: 2 }]);

      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: "codes",
            rows: [{ v: 3 }],
          }),
        ),
      ).toEqual({ status: 422, code: "space_db_key_invalid", pointer: "/rows/0/code" });

      const created = await ok(
        await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
          table: "docs",
          rows: [{ body: {} }, { body: [] }],
        }),
        spaceDbRowsCreatedSchema,
      );
      expect(created.keys).toEqual([{ id: 1 }, { id: 2 }]);
      const docs = await ok(await query(rt, "docs"), spaceDbRowsPageSchema);
      expect(docs.rows.map((row) => JSON.parse(String(row.body)))).toEqual([{}, []]);
    });

    test("a guarded table is refused when the secret rules can't be read", async () => {
      const broken = await startRuntime({ env: { SPACEFAST_ZERO_DATABASE_URL: mysql.url } });
      runtimes.push(broken);
      const rulesFile = path.join(broken.engineRoot, "admin/space-db-secrets.json");
      const rules = z
        .object({ names: z.array(ruleSchema) })
        .loose()
        .parse(JSON.parse(readFileSync(rulesFile, "utf8")));
      for (const contents of [
        "{not json",
        JSON.stringify({ ...rules, spacefast: {} }),
        JSON.stringify({ ...rules, spacefast: [] }),
        // An object with numeric keys is not a list, whatever PHP makes of it.
        JSON.stringify({ ...rules, spacefast: { 0: { match: "exact", name: "sfi_x" } } }),
      ]) {
        writeFileSync(rulesFile, contents);
        expect(await problem(await query(broken, "wp_options"))).toMatchObject({
          status: 500,
          code: "space_db_query_failed",
        });
      }
      // Tables without option or meta rows still read.
      const users = await ok(await query(broken, "wp_users"), spaceDbRowsPageSchema);
      expect(users.rows[0]?.user_pass).toBe("[redacted]");
    });

    test("big keys stay exact, long keys still page, and trimmed names stay ordinary", async () => {
      const big = await ok(await query(rt, "bigkeys"), spaceDbRowsPageSchema);
      // Past 2^53 a JSON number would round; the digits travel as a string.
      expect(big.rows).toEqual([
        { id: 5, v: 2 },
        { id: "9007199254740993", v: 1 },
      ]);
      const gone = await ok(
        await call(rt, "POST", "rows/delete", "space_db_rows_delete", {
          table: "bigkeys",
          keys: [{ id: "9007199254740993" }],
        }),
        spaceDbRowsDeletedSchema,
      );
      expect(gone).toEqual({ table: "bigkeys", deleted: 1, missing: [] });

      // A key too long for a cursor continues by position, still in key order.
      const first = await ok(await query(rt, "longkeys", { limit: 1 }), spaceDbRowsPageSchema);
      const second = await ok(
        await query(rt, "longkeys", { limit: 1, cursor: first.cursor }),
        spaceDbRowsPageSchema,
      );
      const third = await ok(
        await query(rt, "longkeys", { limit: 1, cursor: second.cursor }),
        spaceDbRowsPageSchema,
      );
      expect([first, second, third].map((page) => String(page.rows[0]?.k).slice(-1))).toEqual([
        "a",
        "b",
        "c",
      ]);
      expect([first.cursor, second.cursor].map((cursor) => String(cursor).length <= 4096)).toEqual([
        true,
        true,
      ]);
      expect(third.cursor).toBeNull();

      // PHP trims before it classifies; so does the query.
      const tabbed = await ok(
        await query(rt, "wp_usermeta", { where: { meta_value: "tabbed" } }),
        spaceDbRowsPageSchema,
      );
      expect(tabbed.rows.map((row) => row.user_id)).toEqual([3]);
    });

    test("a stored secret name refuses the insert; protected keys and triggers are read-only", async () => {
      // The column default would name the row `auth_key`.
      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: "wp_lockedopts",
            rows: [{ option_value: "x" }],
          }),
        ),
      ).toEqual({ status: 403, code: "space_db_value_protected", pointer: "/rows/0" });
      expect((await ok(await query(rt, "wp_lockedopts"), spaceDbRowsPageSchema)).rows).toEqual([]);
      // A key its column rounds would name no stored row, so neither the
      // receipt nor the stored-name check could find it.
      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: "wp_decopts",
            rows: [{ k: 1.234, option_value: "x" }],
          }),
        ),
      ).toEqual({ status: 422, code: "space_db_key_invalid", pointer: "/rows/0" });
      expect((await ok(await query(rt, "wp_decopts"), spaceDbRowsPageSchema)).rows).toEqual([]);

      // An explicit 0 in an auto-increment column is generated, and the
      // receipt names the stored key.
      const created = await ok(
        await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
          table: "wp_lockedopts",
          rows: [{ id: 0, option_name: "greeting", option_value: "hi" }],
        }),
        spaceDbRowsCreatedSchema,
      );
      const stored = await ok(
        await query(rt, "wp_lockedopts", { where: { id: created.keys[0]?.id ?? 0 } }),
        spaceDbRowsPageSchema,
      );
      expect(stored.rows.map((row) => row.option_name)).toEqual(["greeting"]);

      const { tables } = await ok(
        await call(rt, "GET", "tables", "space_db_tables"),
        spaceDbTablesSchema,
      );
      expect(tables.find((table) => table.name === "wp_vkopts")?.readOnlyReason).toMatch(
        /protected/,
      );
      expect(tables.find((table) => table.name === "audited")?.readOnlyReason).toMatch(/triggers/);
      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: "audited",
            rows: [{ id: 1 }],
          }),
        ),
      ).toMatchObject({ status: 422, code: "space_db_table_not_editable" });
    });

    test("a table with binary option names hides every value and is read-only", async () => {
      const { tables } = await ok(
        await call(rt, "GET", "tables", "space_db_tables"),
        spaceDbTablesSchema,
      );
      expect(tables.find((table) => table.name === "wp_binopts")?.readOnlyReason).toMatch(
        /names as binary/,
      );
      const page = await ok(await query(rt, "wp_binopts"), spaceDbRowsPageSchema);
      expect([page.redactedColumns, page.rows.map((row) => row.option_value)]).toEqual([
        ["option_value"],
        ["[redacted]", "[redacted]"],
      ]);
      expect(
        await problem(await query(rt, "wp_binopts", { where: { option_value: "My Space" } })),
      ).toEqual({ status: 403, code: "space_db_value_protected", pointer: "/where/option_value" });
    });

    test("a table whose triggers the account can't see is read-only", async () => {
      // Triggers are listed only to an account holding TRIGGER on the table,
      // so an empty list from any other account proves nothing.
      mysql.exec(
        [
          "CREATE USER 'sd_rows'@'%' IDENTIFIED BY 'rows-pw'",
          "GRANT SELECT, INSERT, UPDATE, DELETE ON space_db_test.* TO 'sd_rows'@'%'",
          "GRANT TRIGGER ON space_db_test.codes TO 'sd_rows'@'%'",
          "CREATE USER 'sd_pattern'@'%' IDENTIFIED BY 'pattern-pw'",
          "GRANT ALL ON `space\\_db\\_%`.* TO 'sd_pattern'@'%'",
        ].join("; "),
      );
      const connect = async (user: string, password: string) => {
        const url = new URL(mysql.url);
        url.username = user;
        url.password = password;
        const runtime = await startRuntime({
          env: { SPACEFAST_ZERO_DATABASE_URL: url.toString() },
        });
        runtimes.push(runtime);
        const { tables } = await ok(
          await call(runtime, "GET", "tables", "space_db_tables"),
          spaceDbTablesSchema,
        );
        const reasons = new Map(tables.map((table) => [table.name, table.readOnlyReason]));
        return { runtime, reason: (name: string) => reasons.get(name) };
      };

      const limited = await connect("sd_rows", "rows-pw");
      expect(limited.reason("wp_options")).toMatch(/can't see whether this table has triggers/);
      // MySQL hides the trigger from this account; MariaDB shows it. Either
      // way the table is read-only.
      expect(limited.reason("audited")).toMatch(
        flavor === "mysql"
          ? /can't see whether this table has triggers/
          : /^This table has triggers/,
      );
      // A grant on one table lets the account see that table's triggers.
      expect(limited.reason("codes")).toBeNull();
      expect(
        await problem(
          await call(limited.runtime, "POST", "rows/insert", "space_db_rows_insert", {
            table: "audited",
            rows: [{ id: 2 }],
          }),
        ),
      ).toMatchObject({ status: 422, code: "space_db_table_not_editable" });

      // A database grant may name a pattern.
      const pattern = await connect("sd_pattern", "pattern-pw");
      expect(pattern.reason("wp_options")).toBeNull();
      expect(pattern.reason("audited")).toMatch(/^This table has triggers/);

      // Of two grants matching this database the server applies one, so a
      // pattern's TRIGGER counts only when the exact grant holds it too.
      mysql.exec(
        [
          "CREATE USER 'sd_overlap'@'%' IDENTIFIED BY 'overlap-pw'",
          "GRANT SELECT, INSERT, UPDATE, DELETE ON space_db_test.* TO 'sd_overlap'@'%'",
          "GRANT SELECT, INSERT, UPDATE, DELETE, TRIGGER ON `space\\_db\\_%`.* TO 'sd_overlap'@'%'",
        ].join("; "),
      );
      expect((await connect("sd_overlap", "overlap-pw")).reason("wp_options")).toMatch(
        /can't see whether this table has triggers/,
      );

      if (flavor === "mysql") {
        // Partial revokes restrict a global grant out of information_schema's
        // sight, and make a database grant name its database literally.
        mysql.exec(
          [
            "SET GLOBAL partial_revokes = ON",
            "CREATE USER 'sd_partial'@'%' IDENTIFIED BY 'partial-pw'",
            "GRANT SELECT, INSERT, UPDATE, DELETE, TRIGGER ON *.* TO 'sd_partial'@'%'",
            "REVOKE TRIGGER ON space_db_test.* FROM 'sd_partial'@'%'",
            "CREATE USER 'sd_literal'@'%' IDENTIFIED BY 'literal-pw'",
            "GRANT SELECT ON space_db_test.wp_options TO 'sd_literal'@'%'",
            "GRANT TRIGGER ON `space\\_db\\_%`.* TO 'sd_literal'@'%'",
          ].join("; "),
        );
        try {
          expect([
            (await connect("sd_partial", "partial-pw")).reason("wp_options"),
            (await connect("sd_literal", "literal-pw")).reason("wp_options"),
          ]).toEqual([
            expect.stringMatching(/can't see whether this table has triggers/),
            expect.stringMatching(/can't see whether this table has triggers/),
          ]);
        } finally {
          mysql.exec(
            "DROP USER 'sd_partial'@'%', 'sd_literal'@'%'; SET GLOBAL partial_revokes = OFF",
          );
        }
      }
    });

    test("a trigger created while a write waits for the table stops the write", async () => {
      mysql.exec(
        "CREATE TABLE racy (id INT PRIMARY KEY); CREATE TABLE racy_log (id INT) ENGINE=MyISAM",
      );
      const sql = async (statement: string) => {
        const result = await mysql.session(statement);
        if (result.exitCode !== 0) throw new Error(`${statement}: ${result.stderr}`);
        return result.stdout;
      };
      const until = async (statement: string, expected: string) => {
        const deadline = Date.now() + 30_000;
        while ((await sql(statement)) !== expected) {
          if (Date.now() >= deadline) throw new Error(`timed out waiting for ${statement}`);
          await Bun.sleep(10);
        }
      };
      const holding =
        "SELECT ID FROM information_schema.PROCESSLIST WHERE INFO = 'SELECT SLEEP(120)'";
      const waiting =
        "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE = 'Waiting for table metadata lock'";

      // A session reading the table holds it, so creating a trigger on it
      // waits, and the write's own hold on the table queues behind that. The
      // trigger lands after the write's first check and before its rows.
      const holder = mysql.session("BEGIN; SELECT COUNT(*) FROM racy; SELECT SLEEP(120)");
      await until(`SELECT COUNT(*) FROM (${holding}) AS held`, "1");
      const creator = mysql.session(
        "CREATE TRIGGER racy_ai AFTER INSERT ON racy FOR EACH ROW INSERT INTO racy_log VALUES (NEW.id)",
      );
      await until(waiting, "1");
      const insert = call(rt, "POST", "rows/insert", "space_db_rows_insert", {
        table: "racy",
        rows: [{ id: 1 }],
      });
      await until(waiting, "2");
      await sql(`KILL ${await sql(holding)}`);
      await holder;
      expect((await creator).exitCode).toBe(0);

      expect(await problem(await insert)).toMatchObject({
        status: 422,
        code: "space_db_table_not_editable",
      });
      expect(
        mysql.exec("SELECT (SELECT COUNT(*) FROM racy), (SELECT COUNT(*) FROM racy_log)"),
      ).toBe("0\t0");
    });

    test("a binary primary key pages by position and is read-only", async () => {
      // Non-UTF-8 key bytes read back base64-encoded, so they can neither seek
      // a keyset page nor match a row to change.
      const { tables } = await ok(
        await call(rt, "GET", "tables", "space_db_tables"),
        spaceDbTablesSchema,
      );
      expect(tables.find((table) => table.name === "uuid_items")?.readOnlyReason).toMatch(/binary/);
      const first = await ok(await query(rt, "uuid_items", { limit: 2 }), spaceDbRowsPageSchema);
      const rest = await ok(
        await query(rt, "uuid_items", { limit: 2, cursor: first.cursor }),
        spaceDbRowsPageSchema,
      );
      expect([...first.rows, ...rest.rows].map((row) => row.title)).toEqual(["a", "b", "c"]);
      expect(rest.cursor).toBeNull();
      expect(
        await problem(
          await call(rt, "POST", "rows/update", "space_db_rows_update", {
            table: "uuid_items",
            rows: [{ key: { id: String(first.rows[0]?.id) }, set: { title: "x" } }],
          }),
        ),
      ).toMatchObject({ status: 422, code: "space_db_table_not_editable" });
    });

    test("pages by primary key, and by position when a table has none", async () => {
      const first = await ok(await query(rt, D1_TABLE, { limit: 2 }), spaceDbRowsPageSchema);
      expect(first.rows.map((row) => row.title)).toEqual(["one", "two"]);
      expect(first.cursor).toEqual(expect.any(String));
      const second = await ok(
        await query(rt, D1_TABLE, { limit: 2, cursor: first.cursor }),
        spaceDbRowsPageSchema,
      );
      expect(second.rows.map((row) => row.title)).toEqual(["three"]);
      expect(second.cursor).toBeNull();

      const raw = await ok(await query(rt, "notes_raw", { limit: 2 }), spaceDbRowsPageSchema);
      const rest = await ok(
        await query(rt, "notes_raw", { limit: 2, cursor: raw.cursor }),
        spaceDbRowsPageSchema,
      );
      expect([...raw.rows, ...rest.rows].map((row) => row.body).toSorted()).toEqual([
        "a",
        "b",
        "c",
      ]);

      expect(await problem(await query(rt, "wp_options", { cursor: first.cursor }))).toMatchObject({
        status: 422,
        code: "space_db_cursor_invalid",
      });
    });

    test("refuses Spacefast's tables and names a missing one", async () => {
      for (const table of [
        "_spacefast_application_journal",
        D1_LEDGER,
        "wp_sfi_sessions",
        "wp_tp__jobs",
        "wp_tp_identity_accounts",
        `wp_zero_${CONTENT_HASH}_migrations`,
        `${ZERO_V2}zero__outbox`,
        `${OTHER_ZERO_V2}posts`,
        "wp_zero_identity_sessions",
        `${ZERO_V2}zero_identity_accounts`,
        "wp_zero_spacefast_migrations",
        "wp_tp_spacefast_migrations",
      ]) {
        expect({ table, ...(await problem(await query(rt, table))) }).toEqual({
          table,
          status: 403,
          code: "space_db_table_protected",
        });
      }
      expect(await problem(await query(rt, "wp_nothing"))).toMatchObject({
        status: 404,
        code: "space_db_table_not_found",
      });
    });

    test("adds rows in one transaction and returns their keys", async () => {
      const created = await ok(
        await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
          table: D1_TABLE,
          rows: [{ title: "four" }, { title: "five" }],
        }),
        spaceDbRowsCreatedSchema,
      );
      expect(created).toEqual({ table: D1_TABLE, inserted: 2, keys: [{ id: 4 }, { id: 5 }] });

      // The second row collides with the first; neither lands.
      const collision = await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
        table: "wp_options",
        rows: [
          { option_name: "fresh", option_value: "1" },
          { option_name: "blogname", option_value: "2" },
        ],
      });
      const collisionText = await collision.clone().text();
      expect(await problem(collision)).toMatchObject({
        status: 409,
        code: "space_db_duplicate_key",
      });
      // Only the error number travels; MySQL's message quotes stored values.
      expect(collisionText).not.toContain("Duplicate entry");
      expect(collisionText).not.toContain("blogname");
      const fresh = await ok(
        await query(rt, "wp_options", { where: { option_name: "fresh" } }),
        spaceDbRowsPageSchema,
      );
      expect(fresh.rows).toEqual([]);

      const tooLong = await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
        table: D1_TABLE,
        rows: [{ title: "x".repeat(40) }],
      });
      expect(await problem(tooLong)).toMatchObject({ status: 422, code: "space_db_value_invalid" });

      for (const [rows, expected] of [
        [
          [{ option_name: "auth_salt", option_value: "s" }],
          { status: 403, code: "space_db_value_protected" },
        ],
        [[{ nope: 1 }], { status: 422, code: "space_db_column_not_found" }],
      ] as const) {
        expect(
          await problem(
            await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
              table: "wp_options",
              rows,
            }),
          ),
        ).toMatchObject(expected);
      }
      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: D1_TABLE,
            rows: [{ title: "b", doubled: 2 }],
          }),
        ),
      ).toMatchObject({
        status: 422,
        code: "space_db_column_not_editable",
        pointer: "/rows/0/doubled",
      });
      expect(
        await problem(
          await call(rt, "POST", "rows/insert", "space_db_rows_insert", {
            table: "notes_raw",
            rows: [{ body: "d" }],
          }),
        ),
      ).toMatchObject({ status: 422, code: "space_db_table_not_editable" });
    });

    test("updates rows by key and reports the keys that matched nothing", async () => {
      const updated = await ok(
        await call(rt, "POST", "rows/update", "space_db_rows_update", {
          table: D1_TABLE,
          rows: [
            { key: { id: 1 }, set: { title: "uno" } },
            { key: { id: 99 }, set: { title: "ghost" } },
          ],
        }),
        spaceDbRowsUpdatedSchema,
      );
      expect(updated).toEqual({ table: D1_TABLE, updated: 1, missing: [{ id: 99 }] });
      const row = await ok(await query(rt, D1_TABLE, { where: { id: 1 } }), spaceDbRowsPageSchema);
      expect(row.rows[0]?.title).toBe("uno");

      const update = (table: string, rows: Json) =>
        call(rt, "POST", "rows/update", "space_db_rows_update", { table, rows });
      expect(
        await problem(await update("wp_users", [{ key: { ID: 1 }, set: { user_pass: "x" } }])),
      ).toMatchObject({
        status: 403,
        code: "space_db_value_protected",
      });
      expect(
        await problem(
          await update("wp_options", [{ key: { option_id: 2 }, set: { option_value: "x" } }]),
        ),
      ).toMatchObject({
        status: 403,
        code: "space_db_value_protected",
      });
      expect(
        await problem(
          await update("wp_options", [
            { key: { option_id: 1 }, set: { option_name: "nonce_key" } },
          ]),
        ),
      ).toMatchObject({
        status: 403,
        code: "space_db_value_protected",
      });
      expect(
        await problem(await update(D1_TABLE, [{ key: { title: "uno" }, set: { title: "x" } }])),
      ).toMatchObject({
        status: 422,
        code: "space_db_key_invalid",
      });
    });

    test("deletes rows by key, and a secret row in the batch stops the whole call", async () => {
      const deleted = await ok(
        await call(rt, "POST", "rows/delete", "space_db_rows_delete", {
          table: D1_TABLE,
          keys: [{ id: 2 }, { id: 98 }],
        }),
        spaceDbRowsDeletedSchema,
      );
      expect(deleted).toEqual({ table: D1_TABLE, deleted: 1, missing: [{ id: 98 }] });

      const blocked = await call(rt, "POST", "rows/delete", "space_db_rows_delete", {
        table: "wp_options",
        keys: [{ option_id: 4 }, { option_id: 2 }],
      });
      expect(await problem(blocked)).toMatchObject({
        status: 403,
        code: "space_db_value_protected",
        pointer: "/keys/1",
      });
      const siteurl = await ok(
        await query(rt, "wp_options", { where: { option_name: "siteurl" } }),
        spaceDbRowsPageSchema,
      );
      expect(siteurl.rows).toHaveLength(1);
    });
  });
}
