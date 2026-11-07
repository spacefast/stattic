// Client runtime request building, against an in-process node:http server with canned responses.
// The runtime is copied next to a fixture schema.ts/types.ts in the exact layout `zero build` emits
// (zero/client/{index,schema,types}.ts), then imported for real.
//
// Run: node --test runtime/client/index.test.ts   (Node 22 strips types)

import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { createServer, type IncomingMessage, type Server } from "node:http";
import { copyFileSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { createHash } from "node:crypto";

const SCHEMA_TS = `export const SCHEMA = {
  name: "blog",
  collections: {
    posts: { path: "posts", primaryKey: "id", drafts: true, revisions: true,
      relations: { author: { kind: "one", collection: "authors", optional: true } }, media: ["cover"], mediaList: [], files: ["deck"] },
    authors: { path: "authors", primaryKey: "id", drafts: true, revisions: true, relations: {}, media: [], mediaList: [], files: [] },
    shoppingList: { path: "shopping_list", primaryKey: "id", drafts: false, revisions: false, relations: {}, media: [], mediaList: [], files: [] },
  },
  globals: ["settings"],
  actions: ["approveComment", "publishedCount"],
  buckets: ["decks"],
} as const;
`;
const TYPES_TS = `export interface User { id: number; name: string; role: string }
export type Media = Record<string, unknown>; export type PrivateFile = Record<string, unknown>; export type RevisionSummary = unknown; export type Status = string; export type Locale = string;
export type Collections = Record<string, any>; export type Globals = Record<string, any>; export type Actions = Record<string, any>;
`;

interface Seen { method: string; path: string; query: URLSearchParams; headers: IncomingMessage["headers"]; body: Buffer }
let seen: Seen[] = [];
let reply: (s: Seen) => { status: number; type?: string; body: string } = () => ({ status: 200, body: '{"data":null}' });
let server: Server;
let origin = "";
let url = "";
const dirs: string[] = [];
// The runtime's public surface, loosely typed: the test drives it the way generated types would.
let mod: any;

async function load(schemaTs: string): Promise<unknown> {
  const dir = mkdtempSync(join(tmpdir(), "zero-client-"));
  dirs.push(dir);
  copyFileSync(join(dirname(fileURLToPath(import.meta.url)), "index.ts"), join(dir, "index.ts"));
  writeFileSync(join(dir, "schema.ts"), schemaTs);
  writeFileSync(join(dir, "types.ts"), TYPES_TS);
  return import(pathToFileURL(join(dir, "index.ts")).href);
}

before(async () => {
  mod = await load(SCHEMA_TS);

  server = createServer(async (req, res) => {
    const chunks: Buffer[] = [];
    for await (const c of req) chunks.push(c as Buffer);
    const u = new URL(req.url ?? "/", "http://x");
    const s: Seen = { method: req.method ?? "", path: u.pathname, query: u.searchParams, headers: req.headers, body: Buffer.concat(chunks) };
    seen.push(s);
    const r = reply(s);
    res.writeHead(r.status, { "content-type": r.type ?? "application/json" }).end(r.body);
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  origin = `http://127.0.0.1:${(server.address() as { port: number }).port}`;
  url = `${origin}/wp-json/zero/v1`;
});

after(() => {
  server.close();
  for (const dir of dirs) rmSync(dir, { recursive: true, force: true });
});

const ok = (data: unknown) => () => ({ status: 200, body: JSON.stringify({ data }) });
const last = () => seen[seen.length - 1];
const problem = (status: number, code: string) => ({
  status,
  type: "application/problem+json",
  body: JSON.stringify({ type: "about:blank", title: code, status, code, detail: code }),
});
const tokens = (n: number, user: unknown, extra: Record<string, unknown> = {}) =>
  ok({ accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 900, user, ...extra })();
/** The issuer's token response (`<issuer>/token`): a rotated pair. */
const rotated = (n: number) => ({ status: 200, body: JSON.stringify({ access_token: `za_${n}`, refresh_token: `zr_${n}`, expires_in: 900 }) });
/** The issuer refusing a grant (`{ error }`). */
const refused = (error: string) => ({ status: 401, body: JSON.stringify({ error, error_description: error }) });
const guest = { id: 7, name: "Guest", role: "guest" };
/** Each request as one line; form and JSON bodies verbatim. */
const lines = () => seen.map((s) => `${s.method} ${s.path} ${s.headers.authorization ?? "-"} ${s.body.toString()}`.trim());
const form = (s: Seen) => Object.fromEntries(new URLSearchParams(s.body.toString()));

/**
 * Minimal browser globals for redirect sign-in: `sessionStorage` over a Map and a `location` whose
 * `assign` resolves `navigated`. Restored by `restore()`.
 */
function stubBrowser(search = "") {
  const saved = (["sessionStorage", "location"] as const).map((k) => [k, Object.getOwnPropertyDescriptor(globalThis, k)] as const);
  const store = new Map<string, string>();
  let navigate: (to: string) => void = () => {};
  const navigated = new Promise<string>((resolve) => (navigate = resolve));
  const visits: string[] = [];
  Object.defineProperty(globalThis, "sessionStorage", {
    configurable: true,
    value: { getItem: (k: string) => store.get(k) ?? null, setItem: (k: string, v: string) => void store.set(k, v), removeItem: (k: string) => void store.delete(k) },
  });
  Object.defineProperty(globalThis, "location", { configurable: true, value: { search, href: `https://blog.test/callback${search}`, assign: (to: string) => (visits.push(to), navigate(to)) } });
  return {
    store,
    navigated,
    visits,
    restore() {
      for (const [k, d] of saved) d ? Object.defineProperty(globalThis, k, d) : Reflect.deleteProperty(globalThis, k);
    },
  };
}

const sha256url = (v: string) => createHash("sha256").update(v).digest("base64url");

test("list encodes where/orderBy/limit/offset/status/locale; with is a comma list or JSON", async () => {
  seen = [];
  reply = ok([{ id: 1 }]);
  const zero = mod.createClient({ url: url + "/" });
  const rows = await zero.posts.list({
    where: { tags: { has: "news" }, OR: [{ title: { like: "%a&b%" } }] },
    orderBy: { createdAt: "desc", title: "asc" },
    limit: 10,
    offset: 20,
    status: "draft",
    locale: "de",
    with: { author: true, cover: true },
  });
  assert.deepEqual(rows, [{ id: 1 }]);
  assert.equal(last().query.get("locale"), "de");
  const q = last().query;
  assert.equal(last().method, "GET");
  assert.equal(last().path, "/wp-json/zero/v1/posts");
  assert.deepEqual(JSON.parse(q.get("where") ?? ""), { tags: { has: "news" }, OR: [{ title: { like: "%a&b%" } }] });
  assert.deepEqual([q.get("orderBy"), q.get("limit"), q.get("offset"), q.get("status"), q.get("with")], ["-createdAt,title", "10", "20", "draft", "author,cover"]);

  // Any entry with options switches `with` to JSON; nested orderBy strings travel as objects.
  await zero.posts.get(7, { with: { author: { with: { posts: true }, orderBy: "-id", limit: 2 } }, locale: "*" });
  assert.equal(last().path, "/wp-json/zero/v1/posts/7");
  assert.equal(last().query.get("locale"), "*");
  assert.deepEqual(JSON.parse(last().query.get("with") ?? ""), { author: { with: { posts: true }, orderBy: { id: "desc" }, limit: 2 } });

  // The collection key is not the path: shoppingList lives at /shopping_list.
  await zero.shoppingList.list();
  assert.equal(last().path, "/wp-json/zero/v1/shopping_list");
  assert.equal(last().query.toString(), "");
});

test("writes, revisions, globals, actions and auth hit the §4.8 routes with JSON bodies", async () => {
  seen = [];
  reply = ok({ id: 1 });
  const zero = mod.createClient({ url });
  await zero.posts.create({ title: "Hi" });
  await zero.posts.update(1, { title: "Yo" });
  await zero.posts.delete(1);
  await zero.posts.revisions.list(1);
  await zero.posts.revisions.get(1, 4);
  await zero.posts.revisions.restore(1, 4);
  await zero.globals.settings.get();
  await zero.globals.settings.update({ tagline: "x" });
  await zero.actions.approveComment({ id: 3 });
  await zero.actions.publishedCount();
  await zero.auth.me();
  await zero.schema();
  assert.deepEqual(
    seen.map((s) => `${s.method} ${s.path} ${s.body.toString()}`),
    [
      'POST /wp-json/zero/v1/posts {"title":"Hi"}',
      'PATCH /wp-json/zero/v1/posts/1 {"title":"Yo"}',
      "DELETE /wp-json/zero/v1/posts/1 ",
      "GET /wp-json/zero/v1/posts/1/revisions ",
      "GET /wp-json/zero/v1/posts/1/revisions/4 ",
      "POST /wp-json/zero/v1/posts/1/revisions/4/restore ",
      "GET /wp-json/zero/v1/globals/settings ",
      'PATCH /wp-json/zero/v1/globals/settings {"tagline":"x"}',
      'POST /wp-json/zero/v1/actions/approveComment {"id":3}',
      "POST /wp-json/zero/v1/actions/publishedCount {}",
      "GET /wp-json/zero/v1/me ",
      "GET /wp-json/zero/v1/_schema ",
    ],
  );
  assert.equal(seen[0].headers["content-type"], "application/json");
});

test("auth headers: anonymous sends none, a guest's bearer is held, WP nonce, custom headers win", async () => {
  seen = [];
  reply = (s) => (s.path === "/wp-json/zero/v1/auth/guest" ? tokens(1, guest) : ok(null)());

  const anon = mod.createClient({ url });
  await anon.auth.me();
  assert.deepEqual([last().headers.authorization, last().headers["x-wp-nonce"]], [undefined, undefined]);
  assert.deepEqual(await anon.auth.guest(), guest);
  await anon.auth.me();
  assert.equal(last().headers.authorization, "Bearer za_1");
  assert.deepEqual({ ...anon.auth.session(), expiresAt: typeof anon.auth.session()?.expiresAt }, { accessToken: "za_1", refreshToken: "zr_1", expiresAt: "number" });

  // WP cookie auth: nonce header + same-origin credentials (observed through a fetch wrapper).
  let credentials: RequestCredentials | undefined;
  const wp = mod.createClient({
    url,
    nonce: "n0nce",
    fetch: (input: RequestInfo | URL, init?: RequestInit) => ((credentials = init?.credentials), fetch(input, init)),
  });
  await wp.auth.me();
  assert.deepEqual([last().headers["x-wp-nonce"], last().headers.authorization, credentials], ["n0nce", undefined, "same-origin"]);

  // WP application passwords travel as a caller-supplied header and override a held session.
  const basic = "Basic " + btoa("admin:xxxx yyyy");
  await mod.createClient({ url, headers: { Authorization: basic } }).auth.me();
  assert.equal(last().headers.authorization, basic);
  await anon.auth.guest().catch(() => undefined);
  const held = mod.createClient({ url, headers: { Authorization: basic } });
  reply = (s) => (s.path === "/wp-json/zero/v1/auth/guest" ? tokens(2, guest) : ok(null)());
  await held.auth.guest();
  await held.auth.me();
  assert.equal(last().headers.authorization, basic);
});

test("problem documents become ZeroError with every field; non-problem failures still map", async () => {
  const zero = mod.createClient({ url });
  const problem = { type: "about:blank", title: "Invalid input", status: 422, code: "invalid_input", detail: "Too long", pointer: "/sections/1/heading" };
  reply = () => ({ status: 422, type: "application/problem+json", body: JSON.stringify(problem) });
  const err = await zero.posts.create({ title: "x" }).catch((e: unknown) => e);
  assert.ok(err instanceof mod.ZeroError);
  assert.ok(err instanceof Error);
  assert.deepEqual(
    { type: err.type, title: err.title, status: err.status, code: err.code, detail: err.detail, pointer: err.pointer, message: err.message },
    { ...problem, message: "Too long" },
  );

  // No pointer in the document → no pointer on the error.
  reply = () => ({ status: 403, type: "application/problem+json", body: JSON.stringify({ type: "about:blank", title: "Forbidden", status: 403, code: "forbidden", detail: "no" }) });
  const forbidden = await zero.actions.approveComment({ id: 1 }).catch((e: unknown) => e);
  assert.deepEqual([forbidden.status, forbidden.code, forbidden.pointer], [403, "forbidden", undefined]);

  // A WordPress core error body (before Zero's route runs) maps onto the same shape.
  reply = () => ({ status: 401, body: JSON.stringify({ code: "rest_cookie_invalid_nonce", message: "Cookie check failed", data: { status: 403 } }) });
  const wpErr = await zero.auth.me().catch((e: unknown) => e);
  assert.deepEqual([wpErr.status, wpErr.code, wpErr.detail], [401, "rest_cookie_invalid_nonce", "Cookie check failed"]);

  // HTML from a proxy, and an unreachable server: status 0, code "network".
  reply = () => ({ status: 502, type: "text/html", body: "<h1>Bad gateway</h1>" });
  const html = await zero.posts.list().catch((e: unknown) => e);
  assert.deepEqual([html.status, html.code], [0, "network"]);
  const down = await mod.createClient({ url: "http://127.0.0.1:1/wp-json/zero/v1" }).posts.list().catch((e: unknown) => e);
  assert.deepEqual([down instanceof mod.ZeroError, down.status, down.code], [true, 0, "network"]);
});

test("media upload is multipart with `file` (+ filename, type) and `alt`", async () => {
  seen = [];
  const media = { id: 9, url: "/uploads/2026/10/a.png", mime: "image/png", size: 3, width: 1, height: 1, alt: "A pixel", filename: "pixel.png", createdAt: "2026-10-28T00:00:00.000Z" };
  reply = () => ({ status: 201, body: JSON.stringify({ data: media }) });
  const zero = mod.createClient({ url, headers: { Authorization: "Bearer zt_abc" } });
  const bytes = new Uint8Array([137, 80, 78]);
  assert.deepEqual(await zero.media.upload(new Blob([bytes], { type: "image/png" }), { alt: "A pixel", filename: "pixel.png" }), media);

  const s = last();
  assert.deepEqual([s.method, s.path, s.headers.authorization], ["POST", "/wp-json/zero/v1/media", "Bearer zt_abc"]);
  assert.match(s.headers["content-type"] ?? "", /^multipart\/form-data; boundary=/);
  const form = await new Response(s.body, { headers: { "content-type": s.headers["content-type"] ?? "" } }).formData();
  const file = form.get("file") as File;
  assert.deepEqual([file.name, file.type, new Uint8Array(await file.arrayBuffer())], ["pixel.png", "image/png", bytes]);
  assert.equal(form.get("alt"), "A pixel");

  // A File keeps its own name; no alt → no alt part.
  await zero.media.upload(new File([bytes], "own.png", { type: "image/png" }));
  const again = await new Response(last().body, { headers: { "content-type": last().headers["content-type"] ?? "" } }).formData();
  assert.deepEqual([(again.get("file") as File).name, again.has("alt")], ["own.png", false]);

  await zero.media.list({ where: { mime: { like: "image/%" } }, limit: 5 });
  assert.deepEqual([last().path, last().query.get("limit"), JSON.parse(last().query.get("where") ?? "")], ["/wp-json/zero/v1/media", "5", { mime: { like: "image/%" } }]);
  await zero.media.delete(9);
  assert.deepEqual([last().method, last().path], ["DELETE", "/wp-json/zero/v1/media/9"]);
});

test("media.update is a JSON PATCH of alt/focal", async () => {
  reply = ok({ id: 9 });
  const zero = mod.createClient({ url });
  await zero.media.update(9, { focal: { x: 0.25, y: 1 }, alt: null });
  assert.deepEqual([last().method, last().path, last().headers["content-type"], JSON.parse(last().body.toString())],
    ["PATCH", "/wp-json/zero/v1/media/9", "application/json", { focal: { x: 0.25, y: 1 }, alt: null }]);
});

test("files.<bucket> upload/get/delete hit /files/<bucket>", async () => {
  const row = { id: 4, bucket: "decks", url: "/wp-json/zero/v1/files/decks/4/download?exp=1&sig=ab" };
  reply = ok(row);
  const zero = mod.createClient({ url });
  assert.deepEqual(await zero.files.decks.upload(new Blob([new Uint8Array([37, 80])], { type: "application/pdf" }), { filename: "q3.pdf" }), row);
  const form = await new Response(last().body, { headers: { "content-type": last().headers["content-type"] ?? "" } }).formData();
  assert.deepEqual([last().method, last().path, (form.get("file") as File).name], ["POST", "/wp-json/zero/v1/files/decks", "q3.pdf"]);
  await zero.files.decks.get(4);
  assert.deepEqual([last().method, last().path], ["GET", "/wp-json/zero/v1/files/decks/4"]);
  await zero.files.decks.delete(4);
  assert.deepEqual([last().method, last().path], ["DELETE", "/wp-json/zero/v1/files/decks/4"]);
});

test("default base URL is the REST prefix on the page's origin", async () => {
  let requested = "";
  const zero = mod.createClient({ fetch: async (input: RequestInfo | URL) => ((requested = String(input)), new Response('{"data":null}')) });
  await zero.auth.me();
  assert.equal(requested, "/wp-json/zero/v1/me");
});

test("a 401 unauthenticated refreshes once at the issuer and retries once; a refused refresh forgets the session and surfaces the 401", async () => {
  const zero = mod.createClient({ url, auth: { issuer: `${origin}/identity` } });
  reply = () => tokens(1, guest);
  await zero.auth.guest();

  // za_1 has expired: the server takes only za_2, which the rotated refresh hands out.
  seen = [];
  reply = (s) => (s.path === "/identity/token" ? rotated(2) : s.headers.authorization === "Bearer za_2" ? ok([{ id: 1 }])() : problem(401, "unauthenticated"));
  assert.deepEqual(await zero.posts.list(), [{ id: 1 }]);
  await zero.posts.list();
  assert.deepEqual(lines(), [
    "GET /wp-json/zero/v1/posts Bearer za_1",
    "POST /identity/token - grant_type=refresh_token&refresh_token=zr_1&client_id=",
    "GET /wp-json/zero/v1/posts Bearer za_2",
    "GET /wp-json/zero/v1/posts Bearer za_2",
  ]);
  assert.equal(zero.auth.session()?.refreshToken, "zr_2");

  // The server now refuses every access token and rotates the refresh token only once. The first write
  // refreshes and retries once, then surfaces the 401; the next one finds the refresh token dead, gets the
  // request's own 401, and the session is gone.
  seen = [];
  let rotations = 0;
  reply = (s) => (s.path === "/identity/token" ? (rotations++ === 0 ? rotated(3) : refused("invalid_grant")) : problem(401, "unauthenticated"));
  for (const title of ["x", "y"]) {
    const err = await zero.posts.create({ title }).catch((e: unknown) => e);
    assert.deepEqual([err instanceof mod.ZeroError, err.status, err.code], [true, 401, "unauthenticated"]);
  }
  assert.deepEqual(lines(), [
    'POST /wp-json/zero/v1/posts Bearer za_2 {"title":"x"}',
    "POST /identity/token - grant_type=refresh_token&refresh_token=zr_2&client_id=",
    'POST /wp-json/zero/v1/posts Bearer za_3 {"title":"x"}',
    'POST /wp-json/zero/v1/posts Bearer za_3 {"title":"y"}',
    "POST /identity/token - grant_type=refresh_token&refresh_token=zr_3&client_id=",
  ]);
  assert.equal(zero.auth.session(), null);
});

test("guests: the first unauthenticated write starts a guest session and retries; reads don't", async () => {
  reply = (s) => {
    if (s.path === "/wp-json/zero/v1/auth/guest") return tokens(1, guest);
    return s.headers.authorization ? ok({ id: 1 })() : problem(401, "unauthenticated");
  };
  const zero = mod.createClient({ url, auth: { guests: true } });

  seen = [];
  assert.equal((await zero.posts.list().catch((e: unknown) => e)).code, "unauthenticated");
  assert.deepEqual(await zero.posts.create({ title: "Hi" }), { id: 1 });
  await zero.posts.create({ title: "Again" });
  assert.deepEqual(lines(), [
    "GET /wp-json/zero/v1/posts -",
    'POST /wp-json/zero/v1/posts - {"title":"Hi"}',
    "POST /wp-json/zero/v1/auth/guest - {}",
    'POST /wp-json/zero/v1/posts Bearer za_1 {"title":"Hi"}',
    'POST /wp-json/zero/v1/posts Bearer za_1 {"title":"Again"}',
  ]);

  // Without `guests`, the same write just fails.
  seen = [];
  assert.equal((await mod.createClient({ url }).posts.create({ title: "Hi" }).catch((e: unknown) => e)).code, "unauthenticated");
  assert.equal(seen.length, 1);
});

test("guests: two first writes in flight share one guest, even when one 401 lands after the guest exists", async () => {
  // A fetch stub with manual control: each request waits until the test releases it.
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const zero = mod.createClient({ url: "http://x/wp-json/zero/v1", fetch, auth: { guests: true } });
  // Microtask turns only (no timers); a bounded count so a regression fails instead of hanging.
  const until = async (n: number) => {
    for (let turn = 0; pending.length < n; turn++) {
      if (turn > 10_000) throw new Error(`expected ${n} requests, saw ${pending.map((p) => p.url).join(", ")}`);
      await Promise.resolve();
    }
  };
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const denied = () => json(401, { type: "about:blank", title: "Unauthorized", status: 401, code: "unauthenticated", detail: "no" });

  const a = zero.posts.create({ title: "a" });
  const b = zero.posts.create({ title: "b" });
  await until(2);
  pending[0].release(denied());                       // a's 401 starts the guest
  await until(3);
  assert.equal(pending[2].url, "http://x/wp-json/zero/v1/auth/guest");
  pending[2].release(json(201, { data: { accessToken: "za_g", refreshToken: "zr_g", expiresIn: 300, user: { id: 9, name: "Guest", role: "guest" } } }));
  await until(4);                                      // a retries with the guest
  pending[1].release(denied());                        // b's 401 arrives after the guest exists
  await until(5);
  assert.equal(pending[4].url, "http://x/wp-json/zero/v1/posts", "b retries with the existing guest instead of starting another");
  pending[3].release(json(201, { data: { id: 1 } }));
  pending[4].release(json(201, { data: { id: 2 } }));
  assert.deepEqual([await a, await b], [{ id: 1 }, { id: 2 }]);
  assert.equal(pending.filter((p) => p.url.endsWith("/auth/guest")).length, 1, "one guest");
  assert.deepEqual([pending[3], pending[4]].map((p) => (p.init.headers as Record<string, string>).authorization), ["Bearer za_g", "Bearer za_g"]);
});

test("a 401 or refresh that outlives a sign-in never acts as the new user", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const zero = mod.createClient({ url: "http://x/wp-json/zero/v1", fetch, auth: { issuer: "http://x/identity" } });
  const until = async (n: number) => {
    for (let turn = 0; pending.length < n; turn++) {
      if (turn > 10_000) throw new Error(`expected ${n} requests, saw ${pending.map((p) => p.url).join(", ")}`);
      await Promise.resolve();
    }
  };
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const denied = () => json(401, { type: "about:blank", title: "Unauthorized", status: 401, code: "unauthenticated", detail: "no" });
  const issued = (n: string) => json(200, { data: { accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 300, user: { id: n.length, name: n, role: "guest" } } });
  const auth = (i: number) => (pending[i].init.headers as Record<string, string>).authorization;

  // Guest sessions stand in for sign-ins: the same token shape, the same generation rules.
  const loginA = zero.auth.guest();
  await until(1);
  pending[0].release(issued("a"));
  await loginA;
  const draft = zero.posts.create({ title: "A's private draft" }); // sent as A
  await until(2);
  assert.equal(auth(1), "Bearer za_a");
  const loginB = zero.auth.guest();                               // B signs in on the same client
  await until(3);
  pending[2].release(issued("b"));
  await loginB;
  pending[1].release(denied());                                   // A's 401 lands late
  // Settle the draft, or see what it sent instead (a retry or refresh would be a 4th request).
  let failed: any;
  void draft.then(() => (failed = "resolved"), (e: unknown) => (failed = e));
  for (let turn = 0; failed === undefined && pending.length === 3 && turn < 10_000; turn++) await Promise.resolve();
  assert.equal(pending.length, 3, `nothing was retried or refreshed as B (saw ${pending.slice(3).map((p) => `${p.url} ${auth(pending.indexOf(p))}`).join(", ")})`);
  assert.equal(failed?.code, "unauthenticated", "A's request fails as it was sent");

  // A refresh started as B, finishing after a sign-out, doesn't bring B back.
  const other = zero.posts.create({ title: "x" });
  await until(4);
  pending[3].release(denied());
  await until(5);
  assert.equal(pending[4].url, "http://x/identity/token");
  const out = zero.auth.signOut();
  await until(6);
  assert.equal(pending[5].url, "http://x/identity/revoke");
  pending[5].release(json(200, {}));
  await out;
  pending[4].release(new Response(rotated("b2").body, { status: 200, headers: { "content-type": "application/json" } }));
  await other.catch(() => undefined);
  assert.equal(zero.auth.session(), null, "the late refresh was dropped");
});

test("two clients sharing sessionStorage: one's late 401, guest or sign-out never acts across the other's sign-in", async () => {
  const browser = stubBrowser();
  try {
    const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
    const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
    const opts = { url: "http://x/wp-json/zero/v1", fetch, auth: { storage: "session" as const, guests: true, issuer: "http://x/identity" } };
    const a = mod.createClient(opts);
    const b = mod.createClient(opts);
    const until = async (n: number) => {
      for (let turn = 0; pending.length < n; turn++) {
        if (turn > 10_000) throw new Error(`expected ${n} requests, saw ${pending.map((p) => p.url).join(", ")}`);
        await Promise.resolve();
      }
    };
    const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
    const denied = () => json(401, { type: "about:blank", title: "Unauthorized", status: 401, code: "unauthenticated", detail: "no" });
    const issued = (n: string) => json(200, { data: { accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 300, user: { id: n.length, name: n, role: "guest" } } });
    const settle = async (p: Promise<unknown>) => {
      let out: unknown = "pending";
      void p.then((v) => (out = v), (e) => (out = e));
      for (let turn = 0; out === "pending" && turn < 10_000; turn++) await Promise.resolve();
      return out;
    };

    // Alice signs in through A (guest sessions stand in for sign-ins); A sends a write as Alice;
    // Bob signs in through B (same storage).
    const alice = a.auth.guest();
    await until(1);
    pending[0].release(issued("alice"));
    await alice;
    const draft = a.posts.create({ title: "Alice's draft" });
    await until(2);
    const bob = b.auth.guest();
    await until(3);
    pending[2].release(issued("bob"));
    await bob;
    pending[1].release(denied());
    assert.equal(((await settle(draft)) as any).code, "unauthenticated");
    assert.equal(pending.length, 3, "Alice's write was neither refreshed nor retried as Bob");
    assert.equal(b.auth.session()?.accessToken, "za_bob");

    // An automatic guest that completes after Carol signed in doesn't replace her.
    const bobOut = b.auth.signOut();
    await until(4);
    pending[3].release(json(200, { data: true }));
    await bobOut;
    const write = a.posts.create({ title: "anon" });
    await until(5);
    pending[4].release(denied());                       // starts a guest
    await until(6);
    const carol = b.auth.guest();
    await until(7);
    pending[6].release(issued("carol"));
    await carol;
    pending[5].release(issued("guest"));                // the guest answer arrives late
    await settle(write);
    assert.equal(a.auth.session()?.accessToken, "za_carol", "Carol's sign-in stands");

    // A sign-out that started before Dave signed in doesn't clear Dave.
    const out = a.auth.signOut();                        // request 7
    await until(8);
    const dave = b.auth.guest(); // request 8
    await until(9);
    pending[8].release(issued("dave"));
    await dave;
    pending[7].release(json(200, { data: true }));
    await settle(out);
    assert.equal(b.auth.session()?.accessToken, "za_dave", "Dave stays signed in");

    // Both clients get a 401 for Dave at once: only one refresh token is ever presented.
    const n = pending.length;
    const fromA = a.posts.create({ title: "a" });
    const fromB = b.posts.create({ title: "b" });
    await until(n + 2);
    pending[n].release(denied());
    pending[n + 1].release(denied());
    await until(n + 3);
    assert.equal(pending[n + 2].url, "http://x/identity/token");
    pending[n + 2].release(new Response(rotated("dave2").body, { status: 200, headers: { "content-type": "application/json" } })); // one client rotates; the other picks it up
    await until(n + 5);
    const retries = pending.slice(n + 3, n + 5).map((p) => (p.init.headers as Record<string, string>).authorization);
    assert.deepEqual(retries, ["Bearer za_dave2", "Bearer za_dave2"], "both retried with the one rotated pair");
    assert.equal(pending.slice(n).filter((p) => p.url.endsWith("/identity/token")).length, 1, "exactly one refresh for the shared session");
    pending[n + 3].release(json(201, { data: { id: 1 } }));
    pending[n + 4].release(json(201, { data: { id: 2 } }));
    await Promise.all([fromA, fromB]);

    // A guest started while signed out doesn't come back after a later sign-in and sign-out.
    const daveOut = a.auth.signOut();
    await until(n + 6);
    pending[n + 5].release(json(200, { data: true }));
    await daveOut;
    const anon = a.posts.create({ title: "anon again" });
    await until(n + 7);
    pending[n + 6].release(denied());                    // starts a guest (pending)
    await until(n + 8);
    const erin = b.auth.guest();
    await until(n + 9);
    pending[n + 8].release(issued("erin"));
    await erin;
    const erinOut = b.auth.signOut();
    await until(n + 10);
    pending[n + 9].release(json(200, { data: true }));
    await erinOut;
    pending[n + 7].release(issued("late-guest"));        // the old guest answer arrives last
    await settle(anon);
    assert.equal(a.auth.session(), null, "signed out stays signed out");
  } finally {
    browser.restore();
  }
});

test("a refresh still in flight for an earlier sign-in never stands in for the current session's", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const zero = mod.createClient({ url: "http://x/wp-json/zero/v1", fetch, auth: { issuer: "http://x/identity" } });
  const until = async (n: number) => {
    for (let turn = 0; pending.length < n; turn++) {
      if (turn > 10_000) throw new Error(`expected ${n} requests, saw ${pending.map((p) => p.url).join(", ")}`);
      await Promise.resolve();
    }
  };
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const denied = () => json(401, { type: "about:blank", title: "Unauthorized", status: 401, code: "unauthenticated", detail: "no" });
  const issued = (n: string) => json(200, { data: { accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 300, user: { id: 1, name: n, role: "guest" } } });
  const oidc = (n: string) => new Response(rotated(n as unknown as number).body, { status: 200, headers: { "content-type": "application/json" } });

  const a = zero.auth.guest();
  await until(1);
  pending[0].release(issued("a"));
  await a;
  const wa = zero.posts.create({ title: "a" });             // 1
  await until(2);
  pending[1].release(denied());
  await until(3);                                           // 2: A's refresh, left pending
  const b = zero.auth.guest();                              // 3
  await until(4);
  pending[3].release(issued("b"));
  await b;
  const wb = zero.posts.create({ title: "b" });             // 4
  await until(5);
  pending[4].release(denied());
  await until(6);
  assert.equal(pending[5].url, "http://x/identity/token", "B refreshes its own session");
  assert.equal(new URLSearchParams(String(pending[5].init.body)).get("refresh_token"), "zr_b");
  pending[5].release(oidc("b2"));
  await until(7);
  assert.equal((pending[6].init.headers as Record<string, string>).authorization, "Bearer za_b2");
  pending[6].release(json(201, { data: { id: 2 } }));
  assert.deepEqual(await wb, { id: 2 });
  pending[2].release(oidc("a2"));                           // A's late refresh: dropped
  await wa.catch(() => undefined);
  assert.equal(zero.auth.session()?.accessToken, "za_b2");
});

test("guest(), signOut(), guest(): the second call starts its own guest", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const zero = mod.createClient({ url: "http://x/wp-json/zero/v1", fetch });
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const issued = (n: string) => json(200, { data: { accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 300, user: { id: 1, name: n, role: "guest" } } });
  const g1 = zero.auth.guest();
  await zero.auth.signOut();
  const g2 = zero.auth.guest();
  for (let turn = 0; pending.length < 2 && turn < 10_000; turn++) await Promise.resolve();
  assert.equal(pending.length, 2, "two guest requests");
  pending[0].release(issued("g1"));
  pending[1].release(issued("g2"));
  await g1.catch(() => undefined);
  assert.equal((await g2).name, "g2");
  assert.equal(zero.auth.session()?.accessToken, "za_g2");
});

