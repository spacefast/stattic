// TypedPress typed client runtime. `typedpress build` copies this file next to the generated `types.ts`
// (interfaces) and `schema.ts` (the SCHEMA descriptor) in `tp/_generated/client/`. Do not edit the copy.
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

/**
 * A read. Await it for one answer, or `subscribe` to keep it current: the callback gets the result
 * now and again whenever it changes, always as this client's visitor reads it.
 */
export interface Query<T> extends Promise<T> {
  /** The same for the same call with the same arguments: what a cache or a hook keys on. */
  readonly key: string;
  /**
   * Calls `onData` with the result, and again each time it changes. `onError` gets the problem of a
   * read the server refuses (a `get` whose row is missing or unreadable is a 404) and network
   * failures; the subscription continues. `seed` is an answer rendered on the server
   * (`TypedPress\Live\Module::evaluate`): its data is delivered at once and its token sent with the
   * first request. Returns a function that ends the subscription.
   */
  subscribe(onData: (data: T) => void, onError?: (error: TypedPressError) => void, seed?: { token: string; data: T }): () => void;
  /**
   * Shows subscribers `update(current)` at once, before the server has answered a write. Call
   * `settle()` when the write succeeded (the change stays until the server's next answer replaces
   * it) or `rollback()` when it failed (subscribers get the server's data back).
   */
  optimistic(update: (current: T) => T): { settle(): void; rollback(): void };
}

export interface CollectionClient<K extends CollectionKey> {
  list<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(q?: ListQuery<K, ExactWith<K, W>>): Query<RowWith<K, W>[]>;
  /**
   * One page and the `cursor` of the next (null on the last page): pass it back as `cursor`. A page
   * with `offset` can be awaited but not subscribed to (`subscribe` throws): subscribe by `cursor`.
   */
  paginate<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(
    q?: ListQuery<K, ExactWith<K, W>> & { cursor?: string | null },
  ): Query<{ rows: RowWith<K, W>[]; cursor: string | null }>;
  /** With `locale`, the row of `id`'s translation group in that locale (or its fallback). */
  get<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(id: IdOf<K>, q?: { with?: ExactWith<K, W> } & LocaleQuery<K>): Query<RowWith<K, W>>;
  /** How many rows `list` with this filter would return without a limit. */
  count(q?: { where?: Collections[K]["filter"] } & StatusQuery<K> & LocaleQuery<K>): Query<number>;
  create(data: Collections[K]["insert"]): Promise<Row<K>>;
  /**
   * On a collection, add `_rev` (from a read) to the patch to refuse the update when the row changed
   * since: a 409 `stale_revision`. While someone else has the row open in the editor the update is a
   * 409 `locked`; `takeover: true` takes over their edit lock.
   */
  update(id: IdOf<K>, patch: Collections[K]["patch"] & WriteConditions): Promise<Row<K>>;
  delete(id: IdOf<K>, options?: WriteConditions): Promise<Row<K>>;
  /** `list(q).subscribe(onRows, onError)`. Returns a function that stops watching. */
  watch<const W extends Collections[K]["with"] = Extract<{}, Collections[K]["with"]>>(
    q: ListQuery<K, ExactWith<K, W>> | undefined,
    onRows: (rows: RowWith<K, W>[]) => void,
    onError?: (error: TypedPressError) => void,
  ): () => void;
  revisions: {
    /** Newest first. Needs `update` access on the row. */
    list(id: IdOf<K>): Promise<RevisionSummary[]>;
    get(id: IdOf<K>, revisionId: number): Promise<Row<K>>;
    /** Runs the update pipeline with the revision as the patch; returns the updated row. */
    restore(id: IdOf<K>, revisionId: number): Promise<Row<K>>;
  };
  /**
   * Save `patch` as the item's staged version: the live row stays as it is until `promote`. One
   * staged version per item, shared by everyone who may update it; each call adds to it. Post
   * kinds only. Every staged call needs `update` access on the row, and answers 404 without it.
   */
  stage(id: IdOf<K>, patch: Collections[K]["patch"] & WriteConditions): Promise<StagedVersion<K>>;
  /** The staged version: the row as it would be once promoted. 404 when nothing is staged. */
  staged(id: IdOf<K>): Promise<StagedVersion<K>>;
  /** The staged fields whose value differs from the live row, with both values. */
  compare(id: IdOf<K>): Promise<StagedComparison<K>>;
  /**
   * Make the staged version live, as an update of the row by the caller. With `at` (a future ISO
   * time) the promote is scheduled instead, and `at: null` cancels a scheduled one; both return
   * the staged version.
   */
  promote(id: IdOf<K>, options?: WriteConditions): Promise<Row<K>>;
  promote(id: IdOf<K>, options: { at: string | null } & WriteConditions): Promise<StagedVersion<K>>;
  /** End the staged version. It stays in the row's revisions, as `revision`. */
  discard(id: IdOf<K>, options?: WriteConditions): Promise<{ revision: number }>;
  /** A signed link to the staged version that works without signing in for `ttl` seconds (an hour by default). */
  previewLink(id: IdOf<K>, options?: { ttl?: number }): Promise<StagedPreview>;
}

