// The Functions relay, end to end, against a real MySQL and a real PHP origin.
//
// This is the one path nothing else covers. `unit.php` proves the token
// verifier refuses what it should. Neither proves that a dispatched worker's
// HTTPS callback actually reaches the in-process database broker, executes
// SQL, and comes back — or that the grant inside the credential is what
// decides whether it may.
//
// So this drives the real engine over HTTP: a real Ed25519 relay token minted
// by the harness key, posted to the real route in serve.php, answered by the
// engine's in-process MySQL broker (db-broker.php; service frames still run
// the real `service-broker` subprocess), against a real MySQL 8.4 container.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";

import {
  deploy,
  get,
  publicAccessConfig,
  putRoute,
  type Runtime,
  RUNTIME_INSTANCE_ID,
  signToken,
  startRuntime,
} from "./harness.ts";
import {
  type MysqlContainer,
  MYSQL_SETUP_TIMEOUT_MS,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

const HOST = "functions-relay.test";
const SPACE_ID = "spc_fx_relay";
const VERSION_ID = "ver_fx_relay_1";
const MYSQL_ROOT_PASSWORD = "fx-relay-secret-pw";
const MYSQL_DATABASE = "fx_relay_test";

let rt: Runtime;
let mysql: MysqlContainer;

// Must match SPACEFAST_RUNTIME_LOG_MARKER in engine/shared/runtime-log.php and
// RUNTIME_LOG_MARKER in the control plane's observability.ts.
const RUNTIME_LOG_MARKER = "sf-log/1 ";
const runtimeLogPath = path.join(
  os.tmpdir(),
  `stattic-relay-log-${Date.now()}-${Math.random().toString(16).slice(2)}.log`,
);

type RuntimeLogRecord = {
  id: string;
  timestamp: string;
  level: string;
  requestId: string | null;
  versionId: string | null;
  handlerName: string | null;
  message: string;
  metadata: Record<string, unknown>;
};

/** Every record the engine has written to PHP's error log so far. */
function runtimeLogRecords(): RuntimeLogRecord[] {
  return readFileSync(runtimeLogPath, "utf8")
    .split("\n")
    .flatMap((line) => {
      const marker = line.indexOf(RUNTIME_LOG_MARKER);
      if (marker < 0) return [];
      try {
        return [JSON.parse(line.slice(marker + RUNTIME_LOG_MARKER.length)) as RuntimeLogRecord];
      } catch {
        return [];
      }
    });
}

async function postLogs(body: unknown, token: string | null): Promise<Response> {
  return await fetch(`${rt.baseUrl}/__spacefast/functions/logs`, {
    method: "POST",
    headers: {
      host: HOST,
      "content-type": "application/json",
      ...(token ? { authorization: `Bearer ${token}` } : {}),
    },
    body: typeof body === "string" ? body : JSON.stringify(body),
  });
}

/** A relay credential exactly as the control plane mints one at finalize. */
function relayToken(overrides: Record<string, unknown> = {}): string {
  return signToken({
    aud: "spacefast-functions-relay",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: SPACE_ID,
    version_id: VERSION_ID,
    capabilities: ["db.read", "db.write"],
    ...overrides,
  });
}

async function relay(
  body: unknown,
  token: string | null,
  broker?: string,
  invocation?: string,
): Promise<Response> {
  return await fetch(`${rt.baseUrl}/__spacefast/functions/relay`, {
    method: "POST",
    headers: {
      host: HOST,
      "content-type": "application/json",
      ...(token ? { authorization: `Bearer ${token}` } : {}),
      // The outbound gateway sets this outside the isolate. Tenant code cannot
      // forge it, so a test that sets it is standing in for the gateway.
      ...(broker ? { "sf-fx-broker": broker } : {}),
      ...(invocation ? { "sf-fx-invocation": invocation } : {}),
    },
    body: typeof body === "string" ? body : JSON.stringify(body),
  });
}

beforeAll(async () => {
  mysql = await startMysqlContainer({
    namePrefix: "sf-fx-relay",
    database: MYSQL_DATABASE,
    rootPassword: MYSQL_ROOT_PASSWORD,
  });
  mysql.exec("CREATE TABLE notes (id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(255) NOT NULL);");

  rt = await startRuntime({
    // The log intake's whole purpose is that a record reaches PHP's error log,
    // because that is the only stream the provider ships off the box. Pointing
    // it at a file is what lets the test read back what a real deployment
    // would only see through the provider's log API.
    phpIni: { log_errors: "1", error_log: runtimeLogPath },
    env: {
      SPACEFAST_ZERO_DATABASE_URL: mysql.url,
      SPACEFAST_ZERO_DATABASE_URL_SOURCE: "provider",
      // Management state, supplied by the control plane per space. Without it
      // the broker refuses mail rather than queueing what it cannot send.
      SPACEFAST_SERVICE_EMAIL_SENDERS: "hello@example.com",
    },
  });
  await deploy(rt, {
    spaceId: SPACE_ID,
    versionId: VERSION_ID,
    metadata: { mode: "website", title: "Functions relay" },
    files: { "index.html": "<h1>static</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "Functions relay" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
}, MYSQL_SETUP_TIMEOUT_MS + 120_000);

afterAll(async () => {
  await rt?.stop?.();
  stopMysqlContainers();
});

test("a granted credential brokers real SQL against real MySQL", async () => {
  const write = await relay(
    { mode: "execute", sql: "INSERT INTO notes (body) VALUES (?)", params: ["from-the-relay"] },
    relayToken(),
  );
  // Body in the failure message: a bare status tells you it refused, not which
  // check refused it, and every refusal here is deliberately the same status.
  const writeText = await write.text();
  expect(`${write.status} ${writeText}`).toStartWith("200");
  const writeBody = JSON.parse(writeText) as { ok: boolean; affectedRows?: number };
  expect(writeBody.ok, writeText).toBe(true);

  // Read it back out of band: the assertion is that the row exists in MySQL,
  // not that the broker said it did.
  expect(mysql.exec("SELECT body FROM notes;")).toContain("from-the-relay");

  const read = await relay({ mode: "query", sql: "SELECT body FROM notes" }, relayToken());
  expect(read.status).toBe(200);
  const readBody = (await read.json()) as { ok: boolean; rows?: unknown[] };
  expect(readBody.ok).toBe(true);
  expect(JSON.stringify(readBody.rows)).toContain("from-the-relay");
});

test("a credential claiming only edge-side capabilities gets no relay authority", async () => {
  // The Next cache lives with the worker on the edge; a credential that names
  // it (or any other non-database capability) holds nothing the relay serves.
  const response = await relay(
    { mode: "query", sql: "SELECT 1" },
    relayToken({ capabilities: ["next.cache", "fetch", "log"] }),
  );
  expect(response.status).toBe(403);
  expect((await response.json()) as { code?: string }).toMatchObject({ code: "relay_forbidden" });
});

test("the relay refuses a caller with no credential at all", async () => {
  const response = await relay({ mode: "query", sql: "SELECT 1" }, null);
  expect(response.status).toBe(401);
  const body = (await response.json()) as { code?: string };
  expect(body.code).toBe("relay_unauthorized");
});

test("every scope claim in the credential is enforced by the live route", async () => {
  // unit.php proves the verifier refuses these. This proves the route actually
  // calls the verifier — a wiring mistake would make all of that decorative.
  const tampered: Array<[string, Record<string, unknown>]> = [
    ["another space", { space_id: "spc_other" }],
    ["another runtime", { runtime_instance_id: "rti_other" }],
    ["a version this origin never had", { version_id: "ver_missing" }],
    ["the bundle audience", { aud: "spacefast-functions-bundle" }],
  ];
  for (const [name, override] of tampered) {
    const response = await relay({ mode: "query", sql: "SELECT 1" }, relayToken(override));
    expect(response.status, name).toBe(401);
    const body = (await response.json()) as { code?: string; rows?: unknown };
    // The refusal must be the verifier's, and it must have stopped before the
    // database: a scoped-out caller never learns whether the query would work.
    expect(body.code, name).toBe("relay_unauthorized");
    expect(body.rows, name).toBeUndefined();
  }
});

test("a credential signed by a key that is not the runtime's is refused", async () => {
  // Without this the other assertions are advisory: they would also pass if the
  // route never checked the signature.
  const forged = signToken(
    {
      aud: "spacefast-functions-relay",
      runtime_instance_id: RUNTIME_INSTANCE_ID,
      space_id: SPACE_ID,
      version_id: VERSION_ID,
      capabilities: ["db.read", "db.write"],
    },
    { rogueKey: true },
  );
  const response = await relay({ mode: "query", sql: "SELECT 1" }, forged);
  expect(response.status).toBe(401);
});

test("a Functions grant writes and reads the space storage without a Zero capsule", async () => {
  const token = relayToken({ capabilities: ["storage.read", "storage.write"] });
  const uploaded = await fetch(`${rt.baseUrl}/storage`, {
    method: "POST",
    headers: {
      host: HOST,
      authorization: `Bearer ${token}`,
      "content-type": "text/plain",
      "sf-fx-broker": "storage",
    },
    body: "shared storage bytes",
  });
  const uploadText = await uploaded.text();
  expect(`${uploaded.status} ${uploadText}`).toStartWith("201");
  const object = JSON.parse(uploadText) as { id: string; url: string };
  expect(object.id).toMatch(/^[a-f0-9]{32}$/);

  const stored = await fetch(`${rt.baseUrl}/storage/${object.id}`, {
    headers: {
      host: HOST,
      authorization: `Bearer ${token}`,
      "sf-fx-broker": "storage",
    },
  });
  expect(`${stored.status} ${await stored.text()}`).toBe("200 shared storage bytes");

  // The keyed visitor URL resolves the record the Function just wrote. There
  // is one record store and one CAS, not a Functions facade over capsule data.
  const objectUrl = new URL(object.url);
  const visitor = await get(rt, HOST, `${objectUrl.pathname}${objectUrl.search}`);
  expect(`${visitor.status} ${await visitor.text()}`).toBe("200 shared storage bytes");
});

test("a credential granting nothing reaches no database at all", async () => {
  const response = await relay(
    { mode: "query", sql: "SELECT 1" },
    relayToken({ capabilities: [] }),
  );
  expect(response.status).toBe(403);
  const body = (await response.json()) as { code?: string };
  expect(body.code).toBe("relay_forbidden");
});

test("a read-only grant cannot write, and the write does not land", async () => {
  // The grant travels inside the signed credential, so this is the property the
  // whole design rests on: the broker never looks up what a caller may do.
  const token = relayToken({ capabilities: ["db.read"] });
  const read = await relay({ mode: "query", sql: "SELECT 1 AS permitted" }, token);
  expect(read.status).toBe(200);
  expect((await read.json()) as { ok?: boolean }).toMatchObject({ ok: true });

  const before = mysql.exec("SELECT COUNT(*) FROM notes;");
  const response = await relay(
    { mode: "query", sql: "INSERT INTO notes (body) VALUES (?)", params: ["should-not-land"] },
    token,
  );
  const body = (await response.json()) as { ok?: boolean; code?: string };
  expect(body.ok).not.toBe(true);
  expect(body.code).toBe("zero_db_capability_denied");
  expect(mysql.exec("SELECT COUNT(*) FROM notes;")).toBe(before);
  expect(mysql.exec("SELECT body FROM notes;")).not.toContain("should-not-land");
});

test("a burst of relay operations does not open a connection per operation", async () => {
  // The relay is the one lane where a handler's Kth query used to cost a whole
  // process and a fresh handshake, so what a burst costs the database is a
  // property worth pinning rather than inferring. The broker's persistent link
  // outlives the request in PHP's own pool; one warm-up first, so the burst is
  // measured against a link that already exists. `mysql.exec` opens one
  // connection of its own per probe — the threshold accounts for it.
  const warmup = await relay({ mode: "query", sql: "SELECT 1" }, relayToken());
  expect(warmup.status).toBe(200);
  const accepted = () =>
    Number(mysql.exec("SHOW GLOBAL STATUS LIKE 'Connections';").split("\t")[1]);
  const before = accepted();
  for (let index = 0; index < 8; index++) {
    const response = await relay({ mode: "query", sql: "SELECT 1 AS budget" }, relayToken());
    expect(response.status).toBe(200);
    expect((await response.json()) as { ok?: boolean }).toMatchObject({ ok: true });
  }
  expect(accepted() - before).toBeLessThanOrEqual(4);
});

test("the named broker decides which executor runs, and the grant is narrowed to it", async () => {
  // Two brokers share one authenticated route. A credential holding database
  // capabilities must not reach the service executor by naming it, and a
  // credential holding service capabilities must not reach the database one.
  const dbOnly = relayToken({ capabilities: ["db.read", "db.write"] });
  const serviceOnly = relayToken({ capabilities: ["spam.check"] });

  const crossed = await relay(
    { service: "spam", operation: "check", payload: {} },
    dbOnly,
    "services",
  );
  expect(crossed.status).toBe(403);
  expect((await crossed.json()) as { code?: string }).toMatchObject({ code: "relay_forbidden" });

  const alsoCrossed = await relay({ mode: "query", sql: "SELECT 1" }, serviceOnly, "database");
  expect(alsoCrossed.status).toBe(403);
  expect((await alsoCrossed.json()) as { code?: string }).toMatchObject({
    code: "relay_forbidden",
  });

  // And the matching pair reaches its executor: the service broker answers in
  // band, which only the real `service-broker` subprocess produces.
  const matched = await relay(
    { service: "spam", operation: "check", payload: { content: "x", userIp: "203.0.113.9" } },
    serviceOnly,
    "services",
  );
  const matchedText = await matched.text();
  expect(`${matched.status} ${matchedText}`).toStartWith("200");
  const matchedBody = JSON.parse(matchedText) as { ok: boolean; code?: string };
  expect(matchedBody.ok).toBe(false);
  // No Akismet key is configured for this runtime, so the executor refuses on
  // configuration — which is the proof it ran and reached no network.
  expect(matchedBody.code).toBe("service_not_configured");
});

test("an unrecognised broker is refused rather than defaulted", async () => {
  const response = await relay(
    { service: "spam", operation: "check", payload: {} },
    relayToken({ capabilities: ["spam.check"] }),
    "smtp",
  );
  expect(response.status).toBe(403);
  expect((await response.json()) as { code?: string }).toMatchObject({ code: "relay_forbidden" });
});

test("a service frame naming an ungranted service is refused inside the broker", async () => {
  // The relay narrowed the grant to services and let this through; the refusal
  // below comes from the executor itself, which is the second gate the design
  // depends on.
  const response = await relay(
    { service: "email", operation: "send", payload: {} },
    relayToken({ capabilities: ["spam.check"] }),
    "services",
  );
  const text = await response.text();
  expect(`${response.status} ${text}`).toStartWith("200");
  expect(JSON.parse(text) as { ok: boolean; code?: string }).toMatchObject({
    ok: false,
    code: "service_capability_denied",
  });
});

test("an accepted email is a row in the outbox, written once per invocation", async () => {
  const token = relayToken({ capabilities: ["email.send"] });
  const message = {
    service: "email",
    operation: "send",
    payload: {
      from: { email: "hello@example.com" },
      to: [{ email: "someone@example.com" }],
      subject: "Hi",
      text: "Body",
    },
  };

  const first = await relay(message, token, "services", "inv_outbox_1");
  const firstText = await first.text();
  expect(`${first.status} ${firstText}`).toStartWith("200");
  const accepted = JSON.parse(firstText) as { ok: boolean; result?: { messageId?: string } };
  expect(accepted.ok, firstText).toBe(true);
  const messageId = accepted.result?.messageId ?? "";
  expect(messageId).toStartWith("msg_");

  // The assertion is that the row is in MySQL, not that the broker said so.
  // The table is created by the broker itself on first use.
  const rows = mysql.exec("SELECT message_id, state, invocation_id FROM _spacefast_email_outbox;");
  expect(rows).toContain(messageId);
  expect(rows).toContain("queued");
  expect(rows).toContain("inv_outbox_1");
  // Recipients and body live in the payload column and nowhere else.
  expect(rows).not.toContain("someone@example.com");

  // The same invocation replayed is the same message, not a second send. This
  // is the property the deterministic id and the unique key exist for.
  const replay = await relay(message, token, "services", "inv_outbox_1");
  const replayed = (await replay.json()) as { ok: boolean; result?: { messageId?: string } };
  expect(replayed.result?.messageId).toBe(messageId);
  expect(mysql.exec("SELECT COUNT(*) FROM _spacefast_email_outbox;")).toContain("1");
});

test("mail from an address the space has not verified is refused, and no row is written", async () => {
  const before = mysql.exec("SELECT COUNT(*) FROM _spacefast_email_outbox;");
  const response = await relay(
    {
      service: "email",
      operation: "send",
      payload: {
        from: { email: "someone-else@example.com" },
        to: [{ email: "victim@example.com" }],
        subject: "Hi",
        text: "Body",
      },
    },
    relayToken({ capabilities: ["email.send"] }),
    "services",
    "inv_outbox_2",
  );

  expect(JSON.parse(await response.text()) as { ok: boolean; code?: string }).toMatchObject({
    ok: false,
    code: "email_sender_unverified",
  });
  expect(mysql.exec("SELECT COUNT(*) FROM _spacefast_email_outbox;")).toBe(before);
});

// The hop the one-log-system ruling rests on: a Cloudflare worker has no route
// to the control plane, so its tail events come back to the origin that
// dispatched it, and the origin has to put them somewhere the provider ships.
// `unit.php` proves the writer normalises a record correctly; only this proves
// a real HTTPS delivery on a real relay credential reaches PHP's error log.
test("a worker's tail delivery lands in the PHP error log, under the credential's version", async () => {
  const response = await postLogs(
    [
      {
        level: "warn",
        message: "checkout failed",
        requestId: "req_relay_1",
        handlerName: "POST /api/checkout",
        metadata: { attempt: 2 },
        // A worker naming someone else's version must not be able to file
        // output under it: the credential decides, never the record.
        versionId: "ver_someone_else",
      },
      { level: "log", message: "second line in the same delivery" },
    ],
    relayToken(),
  );
  expect(response.status).toBe(202);

  const written = runtimeLogRecords().filter((record) => record.requestId === "req_relay_1");
  expect(written).toHaveLength(1);
  const [entry] = written;
  expect(entry?.message).toBe("checkout failed");
  expect(entry?.level).toBe("warn");
  expect(entry?.handlerName).toBe("POST /api/checkout");
  expect(entry?.metadata).toEqual({ attempt: 2 });
  expect(entry?.versionId).toBe(VERSION_ID);

  // Both records in one delivery are written, and `log` resolves to the
  // contract's `info` rather than being dropped as an unknown level.
  const second = runtimeLogRecords().find(
    (record) => record.message === "second line in the same delivery",
  );
  expect(second?.level).toBe("info");
});

test("an unauthenticated log delivery is refused and writes nothing", async () => {
  const before = runtimeLogRecords().length;
  const response = await postLogs([{ level: "error", message: "smuggled" }], null);
  expect(response.status).toBe(401);
  expect(runtimeLogRecords()).toHaveLength(before);
});

test("the relay is not reachable as static content", async () => {
  // It lives under the control prefix, which is private by default at the front
  // door. A GET must not resolve as a file, and must not leak that it exists.
  const response = await fetch(`${rt.baseUrl}/__spacefast/functions/relay`, {
    method: "GET",
    headers: { host: HOST },
  });
  expect([401, 404, 405]).toContain(response.status);
});

// The outbound half of Functions: the origin dispatches to an execution edge
// and is DECIDER ONLY — it never runs the worker itself and never falls back to
// the control plane. The compile step is the sole decider of what is a function
// path (a version carries a functions config or it does not); serving only
// forwards or refuses. These tests use the same runtime env knobs the platform
// sets: SPACEFAST_FUNCTIONS_DISPATCH_TOKEN is the origin's dispatch credential,
// and the edge host is whatever `host.hostname` the compiled config names.

/** A functions config exactly as the control plane finalizes one for a version. */
function functionsFinalize(hostname: string): Record<string, unknown> {
  return {
    artifact: {
      appName: "fx-dispatch",
      entry: "worker.js",
      mainModule: "index.js",
      compatibilityDate: "2026-07-01",
      compatibilityFlags: [],
      routes: [{ method: null, path: "/", subtree: true }],
    },
    host: { hostname, bundleUrl: "https://bundle.invalid/bundle.json" },
    grantedCapabilities: [],
  };
}

test("a function path with no reachable edge answers 503, and the origin never runs the worker", async () => {
  // `rt` carries no SPACEFAST_FUNCTIONS_DISPATCH_TOKEN, so there is no edge to
  // reach — the platform-wide rollback state.
  const host = "fx-noedge.test";
  await deploy(rt, {
    spaceId: "spc_fx_noedge",
    versionId: "ver_fx_noedge_1",
    metadata: { mode: "website", title: "no edge" },
    files: { "index.html": "<h1>static shell</h1>\n" },
    functions: functionsFinalize("functions.invalid"),
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "no edge" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });

  // A path resolving to no static file is a function path. With no edge it must
  // answer 503 under one code — never the worker's output (the origin does not
  // execute it, and there is no control-plane fallback) and never a static 200.
  const dispatched = await get(rt, host, "/api/hello", {
    headers: { accept: "application/json" },
  });
  expect(dispatched.status).toBe(503);
  const problem = (await dispatched.json()) as { code?: string };
  expect(problem.code).toBe("functions_edge_unconfigured");

  // An asset-looking extension on a routed path is still a function path: the
  // compiled route decides, not the terminal static-404 gate.
  const dispatchedJson = await get(rt, host, "/api/data.json", {
    headers: { accept: "application/json" },
  });
  expect(dispatchedJson.status).toBe(503);
  expect(((await dispatchedJson.json()) as { code?: string }).code).toBe(
    "functions_edge_unconfigured",
  );

  // Everything that is not a function path keeps working: the published static
  // shell still serves, so the 503 is scoped to dispatch, not the whole site.
  const shell = await get(rt, host, "/");
  expect(shell.status).toBe(200);
  expect(await shell.text()).toBe("<h1>static shell</h1>\n");
});

test("a version with no compiled functions never enters dispatch: an app route is a plain 404", async () => {
  // The down-ramp shape a disabled or hostless finalize produces: no functions
  // config at all. The bundle bytes may still be published as ordinary files,
  // but with no config no function route is compiled, so serving never reaches
  // dispatch — an unresolved path is an ordinary static miss, not a 503.
  const host = "fx-static-only.test";
  await deploy(rt, {
    spaceId: "spc_fx_static_only",
    versionId: "ver_fx_static_only_1",
    metadata: { mode: "website", title: "static only" },
    files: { "index.html": "<h1>static</h1>\n" },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "static only" }),
      production_hostnames: [host],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  const miss = await get(rt, host, "/api/hello");
  expect(miss.status).toBe(404);
});

test("a reachable edge receives the dispatch: the origin forwards to exactly the host the config names", async () => {
  // A local listener stands in for the edge origin. It refuses at the transport
  // layer, so the assertion is that the dispatch reached OUT to the configured
  // host at all — the origin forwarded rather than executing the worker locally
  // or serving static. The recorded connection is proof the target was exactly
  // `host.hostname` from the compiled config, so swapping the edge system later
  // is a config change, not a serving change.
  const connections: string[] = [];
  const edge = net.createServer((socket) => {
    connections.push(socket.remoteAddress ?? "");
    socket.destroy();
  });
  const edgePort = await new Promise<number>((resolve, reject) => {
    edge.once("error", reject);
    edge.listen(0, "127.0.0.1", () => {
      const address = edge.address();
      resolve(typeof address === "object" && address ? address.port : 0);
    });
  });

  const edgeRt = await startRuntime({
    env: { SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fx-dispatch-secret" },
  });
  try {
    const host = "fx-edge.test";
    await deploy(edgeRt, {
      spaceId: "spc_fx_edge",
      versionId: "ver_fx_edge_1",
      metadata: { mode: "website", title: "edge" },
      files: { "index.html": "<h1>static shell</h1>\n" },
      functions: functionsFinalize(`127.0.0.1:${edgePort}`),
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website", site_title: "edge" }),
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const dispatched = await get(edgeRt, host, "/api/hello");
    // The edge refused mid-handshake, so dispatch reports 502 — but 502 is only
    // reachable by having forwarded. A local execution or a static fallthrough
    // would never have opened a connection to the edge host at all.
    expect(dispatched.status).toBe(502);
    expect(connections.length).toBeGreaterThan(0);

    // Static content on the same version still serves: dispatch owns only the
    // paths that resolve to no file.
    const shell = await get(edgeRt, host, "/");
    expect(shell.status).toBe(200);
  } finally {
    edgeRt.stop();
    await new Promise<void>((resolve) => edge.close(() => resolve()));
  }
});

test("visitor access policy cannot intercept a Functions machine callback", async () => {
  await putRoute(rt, SPACE_ID, "production", {
    version_id: VERSION_ID,
    config: {
      policy: {
        rules: [
          {
            id: "deny-every-visitor",
            match: { pathPattern: "/**" },
            effect: "deny",
            reasonCode: "visitor-denied",
          },
        ],
      },
    },
  });
  try {
    const response = await relay(
      { mode: "query", sql: "SELECT 1" },
      relayToken({ capabilities: [] }),
    );
    expect(response.status).toBe(403);
    const body = (await response.json()) as { code?: string };
    expect(body.code).toBe("relay_forbidden");
  } finally {
    await putRoute(rt, SPACE_ID, "production", {
      version_id: VERSION_ID,
      config: { policy: null },
    });
  }
});
