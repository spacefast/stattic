// Zero typed client runtime. `zero build` copies this file next to the generated `types.ts`
// (interfaces) and `schema.ts` (the SCHEMA descriptor) in `zero/client/`. Do not edit the copy.
//
// No dependencies; `fetch`-based; erasable TypeScript only, so Node (type stripping), Bun, Deno and
// bundlers run it as-is. Wire format: design §4.8 / §7.

import { SCHEMA } from "./schema.ts";
import type { Actions, Collections, Globals, Locale, Media, PrivateFile, RevisionSummary, Status, User } from "./types.ts";

export type * from "./types.ts";
export { SCHEMA };

// ---------------------------------------------------------------------------
// Types derived from the generated interfaces and the SCHEMA descriptor
// ---------------------------------------------------------------------------

type Descriptor = typeof SCHEMA;
type CollectionDescriptors = Descriptor["collections"];

/** Schema keys of every collection (`client.<key>`). */
export type CollectionKey = keyof Collections & keyof CollectionDescriptors & string;

type Row<K extends CollectionKey> = Collections[K]["row"];
type Rels<K extends CollectionKey> = CollectionDescriptors[K]["relations"];
type IdOf<K extends CollectionKey> = Row<K>[CollectionDescriptors[K]["primaryKey"] & keyof Row<K>];

/** `with` keys that load something (`false`/`undefined` entries load nothing). */
type Requested<W> = { [P in keyof W]-?: W[P] extends false | undefined | null ? never : P }[keyof W];

type NestedWith<V> = V extends { with: infer W } ? W : {};

type RelationValue<R, V> = R extends { kind: "one"; collection: infer T extends CollectionKey; optional: infer O }
  ? RowWith<T, NestedWith<V>> | (O extends false ? never : null)
  : R extends { kind: "many"; collection: infer T extends CollectionKey }
    ? RowWith<T, NestedWith<V>>[]
    : never;

type WithValue<K extends CollectionKey, P, V> = P extends keyof Rels<K>
  ? RelationValue<Rels<K>[P], V>
  : P extends CollectionDescriptors[K]["mediaList"][number]
    ? Media[] | (null extends Row<K>[P & keyof Row<K>] ? null : never)
    : P extends CollectionDescriptors[K]["media"][number]
      ? Media | null
      : P extends CollectionDescriptors[K]["files"][number]
        ? PrivateFile | number | (null extends Row<K>[P & keyof Row<K>] ? null : never)
        : never;

/**
 * A row of collection `K` loaded with `with: W`: `one` relations become the related row (or null when
 * optional), `many` relations a list, and requested media columns are replaced in place by media rows.
 * Requested file columns become the file row when the bucket's read rule admits the viewer, else stay the id.
 */
export type RowWith<K extends CollectionKey, W> = [Requested<W>] extends [never]
  ? Row<K>
  : ([Requested<W> & keyof Row<K>] extends [never] ? Row<K> : Omit<Row<K>, Requested<W> & keyof Row<K>>) & {
      -readonly [P in Exclude<Requested<W>, OptionalKeys<Row<K>>>]: WithValue<K, P, W[P]>;
    } & {
      // A column the viewer may not read (field access) stays optional when populated.
      -readonly [P in Extract<Requested<W>, OptionalKeys<Row<K>>>]?: WithValue<K, P, W[P]>;
    };

type OptionalKeys<T> = { [P in keyof T]-?: {} extends Pick<T, P> ? P : never }[keyof T] & PropertyKey;

/** Rejects `with` keys that are neither relations nor media columns (`with: { autor: true }`). */
type ExactWith<K extends CollectionKey, W> = W & { [P in Exclude<keyof W, keyof Collections[K]["with"]>]: never };

type StatusQuery<K extends CollectionKey> = CollectionDescriptors[K]["drafts"] extends true
  ? { /** Shorthand for `where: { status }` (ANDed with `where`). */ status?: Status }
  : {};

type LocaleQuery<K extends CollectionKey> = CollectionDescriptors[K]["localized"] extends true
  ? { /** Rows in this locale, falling back to the default locale per translation group; `"*"` = every row. Default: the default locale. */ locale?: Locale | "*" }
  : {};

export type ListQuery<K extends CollectionKey, W = Collections[K]["with"]> = {
  where?: Collections[K]["filter"];
  /** `{ createdAt: "desc", title: "asc" }` or the wire form `"-createdAt,title"`. */
  orderBy?: Collections[K]["order"];
  limit?: number;
  offset?: number;
  with?: W;
} & StatusQuery<K> & LocaleQuery<K>;

