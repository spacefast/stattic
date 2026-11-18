// Client runtime request building, against an in-process node:http server with canned responses.
// The runtime is copied next to a fixture schema.ts/types.ts in the exact layout `typedpress build` emits
// (tp/_generated/client/{index,schema,types}.ts), then imported for real.
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
    authors: { path: "authors", primaryKey: "id", drafts: true, revisions: true,
      relations: { posts: { kind: "many", collection: "posts", optional: false } }, media: [], mediaList: [], files: [] },
    shoppingList: { path: "shopping_list", primaryKey: "id", drafts: false, revisions: false, relations: {}, media: [], mediaList: [], files: [] },
    comments: { path: "comments", primaryKey: "id", drafts: false, revisions: false,
      relations: { post: { kind: "one", collection: "posts", optional: false } }, media: [], mediaList: [], files: [] },
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
  const dir = mkdtempSync(join(tmpdir(), "typedpress-client-"));
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
  url = `${origin}/__tp/api/v1`;
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
  const first = mod.createClient({ url: url + "/" });
  const rows = await first.posts.list({
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
  assert.equal(last().path, "/__tp/api/v1/posts");
  assert.deepEqual(JSON.parse(q.get("where") ?? ""), { tags: { has: "news" }, OR: [{ title: { like: "%a&b%" } }] });
  assert.deepEqual([q.get("orderBy"), q.get("limit"), q.get("offset"), q.get("status"), q.get("with")], ["-createdAt,title", "10", "20", "draft", "author,cover"]);

  // Any entry with options switches `with` to JSON; nested orderBy strings travel as objects.
  await first.posts.get(7, { with: { author: { with: { posts: true }, orderBy: "-id", limit: 2 } }, locale: "*" });
  assert.equal(last().path, "/__tp/api/v1/posts/7");
  assert.equal(last().query.get("locale"), "*");
  assert.deepEqual(JSON.parse(last().query.get("with") ?? ""), { author: { with: { posts: true }, orderBy: { id: "desc" }, limit: 2 } });

  // The collection key is not the path: shoppingList lives at /shopping_list.
  await first.shoppingList.list();
  assert.equal(last().path, "/__tp/api/v1/shopping_list");
  assert.equal(last().query.toString(), "");

  // paginate: the envelope's cursor, passed back as `cursor`.
  reply = () => ({ status: 200, body: JSON.stringify({ data: [{ id: 2 }], cursor: "c2" }) });
  assert.deepEqual(await first.posts.paginate({ limit: 1 }), { rows: [{ id: 2 }], cursor: "c2" });
  reply = () => ({ status: 200, body: JSON.stringify({ data: [{ id: 3 }], cursor: null }) });
  assert.deepEqual(await first.posts.paginate({ limit: 1, cursor: "c2" }), { rows: [{ id: 3 }], cursor: null });
  assert.deepEqual([last().query.get("cursor"), last().query.get("limit")], ["c2", "1"]);
});

test("revisions.list follows the cursor and returns every revision, newest first", async () => {
  seen = [];
  const pages: Record<string, { data: unknown[]; cursor: string | null }> = {
    "": { data: [{ id: 9 }, { id: 8 }], cursor: "c1" },
    c1: { data: [{ id: 7 }], cursor: null },
  };
  reply = (s) => ({ status: 200, body: JSON.stringify(pages[s.query.get("cursor") ?? ""]) });
  const first = mod.createClient({ url });
  assert.deepEqual(await first.posts.revisions.list(1), [{ id: 9 }, { id: 8 }, { id: 7 }]);
  assert.deepEqual(seen.map((s) => [s.path, s.query.get("cursor"), s.query.get("limit")]), [
    ["/__tp/api/v1/posts/1/revisions", null, "100"],
    ["/__tp/api/v1/posts/1/revisions", "c1", "100"],
  ]);
});

test("writes, revisions, globals, actions and auth hit the §4.8 routes with JSON bodies", async () => {
  seen = [];
  reply = ok({ id: 1 });
  const first = mod.createClient({ url });
  await first.posts.create({ title: "Hi" });
  await first.posts.update(1, { title: "Yo" });
  await first.posts.delete(1);
  reply = () => ({ status: 200, body: JSON.stringify({ data: [], cursor: null }) });
  await first.posts.revisions.list(1);
  reply = ok({ id: 1 });
  await first.posts.revisions.get(1, 4);
  await first.posts.revisions.restore(1, 4);
  await first.posts.stage(1, { title: "Staged" });
  await first.posts.staged(1);
  await first.posts.compare(1);
  await first.posts.promote(1, { at: "2030-01-01T00:00:00Z" });
  await first.posts.promote(1, { at: null });
  await first.posts.promote(1);
  await first.posts.discard(1);
  await first.posts.update(1, { title: "Yo", _rev: "r1", takeover: true });
  await first.posts.delete(1, { _rev: "r2", takeover: true });
  await first.posts.promote(1, { _rev: "r3" });
  await first.posts.discard(1, { _rev: "r4" });
  await first.posts.previewLink(1, { ttl: 600 });
  await first.globals.settings.get();
  await first.globals.settings.update({ tagline: "x" });
  await first.actions.approveComment({ id: 3 });
  await first.actions.publishedCount();
  await first.auth.me();
  await first.schema();
  assert.deepEqual(
    seen.map((s) => `${s.method} ${s.path}${s.query.size ? `?${s.query}` : ""} ${s.body.toString()}`),
    [
      'POST /__tp/api/v1/posts {"title":"Hi"}',
      'PATCH /__tp/api/v1/posts/1 {"title":"Yo"}',
      "DELETE /__tp/api/v1/posts/1 ",
      "GET /__tp/api/v1/posts/1/revisions?limit=100 ",
      "GET /__tp/api/v1/posts/1/revisions/4 ",
      "POST /__tp/api/v1/posts/1/revisions/4/restore ",
      'PATCH /__tp/api/v1/posts/1/staged {"title":"Staged"}',
      "GET /__tp/api/v1/posts/1/staged ",
      "GET /__tp/api/v1/posts/1/staged/compare ",
      'POST /__tp/api/v1/posts/1/staged/promote {"at":"2030-01-01T00:00:00Z"}',
      'POST /__tp/api/v1/posts/1/staged/promote {"at":null}',
      "POST /__tp/api/v1/posts/1/staged/promote {}",
      "DELETE /__tp/api/v1/posts/1/staged ",
      'PATCH /__tp/api/v1/posts/1 {"title":"Yo","_rev":"r1","takeover":true}',
      "DELETE /__tp/api/v1/posts/1?_rev=r2&takeover=1 ",
      'POST /__tp/api/v1/posts/1/staged/promote {"_rev":"r3"}',
      "DELETE /__tp/api/v1/posts/1/staged?_rev=r4 ",
      'POST /__tp/api/v1/posts/1/staged/preview {"ttl":600}',
      "GET /__tp/api/v1/settings ",
      'PATCH /__tp/api/v1/settings {"tagline":"x"}',
      'POST /__tp/api/v1/actions/approveComment {"id":3}',
      "POST /__tp/api/v1/actions/publishedCount {}",
      "GET /__tp/api/v1/me ",
      "GET /__tp/api/v1 ",
    ],
  );
  assert.equal(seen[0].headers["content-type"], "application/json");
});

test("auth headers: anonymous sends none, a guest's bearer is held, WP nonce, custom headers win", async () => {
  seen = [];
  reply = (s) => (s.path === "/__tp/api/v1/auth/guest" ? tokens(1, guest) : ok(null)());

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
  reply = (s) => (s.path === "/__tp/api/v1/auth/guest" ? tokens(2, guest) : ok(null)());
  await held.auth.guest();
  await held.auth.me();
  assert.equal(last().headers.authorization, basic);
});

test("problem documents become TypedPressError with every field; non-problem failures still map", async () => {
  const first = mod.createClient({ url });
  const problem = { type: "about:blank", title: "Invalid input", status: 422, code: "invalid_input", detail: "Too long", pointer: "/sections/1/heading" };
  reply = () => ({ status: 422, type: "application/problem+json", body: JSON.stringify(problem) });
  const err = await first.posts.create({ title: "x" }).catch((e: unknown) => e);
  assert.ok(err instanceof mod.TypedPressError);
  assert.ok(err instanceof Error);
  assert.deepEqual(
    { type: err.type, title: err.title, status: err.status, code: err.code, detail: err.detail, pointer: err.pointer, message: err.message },
    { ...problem, message: "Too long" },
  );

  // No pointer in the document → no pointer on the error.
  reply = () => ({ status: 403, type: "application/problem+json", body: JSON.stringify({ type: "about:blank", title: "Forbidden", status: 403, code: "forbidden", detail: "no" }) });
  const forbidden = await first.actions.approveComment({ id: 1 }).catch((e: unknown) => e);
  assert.deepEqual([forbidden.status, forbidden.code, forbidden.pointer], [403, "forbidden", undefined]);

  // A WordPress core error body (before TypedPress's route runs) maps onto the same shape.
  reply = () => ({ status: 401, body: JSON.stringify({ code: "rest_cookie_invalid_nonce", message: "Cookie check failed", data: { status: 403 } }) });
  const wpErr = await first.auth.me().catch((e: unknown) => e);
  assert.deepEqual([wpErr.status, wpErr.code, wpErr.detail], [401, "rest_cookie_invalid_nonce", "Cookie check failed"]);

  // HTML from a proxy, and an unreachable server: status 0, code "network".
  reply = () => ({ status: 502, type: "text/html", body: "<h1>Bad gateway</h1>" });
  const html = await first.posts.list().catch((e: unknown) => e);
  assert.deepEqual([html.status, html.code], [0, "network"]);
  const down = await mod.createClient({ url: "http://127.0.0.1:1/__tp/api/v1" }).posts.list().catch((e: unknown) => e);
  assert.deepEqual([down instanceof mod.TypedPressError, down.status, down.code], [true, 0, "network"]);
});