test("signing out while anonymous still ends pending sign-ins; an old anonymous write never borrows a newer guest", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const zero = mod.createClient({ url: "http://x/wp-json/zero/v1", fetch, auth: { guests: true, issuer: "http://x/identity" } });
  const until = async (n: number) => {
    for (let turn = 0; pending.length < n; turn++) {
      if (turn > 10_000) throw new Error(`expected ${n} requests, saw ${pending.map((p) => p.url).join(", ")}`);
      await Promise.resolve();
    }
  };
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const denied = () => json(401, { type: "about:blank", title: "Unauthorized", status: 401, code: "unauthenticated", detail: "no" });
  const issued = (n: string) => json(200, { data: { accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 300, user: { id: 1, name: n, role: "guest" } } });
  const settle = async (p: Promise<unknown>) => {
    let out: unknown = "pending";
    void p.then((v) => (out = v), (e) => (out = e));
    for (let turn = 0; out === "pending" && turn < 10_000; turn++) await Promise.resolve();
    return out;
  };

  // guest() in flight, then signOut() while still anonymous: it may not sign in later.
  const started = zero.auth.guest();
  await until(1);
  await zero.auth.signOut();
  pending[0].release(issued("late-guest"));
  await settle(started);
  assert.equal(zero.auth.session(), null, "still signed out");

  // An anonymous write from before a sign-in/sign-out doesn't retry as the guest that came after.
  const old = zero.posts.create({ title: "old" });          // request 1, anonymous
  await until(2);
  const fresh = zero.auth.guest();                          // request 2: a guest from a later generation
  await until(3);
  pending[2].release(issued("guest-a"));
  await fresh;
  const out = zero.auth.signOut();                          // request 3: revoke guest-a at the issuer
  await until(4);
  assert.equal(pending[3].url, "http://x/identity/revoke");
  pending[3].release(json(200, {}));
  await out;
  const newer = zero.auth.guest();                          // request 4: yet another guest
  await until(5);
  pending[4].release(issued("guest-b"));
  await newer;
  pending[1].release(denied());                             // the old write's 401 lands now
  const failed: any = await settle(old);
  assert.equal(failed.code, "unauthenticated", "the old write fails as sent");
  assert.equal(pending.length, 5, "it was not retried as a later guest");
});