export interface CollectionClient<K extends CollectionKey> {
  list<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(q?: ListQuery<K, ExactWith<K, W>>): Promise<RowWith<K, W>[]>;
  /** With `locale`, the row of `id`'s translation group in that locale (or its fallback). */
  get<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(id: IdOf<K>, q?: { with?: ExactWith<K, W> } & LocaleQuery<K>): Promise<RowWith<K, W>>;
  create(data: Collections[K]["insert"]): Promise<Row<K>>;
  update(id: IdOf<K>, patch: Collections[K]["patch"]): Promise<Row<K>>;
  delete(id: IdOf<K>): Promise<Row<K>>;
  /**
   * Runs `list(q)` now and again whenever this collection (or a collection loaded through `with`)
   * changes. Every refresh is the normal access-checked read; change notices carry no rows.
   * Returns a function that stops watching.
   */
  watch<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(
    q: ListQuery<K, ExactWith<K, W>> | undefined,
    onRows: (rows: RowWith<K, W>[]) => void,
    onError?: (error: ZeroError) => void,
  ): () => void;
  revisions: {
    /** Newest first. Needs `update` access on the row. */
    list(id: IdOf<K>): Promise<RevisionSummary[]>;
    get(id: IdOf<K>, revisionId: number): Promise<Row<K>>;
    /** Runs the update pipeline with the revision as the patch; returns the updated row. */
    restore(id: IdOf<K>, revisionId: number): Promise<Row<K>>;
  };
}

export interface MediaQuery {
  where?: Record<string, unknown>;
  orderBy?: Record<string, "asc" | "desc"> | string;
  limit?: number;
  offset?: number;
}

export interface MediaClient {
  list(q?: MediaQuery): Promise<Media[]>;
  get(id: number): Promise<Media>;
  /** `multipart/form-data` with fields `file` and `alt`. */
  upload(file: Blob, opts?: { alt?: string; filename?: string }): Promise<Media>;
  /** Editors only. A new `focal` point (0..1 from the top-left) regenerates every `cover` size. */
  update(id: number, patch: { alt?: string | null; focal?: { x: number; y: number } | null }): Promise<Media>;
  delete(id: number): Promise<Media>;
}

export interface FileBucketClient {
  /** `multipart/form-data` field `file`; the bucket's `accept`, `maxBytes` and `create` rule apply. */
  upload(file: Blob, opts?: { filename?: string }): Promise<PrivateFile>;
  /** The row with a fresh `url`: a signed download link valid for 5 minutes. */
  get(id: number): Promise<PrivateFile>;
  delete(id: number): Promise<PrivateFile>;
}

export type FilesClient = { readonly [B in Descriptor["buckets"][number]]: FileBucketClient };

export type ActionsClient = {
  [A in keyof Actions]: {} extends Actions[A]["input"]
    ? (input?: Actions[A]["input"]) => Promise<Actions[A]["output"]>
    : (input: Actions[A]["input"]) => Promise<Actions[A]["output"]>;
};

export type GlobalsClient = {
  [G in keyof Globals]: {
    get(): Promise<Globals[G]["row"]>;
    update(patch: Globals[G]["patch"]): Promise<Globals[G]["row"]>;
  };
};

/** The tokens a signed-in client holds (`auth.session()`). */
export interface Session {
  accessToken: string;
  refreshToken: string;
  /** Epoch milliseconds. */
  expiresAt: number;
}

export interface AuthClient {
  /** Redirects to `<issuer>/authorize` (PKCE S256); the promise never settles. */
  signIn(opts?: { returnTo?: string }): Promise<never>;
  /** On the redirect URI: exchanges `?code` at `<issuer>/token`, keeps the tokens, returns the user. */
  handleCallback(): Promise<User>;
  /** Starts a guest session (`role: "guest"`) and keeps its tokens. */
  guest(): Promise<User>;
  /** Sends the current guest to the issuer's screens to upgrade in place; never settles. */
  upgrade(): Promise<never>;
  /** The signed-in user (with `member`, the auth collection row, when the app has one), or null. */
  me(): Promise<User | null>;
  /** Revokes the session and forgets the tokens; also signs out at the issuer when `returnTo` is given. */
  signOut(opts?: { returnTo?: string }): Promise<void>;
  /** The held tokens, for server-side callers that forward them; null when signed out. */
  session(): Session | null;
}

export type Client = { readonly [K in CollectionKey]: CollectionClient<K> } & {
  readonly media: MediaClient;
  readonly files: FilesClient;
  readonly actions: ActionsClient;
  readonly globals: GlobalsClient;
  readonly auth: AuthClient;
  /** `GET /_schema`: the app's public schema description. */
  schema(): Promise<unknown>;
};

/** One committed transaction's changes, as the runtime's `zero_realtime_changes` hook sends them. */
export interface ChangeNotice {
  id: string;
  at: string;
  /** `ids: null` means "some rows changed": too many to list, or ids withheld from a shared room. */
  changes: { collection: string; ops: ("create" | "update" | "delete")[]; ids: (string | number)[] | null }[];
}

/**
 * Delivers change notices from the host (Cast, a WebSocket relay, Pusher, ...). Zero ships none.
 * `connect` starts delivery and returns a stop function; call `status("live")` once notices flow
 * and `status("down")` when they stop, so the client polls meanwhile.
 */
export interface RealtimeTransport {
  connect(deliver: (notice: ChangeNotice) => void, status: (state: "live" | "down") => void): () => void;
}

export interface RealtimeOptions {
  /** The transport. Default: the one a host registered with `registerRealtimeTransport`; `false` = polling only. */
  transport?: RealtimeTransport | false;
  /** Refresh watched reads this often while no transport is live. Default 30000; `false` turns polling off. */
  pollMs?: number | false;
  /**
   * A slow safety refresh while the transport is live, covering a notice the transport lost.
   * Default 60000 with up to ±20% jitter per refresh; `false` turns it off.
   */
  livePollMs?: number | false;
}