/** A staged version: the row once promoted, the fields that are staged, who staged last. */
/**
 * A write's conditions on a collection's row: `_rev`, the token a read returned (the row's own, or a
 * staged version's for stage, promote and discard), refuses the write if the row changed since;
 * `takeover` takes over another user's edit lock.
 */
export interface WriteConditions {
  _rev?: string;
  takeover?: boolean;
}

export interface StagedVersion<K extends CollectionKey> {
  /** The revision holding it. */
  id: number;
  row: Row<K>;
  fields: (keyof Row<K> & string)[];
  by: { id: number; name: string } | null;
  at: string;
  /** When a scheduled promote runs, or null. */
  promoteAt: string | null;
  /** The staged version's token, for the next stage, promote or discard. */
  _rev: string;
  /** Why the last scheduled promote did not run. */
  error?: { code: string; detail: string; at: string };
}

export interface StagedComparison<K extends CollectionKey> extends Omit<StagedVersion<K>, "row" | "fields"> {
  changes: { [F in keyof Row<K> & string]: { field: F; live: Row<K>[F]; staged: Row<K>[F] } }[keyof Row<K> & string][];
}

export interface StagedPreview {
  token: string;
  expiresAt: string;
  /** Reads the staged version without signing in. */
  api: string;
  /** The item's own page showing the staged version, when the kind has a public URL. */
  url: string | null;
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
  /** `GET /__tp/api/v1`: the app's schema (kinds, fields, globals, actions), as `typedpress pull` reads it. */
  schema(): Promise<unknown>;
};

/** One committed transaction's changes, as the runtime's `tp_realtime_changes` hook sends them. */
export interface ChangeNotice {
  id: string;
  at: string;
  /** `ids: null` means "some rows changed": too many to list, or ids withheld from a shared room. */
  changes: { collection: string; ops: ("create" | "update" | "delete")[]; ids: (string | number)[] | null }[];
}

/**
 * Delivers change notices from the host (Cast, a WebSocket relay, Pusher, ...). TypedPress ships none.
 * `connect` starts delivery and returns a stop function; call `status("live")` once notices flow
 * and `status("down")` when they stop, so the client polls meanwhile.
 */
export interface RealtimeTransport {
  connect(deliver: (notice: ChangeNotice) => void, status: (state: "live" | "down") => void): () => void;
}

export interface RealtimeOptions {
  /** The transport. Default: the one a host registered with `registerRealtimeTransport`; `false` = polling only. */
  transport?: RealtimeTransport | false;
  /**
   * How often subscriptions ask the server while no transport is live. Default: adaptive, between
   * the bounds the server gives (2 s after a change, growing by half up to 30 s while nothing
   * changes, each wait jittered by 20%). `{ min, max }` sets the bounds, a number a fixed interval,
   * and `false` turns polling off. A hidden tab doesn't poll; it asks once when it is shown again.
   */
  pollMs?: number | false | { min?: number; max?: number };
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
  const g = globalThis as { __tpRealtime?: Registry };
  return (g.__tpRealtime ??= { waiters: new Set() });
}

/** For host scripts: every client on this page, created before or after, uses this transport by default. */
export function registerRealtimeTransport(transport: RealtimeTransport): void {
  const r = registry();
  r.transport = transport;
  for (const wake of [...r.waiters]) wake();
}

export interface ClientOptions {
  /** Base URL including the API prefix: "https://x.test/__tp/api/v1". Default: `/__tp/api/v1` on the page's origin. */
  url?: string;
  /** WordPress cookie auth: the REST nonce; sent as `X-WP-Nonce` with `credentials: "same-origin"`. */
  nonce?: string;
  fetch?: typeof fetch;
  /** Extra headers on every request (e.g. `Authorization: Basic ...` for application passwords). They win over the above. */
  headers?: Record<string, string>;
  /** Frontend sign-in (design §6.2). */
  auth?: AuthOptions;
  /** Live updates for `subscribe` and `watch`. */
  realtime?: RealtimeOptions;
}

export interface AuthOptions {
  /** The typedpress-identity OIDC issuer (`https://x.test/identity`). Default: `issuer` from `GET /auth/config`. */
  issuer?: string;
  /** The registered application's client id. */
  clientId?: string;
  /** Where the issuer sends the browser back (the page that calls `handleCallback()`). */
  redirectUri?: string;
  /** Where tokens live: in memory (default) or `sessionStorage` under `typedpress.auth:<base url>`. */
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
export class TypedPressError extends Error {
  readonly status: number;
  readonly code: string;
  readonly detail: string;
  readonly title: string;
  readonly type: string;
  readonly pointer?: string;