test("signIn redirects to <issuer>/authorize with PKCE S256, state and nonce kept in sessionStorage", async () => {
  const browser = stubBrowser();
  try {
    seen = [];
    reply = (s) => (s.path === "/wp-json/zero/v1/auth/config" ? ok({ issuer: `${origin}/identity`, guests: true })() : problem(404, "not_found"));
    const zero = mod.createClient({ url: `${origin}/wp-json/zero/v1`, auth: { clientId: "app_blog", redirectUri: "https://blog.test/callback" } });
    void zero.auth.signIn({ returnTo: "/drafts" });
    const to = new URL(await browser.navigated);

    // No issuer option: it comes from /auth/config.
    assert.deepEqual(lines(), ["GET /wp-json/zero/v1/auth/config -"]);
    assert.equal(`${to.origin}${to.pathname}`, `${origin}/identity/authorize`);
    const pkce = JSON.parse(browser.store.get("zero.auth.pkce") ?? "{}");
    assert.deepEqual(Object.fromEntries(to.searchParams), {
      response_type: "code",
      client_id: "app_blog",
      redirect_uri: "https://blog.test/callback",
      scope: "openid profile email",
      state: pkce.state,
      nonce: pkce.nonce,
      code_challenge: sha256url(pkce.verifier),
      code_challenge_method: "S256",
    });
    // 64 / 32 / 32 random bytes, base64url.
    assert.deepEqual(
      [pkce.verifier, pkce.state, pkce.nonce].map((v: string) => /^[A-Za-z0-9_-]+$/.test(v) && v.length),
      [86, 43, 43],
    );
    assert.equal(pkce.returnTo, "/drafts");

    const missing = await mod.createClient({ url, auth: { issuer: `${origin}/identity` } }).auth.signIn().catch((e: unknown) => e);
    assert.deepEqual([missing.status, missing.code], [400, "invalid_input"]);
  } finally {
    browser.restore();
  }
});