/**
 * The page-wide registry, on `globalThis` so a host script and an app bundle that each carry their
 * own copy of this client still meet.
 */
interface Registry {
  transport?: RealtimeTransport;
  waiters: Set<() => void>;
}
function registry(): Registry {
  const g = globalThis as { __zeroRealtime?: Registry };
  return (g.__zeroRealtime ??= { waiters: new Set() });
}

/** For host scripts: every client on this page, created before or after, uses this transport by default. */
export function registerRealtimeTransport(transport: RealtimeTransport): void {
  const r = registry();
  r.transport = transport;
  for (const wake of [...r.waiters]) wake();
}

export interface ClientOptions {
  /** Base URL including the REST prefix: "https://x.test/wp-json/zero/v1". Default: `/wp-json/zero/v1` on the page's origin. */
  url?: string;
  /** WordPress cookie auth: the REST nonce; sent as `X-WP-Nonce` with `credentials: "same-origin"`. */
  nonce?: string;
  fetch?: typeof fetch;
  /** Extra headers on every request (e.g. `Authorization: Basic ...` for application passwords). They win over the above. */
  headers?: Record<string, string>;
  /** Frontend sign-in (design §6.2). */
  auth?: AuthOptions;
  /** Live updates for `watch`. */
  realtime?: RealtimeOptions;
}

export interface AuthOptions {
  /** The zero-identity OIDC issuer (`https://x.test/identity`). Default: `issuer` from `GET /auth/config`. */
  issuer?: string;
  /** The registered application's client id. */
  clientId?: string;
  /** Where the issuer sends the browser back (the page that calls `handleCallback()`). */
  redirectUri?: string;
  /** Where tokens live: in memory (default) or `sessionStorage` under `zero.auth:<base url>`. */
  storage?: "memory" | "session";
  /** A write rejected as unauthenticated while signed out starts a guest session and is retried once. */
  guests?: boolean;
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

/** RFC 9457 problem document, as the runtime sends it. */
export interface Problem {
  type: string;
  title: string;
  status: number;
  code: string;
  detail: string;
  pointer?: string;
}

/** Thrown for every non-2xx response; the fields are the problem document. Network failures: status 0, code "network". */
export class ZeroError extends Error {
  readonly status: number;
  readonly code: string;
  readonly detail: string;
  readonly title: string;
  readonly type: string;
  readonly pointer?: string;

  constructor(problem: Problem) {
    super(problem.detail);
    this.name = "ZeroError";
    this.status = problem.status;
    this.code = problem.code;
    this.detail = problem.detail;
    this.title = problem.title;
    this.type = problem.type;
    this.pointer = problem.pointer;
  }
}

function networkError(detail: string): ZeroError {
  return new ZeroError({ type: "about:blank", title: "Network error", status: 0, code: "network", detail });
}

// ---------------------------------------------------------------------------
// Wire encoding
// ---------------------------------------------------------------------------

type Json = Record<string, unknown>;

const isObject = (v: unknown): v is Json => typeof v === "object" && v !== null && !Array.isArray(v);

/** `{ a: "asc", b: "desc" }` → `"a,-b"`. */
function orderParam(order: unknown): string {
  if (typeof order === "string") return order;
  if (!isObject(order)) return "";
  return Object.entries(order)
    .filter(([, dir]) => dir === "asc" || dir === "desc")
    .map(([col, dir]) => (dir === "desc" ? `-${col}` : col))
    .join(",");
}

/** `"a,-b"` → `{ a: "asc", b: "desc" }` (nested `with` options travel as JSON objects). */
function orderObject(order: unknown): unknown {
  if (typeof order !== "string") return order;
  const out: Record<string, "asc" | "desc"> = {};
  for (const part of order.split(",").map((s) => s.trim()).filter(Boolean)) {
    out[part.replace(/^[-+]/, "")] = part.startsWith("-") ? "desc" : "asc";
  }
  return out;
}

function withJson(w: Json): Json {
  const out: Json = {};
  for (const [name, v] of Object.entries(w)) {
    if (v === true) out[name] = true;
    else if (isObject(v)) {
      out[name] = {
        ...v,
        ...(v.orderBy !== undefined ? { orderBy: orderObject(v.orderBy) } : {}),
        ...(isObject(v.with) ? { with: withJson(v.with) } : {}),
      };
    }
  }
  return out;
}

/** Comma list when every entry is `true`; JSON when any entry carries options. */
function withParam(w: unknown): string {
  if (!isObject(w)) return "";
  const json = withJson(w);
  const names = Object.keys(json);
  if (names.length === 0) return "";
  return names.some((n) => json[n] !== true) ? JSON.stringify(json) : names.join(",");
}

function listParams(q: Json | undefined): URLSearchParams {
  const p = new URLSearchParams();
  if (!q) return p;
  if (q.where !== undefined) p.set("where", JSON.stringify(q.where));
  if (typeof q.status === "string") p.set("status", q.status);
  if (typeof q.locale === "string") p.set("locale", q.locale);
  if (q.orderBy !== undefined) {
    const order = orderParam(q.orderBy);
    if (order) p.set("orderBy", order);
  }
  if (typeof q.limit === "number") p.set("limit", String(q.limit));
  if (typeof q.offset === "number") p.set("offset", String(q.offset));
  const w = withParam(q.with);
  if (w) p.set("with", w);
  return p;
}

const seg = (v: string | number) => encodeURIComponent(String(v));

// ---------------------------------------------------------------------------
// Client
// ---------------------------------------------------------------------------

interface Send {
  query?: URLSearchParams;
  json?: unknown;
  form?: FormData;
  /** Token and guest endpoints: no held Bearer, no refresh or guest retry. */
  anonymous?: boolean;
}

/** What `auth.session()` exposes, plus what refresh and sign-out need. Stored as JSON under `zero.auth:<base>`. */
interface Held extends Session {
  clientId?: string;
  idToken?: string;
  /** One sign-in: random when tokens are first issued, kept by refreshes. Shared through storage. */
  sid?: string;
}

/** Zero's guest token response (`/auth/guest`). */
interface Issued {
  accessToken: string;
  refreshToken: string;
  expiresIn: number;
  user: User;
  clientId?: string;
}

/** The OIDC issuer's token response (`<issuer>/token`). */
interface OidcTokens {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  id_token?: string;
}

/** The browser globals sign-in touches, looked up at call time (absent on servers). */
interface BrowserGlobals {
  sessionStorage?: { getItem(key: string): string | null; setItem(key: string, value: string): void; removeItem(key: string): void };
  location?: { search: string; href: string; assign(url: string): void };
}

const browser = () => globalThis as unknown as BrowserGlobals;

const PKCE_KEY = "zero.auth.pkce";

function base64url(bytes: Uint8Array): string {
  let bin = "";
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function randomString(bytes: number): string {
  return base64url(crypto.getRandomValues(new Uint8Array(bytes)));
}

async function s256(verifier: string): Promise<string> {
  return base64url(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(verifier))));
}