test("media upload is multipart with `file` (+ filename, type) and `alt`", async () => {
  seen = [];
  const media = { id: 9, url: "/uploads/2026/10/a.png", mime: "image/png", size: 3, width: 1, height: 1, alt: "A pixel", filename: "pixel.png", createdAt: "2026-10-28T00:00:00.000Z" };
  reply = () => ({ status: 201, body: JSON.stringify({ data: media }) });
  const first = mod.createClient({ url, headers: { Authorization: "Bearer zt_abc" } });
  const bytes = new Uint8Array([137, 80, 78]);
  assert.deepEqual(await first.media.upload(new Blob([bytes], { type: "image/png" }), { alt: "A pixel", filename: "pixel.png" }), media);

  const s = last();
  assert.deepEqual([s.method, s.path, s.headers.authorization], ["POST", "/__tp/api/v1/media", "Bearer zt_abc"]);
  assert.match(s.headers["content-type"] ?? "", /^multipart\/form-data; boundary=/);
  const form = await new Response(s.body, { headers: { "content-type": s.headers["content-type"] ?? "" } }).formData();
  const file = form.get("file") as File;
  assert.deepEqual([file.name, file.type, new Uint8Array(await file.arrayBuffer())], ["pixel.png", "image/png", bytes]);
  assert.equal(form.get("alt"), "A pixel");

  // A File keeps its own name; no alt → no alt part.
  await first.media.upload(new File([bytes], "own.png", { type: "image/png" }));
  const again = await new Response(last().body, { headers: { "content-type": last().headers["content-type"] ?? "" } }).formData();
  assert.deepEqual([(again.get("file") as File).name, again.has("alt")], ["own.png", false]);

  await first.media.list({ where: { mime: { like: "image/%" } }, limit: 5 });
  assert.deepEqual([last().path, last().query.get("limit"), JSON.parse(last().query.get("where") ?? "")], ["/__tp/api/v1/media", "5", { mime: { like: "image/%" } }]);
  await first.media.delete(9);
  assert.deepEqual([last().method, last().path], ["DELETE", "/__tp/api/v1/media/9"]);
});

test("media.update is a JSON PATCH of alt/focal", async () => {
  reply = ok({ id: 9 });
  const first = mod.createClient({ url });
  await first.media.update(9, { focal: { x: 0.25, y: 1 }, alt: null });
  assert.deepEqual([last().method, last().path, last().headers["content-type"], JSON.parse(last().body.toString())],
    ["PATCH", "/__tp/api/v1/media/9", "application/json", { focal: { x: 0.25, y: 1 }, alt: null }]);
});

test("files.<bucket> upload/get/delete hit /files/<bucket>", async () => {
  const row = { id: 4, bucket: "decks", url: "/__tp/api/v1/files/decks/4/download?exp=1&sig=ab" };
  reply = ok(row);
  const first = mod.createClient({ url });
  assert.deepEqual(await first.files.decks.upload(new Blob([new Uint8Array([37, 80])], { type: "application/pdf" }), { filename: "q3.pdf" }), row);
  const form = await new Response(last().body, { headers: { "content-type": last().headers["content-type"] ?? "" } }).formData();
  assert.deepEqual([last().method, last().path, (form.get("file") as File).name], ["POST", "/__tp/api/v1/files/decks", "q3.pdf"]);
  await first.files.decks.get(4);
  assert.deepEqual([last().method, last().path], ["GET", "/__tp/api/v1/files/decks/4"]);
  await first.files.decks.delete(4);
  assert.deepEqual([last().method, last().path], ["DELETE", "/__tp/api/v1/files/decks/4"]);
});

test("default base URL is the REST prefix on the page's origin", async () => {
  let requested = "";
  const first = mod.createClient({ fetch: async (input: RequestInfo | URL) => ((requested = String(input)), new Response('{"data":null}')) });
  await first.auth.me();
  assert.equal(requested, "/__tp/api/v1/me");
});

test("a 401 unauthenticated refreshes once at the issuer and retries once; a refused refresh forgets the session and surfaces the 401", async () => {
  const first = mod.createClient({ url, auth: { issuer: `${origin}/identity` } });
  reply = () => tokens(1, guest);
  await first.auth.guest();

  // za_1 has expired: the server takes only za_2, which the rotated refresh hands out.
  seen = [];
  reply = (s) => (s.path === "/identity/token" ? rotated(2) : s.headers.authorization === "Bearer za_2" ? ok([{ id: 1 }])() : problem(401, "unauthenticated"));
  assert.deepEqual(await first.posts.list(), [{ id: 1 }]);
  await first.posts.list();
  assert.deepEqual(lines(), [
    "GET /__tp/api/v1/posts Bearer za_1",
    "POST /identity/token - grant_type=refresh_token&refresh_token=zr_1&client_id=",
    "GET /__tp/api/v1/posts Bearer za_2",
    "GET /__tp/api/v1/posts Bearer za_2",
  ]);
  assert.equal(first.auth.session()?.refreshToken, "zr_2");

  // The server now refuses every access token and rotates the refresh token only once. The first write
  // refreshes and retries once, then surfaces the 401; the next one finds the refresh token dead, gets the
  // request's own 401, and the session is gone.
  seen = [];
  let rotations = 0;
  reply = (s) => (s.path === "/identity/token" ? (rotations++ === 0 ? rotated(3) : refused("invalid_grant")) : problem(401, "unauthenticated"));
  for (const title of ["x", "y"]) {
    const err = await first.posts.create({ title }).catch((e: unknown) => e);
    assert.deepEqual([err instanceof mod.TypedPressError, err.status, err.code], [true, 401, "unauthenticated"]);
  }
  assert.deepEqual(lines(), [
    'POST /__tp/api/v1/posts Bearer za_2 {"title":"x"}',
    "POST /identity/token - grant_type=refresh_token&refresh_token=zr_2&client_id=",
    'POST /__tp/api/v1/posts Bearer za_3 {"title":"x"}',
    'POST /__tp/api/v1/posts Bearer za_3 {"title":"y"}',
    "POST /identity/token - grant_type=refresh_token&refresh_token=zr_3&client_id=",
  ]);
  assert.equal(first.auth.session(), null);
});

test("guests: the first unauthenticated write starts a guest session and retries; reads don't", async () => {
  reply = (s) => {
    if (s.path === "/__tp/api/v1/auth/guest") return tokens(1, guest);
    return s.headers.authorization ? ok({ id: 1 })() : problem(401, "unauthenticated");
  };
  const first = mod.createClient({ url, auth: { guests: true } });

  seen = [];
  assert.equal((await first.posts.list().catch((e: unknown) => e)).code, "unauthenticated");
  assert.deepEqual(await first.posts.create({ title: "Hi" }), { id: 1 });
  await first.posts.create({ title: "Again" });
  assert.deepEqual(lines(), [
    "GET /__tp/api/v1/posts -",
    'POST /__tp/api/v1/posts - {"title":"Hi"}',
    "POST /__tp/api/v1/auth/guest - {}",
    'POST /__tp/api/v1/posts Bearer za_1 {"title":"Hi"}',
    'POST /__tp/api/v1/posts Bearer za_1 {"title":"Again"}',
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
  const first = mod.createClient({ url: "http://x/__tp/api/v1", fetch, auth: { guests: true } });
  // Microtask turns only (no timers); a bounded count so a regression fails instead of hanging.
  const until = async (n: number) => {
    for (let turn = 0; pending.length < n; turn++) {
      if (turn > 10_000) throw new Error(`expected ${n} requests, saw ${pending.map((p) => p.url).join(", ")}`);
      await Promise.resolve();
    }
  };
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const denied = () => json(401, { type: "about:blank", title: "Unauthorized", status: 401, code: "unauthenticated", detail: "no" });

  const a = first.posts.create({ title: "a" });
  const b = first.posts.create({ title: "b" });
  await until(2);
  pending[0].release(denied());                       // a's 401 starts the guest
  await until(3);
  assert.equal(pending[2].url, "http://x/__tp/api/v1/auth/guest");
  pending[2].release(json(201, { data: { accessToken: "za_g", refreshToken: "zr_g", expiresIn: 300, user: { id: 9, name: "Guest", role: "guest" } } }));
  await until(4);                                      // a retries with the guest
  pending[1].release(denied());                        // b's 401 arrives after the guest exists
  await until(5);
  assert.equal(pending[4].url, "http://x/__tp/api/v1/posts", "b retries with the existing guest instead of starting another");
  pending[3].release(json(201, { data: { id: 1 } }));
  pending[4].release(json(201, { data: { id: 2 } }));
  assert.deepEqual([await a, await b], [{ id: 1 }, { id: 2 }]);
  assert.equal(pending.filter((p) => p.url.endsWith("/auth/guest")).length, 1, "one guest");
  assert.deepEqual([pending[3], pending[4]].map((p) => (p.init.headers as Record<string, string>).authorization), ["Bearer za_g", "Bearer za_g"]);
});

test("a 401 or refresh that outlives a sign-in never acts as the new user", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const first = mod.createClient({ url: "http://x/__tp/api/v1", fetch, auth: { issuer: "http://x/identity" } });
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
  const loginA = first.auth.guest();
  await until(1);
  pending[0].release(issued("a"));
  await loginA;
  const draft = first.posts.create({ title: "A's private draft" }); // sent as A
  await until(2);
  assert.equal(auth(1), "Bearer za_a");
  const loginB = first.auth.guest();                               // B signs in on the same client
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
  const other = first.posts.create({ title: "x" });
  await until(4);
  pending[3].release(denied());
  await until(5);
  assert.equal(pending[4].url, "http://x/identity/token");
  const out = first.auth.signOut();
  await until(6);
  assert.equal(pending[5].url, "http://x/identity/revoke");
  pending[5].release(json(200, {}));
  await out;
  pending[4].release(new Response(rotated("b2").body, { status: 200, headers: { "content-type": "application/json" } }));
  await other.catch(() => undefined);
  assert.equal(first.auth.session(), null, "the late refresh was dropped");
});

test("two clients sharing sessionStorage: one's late 401, guest or sign-out never acts across the other's sign-in", async () => {
  const browser = stubBrowser();
  try {
    const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
    const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
    const opts = { url: "http://x/__tp/api/v1", fetch, auth: { storage: "session" as const, guests: true, issuer: "http://x/identity" } };
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
  const first = mod.createClient({ url: "http://x/__tp/api/v1", fetch, auth: { issuer: "http://x/identity" } });
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

  const a = first.auth.guest();
  await until(1);
  pending[0].release(issued("a"));
  await a;
  const wa = first.posts.create({ title: "a" });             // 1
  await until(2);
  pending[1].release(denied());
  await until(3);                                           // 2: A's refresh, left pending
  const b = first.auth.guest();                              // 3
  await until(4);
  pending[3].release(issued("b"));
  await b;
  const wb = first.posts.create({ title: "b" });             // 4
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
  assert.equal(first.auth.session()?.accessToken, "za_b2");
});

test("guest(), signOut(), guest(): the second call starts its own guest", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const first = mod.createClient({ url: "http://x/__tp/api/v1", fetch });
  const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });
  const issued = (n: string) => json(200, { data: { accessToken: `za_${n}`, refreshToken: `zr_${n}`, expiresIn: 300, user: { id: 1, name: n, role: "guest" } } });
  const g1 = first.auth.guest();
  await first.auth.signOut();
  const g2 = first.auth.guest();
  for (let turn = 0; pending.length < 2 && turn < 10_000; turn++) await Promise.resolve();
  assert.equal(pending.length, 2, "two guest requests");
  pending[0].release(issued("g1"));
  pending[1].release(issued("g2"));
  await g1.catch(() => undefined);
  assert.equal((await g2).name, "g2");
  assert.equal(first.auth.session()?.accessToken, "za_g2");
});