test("handleCallback refuses a foreign state, exchanges the code with the verifier, and signOut revokes then logs out", async () => {
  const issuer = `${origin}/identity`;
  const auth = { issuer, clientId: "app_blog", redirectUri: "https://blog.test/callback", storage: "session" };
  const base = `${origin}/wp-json/zero/v1`;
  const pkce = JSON.stringify({ verifier: "v".repeat(86), state: "s".repeat(43), nonce: "n".repeat(43), returnTo: "/drafts" });
  const member = { id: 3, name: "ann", role: "subscriber", member: { id: 3, email: "ann@x.test" } };
  reply = (s) => {
    if (s.path === "/identity/token") return { status: 200, body: JSON.stringify({ access_token: "at_1", refresh_token: "rt_1", expires_in: 3600, id_token: "h.p.s", token_type: "Bearer" }) };
    if (s.path === "/identity/revoke") return { status: 200, body: "{}" };
    return ok(member)();
  };

  const forged = stubBrowser("?code=c0de&state=other");
  try {
    forged.store.set("zero.auth.pkce", pkce);
    seen = [];
    const err = await mod.createClient({ url: base, auth }).auth.handleCallback().catch((e: unknown) => e);
    assert.deepEqual([err instanceof mod.ZeroError, err.code], [true, "invalid_state"]);
    assert.deepEqual(seen, []);
  } finally {
    forged.restore();
  }

  const browser = stubBrowser(`?code=c0de&state=${"s".repeat(43)}`);
  try {
    browser.store.set("zero.auth.pkce", pkce);
    const zero = mod.createClient({ url: base, auth });
    seen = [];
    assert.deepEqual(await zero.auth.handleCallback(), member);
    assert.equal(await browser.navigated, "https://blog.test/drafts", "back to signIn's returnTo");
    assert.deepEqual([seen[0].path, seen[0].headers["content-type"], seen[0].headers.authorization], ["/identity/token", "application/x-www-form-urlencoded", undefined]);
    assert.deepEqual(form(seen[0]), {
      grant_type: "authorization_code",
      code: "c0de",
      redirect_uri: "https://blog.test/callback",
      client_id: "app_blog",
      code_verifier: "v".repeat(86),
    });
    assert.deepEqual([seen[1].path, seen[1].headers.authorization], ["/wp-json/zero/v1/me", "Bearer at_1"]);
    assert.equal(browser.store.has("zero.auth.pkce"), false);
    const kept = JSON.parse(browser.store.get(`zero.auth:${base}`) ?? "{}");
    assert.deepEqual({ ...kept, expiresAt: typeof kept.expiresAt, sid: typeof kept.sid }, { accessToken: "at_1", refreshToken: "rt_1", expiresAt: "number", clientId: "app_blog", idToken: "h.p.s", sid: "string" });

    seen = [];
    await zero.auth.signOut({ returnTo: "https://blog.test/" });
    assert.deepEqual([seen.length, seen[0].path, form(seen[0])], [1, "/identity/revoke", { token: "rt_1", client_id: "app_blog" }]);
    // Signed out, the record keeps only the session generation (no tokens).
    assert.deepEqual(Object.keys(JSON.parse(browser.store.get(`zero.auth:${base}`) ?? "{}")), ["sid"]);
    const logout = new URL(browser.visits.at(-1) ?? "");
    assert.deepEqual(
      [`${logout.origin}${logout.pathname}`, Object.fromEntries(logout.searchParams)],
      [`${issuer}/logout`, { id_token_hint: "h.p.s", post_logout_redirect_uri: "https://blog.test/" }],
    );
  } finally {
    browser.restore();
  }
});