function authError(status: number, code: string, detail: string): ZeroError {
  return new ZeroError({ type: "about:blank", title: code, status, code, detail });
}

/** In-flight refreshes of sessionStorage-backed sessions, by storage key (shared by clients in this tab). */
const refreshes = new Map<string, Promise<boolean>>();

export function createClient(options: ClientOptions = {}): Client {
  const base = (options.url ?? "/wp-json/zero/v1").replace(/\/+$/, "");
  const authOpts = options.auth ?? {};
  const doFetch = (input: string, init: RequestInit) => (options.fetch ?? globalThis.fetch)(input, init);

  // Token storage: memory by default, sessionStorage on request. The record always exists once
  // written: signed out, it keeps only a `sid`, so every sign-in and sign-out (including from the
  // anonymous state) is a distinct generation that late results can be checked against.
  const storageKey = `zero.auth:${base}`;
  let memory: Stored = {};
  type Stored = Partial<Held> & { sid?: string };
  function stored(): Stored {
    if (authOpts.storage !== "session") return memory;
    const raw = browser().sessionStorage?.getItem(storageKey);
    if (!raw) return {};
    try {
      const v: unknown = JSON.parse(raw);
      return isObject(v) ? (v as Stored) : {};
    } catch {
      return {};
    }
  }
  function store(next: Stored): void {
    if (authOpts.storage !== "session") {
      memory = next;
      return;
    }
    browser().sessionStorage?.setItem(storageKey, JSON.stringify(next));
  }
  function held(): Held | null {
    const v = stored();
    return typeof v.accessToken === "string" && typeof v.refreshToken === "string" ? (v as Held) : null;
  }
  // Who is signed in is the stored `sid`, shared by every client on the same storage. A sign-in,
  // guest, callback or sign-out starts a new one; a refresh keeps it. Every request, refresh and
  // sign-in remembers the sid it started from and acts only if it is still current: a 401 that
  // arrives after the identity changed is never retried as the new user, and a late result never
  // overwrites or clears a newer session.
  const sidOf = (v: Stored | null): string => v?.sid ?? v?.accessToken ?? "";
  const currentSid = (): string => sidOf(stored());
  function switchTo(next: Held | null, from: string): boolean {
    if (currentSid() !== from) return false;
    store({ ...(next ?? {}), sid: randomString(12) });
    return true;
  }
  /** Keep the same identity (refresh): replace the tokens, keep the sid. */
  function hold(next: Held, sid: string): void {
    store({ ...next, sid });
  }
  const expiresAt = (expiresIn: number) => Date.now() + expiresIn * 1000;

  async function call(url: string, init: RequestInit, label: string): Promise<{ res: Response; parsed: unknown }> {
    let res: Response;
    let text: string;
    try {
      res = await doFetch(url, init);
      text = await res.text();
    } catch (e) {
      throw networkError(`${label}: ${e instanceof Error ? e.message : String(e)}`);
    }
    try {
      return { res, parsed: text === "" ? undefined : JSON.parse(text) };
    } catch {
      throw networkError(`${label}: HTTP ${res.status} with a non-JSON body`);
    }
  }

  async function request<T>(method: string, path: string, send: Send = {}, retried = false): Promise<T> {
    const headers: Record<string, string> = {};
    const tokens = send.anonymous ? null : held();
    const started = currentSid();
    if (tokens) headers.authorization = `Bearer ${tokens.accessToken}`;
    if (options.nonce !== undefined) headers["x-wp-nonce"] = options.nonce;
    let body: string | FormData | undefined;
    if (send.form) body = send.form;
    else if (send.json !== undefined) {
      headers["content-type"] = "application/json";
      body = JSON.stringify(send.json);
    }
    for (const [k, v] of Object.entries(options.headers ?? {})) headers[k.toLowerCase()] = v;

    const qs = send.query?.toString();
    const init: RequestInit = { method, headers, body };
    if (options.nonce !== undefined) init.credentials = "same-origin";
    const { res, parsed } = await call(base + path + (qs ? `?${qs}` : ""), init, `${method} ${path}`);
    if (res.ok) {
      if (!isObject(parsed) || !("data" in parsed)) throw networkError(`${method} ${path}: response has no data`);
      return parsed.data as T;
    }
    const error = toError(res, parsed);
    if (error.status === 401 && error.code === "unauthenticated" && !send.anonymous && !retried) {
      // Only a 401 for the Bearer this client holds is ours to repair, and only for the identity
      // that sent it: after a sign-in or sign-out in between, the request fails as it was sent.
      if (tokens && headers.authorization === `Bearer ${tokens.accessToken}`) {
        // Refresh and retry only while the session that sent it is still the current one.
        if (currentSid() === started && (await renew(tokens)) && currentSid() === started) return request<T>(method, path, send, true);
      } else if (!tokens && authOpts.guests && method !== "GET" && headers.authorization === undefined) {
        // An anonymous write becomes a guest's: the one it starts, or the one a concurrent first
        // write started from the same anonymous state. Never a user who signed in meanwhile.
        // Only the guest started from this request's own anonymous generation: one begun later
        // (after a sign-in and sign-out) belongs to someone else's anonymous state.
        if (!held()) await startGuest(started).catch(() => undefined);
        if (guestSid !== "" && currentSid() === guestSid && guestFromSid === started) return request<T>(method, path, send, true);
      }
    }
    throw error;
  }

  // One refresh at a time across every client on the same storage: refresh tokens rotate, and
  // presenting a used one revokes the whole family. sessionStorage belongs to one tab, so clients
  // sharing it share this module: an in-flight refresh is kept per storage key, not per client.
  /** Refresh the session `sent` belongs to (the caller checked it is still the current one). */
  function renew(sent: Held): Promise<boolean> {
    const current = held();
    if (!current) return Promise.resolve(false);
    if (current.accessToken !== sent.accessToken) return Promise.resolve(true); // already refreshed
    // Keyed by the session too: a refresh of an earlier sign-in never stands in for this one's.
    const key = `${authOpts.storage === "session" ? storageKey : clientKey}#${currentSid()}`;
    const shared = refreshes.get(key);
    if (shared) return shared;
    const started = refresh(current).finally(() => {
      if (refreshes.get(key) === started) refreshes.delete(key);
    });
    refreshes.set(key, started);
    return started;
  }
  const clientKey = `memory:${randomString(9)}`;

  /** True on a rotated pair. A rejected refresh forgets the tokens; a network failure keeps them for a later try. */
  async function refresh(current: Held): Promise<boolean> {
    const started = currentSid();
    // A rotated pair for a session no longer held is dropped, not stored; the sid stays the same.
    const keep = (next: Held): boolean => {
      if (currentSid() !== started) return false;
      hold(next, started);
      return true;
    };
    try {
      const clientId = current.clientId ?? authOpts.clientId ?? "";
      const out = await issuerPost<OidcTokens>("/token", { grant_type: "refresh_token", refresh_token: current.refreshToken, client_id: clientId });
      return keep({
        accessToken: out.access_token,
        refreshToken: out.refresh_token,
        expiresAt: expiresAt(out.expires_in),
        ...(current.clientId !== undefined ? { clientId: current.clientId } : {}),
        ...((out.id_token ?? current.idToken) !== undefined ? { idToken: out.id_token ?? current.idToken } : {}),
      });
    } catch (e) {
      // A refused refresh signs this session out: a new generation, like any sign-out.
      if (!(e instanceof ZeroError) || e.status !== 0) switchTo(null, started);
      return false;
    }
  }

  /** Store a guest's tokens unless the session changed since `from` (then the newer one stays). */
  function adopt(out: Issued, from: string): User {
    const adopted = switchTo({
      accessToken: out.accessToken,
      refreshToken: out.refreshToken,
      expiresAt: expiresAt(out.expiresIn),
      ...(typeof out.clientId === "string" ? { clientId: out.clientId } : {}),
    }, from);
    if (!adopted) throw authError(409, "session_changed", "Another sign-in or sign-out happened first");
    return out.user;
  }

  // Concurrent first writes share one guest.
  /** The sid of the guest session this client started ("" when none), and the anonymous sid it replaced. */
  let guestSid = "";
  let guestFromSid = "";
  // Shared only between calls starting from the same session state; an older completion never
  // clears a newer request.
  const guestings = new Map<string, Promise<User>>();
  function startGuest(from: string): Promise<User> {
    const existing = guestings.get(from);
    if (existing) return existing;
    const started: Promise<User> = request<Issued>("POST", "/auth/guest", { json: {}, anonymous: true })
      .then((out) => {
        const user = adopt(out, from);
        guestFromSid = from;
        guestSid = currentSid();
        return user;
      })
      .finally(() => {
        if (guestings.get(from) === started) guestings.delete(from);
      });
    guestings.set(from, started);
    return started;
  }

  let issuerUrl: string | undefined = authOpts.issuer?.replace(/\/+$/, "");
  async function issuer(): Promise<string> {
    if (issuerUrl === undefined) {
      const config = await request<{ issuer: string | null }>("GET", "/auth/config", { anonymous: true });
      if (typeof config.issuer !== "string") throw authError(400, "not_supported", "This app has no sign-in issuer");
      issuerUrl = config.issuer.replace(/\/+$/, "");
    }
    return issuerUrl;
  }

  /** OAuth endpoints answer bare JSON; failures are `{ error, error_description }`. */
  async function issuerPost<T>(path: string, form: Record<string, string>, accessToken?: string): Promise<T> {
    const url = (await issuer()) + path;
    const headers: Record<string, string> = { "content-type": "application/x-www-form-urlencoded" };
    if (accessToken !== undefined) headers.authorization = `Bearer ${accessToken}`;
    const { res, parsed } = await call(url, { method: "POST", headers, body: new URLSearchParams(form).toString() }, `POST ${url}`);
    if (res.ok) return parsed as T;
    const body = isObject(parsed) ? parsed : {};
    const code = typeof body.error === "string" ? body.error : "http_error";
    throw authError(res.status, code, typeof body.error_description === "string" ? body.error_description : `HTTP ${res.status}`);
  }

  async function redirectToIssuer(returnTo: string | undefined, extra: Record<string, string>): Promise<never> {
    const { clientId, redirectUri } = authOpts;
    if (!clientId || !redirectUri) throw authError(400, "invalid_input", "auth.clientId and auth.redirectUri are required to sign in");
    const { sessionStorage, location } = browser();
    if (!sessionStorage || !location) throw authError(400, "not_supported", "Redirect sign-in needs a browser");
    const verifier = randomString(64);
    const state = randomString(32);
    const nonce = randomString(32);
    const params = new URLSearchParams({
      response_type: "code",
      client_id: clientId,
      redirect_uri: redirectUri,
      scope: "openid profile email",
      state,
      nonce,
      code_challenge: await s256(verifier),
      code_challenge_method: "S256",
      ...extra,
    });
    const url = `${await issuer()}/authorize?${params}`;
    sessionStorage.setItem(PKCE_KEY, JSON.stringify({ verifier, state, nonce, ...(returnTo !== undefined ? { returnTo } : {}) }));
    location.assign(url);
    return new Promise<never>(() => {});
  }

  const auth: AuthClient = {
    signIn(opts = {}) {
      return redirectToIssuer(opts.returnTo, {});
    },
    async handleCallback() {
      const { sessionStorage, location } = browser();
      const params = new URLSearchParams(location?.search ?? "");
      let pkce: Json = {};
      try {
        const v: unknown = JSON.parse(sessionStorage?.getItem(PKCE_KEY) ?? "null");
        if (isObject(v)) pkce = v;
      } catch {
        // No usable sign-in in progress: the state check below refuses it.
      }
      const state = params.get("state");
      if (typeof pkce.state !== "string" || typeof pkce.verifier !== "string" || state !== pkce.state) {
        throw authError(400, "invalid_state", "This sign-in response does not match a sign-in started here");
      }
      sessionStorage?.removeItem(PKCE_KEY);
      const failed = params.get("error");
      if (failed) throw authError(400, failed, params.get("error_description") ?? failed);
      const code = params.get("code");
      if (!code) throw authError(400, "invalid_input", "The sign-in response has no code");
      const clientId = authOpts.clientId ?? "";
      const from = currentSid();
      const out = await issuerPost<OidcTokens>("/token", {
        grant_type: "authorization_code",
        code,
        redirect_uri: authOpts.redirectUri ?? "",
        client_id: clientId,
        code_verifier: pkce.verifier,
      });
      if (!switchTo({
        accessToken: out.access_token,
        refreshToken: out.refresh_token,
        expiresAt: expiresAt(out.expires_in),
        clientId,
        ...(out.id_token !== undefined ? { idToken: out.id_token } : {}),
      }, from)) throw authError(409, "session_changed", "Another sign-in or sign-out happened first");
      const user = await auth.me();
      if (!user) throw authError(401, "unauthenticated", "The issuer's tokens were not accepted");
      // Back to where signIn({ returnTo }) started: only to this origin, and only when the tokens
      // survive a page load (sessionStorage); with memory storage the caller navigates itself.
      if (typeof pkce.returnTo === "string" && location && authOpts.storage === "session") {
        let to: URL | null = null;
        try {
          to = new URL(pkce.returnTo, location.href);
        } catch {
          to = null;
        }
        if (to && to.origin === new URL(location.href).origin) location.assign(to.href);
      }
      return user;
    },
    guest: () => startGuest(currentSid()),
    async upgrade() {
      const current = held();
      if (!current) throw authError(401, "unauthenticated", "Only a guest session can be upgraded");
      const { handoff } = await issuerPost<{ handoff: string }>("/handoff", {}, current.accessToken);
      return redirectToIssuer(undefined, { handoff });
    },
    me: () => request<User | null>("GET", "/me"),
    async signOut(opts = {}) {
      const current = held();
      const from = currentSid();
      if (!current) {
        // Already signed out: still a new generation, so a sign-in or guest started before this
        // call can't complete into a session afterwards.
        switchTo(null, from);
        return;
      }
      try {
        await issuerPost("/revoke", { token: current.refreshToken, client_id: current.clientId ?? authOpts.clientId ?? "" });
      } catch (e) {
        // A session the server already ended is signed out; anything else is the caller's to see.
        if (!(e instanceof ZeroError && e.status === 401)) throw e;
      } finally {
        switchTo(null, from); // only the session this sign-out began with
      }
      if (opts.returnTo !== undefined && current.idToken !== undefined) {
        const params = new URLSearchParams({ id_token_hint: current.idToken, post_logout_redirect_uri: opts.returnTo });
        browser().location?.assign(`${await issuer()}/logout?${params}`);
      }
    },
    session() {
      const current = held();
      return current ? { accessToken: current.accessToken, refreshToken: current.refreshToken, expiresAt: current.expiresAt } : null;
    },
  };

  // One transport connection per client while anything is watched; polling while it is not live.
  const rtOpts = options.realtime ?? {};
  const pollMs = rtOpts.pollMs === undefined ? 30_000 : rtOpts.pollMs;
  const livePollMs = rtOpts.livePollMs === undefined ? 60_000 : rtOpts.livePollMs;
  const listeners = new Set<{ collections: ReadonlySet<string>; onChange: (n: ChangeNotice | null) => void }>();
  let disconnect: (() => void) | null = null;
  let live = false;
  // One timer: `pollMs` while down, `livePollMs` (jittered) while live. Rearmed after each refresh.
  let poller: ReturnType<typeof setTimeout> | null = null;
  let pollerLive = false;
  const dispatch = (notice: ChangeNotice | null) => {
    for (const l of [...listeners]) {
      if (notice === null || notice.changes.some((c) => l.collections.has(c.collection))) l.onChange(notice);
    }
  };
  const syncPolling = () => {
    const every = live ? livePollMs : pollMs;
    const want = listeners.size > 0 && every !== false;
    if (poller !== null && (!want || pollerLive !== live)) {
      clearTimeout(poller);
      poller = null;
    }
    if (!want || poller !== null) return;
    pollerLive = live;
    // Jitter only the live safety poll: every tab of a busy page must not refresh at once.
    const delay = live ? Math.round((every as number) * (0.8 + Math.random() * 0.4)) : (every as number);
    poller = setTimeout(() => {
      poller = null;
      dispatch(null);
      syncPolling();
    }, delay);
  };
  const connect = () => {
    if (disconnect !== null || listeners.size === 0 || rtOpts.transport === false) return;
    const transport = rtOpts.transport ?? registry().transport;
    if (!transport || typeof transport.connect !== "function") return;
    registry().waiters.delete(connect);
    let current = true;
    const stop = transport.connect(
      (notice) => {
        if (current && isObject(notice) && Array.isArray(notice.changes)) dispatch(notice);
      },
      (state) => {
        if (!current) return;
        const was = live;
        live = state === "live";
        syncPolling();
        // Back online: catch up on whatever changed while the transport was down.
        if (live && !was) dispatch(null);
      },
    );
    disconnect = () => {
      current = false;
      live = false;
      stop();
    };
  };
  const realtime = {
    subscribe(collections: readonly string[], onChange: (notice: ChangeNotice | null) => void): () => void {
      const entry = { collections: new Set(collections), onChange };
      listeners.add(entry);
      registry().waiters.add(connect);
      connect();
      syncPolling();
      return () => {
        listeners.delete(entry);
        if (listeners.size === 0) {
          registry().waiters.delete(connect);
          disconnect?.();
          disconnect = null;
        }
        syncPolling();
      };
    },
  };

  function collection(key: CollectionKey) {
    const desc: { path: string } = SCHEMA.collections[key];
    const path = `/${seg(desc.path)}`;
    const one = (id: string | number) => `${path}/${seg(id)}`;
    const list = (q?: Json) => request("GET", path, { query: listParams(q) });
    return {
      list,
      watch: (q: Json | undefined, onRows: (rows: unknown) => void, onError?: (error: ZeroError) => void) =>
        watchWith(realtime, dependencies(key, q?.with), () => list(q), onRows, onError),
      get: (id: string | number, q?: Json) => request("GET", one(id), { query: listParams(q ? { with: q.with, locale: q.locale } : undefined) }),
      create: (data: unknown) => request("POST", path, { json: data }),
      update: (id: string | number, patch: unknown) => request("PATCH", one(id), { json: patch }),
      delete: (id: string | number) => request("DELETE", one(id)),
      revisions: {
        list: (id: string | number) => request("GET", `${one(id)}/revisions`),
        get: (id: string | number, rid: number) => request("GET", `${one(id)}/revisions/${seg(rid)}`),
        restore: (id: string | number, rid: number) => request("POST", `${one(id)}/revisions/${seg(rid)}/restore`),
      },
    };
  }

  const client: Json = {
    media: {
      list: (q?: Json) => request("GET", "/media", { query: listParams(q) }),
      get: (id: number) => request("GET", `/media/${seg(id)}`),
      upload(file: Blob, opts: { alt?: string; filename?: string } = {}) {
        const form = new FormData();
        const name = opts.filename ?? ("name" in file && typeof file.name === "string" ? file.name : "upload");
        form.append("file", file, name);
        if (opts.alt !== undefined) form.append("alt", opts.alt);
        return request("POST", "/media", { form });
      },
      update: (id: number, patch: unknown) => request("PATCH", `/media/${seg(id)}`, { json: patch }),
      delete: (id: number) => request("DELETE", `/media/${seg(id)}`),
    },
    files: Object.fromEntries(
      SCHEMA.buckets.map((bucket: string) => [
        bucket,
        {
          upload(file: Blob, opts: { filename?: string } = {}) {
            const form = new FormData();
            form.append("file", file, opts.filename ?? ("name" in file && typeof file.name === "string" ? file.name : "upload"));
            return request("POST", `/files/${seg(bucket)}`, { form });
          },
          get: (id: number) => request("GET", `/files/${seg(bucket)}/${seg(id)}`),
          delete: (id: number) => request("DELETE", `/files/${seg(bucket)}/${seg(id)}`),
        },
      ]),
    ),
    actions: Object.fromEntries(
      SCHEMA.actions.map((name: string) => [name, (input?: unknown) => request("POST", `/actions/${seg(name)}`, { json: input ?? {} })]),
    ),
    globals: Object.fromEntries(
      SCHEMA.globals.map((name: string) => [
        name,
        {
          get: () => request("GET", `/globals/${seg(name)}`),
          update: (patch: unknown) => request("PATCH", `/globals/${seg(name)}`, { json: patch }),
        },
      ]),
    ),
    auth,
    schema: () => request("GET", "/_schema"),
  };
  for (const key of Object.keys(SCHEMA.collections)) client[key] = collection(key as CollectionKey);
  hubs.set(client, realtime);
  return client as unknown as Client;
}