test("signing out while anonymous still ends pending sign-ins; an old anonymous write never borrows a newer guest", async () => {
  const pending: { url: string; init: RequestInit; release: (r: Response) => void }[] = [];
  const fetch = (url: string, init: RequestInit) => new Promise<Response>((release) => pending.push({ url, init, release }));
  const first = mod.createClient({ url: "http://x/__tp/api/v1", fetch, auth: { guests: true, issuer: "http://x/identity" } });
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
  const started = first.auth.guest();
  await until(1);
  await first.auth.signOut();
  pending[0].release(issued("late-guest"));
  await settle(started);
  assert.equal(first.auth.session(), null, "still signed out");

  // An anonymous write from before a sign-in/sign-out doesn't retry as the guest that came after.
  const old = first.posts.create({ title: "old" });          // request 1, anonymous
  await until(2);
  const fresh = first.auth.guest();                          // request 2: a guest from a later generation
  await until(3);
  pending[2].release(issued("guest-a"));
  await fresh;
  const out = first.auth.signOut();                          // request 3: revoke guest-a at the issuer
  await until(4);
  assert.equal(pending[3].url, "http://x/identity/revoke");
  pending[3].release(json(200, {}));
  await out;
  const newer = first.auth.guest();                          // request 4: yet another guest
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
    reply = (s) => (s.path === "/__tp/api/v1/auth/config" ? ok({ issuer: `${origin}/identity`, guests: true })() : problem(404, "not_found"));
    const first = mod.createClient({ url: `${origin}/__tp/api/v1`, auth: { clientId: "app_blog", redirectUri: "https://blog.test/callback" } });
    void first.auth.signIn({ returnTo: "/drafts" });
    const to = new URL(await browser.navigated);

    // No issuer option: it comes from /auth/config.
    assert.deepEqual(lines(), ["GET /__tp/api/v1/auth/config -"]);
    assert.equal(`${to.origin}${to.pathname}`, `${origin}/identity/authorize`);
    const pkce = JSON.parse(browser.store.get("typedpress.auth.pkce") ?? "{}");
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
  const base = `${origin}/__tp/api/v1`;
  const pkce = JSON.stringify({ verifier: "v".repeat(86), state: "s".repeat(43), nonce: "n".repeat(43), returnTo: "/drafts" });
  const member = { id: 3, name: "ann", role: "subscriber", member: { id: 3, email: "ann@x.test" } };
  reply = (s) => {
    if (s.path === "/identity/token") return { status: 200, body: JSON.stringify({ access_token: "at_1", refresh_token: "rt_1", expires_in: 3600, id_token: "h.p.s", token_type: "Bearer" }) };
    if (s.path === "/identity/revoke") return { status: 200, body: "{}" };
    return ok(member)();
  };

  const forged = stubBrowser("?code=c0de&state=other");
  try {
    forged.store.set("typedpress.auth.pkce", pkce);
    seen = [];
    const err = await mod.createClient({ url: base, auth }).auth.handleCallback().catch((e: unknown) => e);
    assert.deepEqual([err instanceof mod.TypedPressError, err.code], [true, "invalid_state"]);
    assert.deepEqual(seen, []);
  } finally {
    forged.restore();
  }

  const browser = stubBrowser(`?code=c0de&state=${"s".repeat(43)}`);
  try {
    browser.store.set("typedpress.auth.pkce", pkce);
    const first = mod.createClient({ url: base, auth });
    seen = [];
    assert.deepEqual(await first.auth.handleCallback(), member);
    assert.equal(await browser.navigated, "https://blog.test/drafts", "back to signIn's returnTo");
    assert.deepEqual([seen[0].path, seen[0].headers["content-type"], seen[0].headers.authorization], ["/identity/token", "application/x-www-form-urlencoded", undefined]);
    assert.deepEqual(form(seen[0]), {
      grant_type: "authorization_code",
      code: "c0de",
      redirect_uri: "https://blog.test/callback",
      client_id: "app_blog",
      code_verifier: "v".repeat(86),
    });
    assert.deepEqual([seen[1].path, seen[1].headers.authorization], ["/__tp/api/v1/me", "Bearer at_1"]);
    assert.equal(browser.store.has("typedpress.auth.pkce"), false);
    const kept = JSON.parse(browser.store.get(`typedpress.auth:${base}`) ?? "{}");
    assert.deepEqual({ ...kept, expiresAt: typeof kept.expiresAt, sid: typeof kept.sid }, { accessToken: "at_1", refreshToken: "rt_1", expiresAt: "number", clientId: "app_blog", idToken: "h.p.s", sid: "string" });

    seen = [];
    await first.auth.signOut({ returnTo: "https://blog.test/" });
    assert.deepEqual([seen.length, seen[0].path, form(seen[0])], [1, "/identity/revoke", { token: "rt_1", client_id: "app_blog" }]);
    // Signed out, the record keeps only the session generation (no tokens).
    assert.deepEqual(Object.keys(JSON.parse(browser.store.get(`typedpress.auth:${base}`) ?? "{}")), ["sid"]);
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
  const base = `${origin}/__tp/api/v1`;
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
      browser.store.set("typedpress.auth.pkce", JSON.stringify({ verifier: "v".repeat(86), state: "s".repeat(43), nonce: "n".repeat(43), returnTo }));
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
    const base = `${origin}/__tp/api/v1`;
    const first = mod.createClient({ url: base, auth: { issuer: `${origin}/identity`, clientId: "app_blog", redirectUri: "https://blog.test/callback" } });
    reply = (s) => {
      if (s.path === "/__tp/api/v1/auth/guest") return tokens(1, { id: 9, name: "Guest", role: "guest" }, { clientId: "app_zero_guest" });
      if (s.path === "/identity/token") return { status: 200, body: JSON.stringify({ access_token: "at_2", refresh_token: "rt_2", expires_in: 3600 }) };
      if (s.path === "/identity/handoff") return { status: 200, body: JSON.stringify({ handoff: "h1.secret" }) };
      return s.headers.authorization === "Bearer at_2" ? ok({ id: 1 })() : problem(401, "unauthenticated");
    };
    assert.equal((await first.auth.guest()).role, "guest");

    seen = [];
    await first.posts.create({ title: "Hi" });
    assert.deepEqual(
      seen.map((s) => [s.path, s.headers.authorization ?? "-"]),
      [["/__tp/api/v1/posts", "Bearer za_1"], ["/identity/token", "-"], ["/__tp/api/v1/posts", "Bearer at_2"]],
    );
    assert.deepEqual(form(seen[1]), { grant_type: "refresh_token", refresh_token: "zr_1", client_id: "app_zero_guest" });

    seen = [];
    void first.auth.upgrade();
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

/**
 * The subscription endpoint, in memory: each read's answer comes from `rows(query)`, and its token
 * is the JSON of that answer, so "not modified" is exactly "the same answer as last time".
 */
function liveServer(rows: (query: any) => unknown, poll = { minMs: 2000, maxMs: 30000 }) {
  const state = { requests: [] as any[][], fail: null as null | ReturnType<typeof problem>, errors: new Map<string, ReturnType<typeof problem>>() };
  reply = (s) => {
    if (s.path !== "/__tp/api/v1/_live") return ok(rows({ kind: s.path.split("/").pop(), op: "http", method: s.method }))();
    if (state.fail) return state.fail;
    const queries = JSON.parse(s.body.toString()).queries as any[];
    state.requests.push(queries);
    const results = queries.map((q) => {
      const refused = state.errors.get(q.kind);
      if (refused) return { error: JSON.parse(refused.body) };
      const data = rows(q);
      const token = JSON.stringify(data);
      return q.token === token ? { changed: false, token } : { changed: true, token, data };
    });
    return ok({ results, poll })();
  };
  return state;
}

test("subscribe asks the subscription endpoint once, then sends back the token and hears only changes", async () => {
  seen = [];
  let title = "v1";
  const server = liveServer(() => [{ id: 1, title }]);
  const transport = fakeTransport();
  const first = mod.createClient({ url, realtime: { transport, pollMs: false, livePollMs: false } });
  const got: unknown[] = [];
  const query = { where: { title: { like: "%v%" } }, orderBy: "-id", status: "draft", with: { author: true } };
  const stop = first.posts.list(query).subscribe((rows: unknown) => got.push(rows));
  await tick();
  assert.equal(transport.connects, 1, "the first subscriber connects the transport");
  assert.deepEqual(got, [[{ id: 1, title: "v1" }]], "the first answer");
  assert.deepEqual([last().method, last().path], ["POST", "/__tp/api/v1/_live"]);
  assert.deepEqual(server.requests[0], [{
    kind: "posts", op: "list",
    args: { where: { AND: [{ title: { like: "%v%" } }, { status: "draft" }] }, orderBy: { id: "desc" }, with: { author: true } },
  }], "the read as the kind query API's call, without a token the first time");

  transport.set("live");
  await tick();
  assert.equal(server.requests.length, 2, "going live asks once to catch up");
  assert.equal(server.requests[1][0].token, JSON.stringify([{ id: 1, title: "v1" }]), "with the token of the last answer");
  assert.equal(got.length, 1, "not modified: the callback is not called");

  title = "v2";
  transport.push({ ...notice("posts"), changes: [{ collection: "posts", ops: ["update"], ids: [1], row: { title: "pushed" } }] });
  await tick();
  assert.deepEqual(got.at(-1), [{ id: 1, title: "v2" }], "the result is the server's answer, never the pushed payload");

  transport.push(notice("authors"));
  await tick();
  assert.equal(server.requests.length, 4, "a change to a kind loaded through `with` asks too");
  transport.push(notice("shoppingList"));
  await tick();
  assert.equal(server.requests.length, 4, "an unrelated kind does not");

  stop();
  assert.equal(transport.stops, 1, "the last subscriber disconnects");
  transport.push(notice("posts"));
  await tick();
  assert.equal(server.requests.length, 4, "nothing after it ends");
});

test("every subscription of a client travels in one request; the same read is asked for once", async () => {
  const server = liveServer((q) => (q.op === "count" ? 3 : q.op === "get" ? { id: q.id } : q.op === "paginate" ? { rows: [], cursor: null } : [q.kind]));
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const got: Record<string, unknown[]> = { a: [], b: [], count: [], get: [], page: [] };
  const stops = [
    first.posts.list({ limit: 5 }).subscribe((d: unknown) => got.a.push(d)),
    first.posts.list({ limit: 5 }).subscribe((d: unknown) => got.b.push(d)),
    first.authors.count({ where: { name: "x" } }).subscribe((d: unknown) => got.count.push(d)),
    first.posts.get(7).subscribe((d: unknown) => got.get.push(d)),
    first.shoppingList.paginate({ limit: 2, cursor: "c1" }).subscribe((d: unknown) => got.page.push(d)),
  ];
  await tick();
  assert.equal(server.requests.length, 1, "one request for everything subscribed in the same turn");
  assert.deepEqual(server.requests[0], [
    { kind: "posts", op: "list", args: { limit: 5 } },
    { kind: "authors", op: "count", args: { where: { name: "x" } } },
    { kind: "posts", op: "get", id: 7, args: {} },
    { kind: "shoppingList", op: "paginate", args: { limit: 2, cursor: "c1" } },
  ]);
  assert.deepEqual(got, { a: [["posts"]], b: [["posts"]], count: [3], get: [{ id: 7 }], page: [{ rows: [], cursor: null }] });
  assert.equal(first.posts.list({ limit: 5 }).key, first.posts.list({ limit: 5 }).key, "the same call has the same key");
  assert.notEqual(first.posts.list({ limit: 5 }).key, first.posts.list({ limit: 6 }).key);

  // A later subscriber of a read already held gets what is held, at once.
  const late: unknown[] = [];
  stops.push(first.posts.list({ limit: 5 }).subscribe((d: unknown) => late.push(d)));
  await Promise.resolve();
  assert.deepEqual(late, [["posts"]]);
  for (const stop of stops) stop();

  // More than the endpoint's 20 per request goes out as several.
  const many = Array.from({ length: 25 }, (_, i) => first.posts.list({ limit: i + 1 }).subscribe(() => {}));
  server.requests.length = 0;
  await tick();
  assert.deepEqual(server.requests.map((r) => r.length), [20, 5]);
  for (const stop of many) stop();
});

test("awaiting a read is one plain request; nothing is sent until it is awaited or subscribed", async () => {
  seen = [];
  liveServer((q) => (q.op === "count" ? 4 : [{ id: 1 }]));
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const read = first.posts.list({ limit: 1 });
  await tick();
  assert.equal(seen.length, 0, "creating a read sends nothing");
  assert.deepEqual(await read, [{ id: 1 }]);
  assert.deepEqual([last().method, last().path, last().query.get("limit")], ["GET", "/__tp/api/v1/posts", "1"]);
  assert.deepEqual(await read, [{ id: 1 }]);
  assert.equal(seen.length, 1, "awaited twice, sent once");
  assert.equal(await first.posts.count({ where: { title: "x" } }), 4);
  assert.deepEqual(JSON.parse(last().body.toString()), { queries: [{ kind: "posts", op: "count", args: { where: { title: "x" } } }] }, "count has no route of its own: one answer from the subscription endpoint");
  assert.equal(await first.posts.list().then((rows: unknown[]) => rows.length).catch(() => -1).finally(() => {}), 1, "then, catch and finally chain");
});

test("a burst of notices is one more request; a refused read reports its problem and keeps going", async () => {
  let release: () => void = () => {};
  const gate = new Promise<void>((r) => (release = r));
  let calls = 0;
  const server = liveServer(() => []);
  const slowFetch: typeof fetch = async (input, init) => {
    calls++;
    if (calls === 2) await gate;
    return fetch(input, init);
  };
  const transport = fakeTransport();
  const first = mod.createClient({ url, fetch: slowFetch, realtime: { transport, pollMs: false, livePollMs: false } });
  const stop = first.posts.watch(undefined, () => {});
  await tick();
  transport.push(notice("posts"));
  transport.push(notice("posts"));
  await tick();
  assert.equal(calls, 2, "notices in the same turn are one request");
  transport.push(notice("posts"));
  transport.push(notice("posts"));
  transport.push(notice("posts"));
  await tick();
  release();
  await tick();
  assert.equal(calls, 3, "and a burst while that one is out is exactly one more");
  stop();

  // One read refused, one answered, in the same request.
  server.errors.set("posts", problem(403, "forbidden"));
  const client = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const errors: string[] = [];
  const rows: unknown[] = [];
  const stops = [
    client.posts.watch(undefined, () => rows.push("posts"), (e: { code: string; status: number }) => errors.push(`${e.status} ${e.code}`)),
    client.authors.list().subscribe((d: unknown) => rows.push(d)),
  ];
  await tick();
  assert.deepEqual([errors, rows], [["403 forbidden"], [[]]], "the refusal is that read's alone");
  await client.actions.approveComment({ id: 1 });
  await tick();
  assert.deepEqual(errors, ["403 forbidden"], "a read that stays refused reports it once, not on every answer");
  // The whole request failing reaches every subscriber's onError; the subscriptions stay.
  server.errors.clear();
  server.fail = problem(503, "unavailable");
  await client.actions.approveComment({ id: 1 }); // asks for every read again
  await tick();
  assert.deepEqual(errors, ["403 forbidden", "503 unavailable"]);
  for (const s of stops) s();

  // A `get` whose row is missing or unreadable is the API's 404, as when awaited.
  server.fail = null;
  liveServer(() => null);
  const missing: string[] = [];
  const stop3 = client.posts.get(404).subscribe(() => missing.push("data"), (e: { status: number; code: string }) => missing.push(`${e.status} ${e.code}`));
  await tick();
  assert.deepEqual(missing, ["404 not_found"]);
  stop3();
});

test("without a transport the poll gap grows while nothing changes, restarts on a change, and is jittered", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  let random = 0.5; // no jitter: 0.8 + 0.5 × 0.4 = 1
  t.mock.method(Math, "random", () => random);
  let value = 1;
  const asked: number[] = [];
  let now = 0;
  const first = mod.createClient({
    url,
    fetch: async (_input: unknown, init: { body: string }) => {
      asked.push(now);
      const q = JSON.parse(init.body).queries[0];
      const token = String(value);
      return new Response(JSON.stringify({ data: { results: [q.token === token ? { changed: false, token } : { changed: true, token, data: value }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
    },
    realtime: { transport: false },
  });
  const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
  };
  const advance = async (ms: number) => {
    now += ms;
    t.mock.timers.tick(ms);
    await settle();
  };
  const stop = first.posts.count().subscribe(() => {});
  await settle();
  assert.deepEqual(asked, [0], "the first answer, at once");
  // The first answer was a change, so the gap starts at the server's minimum and grows by half.
  const gaps: number[] = [];
  for (let i = 0; i < 9; i++) {
    const before = asked.length;
    let waited = 0;
    while (asked.length === before && waited < 60_000) {
      await advance(250);
      waited += 250;
    }
    gaps.push(waited);
  }
  assert.deepEqual(gaps, [2000, 3000, 4500, 6750, 10250, 15250, 23000, 30000, 30000], "2 s, then half as long again each time, up to 30 s");

  value = 2; // someone wrote
  await advance(30_000);
  const at = asked.length;
  await advance(1999);
  assert.equal(asked.length, at, "after a change the gap is the minimum again");
  await advance(1);
  assert.equal(asked.length, at + 1);

  // Jitter: the same gap, 20% either way.
  random = 0; // × 0.8
  await advance(3000); // this poll schedules the next with the new factor
  const base = asked.length;
  await advance(3599);
  assert.equal(asked.length, base, "4.5 s × 0.8 = 3.6 s, not sooner");
  await advance(1);
  assert.equal(asked.length, base + 1);
  stop();
  await advance(120_000);
  assert.equal(asked.length, base + 1, "nothing after the last subscriber ends");
});

test("pollMs sets the gap: a number is a fixed interval, { min, max } the bounds, false turns polling off; errors back off", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  t.mock.method(Math, "random", () => 0.5);
  const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
  };
  let fail = false;
  const counting = (asked: number[]) => async (_input: unknown, init: { body: string }) => {
    asked.push(1);
    if (fail) return new Response(JSON.stringify({ type: "about:blank", title: "x", status: 503, code: "unavailable", detail: "x" }), { status: 503 });
    const q = JSON.parse(init.body).queries[0];
    return new Response(JSON.stringify({ data: { results: [q.token ? { changed: false, token: "t" } : { changed: true, token: "t", data: 1 }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
  };
  const run = async (pollMs: unknown, ticks: number[]) => {
    const asked: number[] = [];
    const first = mod.createClient({ url, fetch: counting(asked), realtime: { transport: false, pollMs } });
    const stop = first.posts.count().subscribe(() => {}, () => {});
    await settle();
    const counts = [];
    for (const ms of ticks) {
      t.mock.timers.tick(ms);
      await settle();
      counts.push(asked.length);
    }
    stop();
    return counts;
  };
  assert.deepEqual(await run(1000, [999, 1, 1000, 1000]), [1, 2, 3, 4], "a number: every second, however quiet it is");
  assert.deepEqual(await run({ min: 500, max: 600 }, [499, 1, 599, 1, 600]), [1, 2, 2, 3, 4], "bounds: 500 ms, then capped at 600 ms");
  assert.deepEqual(await run(false, [600_000]), [1], "false: the first answer only");
  fail = true;
  assert.deepEqual(await run(undefined, [3999, 1, 7999, 1]), [1, 2, 2, 3], "a failing server is asked half as often each time: 4 s, 8 s");
  fail = false;
});

test("a hidden tab does not poll, and asks once when it is shown again", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  t.mock.method(Math, "random", () => 0.5);
  const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
  };
  const listeners = new Set<() => void>();
  const page = { visibilityState: "visible", addEventListener: (_: string, fn: () => void) => void listeners.add(fn), removeEventListener: (_: string, fn: () => void) => void listeners.delete(fn) };
  const saved = Object.getOwnPropertyDescriptor(globalThis, "document");
  Object.defineProperty(globalThis, "document", { configurable: true, value: page });
  try {
    let asked = 0;
    const transport = fakeTransport();
    const first = mod.createClient({
      url,
      fetch: async () => (asked++, new Response(JSON.stringify({ data: { results: [{ changed: false, token: "t" }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 })),
      realtime: { transport },
    });
    const stop = first.posts.list().subscribe(() => {});
    await settle();
    assert.equal(asked, 1);
    page.visibilityState = "hidden";
    for (const fn of [...listeners]) fn();
    t.mock.timers.tick(600_000);
    await settle();
    assert.equal(asked, 1, "ten minutes hidden: nothing asked");
    transport.set("live");
    await settle();
    transport.push(notice("posts"));
    await settle();
    assert.equal(asked, 1, "a reconnect and a notice to a hidden tab wait");
    page.visibilityState = "visible";
    for (const fn of [...listeners]) fn();
    await settle();
    assert.equal(asked, 2, "shown again: asked once, for everything that waited");
    stop();
    assert.equal(listeners.size, 0, "the last subscriber removes the listener");
  } finally {
    if (saved) Object.defineProperty(globalThis, "document", saved);
    else delete (globalThis as Record<string, unknown>).document;
  }
});

test("a write through the client asks for the reads of that kind at once", async () => {
  const rows: Record<string, string[]> = { posts: ["a"], authors: ["x"] };
  const server = liveServer((q) => (q.op === "http" ? (q.method === "POST" ? { id: 9 } : rows[q.kind]) : rows[q.kind]));
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const posts: unknown[] = [];
  const stops = [first.posts.list().subscribe((d: unknown) => posts.push(d)), first.authors.list().subscribe(() => {})];
  await tick();
  server.requests.length = 0;
  rows.posts = ["a", "b"];
  await first.posts.create({ title: "b" });
  await tick();
  assert.deepEqual(server.requests.map((r) => r.map((q) => q.kind)), [["posts"]], "only the reads of the written kind");
  assert.deepEqual(posts.at(-1), ["a", "b"]);
  server.requests.length = 0;
  await first.actions.approveComment({ id: 1 });
  await tick();
  assert.deepEqual(server.requests.map((r) => r.map((q) => q.kind)), [["posts", "authors"]], "an action may write anything: every read is asked for");
  for (const stop of stops) stop();
});

test("optimistic updates show at once, roll back on failure, and give way to the server's answer", async () => {
  let rows = [{ id: 1, title: "one" }];
  const server = liveServer(() => rows);
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const got: { id: number; title: string }[][] = [];
  const read = first.posts.list();
  const stop = read.subscribe((d: { id: number; title: string }[]) => got.push(d));
  await tick();
  assert.deepEqual(got, [[{ id: 1, title: "one" }]]);

  // A write that fails: shown at once, then taken back, with no request.
  const failed = read.optimistic((current: { id: number; title: string }[]) => [...current, { id: -1, title: "pending" }]);
  assert.deepEqual(got.at(-1)?.map((r) => r.title), ["one", "pending"], "shown before any answer");
  const before = server.requests.length;
  failed.rollback();
  assert.deepEqual(got.at(-1)?.map((r) => r.title), ["one"], "rolled back to the server's data");
  assert.equal(server.requests.length, before);
  failed.rollback();
  assert.equal(got.length, 3, "rolling back twice does nothing more");

  // A write that succeeds: the update stays until the server's next answer replaces it.
  const done = read.optimistic((current: { id: number; title: string }[]) => [...current, { id: -2, title: "two (pending)" }]);
  rows = [...rows, { id: 2, title: "two" }];
  done.settle();
  await tick();
  assert.deepEqual(got.at(-1), [{ id: 1, title: "one" }, { id: 2, title: "two" }], "the server's answer, with the pending row gone");
  assert.ok(!got.some((g, i) => i > 3 && g.length === 1), "never a frame without the new row in between");

  // Two at once apply in order, over whatever the server answers meanwhile.
  const a = read.optimistic((current: { id: number; title: string }[]) => current.map((r) => (r.id === 1 ? { ...r, title: "ONE" } : r)));
  const b = read.optimistic((current: { id: number; title: string }[]) => current.filter((r) => r.id !== 2));
  assert.deepEqual(got.at(-1), [{ id: 1, title: "ONE" }]);
  a.rollback();
  assert.deepEqual(got.at(-1), [{ id: 1, title: "one" }], "the other update still applies");
  b.rollback();
  assert.deepEqual(got.at(-1), [{ id: 1, title: "one" }, { id: 2, title: "two" }]);

  // A write through the client: its own refresh is the answer that replaces the update. The row
  // is never shown twice, and settling costs no request of its own.
  rows = [{ id: 1, title: "one" }];
  const client = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const frames: string[][] = [];
  const mine = client.posts.list();
  const stop2 = mine.subscribe((d: { title: string }[]) => frames.push(d.map((r) => r.title)));
  await tick();
  const saving = mine.optimistic((current: { id: number; title: string }[]) => [...current, { id: -3, title: "three (saving)" }]);
  server.requests.length = 0;
  rows = [...rows, { id: 3, title: "three" }];
  await client.posts.create({ title: "three" });
  saving.settle();
  await tick();
  assert.deepEqual(frames, [["one"], ["one", "three (saving)"], ["one", "three"]], "saving, then saved: no frame with both or neither");
  assert.equal(server.requests.length, 1, "one request: the write's own refresh");
  // Settled late, after that refresh was already shown: dropped at once, with no request.
  const late = mine.optimistic((current: { id: number; title: string }[]) => [...current, { id: -4, title: "four (saving)" }]);
  rows = [...rows, { id: 4, title: "four" }];
  await client.posts.create({ title: "four" });
  await tick();
  assert.deepEqual(frames.at(-1), ["one", "three", "four", "four (saving)"], "not settled yet: the update is still applied");
  late.settle();
  assert.deepEqual(frames.at(-1), ["one", "three", "four"]);
  assert.equal(server.requests.length, 2);
  stop2();

  // On a read nobody is subscribed to there is nothing to show: it does nothing.
  const none = first.authors.list().optimistic(() => []);
  none.settle();
  none.rollback();
  stop();
});

test("a server-rendered answer seeds a subscription: its data at once, its token on the first request", async () => {
  const server = liveServer(() => ["fresh"]);
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const got: unknown[] = [];
  const stop = first.posts.list().subscribe((d: unknown) => got.push(d), undefined, { token: JSON.stringify(["fresh"]), data: ["fresh"] });
  await tick();
  assert.deepEqual(got, [["fresh"]], "delivered once, from the seed");
  assert.equal(server.requests[0][0].token, JSON.stringify(["fresh"]), "and the first request is not modified");
  stop();
});

test("other requests don't restart the interval poll, so every subscription is still asked", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  t.mock.method(Math, "random", () => 0.5);
  const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
  };
  const asked: string[][] = [];
  const first = mod.createClient({
    url,
    fetch: async (_input: unknown, init: { body: string }) => {
      const queries = JSON.parse(init.body).queries;
      asked.push(queries.map((q: { kind: string }) => q.kind));
      return new Response(JSON.stringify({ data: { results: queries.map(() => ({ changed: false, token: "t" })), poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
    },
    realtime: { transport: false, pollMs: 1000 },
  });
  const stop = first.posts.list().subscribe(() => {});
  await settle();
  // Another component subscribes and unsubscribes every 400 ms: each one asks at once, for itself.
  for (let i = 0; i < 6; i++) {
    const other = first.authors.list({ limit: i + 1 }).subscribe(() => {});
    await settle();
    other();
    t.mock.timers.tick(400);
    await settle();
  }
  assert.ok(asked.filter((kinds) => kinds.includes("posts")).length >= 3, `posts asked on the interval: ${JSON.stringify(asked)}`);
  stop();
});

test("other requests whose answers change still don't push back the poll that asks for every read", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  t.mock.method(Math, "random", () => 0.5);
  const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
  };
  const asked: string[][] = [];
  let n = 0;
  const first = mod.createClient({
    url,
    // Every answer changes, as a new subscriber's first answer always does.
    fetch: async (_input: unknown, init: { body: string }) => {
      const queries = JSON.parse(init.body).queries;
      asked.push(queries.map((q: { kind: string }) => q.kind));
      return new Response(JSON.stringify({ data: { results: queries.map(() => ({ changed: true, token: `t${++n}`, data: [n] })), poll: { minMs: 300, maxMs: 300 } } }), { status: 200 });
    },
    realtime: { transport: false },
  });
  const stop = first.posts.list().subscribe(() => {});
  await settle();
  for (let i = 0; i < 20; i++) {
    const other = first.authors.list({ limit: i + 1 }).subscribe(() => {});
    await settle();
    other();
    t.mock.timers.tick(150);
    await settle();
  }
  assert.ok(asked.filter((kinds) => kinds.includes("posts")).length >= 8, `posts asked on the interval while others change: ${asked.filter((k) => k.includes("posts")).length}`);
  stop();
});

test("while live, steady notices for one kind don't stop the safety poll from asking the others", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  t.mock.method(Math, "random", () => 0.5);
  const settle = async () => {
    for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
  };
  const asked: string[][] = [];
  let n = 0;
  const transport = fakeTransport();
  const first = mod.createClient({
    url,
    fetch: async (_input: unknown, init: { body: string }) => {
      const queries = JSON.parse(init.body).queries;
      asked.push(queries.map((q: { kind: string }) => q.kind));
      return new Response(JSON.stringify({ data: { results: queries.map(() => ({ changed: true, token: `t${++n}`, data: [n] })), poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
    },
    realtime: { transport, livePollMs: 400 },
  });
  const stops = [first.posts.list().subscribe(() => {}), first.authors.list().subscribe(() => {})];
  await settle();
  transport.set("live");
  await settle();
  asked.length = 0;
  for (let i = 0; i < 15; i++) {
    transport.push(notice("authors"));
    await settle();
    t.mock.timers.tick(200);
    await settle();
  }
  assert.ok(asked.some((kinds) => kinds.includes("posts")), `the safety poll reached posts: ${JSON.stringify(asked.slice(0, 6))}`);
  for (const s of stops) s();
});

test("an error handler that throws neither fails the batch nor stops the batches after it", async (t) => {
  const reported: unknown[] = [];
  const saved = Object.getOwnPropertyDescriptor(globalThis, "reportError");
  Object.defineProperty(globalThis, "reportError", { configurable: true, value: (e: unknown) => reported.push(e) });
  t.after(() => {
    if (saved) Object.defineProperty(globalThis, "reportError", saved);
    else delete (globalThis as Record<string, unknown>).reportError;
  });
  let call = 0;
  const first = mod.createClient({
    url,
    // 21 reads: two batches. The first fails; its subscriber's onError throws.
    fetch: async (_input: unknown, init: { body: string }) => {
      const queries = JSON.parse(init.body).queries;
      if (++call === 1) return new Response(JSON.stringify({ type: "about:blank", title: "x", status: 503, code: "unavailable", detail: "x" }), { status: 503 });
      return new Response(JSON.stringify({ data: { results: queries.map(() => ({ changed: true, token: "t", data: ["ok"] })), poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
    },
    realtime: { transport: false, pollMs: false },
  });
  const got: unknown[] = [];
  const stops = Array.from({ length: 20 }, (_, i) =>
    first.posts.list({ limit: i + 1 }).subscribe(() => {}, () => {
      throw new Error("onError failed");
    }),
  );
  stops.push(first.authors.list().subscribe((d: unknown) => got.push(d)));
  await tick();
  await tick();
  assert.equal(call, 2, "the second batch was still sent");
  assert.deepEqual(got, [["ok"]], "and its subscriber got its answer");
  assert.ok(reported.some((e) => e instanceof Error && e.message === "onError failed"), "the throwing handler was reported");
  for (const s of stops) s();
});

test("an answer to a request sent before a sign-out is dropped", async () => {
  let release: () => void = () => {};
  let hold: Promise<void> | null = null;
  let answer = "old visitor row";
  const server = liveServer(() => [answer]);
  // The server answers when the request arrives; only the answer's delivery is held.
  const slow: typeof fetch = async (input, init) => {
    const held = String(input).endsWith("/_live") ? hold : null;
    if (held) hold = null;
    const response = await fetch(input, init);
    if (held) await held;
    return response;
  };
  const first = mod.createClient({ url, fetch: slow, realtime: { transport: false, pollMs: false } });
  const got: unknown[] = [];
  const stop = first.posts.list().subscribe((d: unknown) => got.push(d));
  await tick();
  got.length = 0;
  // A refresh goes out as the signed-in visitor and is held; the visitor signs out meanwhile.
  answer = "old visitor row, after the write";
  hold = new Promise<void>((r) => (release = r));
  await first.actions.approveComment({ id: 1 });
  for (let i = 0; i < 100 && server.requests.length < 2; i++) await tick(); // the server has answered as the old visitor
  await first.auth.signOut();
  answer = "new visitor row";
  release();
  await tick();
  await tick();
  assert.deepEqual(got, [["new visitor row"]], `only the new visitor's answer is shown: ${JSON.stringify(got)}`);
  stop();
});

test("a sign-out by another client on the same session storage refreshes this client's subscriptions", async (t) => {
  const browser = stubBrowser();
  t.after(() => browser.restore());
  const server = liveServer(() => ["row"]);
  const a = mod.createClient({ url, auth: { storage: "session" }, realtime: { transport: false, pollMs: false } });
  const b = mod.createClient({ url, auth: { storage: "session" }, realtime: { transport: false, pollMs: false } });
  const stop = a.posts.list().subscribe(() => {});
  await tick();
  await tick();
  const token = server.requests[0][0].token;
  assert.equal(token, undefined, "the first request had no token");
  await a.actions.approveComment({ id: 1 }); // a second request, now with a token
  await tick();
  assert.equal(typeof server.requests.at(-1)![0].token, "string");
  await b.auth.signOut(); // the other client: a new session id in the shared storage
  await a.actions.approveComment({ id: 1 });
  await tick();
  assert.equal(server.requests.at(-1)![0].token, undefined, "this client asks again without the old visitor's token");
  stop();
});

test("a relation filter asking for rows with none of the related rows depends on that kind", async () => {
  const server = liveServer(() => []);
  const transport = fakeTransport();
  const first = mod.createClient({ url, realtime: { transport, pollMs: false, livePollMs: false } });
  const stop = first.authors.list({ where: { posts: [] } }).subscribe(() => {});
  await tick();
  transport.set("live");
  await tick();
  server.requests.length = 0;
  transport.push(notice("posts"));
  await tick();
  assert.deepEqual(server.requests.map((r) => r.map((q) => q.kind)), [["authors"]]);
  stop();
});

test("batches are sized in UTF-8 bytes, as the server counts its body", async () => {
  const server = liveServer(() => []);
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  // 1,500 characters of CJK text: 4,500 bytes each in UTF-8, 1,500 in UTF-16.
  const wide = "漢".repeat(1500);
  const stops = Array.from({ length: 10 }, (_, i) => first.posts.list({ where: { title: `${wide}${i}` } }).subscribe(() => {}));
  await tick();
  for (const r of server.requests) assert.ok(new TextEncoder().encode(JSON.stringify({ queries: r })).length <= 32_768, `a request of ${new TextEncoder().encode(JSON.stringify({ queries: r })).length} bytes`);
  for (const s of stops) s();
});

test("a subscriber that throws doesn't fail the request for the others", async (t) => {
  let rows = ["p1"];
  liveServer((q) => (q.kind === "posts" ? rows : ["a"]));
  const reported: unknown[] = [];
  const saved = Object.getOwnPropertyDescriptor(globalThis, "reportError");
  Object.defineProperty(globalThis, "reportError", { configurable: true, value: (e: unknown) => reported.push(e) });
  t.after(() => {
    if (saved) Object.defineProperty(globalThis, "reportError", saved);
    else delete (globalThis as Record<string, unknown>).reportError;
  });
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const got: unknown[] = [];
  const errors: string[] = [];
  const stops = [
    first.authors.list().subscribe(() => {
      throw new Error("render failed");
    }),
    first.posts.list().subscribe((d: unknown) => got.push(d), (e: { code: string }) => errors.push(e.code)),
  ];
  await tick();
  rows = ["p1", "p2"];
  await first.actions.approveComment({ id: 1 }); // asks for every read again
  await tick();
  assert.deepEqual(got.at(-1), ["p1", "p2"], "the other read's answer is applied");
  assert.deepEqual(errors, [], "and it is not told the request failed");
  assert.ok(reported.some((e) => e instanceof Error && e.message === "render failed"), "the subscriber's own error is reported as uncaught");
  for (const s of stops) s();
});

test("an optimistic update is taken off when the write's refresh answers pending, then not modified", async () => {
  // The reviewed case, request by request: the poll that shows the saved row was answered before
  // the update was settled; the write's own refresh answers pending; the retry is "not modified".
  // Settling must drop the update on that pending answer, with nothing new to deliver after it.
  const token = (rows: string[]) => JSON.stringify(rows);
  const answers: unknown[] = [
    { changed: true, token: token(["one"]), data: ["one"] }, // the first answer
    { changed: true, token: token(["one", "two"]), data: ["one", "two"] }, // a poll: already saved
    { pending: true }, // the write's refresh
    { changed: false, token: token(["one", "two"]) }, // the retry
  ];
  const first = mod.createClient({
    url,
    fetch: async (input: unknown) => {
      if (!String(input).endsWith("/_live")) return new Response('{"data":{"id":2}}', { status: 201 });
      const result = answers.shift() ?? { changed: false, token: token(["one", "two"]) };
      return new Response(JSON.stringify({ data: { results: [result], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
    },
    realtime: { transport: false, pollMs: false },
  });
  const frames: string[][] = [];
  const read = first.posts.list();
  const stop = read.subscribe((d: string[]) => frames.push(d));
  await tick();
  const saving = read.optimistic((current: string[]) => [...current, "two (saving)"]);
  await first.actions.approveComment({ id: 1 }); // asks again: the poll that shows the saved row
  await tick();
  assert.deepEqual(frames.at(-1), ["one", "two", "two (saving)"], "not settled yet: the update still applies");
  await first.posts.create({ title: "two" }); // its refresh answers pending, then not modified
  saving.settle();
  await tick();
  await tick();
  assert.equal(answers.length, 0, "every scripted answer was used");
  assert.deepEqual(frames.at(-1), ["one", "two"], `the update is gone: ${JSON.stringify(frames)}`);
  stop();
});

test("settle() waits for an answer to this read, not to another read's request", async () => {
  let authors = ["ann"];
  let release: () => void = () => {};
  let hold: Promise<void> | null = null;
  liveServer((q) => (q.op === "http" ? { id: 9 } : q.kind === "authors" ? authors : ["p"]));
  // Holds the next subscription request only; writes and other calls go straight through.
  const slow: typeof fetch = async (input, init) => {
    const held = String(input).endsWith("/_live") ? hold : null;
    if (held) hold = null;
    if (held) await held;
    return fetch(input, init);
  };
  const first = mod.createClient({ url, fetch: slow, realtime: { transport: false, pollMs: false } });
  const frames: string[][] = [];
  const read = first.authors.list();
  const stops = [read.subscribe((d: string[]) => frames.push(d)), first.posts.list().subscribe(() => {})];
  await tick();
  const saving = read.optimistic((current: string[]) => [...current, "bob (saving)"]);
  authors = ["ann", "bob"];
  // A write to posts returns; its refresh asks for posts only, and that request is held open.
  hold = new Promise<void>((r) => (release = r));
  await first.posts.create({ title: "x" });
  await Promise.resolve();
  saving.settle();
  assert.deepEqual(frames.at(-1), ["ann", "bob (saving)"], "kept: the request that is out is for posts, not authors");
  release();
  await tick();
  await tick();
  assert.deepEqual(frames.at(-1), ["ann", "bob"], "authors is asked for, and its answer replaces the update");
  for (const s of stops) s();
});

test("an optimistic update made before the first answer applies to it", async () => {
  liveServer(() => ["a"]);
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const frames: string[][] = [];
  const read = first.posts.list();
  const stop = read.subscribe((d: string[]) => frames.push(d));
  const early = read.optimistic((current: string[]) => [...current, "b (saving)"]);
  await tick();
  assert.deepEqual(frames.at(-1), ["a", "b (saving)"]);
  early.rollback();
  assert.deepEqual(frames.at(-1), ["a"]);
  stop();
});

test("a subscription depends on the kinds its where filters through, and paginate sends no offset", async () => {
  const server = liveServer((q) => (q.op === "count" ? 1 : q.op === "paginate" ? { rows: [], cursor: null } : []));
  const transport = fakeTransport();
  const first = mod.createClient({ url, realtime: { transport, pollMs: false, livePollMs: false } });
  const stops = [
    first.posts.list({ where: { OR: [{ author: { name: "ann" } }] } }).subscribe(() => {}),
    first.comments.count({ where: { NOT: { post: { title: "x" } } } }).subscribe(() => {}),
    first.posts.paginate({ limit: 2, cursor: "c2" }).subscribe(() => {}),
  ];
  // A page with `offset` can be awaited, but subscribing to it is refused, not silently page one.
  assert.throws(() => first.posts.paginate({ limit: 2, offset: 4 }).subscribe(() => {}), /page by cursor/);
  assert.notEqual(first.posts.paginate({ limit: 2, offset: 0 }).key, first.posts.paginate({ limit: 2, offset: 4 }).key, "pages at two offsets are two reads");
  await tick();
  transport.set("live");
  await tick();
  assert.deepEqual(server.requests[0][2].args, { limit: 2, cursor: "c2" }, "a subscribed page is asked for by cursor");
  server.requests.length = 0;
  transport.push(notice("authors"));
  await tick();
  assert.deepEqual(server.requests.map((r) => r.map((q) => q.kind)), [["posts"]], "a notice for authors asks the read filtering through author");
  server.requests.length = 0;
  transport.push(notice("posts"));
  await tick();
  assert.ok(server.requests[0].some((q) => q.kind === "comments"), "and one for posts asks the count filtering through post");
  for (const s of stops) s();
});

test("a sign-out asks every subscription again, without the old visitor's tokens", async () => {
  const server = liveServer(() => ["row"]);
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const stop = first.posts.list().subscribe(() => {});
  await tick();
  await tick();
  server.requests.length = 0;
  await first.auth.signOut();
  await tick();
  assert.equal(server.requests.length, 1, "asked again at once");
  assert.equal(server.requests[0][0].token, undefined, "with no token: it was the previous visitor's");
  stop();
});

test("batches stay under the endpoint's body limit", async () => {
  const server = liveServer(() => []);
  const first = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const big = "x".repeat(4000);
  const stops = Array.from({ length: 12 }, (_, i) => first.posts.list({ where: { title: `${big}${i}` } }).subscribe(() => {}));
  // Wait for every read to be sent rather than for a fixed time: a loaded machine can take longer than one tick.
  for (let i = 0; i < 100 && server.requests.flat().length < 12; i++) await tick();
  assert.ok(server.requests.length >= 2, `split by size, not only by count: ${server.requests.map((r) => r.length)}`);
  for (const r of server.requests) assert.ok(JSON.stringify({ queries: r }).length <= 32_768);
  for (const s of stops) s();
});

for (const seeded of [true, false]) {
  test(`${seeded ? "a seeded" : "an unseeded"} subscription in a tab hidden at load sends nothing until the tab is shown`, async (t) => {
    t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
    t.mock.method(Math, "random", () => 0.5);
    const settle = async () => {
      for (let i = 0; i < 5; i++) await new Promise((resolve) => setImmediate(resolve));
    };
    const listeners = new Set<() => void>();
    const page = { visibilityState: "hidden", addEventListener: (_: string, fn: () => void) => void listeners.add(fn), removeEventListener: (_: string, fn: () => void) => void listeners.delete(fn) };
    const saved = Object.getOwnPropertyDescriptor(globalThis, "document");
    Object.defineProperty(globalThis, "document", { configurable: true, value: page });
    t.after(() => {
      if (saved) Object.defineProperty(globalThis, "document", saved);
      else delete (globalThis as Record<string, unknown>).document;
    });
    const sent: { token?: string }[] = [];
    const first = mod.createClient({
      url,
      fetch: async (_input: unknown, init: { body: string }) => {
        const q = JSON.parse(init.body).queries[0];
        sent.push(q);
        return new Response(JSON.stringify({ data: { results: [q.token === "seed" ? { changed: false, token: "seed" } : { changed: true, token: "t", data: ["fresh"] }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 });
      },
      realtime: { transport: false },
    });
    const got: unknown[] = [];
    const stop = first.posts.list().subscribe((d: unknown) => got.push(d), undefined, seeded ? { token: "seed", data: ["rendered"] } : undefined);
    await settle();
    t.mock.timers.tick(600_000);
    await settle();
    assert.equal(sent.length, 0, "hidden at load, ten minutes: no request");
    assert.deepEqual(got, seeded ? [["rendered"]] : [], seeded ? "the server-rendered answer is shown at once" : "nothing to show yet");
    page.visibilityState = "visible";
    for (const fn of [...listeners]) fn();
    await settle();
    assert.equal(sent.length, 1, "shown: one request");
    if (seeded) {
      assert.equal(sent[0].token, "seed", "with the seed's token");
      assert.deepEqual(got, [["rendered"]], "not modified: nothing more to show");
    } else {
      assert.deepEqual(got, [["fresh"]], "the first answer");
    }
    stop();
  });
}

/** A fake document and window: listeners by event name, with `fire(name, event)`. */
function fakePage(t: { after(fn: () => void): void }, state: { visibilityState: string; prerendering?: boolean }) {
  const on = new Map<string, Set<(e: unknown) => void>>();
  const target = (owner: object) => Object.assign(owner, {
    addEventListener: (type: string, fn: (e: unknown) => void) => void (on.get(type) ?? on.set(type, new Set()).get(type)!).add(fn),
    removeEventListener: (type: string, fn: (e: unknown) => void) => void on.get(type)?.delete(fn),
  });
  const doc = target({ ...state });
  const g = globalThis as Record<string, unknown>;
  const saved = { document: Object.getOwnPropertyDescriptor(globalThis, "document"), add: g.addEventListener, remove: g.removeEventListener };
  Object.defineProperty(globalThis, "document", { configurable: true, value: doc });
  target(globalThis);
  t.after(() => {
    if (saved.document) Object.defineProperty(globalThis, "document", saved.document);
    else delete g.document;
    g.addEventListener = saved.add;
    g.removeEventListener = saved.remove;
  });
  return { doc: doc as Record<string, unknown>, fire: (type: string, event: unknown = {}) => [...(on.get(type) ?? [])].forEach((fn) => fn(event)), listening: (type: string) => on.get(type)?.size ?? 0 };
}

test("a prerendered page asks once when it is activated, even if only prerenderingchange fires", async (t) => {
  const page = fakePage(t, { visibilityState: "hidden", prerendering: true });
  let asked = 0;
  const first = mod.createClient({
    url,
    fetch: async () => (asked++, new Response(JSON.stringify({ data: { results: [{ changed: false, token: "seed" }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 })),
    realtime: { transport: false, pollMs: false },
  });
  const got: unknown[] = [];
  const stop = first.posts.list().subscribe((d: unknown) => got.push(d), undefined, { token: "seed", data: ["rendered"] });
  await tick();
  assert.deepEqual([asked, got], [0, [["rendered"]]], "while prerendering: the seed is shown, nothing is sent");
  // Activation: visible and no longer prerendering; Chromium fires prerenderingchange.
  page.doc.prerendering = false;
  page.doc.visibilityState = "visible";
  page.fire("prerenderingchange");
  await tick();
  assert.equal(asked, 1, "activated: one request");
  stop();
  assert.equal(page.listening("prerenderingchange"), 0, "the last subscriber removes the listener");
});

test("a prerendered page sends nothing even when its visibilityState reads visible", async (t) => {
  // document.prerendering is what says a page isn't shown yet; visibilityState alone may not.
  const page = fakePage(t, { visibilityState: "visible", prerendering: true });
  let asked = 0;
  const first = mod.createClient({
    url,
    fetch: async () => (asked++, new Response(JSON.stringify({ data: { results: [{ changed: true, token: "t", data: [] }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 })),
    realtime: { transport: false, pollMs: false },
  });
  const stop = first.posts.list().subscribe(() => {});
  await tick();
  assert.equal(asked, 0, "prerendering: nothing sent");
  page.doc.prerendering = false;
  page.fire("prerenderingchange");
  await tick();
  assert.equal(asked, 1, "activated: one request");
  stop();
});

test("a page restored from the back/forward cache asks again; a first pageshow doesn't", async (t) => {
  const page = fakePage(t, { visibilityState: "visible" });
  let asked = 0;
  const first = mod.createClient({
    url,
    fetch: async () => (asked++, new Response(JSON.stringify({ data: { results: [{ changed: false, token: "t" }], poll: { minMs: 2000, maxMs: 30000 } } }), { status: 200 })),
    realtime: { transport: false, pollMs: false },
  });
  const stop = first.posts.list().subscribe(() => {});
  await tick();
  assert.equal(asked, 1, "the first answer");
  page.fire("pageshow", { persisted: false });
  await tick();
  assert.equal(asked, 1, "a normal load's pageshow asks nothing");
  page.fire("pageshow", { persisted: true });
  await tick();
  assert.equal(asked, 2, "restored from the back/forward cache: asked again");
  stop();
  assert.equal(page.listening("pageshow"), 0, "the last subscriber removes the listener");
});

test("the exported watch() still re-runs an arbitrary read on a notice or the fixed interval", async () => {
  let n = 0;
  reply = () => ok(++n)();
  const transport = fakeTransport();
  const first = mod.createClient({ url, realtime: { transport, pollMs: false, livePollMs: false } });
  const got: unknown[] = [];
  const stop = mod.watch(first, ["posts"], () => first.actions.publishedCount(), (v: unknown) => got.push(v));
  await tick();
  transport.push(notice("posts"));
  await tick();
  transport.push(notice("authors"));
  await tick();
  assert.deepEqual(got, [1, 2], "the read, and once more for the notice that names its kind");
  stop();
});

test("without a live transport, watch polls; a transport registered later is picked up", async () => {
  reply = () => ok([])();
  seen = [];
  const first = mod.createClient({ url, realtime: { pollMs: 30, livePollMs: false } });
  const stop = first.posts.watch(undefined, () => {});
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

  delete (globalThis as Record<string, unknown>).__tpRealtime;

  // A second copy of the client (a host script's) registers; this copy's watchers connect to it.
  const other: any = await load(SCHEMA_TS);
  const second = fakeTransport();
  const stop2 = first.posts.watch(undefined, () => {});
  try {
    other.registerRealtimeTransport(second);
    assert.equal(second.connects, 1, "the registry is page-wide, not per module copy");
  } finally {
    stop2();
    delete (globalThis as Record<string, unknown>).__tpRealtime;
  }
});

test("while live, a slow jittered safety poll still recovers a notice the transport lost", async (t) => {
  t.mock.timers.enable({ apis: ["setTimeout", "Date"] });
  t.mock.method(Math, "random", () => 1); // the latest the jitter allows: 60 s × 1.2
  const transport = fakeTransport();
  const refreshes: number[] = [];
  const first = mod.createClient({
    url,
    // Count reads without the network: only the timers decide when a refresh happens.
    fetch: async () => (refreshes.push(refreshes.length), new Response('{"data":[]}', { status: 200 })),
    realtime: { transport, pollMs: false },
  });
  const stop = first.posts.watch(undefined, () => {});
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

test("useQuery keeps data through a network failure and drops it when the server refuses the read", async () => {
  const { createHooks } = await import(pathToFileURL(join(dirname(fileURLToPath(import.meta.url)), "react.ts")).href);
  // Just enough React to run one useQuery outside a renderer: memo once, subscribe once, read the snapshot.
  let memo: unknown;
  let changed = 0;
  const React = {
    useMemo: (factory: () => unknown) => (memo ??= factory()),
    useSyncExternalStore: (subscribe: (onChange: () => void) => () => void, snapshot: () => unknown) => {
      subscribe(() => changed++);
      return snapshot();
    },
    useRef: (current: unknown) => ({ current }),
    useState: (s: unknown) => [s, () => {}],
    useCallback: (fn: unknown) => fn,
    useEffect: () => {},
    useLayoutEffect: () => {},
  };
  const handlers: { onData?: (data: unknown) => void; onError?: (error: unknown) => void } = {};
  const query = { key: "posts", subscribe: (onData: any, onError: any) => ((handlers.onData = onData), (handlers.onError = onError), () => {}) };
  const { useQuery } = createHooks(React);
  const state = useQuery(query);
  assert.equal(state.loading, true);
  const now = () => (memo as { snapshot: () => any }).snapshot();
  handlers.onData!(["one"]);
  assert.deepEqual(now().data, ["one"]);
  handlers.onError!(new mod.TypedPressError({ type: "about:blank", title: "Network", status: 0, code: "network", detail: "down" }));
  assert.deepEqual([now().data, now().error.code], [["one"], "network"], "a network failure keeps what was shown");
  handlers.onData!(["one"]);
  handlers.onError!(new mod.TypedPressError({ type: "about:blank", title: "Unauthorized", status: 401, code: "unauthorized", detail: "signed out" }));
  assert.deepEqual([now().data, now().error.status], [undefined, 401], "a refusal drops the previous visitor's rows");
  assert.equal(changed, 4);
});

// pageRead: TypedPress's own `<Live>` regions (docs/decisions/2026-11-14-live-regions.md).

test("pageRead: a page read is keyed apart from every kind read, and asks with { op: page, args: { url, region } }", async () => {
  const server = liveServer((q) => (q.op === "page" ? { html: `<p>${q.args.region}</p>`, kinds: ["posts"] } : [q.kind]));
  const client = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const page = mod.pageRead(client, "/", "notes", ["posts"]);
  const list = client.posts.list();
  assert.notEqual(page.key, list.key);
  assert.notEqual(mod.pageRead(client, "/", "notes", []).key, mod.pageRead(client, "/", "other", []).key, "each region is its own read");
  const got: unknown[] = [];
  const stop = page.subscribe((d: unknown) => got.push(d));
  await tick();
  assert.deepEqual(server.requests[0], [{ op: "page", args: { url: "/", region: "notes" } }]);
  assert.deepEqual(got, [{ html: "<p>notes</p>", kinds: ["posts"] }]);
  stop();
  assert.throws(() => mod.pageRead({}, "/", "notes", []), TypeError, "only a client from createClient()");
});

test("pageRead: its deps are what the caller passes, so a notice or a write naming one of them asks again", async () => {
  let n = 0;
  const server = liveServer((q) => (q.op === "page" ? { html: `<p>${n}</p>`, kinds: ["posts", "wp:comments"] } : { id: 1 }));
  const transport = fakeTransport();
  const client = mod.createClient({ url, realtime: { transport, pollMs: false, livePollMs: false } });
  const got: any[] = [];
  const stop = mod.pageRead(client, "/", "notes", ["posts", "wp:comments"]).subscribe((d: any) => got.push(d.html));
  transport.set("live");
  await tick();
  const before = server.requests.length;
  n = 1;
  transport.push(notice("wp:comments", null));
  await tick();
  assert.equal(server.requests.length, before + 1, "a notice for a dep asks at once");
  assert.equal(got.at(-1), "<p>1</p>");
  transport.push(notice("shoppingList"));
  await tick();
  assert.equal(server.requests.length, before + 1, "a kind it doesn't depend on does not");
  n = 2;
  await client.posts.update(1, { title: "x" });
  await tick();
  assert.equal(server.requests.length, before + 2, "a write through the same client to a dep asks at once");
  assert.equal(got.at(-1), "<p>2</p>");
  stop();
});

test("pageRead: a seeded region sends its token with the first ask; resubscribing with new deps keeps one read", async () => {
  const server = liveServer(() => ({ html: "<p>same</p>", kinds: ["posts"] }));
  const client = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const seed = { html: "<p>same</p>", kinds: ["posts"] };
  const got: unknown[] = [];
  let stop = mod.pageRead(client, "/", "notes", ["posts"]).subscribe((d: unknown) => got.push(d), undefined, { token: JSON.stringify(seed), data: seed });
  await tick();
  assert.equal(server.requests[0][0].token, JSON.stringify(seed), "the seed's token goes with the first ask");
  assert.equal(got.length, 1, "the seed is delivered once; the unchanged answer calls nothing");
  // The region's page read other kinds this time: the browser module subscribes again with them.
  stop();
  stop = mod.pageRead(client, "/", "notes", ["posts", "authors"]).subscribe(() => {});
  await tick();
  assert.equal(server.requests.at(-1).length, 1, "one read per region");
  stop();
});

test("pageRead: an answer `pending` (over the server's per-request cap of page reads) is asked again at once", async () => {
  let calls = 0;
  const state = { requests: [] as any[][] };
  reply = (s) => {
    const queries = JSON.parse(s.body.toString()).queries as any[];
    state.requests.push(queries);
    calls++;
    const results = queries.map((q, i) => (calls === 1 && i >= 4 ? { pending: true } : { changed: true, token: `t${q.args.region}`, data: { html: q.args.region, kinds: [] } }));
    return ok({ results, poll: { minMs: 2000, maxMs: 30000 } })();
  };
  const client = mod.createClient({ url, realtime: { transport: false, pollMs: false } });
  const got = new Map<string, string>();
  const stops = ["a", "b", "c", "d", "e"].map((r) => mod.pageRead(client, "/", r, []).subscribe((d: any) => got.set(r, d.html)));
  await tick();
  await tick();
  assert.deepEqual(state.requests.map((q) => q.length), [5, 1], "the pending read is asked again at once, alone");
  assert.deepEqual([...got.keys()].sort(), ["a", "b", "c", "d", "e"], "every region is filled");
  for (const stop of stops) stop();
});