test("handleCallback returns only to its own origin, and only when tokens survive the page load", async () => {
  const issuer = `${origin}/identity`;
  const base = `${origin}/wp-json/zero/v1`;
  const member = { id: 3, name: "ann", role: "subscriber" };
  reply = (s) => (s.path === "/identity/token"
    ? { status: 200, body: JSON.stringify({ access_token: "at_1", refresh_token: "rt_1", expires_in: 3600, token_type: "Bearer" }) }
    : ok(member)());
  for (const [returnTo, storage, expected] of [
    ["/\\evil.test/", "session", []],             // resolves to another origin: refused
    ["/\tevil.test/", "session", ["https://blog.test/evil.test/"]], // tab stripped by URL parsing: same origin
    ["https://evil.test/x", "session", []],
    ["/drafts", "memory", []],                     // memory tokens wouldn't survive the load
  ] as const) {
    const browser = stubBrowser(`?code=c&state=${"s".repeat(43)}`);
    try {
      browser.store.set("zero.auth.pkce", JSON.stringify({ verifier: "v".repeat(86), state: "s".repeat(43), nonce: "n".repeat(43), returnTo }));
      await mod.createClient({ url: base, auth: { issuer, clientId: "app_blog", redirectUri: "https://blog.test/callback", storage } }).auth.handleCallback();
      assert.deepEqual(browser.visits, expected, `returnTo ${JSON.stringify(returnTo)} with ${storage} storage`);
    } finally {
      browser.restore();
    }
  }
});