type Hub = { subscribe(collections: readonly string[], onChange: (notice: ChangeNotice | null) => void): () => void };
const hubs = new WeakMap<object, Hub>();

/**
 * Runs `read` now and again whenever one of `collections` (schema keys) changes, while `client`'s
 * transport is live, or on its polling interval otherwise. For reads `watch` on a collection can't
 * express: an action's result, a `get`, a global. Returns a function that stops watching.
 */
export function watch<T>(
  client: Client,
  collections: readonly CollectionKey[],
  read: () => Promise<T>,
  onResult: (value: T) => void,
  onError?: (error: ZeroError) => void,
): () => void {
  const hub = hubs.get(client) ?? (() => {
    throw new TypeError("watch() needs a client from createClient()");
  })();
  return watchWith(hub, collections, read, onResult, onError);
}

function watchWith<T>(hub: Hub, collections: readonly string[], read: () => Promise<T>, onResult: (value: T) => void, onError?: (error: ZeroError) => void): () => void {
  // Coalesced: one read in flight; changes during it schedule exactly one more.
  let stopped = false;
  let running = false;
  let again = false;
  const refresh = async (): Promise<void> => {
    if (running) {
      again = true;
      return;
    }
    running = true;
    try {
      do {
        again = false;
        try {
          const value = await read();
          if (!stopped) onResult(value);
        } catch (e) {
          if (!stopped) onError?.(e instanceof ZeroError ? e : networkError(String(e)));
        }
      } while (again && !stopped);
    } finally {
      running = false;
    }
  };
  const unsubscribe = hub.subscribe(collections, () => void refresh());
  void refresh();
  return () => {
    stopped = true;
    unsubscribe();
  };
}