  constructor(problem: Problem) {
    super(problem.detail);
    this.name = "TypedPressError";
    this.status = problem.status;
    this.code = problem.code;
    this.detail = problem.detail;
    this.title = problem.title;
    this.type = problem.type;
    this.pointer = problem.pointer;
  }
}

function networkError(detail: string): TypedPressError {
  return new TypedPressError({ type: "about:blank", title: "Network error", status: 0, code: "network", detail });
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

/** One subscribed read, as `POST /_live` takes it: the kind query API's call on a kind. */
type ReadCall =
  | { kind: string; op: "list" | "paginate" | "get" | "count"; args?: Json; id?: string | number }
  /** One `<Live>` region of a page (TypedPress's own pages only): `{ html, kinds }`. */
  | { kind?: undefined; op: "page"; args: { url: string; region: string }; id?: undefined };

/** The client's query as that call's arguments: `status` folded into `where`, `orderBy` as an object. */
function liveArgs(q: Json | undefined): Json {
  const args: Json = {};
  if (!q) return args;
  const where = typeof q.status !== "string" ? q.where : q.where === undefined ? { status: q.status } : { AND: [q.where, { status: q.status }] };
  if (where !== undefined) args.where = where;
  const order = orderObject(q.orderBy);
  if (isObject(order) && Object.keys(order).length > 0) args.orderBy = order;
  if (typeof q.limit === "number") args.limit = q.limit;
  if (typeof q.offset === "number") args.offset = q.offset;
  if (typeof q.cursor === "string") args.cursor = q.cursor;
  if (typeof q.locale === "string") args.locale = q.locale;
  if (isObject(q.with) && Object.keys(withJson(q.with)).length > 0) args.with = withJson(q.with);
  return args;
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
  /** Return the whole `{ data, cursor }` envelope of a list instead of `data`. */
  envelope?: boolean;
}

/** What `auth.session()` exposes, plus what refresh and sign-out need. Stored as JSON under `typedpress.auth:<base>`. */
interface Held extends Session {
  clientId?: string;
  idToken?: string;
  /** One sign-in: random when tokens are first issued, kept by refreshes. Shared through storage. */
  sid?: string;
}

/** TypedPress's guest token response (`/auth/guest`). */
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

const PKCE_KEY = "typedpress.auth.pkce";

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

function authError(status: number, code: string, detail: string): TypedPressError {
  return new TypedPressError({ type: "about:blank", title: code, status, code, detail });
}

/** In-flight refreshes of sessionStorage-backed sessions, by storage key (shared by clients in this tab). */
const refreshes = new Map<string, Promise<boolean>>();

export function createClient(options: ClientOptions = {}): Client {
  const base = (options.url ?? "/__tp/api/v1").replace(/\/+$/, "");
  const authOpts = options.auth ?? {};
  const doFetch = (input: string, init: RequestInit) => (options.fetch ?? globalThis.fetch)(input, init);

  // Token storage: memory by default, sessionStorage on request. The record always exists once
  // written: signed out, it keeps only a `sid`, so every sign-in and sign-out (including from the
  // anonymous state) is a distinct generation that late results can be checked against.
  const storageKey = `typedpress.auth:${base}`;
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
    identityChanged?.();
    return true;
  }
  /** Set once subscriptions exist: a sign-in or sign-out asks every one of them again, as the new visitor. */
  let identityChanged: (() => void) | null = null;
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
      return (send.envelope ? { rows: parsed.data, cursor: parsed.cursor ?? null } : parsed.data) as T;
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
      if (!(e instanceof TypedPressError) || e.status !== 0) switchTo(null, started);
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
        if (!(e instanceof TypedPressError && e.status === 401)) throw e;
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
  const pollOpt = rtOpts.pollMs;
  // The exported watch() helper re-runs an arbitrary read on a fixed interval.
  const pollMs = pollOpt === false ? false : typeof pollOpt === "number" ? pollOpt : pollOpt?.max ?? 30_000;
  const livePollMs = rtOpts.livePollMs === undefined ? 60_000 : rtOpts.livePollMs;
  // `poll` listeners are refreshed by the interval above; subscriptions keep their own (see `subscriptions`).
  const listeners = new Set<{ collections: ReadonlySet<string> | null; poll: boolean; onChange: (n: ChangeNotice | null) => void }>();
  const statusListeners = new Set<() => void>();
  let disconnect: (() => void) | null = null;
  let live = false;
  // One timer: `pollMs` while down, `livePollMs` (jittered) while live. Rearmed after each refresh.
  let poller: ReturnType<typeof setTimeout> | null = null;
  let pollerLive = false;
  const dispatch = (notice: ChangeNotice | null) => {
    for (const l of [...listeners]) {
      if (notice === null ? l.poll : l.collections === null || notice.changes.some((c) => l.collections?.has(c.collection))) l.onChange(notice);
    }
  };
  const syncPolling = () => {
    const every = live ? livePollMs : pollMs;
    const want = [...listeners].some((l) => l.poll) && every !== false;
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
        if (live !== was) for (const changed of [...statusListeners]) changed();
      },
    );
    disconnect = () => {
      current = false;
      live = false;
      stop();
    };
  };
  const realtime = {
    subscribe(collections: readonly string[] | null, onChange: (notice: ChangeNotice | null) => void): () => void {
      const entry = { collections: collections === null ? null : new Set(collections), poll: collections !== null, onChange };
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

  // Subscriptions (docs/realtime.md). Every subscribed read of this client travels in one
  // `POST /_live`, each with the token of its last answer; the server answers "not modified" or the
  // fresh result. Without a live transport the requests repeat on an adaptive interval; with one, a
  // notice asks at once for the reads it names and the interval becomes the slow safety poll.
  interface Subscriber { onData: (data: unknown) => void; onError?: (error: TypedPressError) => void }
  interface Subscription {
    descriptor: ReadCall;
    deps: ReadonlySet<string>;
    token?: string;
    has: boolean;
    data: unknown;
    error?: TypedPressError;
    due: boolean;
    subscribers: Set<Subscriber>;
    /** Optimistic updates, oldest first. `after`: the last request for this read sent before the write was known to be committed (null: not settled). */
    overlays: { apply: (current: unknown) => unknown; after: number | null }[];
    /** Requests that carried this read: the last one sent, and the last one answered. */
    sent: number;
    answered: number;
    /** `sent` when the last write through this client returned. */
    sentAtWrite: number;
  }
  const subscriptions = new Map<string, Subscription>();
  let serverWindow = { min: 2_000, max: 30_000 };
  const bounds = () =>
    typeof pollOpt === "number" ? { min: pollOpt, max: pollOpt } : { min: (pollOpt || {}).min ?? serverWindow.min, max: (pollOpt || {}).max ?? serverWindow.max };
  let gap = bounds().min;
  let liveTimer: ReturnType<typeof setTimeout> | null = null;
  /** When the armed poll fires (Date.now() milliseconds). */
  let liveAt = 0;
  let asking = false;
  let askAgain = false;
  let kicked = false;
  // Requests are numbered as they are sent. An answer to a request for a read sent after a write
  // committed holds that write; each read keeps the numbers of its own requests.
  let sent = 0;
  let writes = 0;
  let stopNotices: (() => void) | null = null;
  type Listens = { addEventListener?(type: string, fn: (event: unknown) => void): void; removeEventListener?(type: string, fn: (event: unknown) => void): void };
  const page = () => (globalThis as { document?: Listens & { visibilityState?: string; prerendering?: boolean } }).document;
  // A prerendered page is hidden until it is activated, whatever visibilityState says meanwhile.
  const hidden = () => page()?.visibilityState === "hidden" || page()?.prerendering === true;
  const markDue = (which: (s: Subscription) => boolean) => {
    for (const s of subscriptions.values()) if (which(s)) s.due = true;
  };
  /**
   * Runs one subscriber callback. A subscriber's own failure (its render threw, its error handler
   * threw) is its own: the others, the answer and the requests after it all stand. It is reported
   * as the page reports an uncaught error.
   */
  const guarded = (fn: () => void) => {
    try {
      fn();
    } catch (e) {
      const report = (globalThis as { reportError?: (e: unknown) => void }).reportError;
      if (typeof report === "function") report(e);
      else console.error(e);
    }
  };
  /** Tells every subscriber of `s` about `error`, each in its own guarded call. */
  const fail = (s: Subscription, error: TypedPressError) => {
    for (const to of [...s.subscribers]) guarded(() => to.onError?.(error));
  };
  const deliver = (s: Subscription, to: Subscriber) => {
    if (s.error) return guarded(() => to.onError?.(s.error!));
    if (!s.has) return;
    // A `get` of a row that is missing, or that this visitor may not read, is the API's 404.
    if (s.descriptor.op === "get" && s.data === null) return guarded(() => to.onError?.(new TypedPressError({ type: "about:blank", title: "Not found", status: 404, code: "not_found", detail: `No ${s.descriptor.kind} row` })));
    const data = s.overlays.reduce((current, overlay) => overlay.apply(current), s.data);
    guarded(() => to.onData(data));
  };
  const emit = (s: Subscription) => {
    for (const to of [...s.subscribers]) deliver(s, to);
  };
  /**
   * Arms the interval poll. It asks for every subscription, so other requests (a notice, a write,
   * a new subscriber) don't restart it: `restart` is only for a changed interval or state.
   */
  const reschedule = (restart = false) => {
    const every = live ? livePollMs : pollOpt === false ? false : gap;
    const off = subscriptions.size === 0 || every === false || hidden();
    if (off) {
      if (liveTimer !== null) clearTimeout(liveTimer);
      liveTimer = null;
      return;
    }
    // Jittered, so the tabs of a busy page don't all ask at once. A fixed interval is taken as given.
    const jitter = !live && typeof pollOpt === "number" ? 1 : 0.8 + Math.random() * 0.4;
    const due = Date.now() + Math.round(every * jitter);
    // An armed poll is kept unless this one would come sooner: other requests (a notice, a write,
    // a new subscriber) ask only for some reads, and must never push back the one that asks for all.
    if (liveTimer !== null && !restart && due >= liveAt) return;
    if (liveTimer !== null) clearTimeout(liveTimer);
    liveAt = due;
    liveTimer = setTimeout(() => {
      liveTimer = null;
      markDue(() => true);
      void ask(true);
    }, due - Date.now());
  };
  /** One answer applied to its subscription; true when the result changed. */
  const settle = (s: Subscription, result: unknown, request: number): boolean => {
    if (!isObject(result)) {
      fail(s, networkError("POST /_live: the answer has no result for this read"));
      return false;
    }
    if (result.pending === true) {
      // The server ran out of time for this request; ask again at once. No data came, so an
      // optimistic update stays: nothing yet holds its write.
      s.due = askAgain = true;
      return false;
    }
    // An answer with data (fresh, or "not modified" over what is held) holds every write committed
    // before its request was sent: updates settled before then are replaced by it.
    const overlays = s.overlays.length;
    s.overlays = s.overlays.filter((o) => o.after === null || request <= o.after);
    const dropped = s.overlays.length !== overlays;
    if (isObject(result.error)) {
      s.token = undefined;
      const error = toError(new Response(null, { status: typeof result.error.status === "number" && result.error.status >= 400 ? result.error.status : 500 }), result.error);
      // A read that stays refused reports it once, not on every poll.
      const same = s.error !== undefined && s.error.status === error.status && s.error.code === error.code && s.error.detail === error.detail;
      s.error = error;
      if (!same || dropped) emit(s);
      return false;
    }
    if (typeof result.token === "string") s.token = result.token;
    const changed = result.changed === true;
    if (changed) {
      s.has = true;
      s.data = result.data;
    }
    const recovered = s.error !== undefined;
    s.error = undefined;
    if (changed || dropped || recovered) emit(s);
    return changed;
  };
  async function ask(fromTimer = false): Promise<void> {
    if (asking) {
      askAgain = true;
      return;
    }
    asking = true;
    try {
      do {
        askAgain = false;
        // Another client on the same storage signed in or out: this one's reads are the old visitor's.
        if (currentSid() !== askedAs) forget();
        const due = [...subscriptions.entries()].filter(([, s]) => s.due);
        if (due.length === 0) break;
        let changed = false;
        let failed = false;
        for (const batch of batches(due)) {
          const number = ++sent;
          const visitor = currentSid();
          for (const [, s] of batch) {
            s.due = false;
            s.sent = number;
          }
          try {
            const out = await request<{ results?: unknown[]; poll?: { minMs?: unknown; maxMs?: unknown } }>("POST", "/_live", {
              json: { queries: batch.map(([, s]) => (s.token === undefined ? s.descriptor : { ...s.descriptor, token: s.token })) },
            });
            // Sent as a visitor who has since signed in or out: the new visitor's request is already due.
            if (currentSid() !== visitor) continue;
            const { minMs, maxMs } = out.poll ?? {};
            if (typeof minMs === "number" && typeof maxMs === "number" && minMs > 0 && maxMs >= minMs) serverWindow = { min: minMs, max: maxMs };
            // A subscription ended, or ended and started again, while the request was out has no use for its answer.
            batch.forEach(([key, s], j) => {
              if (subscriptions.get(key) !== s) return;
              s.answered = Math.max(s.answered, number);
              changed = settle(s, out.results?.[j], number) || changed;
            });
          } catch (e) {
            if (currentSid() !== visitor) continue;
            failed = true;
            const error = e instanceof TypedPressError ? e : networkError(String(e));
            for (const [key, s] of batch) if (subscriptions.get(key) === s) fail(s, error);
          }
        }
        // Back off while nothing changes, further while the server fails; start over after a change.
        const { min, max } = bounds();
        gap = failed ? Math.min(300_000, Math.max(gap, min) * 2) : changed ? min : Math.min(max, Math.max(gap, min) * 1.5);
      } while (askAgain);
    } finally {
      asking = false;
      // The interval's own poll arms the next one; any other request may only bring it sooner.
      reschedule(fromTimer);
    }
  }
  /**
   * Requests of at most 20 reads and 30,000 bytes, under the endpoint's 20 and 32 KiB. A read too
   * large alone still goes alone, and the server answers it with its 413.
   */
  const utf8 = new TextEncoder();
  const batches = <T extends [string, Subscription]>(due: T[]): T[][] => {
    const out: T[][] = [];
    let batch: T[] = [];
    let size = 0;
    for (const entry of due) {
      const s = entry[1];
      // UTF-8 bytes, as the server counts its body, not UTF-16 characters.
      const bytes = utf8.encode(JSON.stringify(s.token === undefined ? s.descriptor : { ...s.descriptor, token: s.token })).length + 1;
      if (batch.length > 0 && (batch.length === 20 || size + bytes > 30_000)) {
        out.push(batch);
        batch = [];
        size = 0;
      }
      batch.push(entry);
      size += bytes;
    }
    if (batch.length > 0) out.push(batch);
    return out;
  };
  /** Ask soon: every subscription marked due in this turn travels in one request. */
  const kick = () => {
    if (kicked) return;
    kicked = true;
    queueMicrotask(() => {
      kicked = false;
      void ask();
    });
  };
  /** A write through this client: the reads of that collection (every read when unknown) are asked for at once. */
  const wrote = <T>(written: Promise<T>, collection?: string): Promise<T> =>
    written.then((result) => {
      writes++;
      for (const s of subscriptions.values()) s.sentAtWrite = s.sent;
      if (subscriptions.size > 0) {
        markDue((s) => collection === undefined || s.deps.has(collection));
        kick();
      }
      return result;
    });
  /** The visitor the held tokens and data are for: the stored session's id when they were last asked. */
  let askedAs = currentSid();
  /** A new visitor: every subscription is asked again, without the previous visitor's tokens. */
  const forget = () => {
    askedAs = currentSid();
    for (const s of subscriptions.values()) {
      s.due = true;
      s.token = undefined; // a token is for the visitor it was issued to
    }
    gap = bounds().min;
  };
  identityChanged = () => {
    if (subscriptions.size === 0) return;
    forget();
    if (!hidden()) kick();
  };
  const shown = () => {
    if (hidden()) return reschedule(true); // stops the interval
    gap = bounds().min;
    markDue(() => true);
    kick();
  };
  /** `pageshow`: only a page restored from the back/forward cache (`persisted`) needs to ask again. */
  const restored = (event: unknown) => {
    if ((event as { persisted?: boolean } | null)?.persisted === true) shown();
  };
  function subscribe(key: string, descriptor: ReadCall, deps: readonly string[], to: Subscriber, seed?: { token: string; data: unknown }): () => void {
    let s = subscriptions.get(key);
    if (!s) {
      s = { descriptor, deps: new Set(deps), has: seed !== undefined, data: seed?.data, token: seed?.token, due: true, subscribers: new Set(), overlays: [], sent: 0, answered: 0, sentAtWrite: 0 };
      subscriptions.set(key, s);
      if (subscriptions.size === 1) {
        // Shown again: a tab made visible, a prerendered page activated (speculation rules), or a page
        // restored from the back/forward cache, which may have been frozen for a long time.
        page()?.addEventListener?.("visibilitychange", shown);
        page()?.addEventListener?.("prerenderingchange", shown);
        (globalThis as Listens).addEventListener?.("pageshow", restored);
        const onStatus = () => {
          // Catch up on what changed while the transport was down; a hidden tab does so when shown.
          if (live) markDue(() => true);
          if (live && !hidden()) kick();
          reschedule(true);
        };
        statusListeners.add(onStatus);
        const stop = realtime.subscribe(null, (notice) => {
          markDue((sub) => notice === null || notice.changes.some((c) => sub.deps.has(c.collection)));
          if (!hidden()) kick(); // a hidden tab asks when it is shown again
        });
        stopNotices = () => {
          statusListeners.delete(onStatus);
          stop();
        };
      }
    }
    const subscription = s;
    subscription.subscribers.add(to);
    // What an earlier subscriber of the same read already holds, at once.
    if (subscription.has || subscription.error) queueMicrotask(() => subscription.subscribers.has(to) && deliver(subscription, to));
    // A hidden tab (opened in the background, or prerendered) sends nothing: a seeded read already
    // shows its server-rendered answer, and an unseeded one has no one to show an answer to. Both
    // stay due, and the `shown` handler asks for them when the tab becomes visible.
    if (!hidden()) kick();
    return () => {
      subscription.subscribers.delete(to);
      if (subscription.subscribers.size > 0 || subscriptions.get(key) !== subscription) return;
      subscriptions.delete(key);
      if (subscriptions.size > 0) return;
      page()?.removeEventListener?.("visibilitychange", shown);
      page()?.removeEventListener?.("prerenderingchange", shown);
      (globalThis as Listens).removeEventListener?.("pageshow", restored);
      stopNotices?.();
      stopNotices = null;
      gap = bounds().min;
      reschedule(true);
    };
  }
  function optimistic(key: string, update: (current: unknown) => unknown): { settle(): void; rollback(): void } {
    const s = subscriptions.get(key);
    const overlay = { apply: update, after: null as number | null };
    const since = writes;
    // Kept even before the first answer: it applies to that answer when it arrives.
    if (s) {
      s.overlays.push(overlay);
      emit(s);
    }
    const drop = () => {
      if (!s || !s.overlays.includes(overlay)) return;
      s.overlays = s.overlays.filter((o) => o !== overlay);
      if (subscriptions.get(key) === s) emit(s);
    };
    return {
      settle() {
        if (!s || !s.overlays.includes(overlay)) return;
        // Kept until an answer that holds the write arrives: one to a request sent after the write
        // returned (a write through this client), or else to one sent from now on.
        overlay.after = writes > since ? s.sentAtWrite : s.sent;
        if (s.answered > overlay.after) return drop(); // that answer is already shown
        if (s.sent > overlay.after || subscriptions.get(key) !== s) return; // it is on its way
        s.due = true;
        kick();
      },
      rollback: drop,
    };
  }
  /** One answer to one read through `/_live`, for reads with no route of their own. */
  async function once<T>(descriptor: ReadCall): Promise<T> {
    const out = await request<{ results?: unknown[] }>("POST", "/_live", { json: { queries: [descriptor] } });
    const result = out.results?.[0];
    if (isObject(result) && isObject(result.error)) throw toError(new Response(null, { status: typeof result.error.status === "number" && result.error.status >= 400 ? result.error.status : 500 }), result.error);
    if (!isObject(result) || !("data" in result)) throw networkError("POST /_live: the answer has no result");
    return result.data as T;
  }
  /**
   * `refuse`: why this read can't be subscribed to (it can still be awaited). `keyed`: arguments
   * that tell two reads apart without being sent with the subscription (a paginate's `offset`).
   */
  function query<T>(descriptor: ReadCall, deps: readonly string[], run: () => Promise<T>, refuse?: string, keyed?: Json): Query<T> {
    // A page read is keyed apart from every kind read, whatever a kind is called.
    const key = JSON.stringify(descriptor.op === "page" ? ["page", descriptor.args.url, descriptor.args.region] : [descriptor.kind, descriptor.op, descriptor.id ?? null, descriptor.args ?? {}, ...(keyed ? [keyed] : [])]);
    // Nothing is sent until the read is awaited or subscribed to.
    let started: Promise<T> | undefined;
    const promise = () => (started ??= run());
    const read: Omit<Query<T>, typeof Symbol.toStringTag> & { [Symbol.toStringTag]: string } = {
      key,
      then: (onFulfilled, onRejected) => promise().then(onFulfilled, onRejected),
      catch: (onRejected) => promise().catch(onRejected),
      finally: (onFinally) => promise().finally(onFinally),
      [Symbol.toStringTag]: "Query",
      subscribe: (onData, onError, seed) => {
        if (refuse !== undefined) throw new TypeError(refuse);
        return subscribe(key, descriptor, deps, { onData: onData as (data: unknown) => void, onError }, seed);
      },
      optimistic: (update) => optimistic(key, update as (current: unknown) => unknown),
    };
    return read as Query<T>;
  }

  /** A DELETE's write conditions travel in its query: `?_rev=…&takeover=1`. */
  function conditions(options?: WriteConditions): URLSearchParams | undefined {
    if (!options) return undefined;
    const query = new URLSearchParams();
    if (options._rev !== undefined) query.set("_rev", options._rev);
    if (options.takeover) query.set("takeover", "1");
    return query;
  }

  function collection(key: CollectionKey) {
    const desc: { path: string } = SCHEMA.collections[key];
    const path = `/${seg(desc.path)}`;
    const one = (id: string | number) => `${path}/${seg(id)}`;
    const reads = (q: Json | undefined) => dependencies(key, q?.with, q?.where);
    const list = (q?: Json) => query({ kind: key, op: "list", args: liveArgs(q) }, reads(q), () => request("GET", path, { query: listParams(q) }));
    const paginate = (q?: Json) =>
      // A subscribed page is reached by `cursor`: the endpoint's paginate takes no `offset`.
      query({ kind: key, op: "paginate", args: liveArgs(q && { ...q, offset: undefined }) }, reads(q), () => {
        const params = listParams(q);
        if (typeof q?.cursor === "string") params.set("cursor", q.cursor);
        return request("GET", path, { query: params, envelope: true });
      }, typeof q?.offset === "number" ? "paginate({ offset }) can be awaited but not subscribed to: page by cursor" : undefined, typeof q?.offset === "number" ? { offset: q.offset } : undefined);
    return {
      list,
      paginate,
      watch: (q: Json | undefined, onRows: (rows: unknown) => void, onError?: (error: TypedPressError) => void) => list(q).subscribe(onRows, onError),
      get: (id: string | number, q?: Json) =>
        query({ kind: key, op: "get", id, args: liveArgs(q ? { with: q.with, locale: q.locale } : undefined) }, reads(q), () =>
          request("GET", one(id), { query: listParams(q ? { with: q.with, locale: q.locale } : undefined) }),
        ),
      count: (q?: Json) => {
        const descriptor: ReadCall = { kind: key, op: "count", args: liveArgs(q ? { where: q.where, status: q.status, locale: q.locale } : undefined) };
        return query<number>(descriptor, dependencies(key, undefined, q?.where), () => once(descriptor));
      },
      create: (data: unknown) => wrote(request("POST", path, { json: data }), key),
      update: (id: string | number, patch: unknown) => wrote(request("PATCH", one(id), { json: patch }), key),
      delete: (id: string | number, options?: WriteConditions) => wrote(request("DELETE", one(id), { query: conditions(options) }), key),
      revisions: {
        // Every revision, newest first: the API pages them ({ data, cursor }), this follows the cursor.
        list: async (id: string | number) => {
          const all: unknown[] = [];
          let cursor: string | null = null;
          do {
            const query = new URLSearchParams({ limit: "100" });
            if (cursor !== null) query.set("cursor", cursor);
            const page = await request<{ rows: unknown[]; cursor: string | null }>("GET", `${one(id)}/revisions`, { query, envelope: true });
            all.push(...page.rows);
            cursor = page.cursor;
          } while (cursor !== null);
          return all;
        },
        get: (id: string | number, rid: number) => request("GET", `${one(id)}/revisions/${seg(rid)}`),
        restore: (id: string | number, rid: number) => request("POST", `${one(id)}/revisions/${seg(rid)}/restore`),
      },
      stage: (id: string | number, patch: unknown) => request("PATCH", `${one(id)}/staged`, { json: patch }),
      staged: (id: string | number) => request("GET", `${one(id)}/staged`),
      compare: (id: string | number) => request("GET", `${one(id)}/staged/compare`),
      promote: (id: string | number, options?: { at?: string | null } & WriteConditions) => request("POST", `${one(id)}/staged/promote`, { json: options ?? {} }),
      discard: (id: string | number, options?: WriteConditions) => request("DELETE", `${one(id)}/staged`, { query: conditions(options) }),
      previewLink: (id: string | number, options?: { ttl?: number }) => request("POST", `${one(id)}/staged/preview`, { json: options ?? {} }),
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
      // An action may write any kind: every subscribed read is asked for once it returns.
      SCHEMA.actions.map((name: string) => [name, (input?: unknown) => wrote(request("POST", `/actions/${seg(name)}`, { json: input ?? {} }))]),
    ),
    globals: Object.fromEntries(
      SCHEMA.globals.map((name: string) => [
        name,
        {
          get: () => request("GET", `/${seg(name)}`),
          update: (patch: unknown) => request("PATCH", `/${seg(name)}`, { json: patch }),
        },
      ]),
    ),
    auth,
    schema: () => request("GET", ""),
  };
  for (const key of Object.keys(SCHEMA.collections)) client[key] = collection(key as CollectionKey);
  hubs.set(client, realtime);
  pageReads.set(client, (url, region, deps) => query<{ html: string; kinds: string[] }>({ op: "page", args: { url, region } }, deps, () => once({ op: "page", args: { url, region } })));
  return client as unknown as Client;
}

type Hub = { subscribe(collections: readonly string[] | null, onChange: (notice: ChangeNotice | null) => void): () => void };
const hubs = new WeakMap<object, Hub>();
const pageReads = new WeakMap<object, (url: string, region: string, deps: readonly string[]) => Query<{ html: string; kinds: string[] }>>();

/**
 * @internal TypedPress's own `<Live>` regions only; not part of the client's API. One region of one of
 * this site's pages as a read: `{ html, kinds }`, kept current like any other subscription (the
 * same batch, polling and notices). `deps` are the kinds the server said the region read: a notice
 * or a write through `client` for one of them asks again at once.
 */
export function pageRead(client: Client, url: string, region: string, deps: readonly string[]): Query<{ html: string; kinds: string[] }> {
  const read = pageReads.get(client) ?? (() => {
    throw new TypeError("pageRead() needs a client from createClient()");
  })();
  return read(url, region, deps);
}

/**
 * Runs `read` now and again whenever one of `collections` (schema keys) changes, while `client`'s
 * transport is live, or on its polling interval otherwise. For reads a `subscribe` can't express:
 * an action's result, a global. Returns a function that stops watching.
 */
export function watch<T>(
  client: Client,
  collections: readonly CollectionKey[],
  read: () => Promise<T>,
  onResult: (value: T) => void,
  onError?: (error: TypedPressError) => void,
): () => void {
  const hub = hubs.get(client) ?? (() => {
    throw new TypeError("watch() needs a client from createClient()");
  })();
  return watchWith(hub, collections, read, onResult, onError);
}

function watchWith<T>(hub: Hub, collections: readonly string[], read: () => Promise<T>, onResult: (value: T) => void, onError?: (error: TypedPressError) => void): () => void {
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
          if (!stopped) onError?.(e instanceof TypedPressError ? e : networkError(String(e)));
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
function dependencies(key: string, w: unknown, where?: unknown, seen = new Set<string>()): string[] {
  seen.add(key);
  const relations: Record<string, { collection?: string }> = (SCHEMA.collections as Record<string, { relations?: Record<string, { collection?: string }> }>)[key]?.relations ?? {};
  if (isObject(w)) {
    for (const [name, v] of Object.entries(w)) {
      const target = relations[name]?.collection;
      if (!v || typeof target !== "string") continue;
      dependencies(target, isObject(v) ? v.with : undefined, isObject(v) ? v.where : undefined, seen);
    }
  }
  // A filter through a relation (`{ author: { name: "x" } }`, under AND, OR or NOT too) reads that kind.
  const filter = (f: unknown) => {
    if (Array.isArray(f)) return f.forEach(filter);
    if (!isObject(f)) return;
    for (const [name, v] of Object.entries(f)) {
      if (name === "AND" || name === "OR" || name === "NOT") filter(v);
      // `{ rel: { ... } }` filters the related rows; `{ rel: [] }` asks for rows with none of them.
      else if (typeof relations[name]?.collection === "string" && (isObject(v) || (Array.isArray(v) && v.length === 0))) dependencies(relations[name]!.collection!, undefined, isObject(v) ? v : undefined, seen);
    }
  };
  filter(where);
  return [...seen];
}

/** Problem documents map field by field; WordPress core errors (`{ code, message, data: { status } }`) are mapped onto the same shape. */
function toError(res: Response, body: unknown): TypedPressError {
  if (isObject(body) && typeof body.code === "string") {
    const detail = typeof body.detail === "string" ? body.detail : typeof body.message === "string" ? body.message : res.statusText;
    return new TypedPressError({
      type: typeof body.type === "string" ? body.type : "about:blank",
      title: typeof body.title === "string" ? body.title : res.statusText || `HTTP ${res.status}`,
      status: typeof body.status === "number" ? body.status : res.status,
      code: body.code,
      detail,
      ...(typeof body.pointer === "string" ? { pointer: body.pointer } : {}),
    });
  }
  return new TypedPressError({
    type: "about:blank",
    title: res.statusText || `HTTP ${res.status}`,
    status: res.status,
    code: "http_error",
    detail: `HTTP ${res.status}`,
  });
}