test("guests refresh at <issuer>/token with the clientId they were issued, and upgrade hands off to the issuer", async () => {
  const browser = stubBrowser();
  try {
    const base = `${origin}/wp-json/zero/v1`;
    const zero = mod.createClient({ url: base, auth: { issuer: `${origin}/identity`, clientId: "app_blog", redirectUri: "https://blog.test/callback" } });
    reply = (s) => {
      if (s.path === "/wp-json/zero/v1/auth/guest") return tokens(1, { id: 9, name: "Guest", role: "guest" }, { clientId: "app_zero_guest" });
      if (s.path === "/identity/token") return { status: 200, body: JSON.stringify({ access_token: "at_2", refresh_token: "rt_2", expires_in: 3600 }) };
      if (s.path === "/identity/handoff") return { status: 200, body: JSON.stringify({ handoff: "h1.secret" }) };
      return s.headers.authorization === "Bearer at_2" ? ok({ id: 1 })() : problem(401, "unauthenticated");
    };
    assert.equal((await zero.auth.guest()).role, "guest");

    seen = [];
    await zero.posts.create({ title: "Hi" });
    assert.deepEqual(
      seen.map((s) => [s.path, s.headers.authorization ?? "-"]),
      [["/wp-json/zero/v1/posts", "Bearer za_1"], ["/identity/token", "-"], ["/wp-json/zero/v1/posts", "Bearer at_2"]],
    );
    assert.deepEqual(form(seen[1]), { grant_type: "refresh_token", refresh_token: "zr_1", client_id: "app_zero_guest" });

    seen = [];
    void zero.auth.upgrade();
    const to = new URL(await browser.navigated);
    assert.deepEqual([seen[0].path, seen[0].headers.authorization], ["/identity/handoff", "Bearer at_2"]);
    assert.deepEqual([to.pathname, to.searchParams.get("handoff"), to.searchParams.get("code_challenge_method")], ["/identity/authorize", "h1.secret", "S256"]);
  } finally {
    browser.restore();
  }
});