/** The collection and every collection its `with` loads: a change to any of them refreshes the read. */
function dependencies(key: string, w: unknown, seen = new Set<string>()): string[] {
  seen.add(key);
  const relations: Record<string, { collection?: string }> = (SCHEMA.collections as Record<string, { relations?: Record<string, { collection?: string }> }>)[key]?.relations ?? {};
  if (isObject(w)) {
    for (const [name, v] of Object.entries(w)) {
      const target = relations[name]?.collection;
      if (!v || typeof target !== "string" || seen.has(target)) continue;
      dependencies(target, isObject(v) ? v.with : undefined, seen);
    }
  }
  return [...seen];
}

/** Problem documents map field by field; WordPress core errors (`{ code, message, data: { status } }`) are mapped onto the same shape. */
function toError(res: Response, body: unknown): ZeroError {
  if (isObject(body) && typeof body.code === "string") {
    const detail = typeof body.detail === "string" ? body.detail : typeof body.message === "string" ? body.message : res.statusText;
    return new ZeroError({
      type: typeof body.type === "string" ? body.type : "about:blank",
      title: typeof body.title === "string" ? body.title : res.statusText || `HTTP ${res.status}`,
      status: typeof body.status === "number" ? body.status : res.status,
      code: body.code,
      detail,
      ...(typeof body.pointer === "string" ? { pointer: body.pointer } : {}),
    });
  }
  return new ZeroError({
    type: "about:blank",
    title: res.statusText || `HTTP ${res.status}`,
    status: res.status,
    code: "http_error",
    detail: `HTTP ${res.status}`,
  });
}