/** A transport the test drives: `push` delivers a notice, `set` reports live/down. */
function fakeTransport() {
  const t = {
    connects: 0,
    stops: 0,
    push: (_n: unknown) => {},
    set: (_s: "live" | "down") => {},
    connect(deliver: (n: unknown) => void, status: (s: "live" | "down") => void) {
      t.connects++;
      t.push = deliver;
      t.set = status;
      return () => void t.stops++;
    },
  };
  return t;
}
const tick = () => new Promise((r) => setTimeout(r, 20));
const notice = (collection: string, ids: number[] | null = [1]) => ({ id: "n", at: "2026-11-07T00:00:00.000Z", changes: [{ collection, ops: ["update"], ids }] });

test("watch re-runs the access-checked list on a matching notice, never trusting pushed data", async () => {
  seen = [];
  let n = 0;
  reply = (s) => ok([{ id: 1, title: `v${++n}` }])();
  const transport = fakeTransport();
  const zero = mod.createClient({ url, realtime: { transport, pollMs: false, livePollMs: false } });
  const got: unknown[] = [];
  const stop = zero.posts.watch({ where: { title: { like: "%v%" } }, with: { author: true } }, (rows: unknown) => got.push(rows));
  await tick();
  assert.equal(transport.connects, 1, "the first watcher connects the transport");
  assert.deepEqual(got, [[{ id: 1, title: "v1" }]], "an initial read");
  transport.set("live");
  await tick();
  assert.equal(got.length, 2, "going live catches up once");

  transport.push({ ...notice("posts"), changes: [{ collection: "posts", ops: ["update"], ids: [1], row: { title: "pushed" } }] });
  await tick();
  assert.deepEqual(got.at(-1), [{ id: 1, title: "v3" }], "the refresh is the server's answer, not the pushed payload");
  assert.equal(last().path, "/wp-json/zero/v1/posts");
  assert.deepEqual(JSON.parse(last().query.get("where") ?? ""), { title: { like: "%v%" } }, "the same query re-runs");

  transport.push(notice("authors"));
  await tick();
  assert.equal(got.length, 4, "a change to a collection loaded through `with` refreshes too");
  transport.push(notice("shoppingList"));
  await tick();
  assert.equal(got.length, 4, "an unrelated collection does not");

  stop();
  assert.equal(transport.stops, 1, "the last watcher disconnects");
  transport.push(notice("posts"));
  await tick();
  assert.equal(got.length, 4, "nothing after stop");
});

test("watch coalesces a burst into one extra read and reports read errors", async () => {
  let release: () => void = () => {};
  const gate = new Promise<void>((r) => (release = r));
  let calls = 0;
  reply = () => ok([])();
  const slowFetch: typeof fetch = async (input, init) => {
    calls++;
    if (calls === 2) await gate;
    return fetch(input, init);
  };
  const transport = fakeTransport();
  const zero = mod.createClient({ url, fetch: slowFetch, realtime: { transport, pollMs: false, livePollMs: false } });
  const stop = zero.posts.watch(undefined, () => {});
  await tick();
  transport.push(notice("posts"));
  transport.push(notice("posts"));
  transport.push(notice("posts"));
  await tick();
  release();
  await tick();
  assert.equal(calls, 3, "initial read, one in flight, one more for the burst");
  stop();

  reply = () => problem(403, "forbidden");
  const errors: string[] = [];
  const stop2 = mod.createClient({ url, realtime: { transport: false, pollMs: false } }).posts.watch(undefined, () => {}, (e: { code: string }) => errors.push(e.code));
  await tick();
  assert.deepEqual(errors, ["forbidden"]);
  stop2();
});

test("without a live transport, watch polls; a transport registered later is picked up", async () => {
  reply = () => ok([])();
  seen = [];
  const zero = mod.createClient({ url, realtime: { pollMs: 30, livePollMs: false } });
  const stop = zero.posts.watch(undefined, () => {});
  await new Promise((r) => setTimeout(r, 100));
  const polled = seen.length;
  assert.ok(polled >= 3, `polled ${polled} times`);

  const transport = fakeTransport();
  mod.registerRealtimeTransport(transport);
  assert.equal(transport.connects, 1, "a host script registering after the client connects existing watchers");
  transport.set("live");
  await tick();
  const atLive = seen.length;
  await new Promise((r) => setTimeout(r, 100));
  assert.equal(seen.length, atLive, "the fast poll stops while live");
  transport.set("down");
  await new Promise((r) => setTimeout(r, 100));
  assert.ok(seen.length > atLive, "and resumes when the transport goes down");
  stop();

  delete (globalThis as Record<string, unknown>).__zeroRealtime;

  // A second copy of the client (a host script's) registers; this copy's watchers connect to it.
  const other: any = await load(SCHEMA_TS);
  const second = fakeTransport();
  const stop2 = zero.posts.watch(undefined, () => {});
  try {
    other.registerRealtimeTransport(second);
    assert.equal(second.connects, 1, "the registry is page-wide, not per module copy");
  } finally {
    stop2();
    delete (globalThis as Record<string, unknown>).__zeroRealtime;
  }
});

test("while live, a slow jittered safety poll still recovers a notice the transport lost", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout"] });
  t.mock.method(Math, "random", () => 1); // the latest the jitter allows: 60 s × 1.2
  const transport = fakeTransport();
  const refreshes: number[] = [];
  const zero = mod.createClient({
    url,
    // Count reads without the network: only the timers decide when a refresh happens.
    fetch: async () => (refreshes.push(refreshes.length), new Response('{"data":[]}', { status: 200 })),
    realtime: { transport, pollMs: false },
  });
  const stop = zero.posts.watch(undefined, () => {});
  transport.set("live"); // initial read + one catch-up read on going live
  // setImmediate is not mocked: one turn of it drains every promise a refresh queued.
  const settle = () => new Promise((resolve) => setImmediate(resolve));
  await settle();
  const base = refreshes.length;
  t.mock.timers.tick(71_999);
  await settle();
  assert.equal(refreshes.length, base, "no refresh before 60 s × 1.2");
  t.mock.timers.tick(1);
  await settle();
  assert.equal(refreshes.length, base + 1, "the safety poll refreshes once a lost notice could have gone missing");
  t.mock.timers.tick(72_000);
  await settle();
  assert.equal(refreshes.length, base + 2, "and keeps refreshing while live");
  stop();
  t.mock.timers.tick(200_000);
  await settle();
  assert.equal(refreshes.length, base + 2, "no poll after the last watcher stops");
});
