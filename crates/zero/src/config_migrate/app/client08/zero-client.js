// Zero 0.8 client compatibility module. `zero migrate app --from zero-0.8` writes it next to a
// converted client app and points the app's `@spacefast/zero/client` imports here.
//
// It keeps the 0.8 hooks' call shapes and runs each call as the v2 action the converter generated
// for the same handler: `useMutation("add")(a, b)` posts `{ <first input>: a, <second input>: b }`
// to `/wp-json/zero/v1/actions/add` and resolves to the action's `data`. HANDLERS lists each
// handler's input names in 0.8's positional order.
//
// What differs from 0.8: reads refresh after this browser's own writes and when the tab regains
// focus, not when another visitor writes. A visitor gets a v2 guest session on the first request
// that needs one, kept in sessionStorage with its refresh token: an expired access token is
// refreshed, so the guest keeps its identity and the rows it owns.
import { h, createContext, toChildArray } from "./preact/preact.js";
import { useContext, useEffect, useMemo, useState } from "./preact/hooks.js";
import HANDLERS from "./handlers.js";

const API = "/wp-json/zero/v1";
const SESSION = "zero08.session";

function held() {
  try {
    return JSON.parse(globalThis.sessionStorage?.getItem(SESSION) ?? "null");
  } catch {
    return null;
  }
}

function hold(session) {
  if (session) globalThis.sessionStorage?.setItem(SESSION, JSON.stringify(session));
  else globalThis.sessionStorage?.removeItem(SESSION);
}

async function send(method, path, body) {
  const headers = { accept: "application/json" };
  const session = held();
  if (session?.accessToken) headers.authorization = `Bearer ${session.accessToken}`;
  if (body !== undefined) headers["content-type"] = "application/json";
  const res = await fetch(API + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  const parsed = await res.json().catch(() => null);
  return { res, parsed, session };
}

function failure(res, parsed, fallback) {
  const message = typeof parsed?.detail === "string" ? parsed.detail : typeof parsed?.message === "string" ? parsed.message : fallback;
  return Object.assign(new Error(message), { code: typeof parsed?.code === "string" ? parsed.code : "http_error", status: res.status });
}

let guesting = null;
/** Starts a guest session; only for a visitor who holds none. */
function startGuest() {
  guesting ??= send("POST", "/auth/guest", {})
    .then(({ res, parsed }) => {
      const out = parsed?.data;
      if (!res.ok || typeof out?.accessToken !== "string") throw failure(res, parsed, "Could not start a guest session.");
      hold({ accessToken: out.accessToken, refreshToken: out.refreshToken, clientId: out.clientId });
      emitAuth();
    })
    .finally(() => {
      guesting = null;
    });
  return guesting;
}

let renewing = null;
/**
 * Renews the held session with its refresh token: same user, new access token, as runtime/client
 * does. Resolves true when the session was renewed (or another call already renewed it), and false
 * when it can't be: no refresh token, or the issuer answered without a token (a refused grant).
 * Throws, keeping the tokens for a later try, when the issuer can't be reached: a network failure,
 * or no usable /auth/config.
 */
function renew(sent) {
  renewing ??= (async () => {
    const current = held();
    if (current?.accessToken !== sent.accessToken) return current !== null;
    if (typeof current.refreshToken !== "string") return false;
    const config = await send("GET", "/auth/config");
    const issuer = config.parsed?.data?.issuer;
    if (!config.res.ok || typeof issuer !== "string") throw failure(config.res, config.parsed, "The sign-in issuer is unavailable.");
    const res = await fetch(`${issuer.replace(/\/+$/, "")}/token`, {
      method: "POST",
      headers: { "content-type": "application/x-www-form-urlencoded", accept: "application/json" },
      body: new URLSearchParams({ grant_type: "refresh_token", refresh_token: current.refreshToken, client_id: current.clientId ?? "" }).toString(),
    });
    const out = await res.json().catch(() => null);
    if (!res.ok || typeof out?.access_token !== "string") return false;
    // A sign-out or a new guest while this ran owns the session now; drop the rotated pair.
    if (held()?.accessToken !== current.accessToken) return held() !== null;
    hold({ ...current, accessToken: out.access_token, refreshToken: out.refresh_token ?? current.refreshToken });
    return true;
  })().finally(() => {
    renewing = null;
  });
  return renewing;
}

/**
 * One request with the session's 401 handling. A rejected token (`unauthenticated`) is renewed and
 * the request retried as the same user. A session that can't be renewed is dropped; with `guest`,
 * a new guest then takes its place, as one does for a visitor with no session. A network failure
 * while renewing rejects and keeps the session. Any other 401 is the handler refusing this user,
 * and the session stays.
 */
async function authed(method, path, body, { guest }) {
  let { res, parsed, session } = await send(method, path, body);
  if (res.status !== 401) return { res, parsed };
  if (!session) {
    // 0.8 gave every visitor a guest identity; v2 starts one when a request needs it.
    if (!guest) return { res, parsed };
    await startGuest();
    return send(method, path, body);
  }
  if (parsed?.code !== "unauthenticated") return { res, parsed };
  if (await renew(session)) {
    if (path !== "/me") void emitAuth();
    return send(method, path, body);
  }
  hold(null);
  if (!guest) return send(method, path, body);
  await startGuest();
  return send(method, path, body);
}

/** The v2 input for a 0.8 call: positional arguments become the handler's named inputs. */
export function input(name, args) {
  const handler = HANDLERS[name];
  if (!handler) throw new Error(`Zero has no query, mutation or action named ${name}.`);
  const out = {};
  handler.inputs.forEach((key, i) => {
    const value = handler.rest && i === handler.inputs.length - 1 ? args.slice(i) : args[i];
    if (value !== undefined) out[key] = value;
  });
  return out;
}

/**
 * `usePaginatedQuery(name, args)` as the handler's positional arguments: the `PaginationOptions`
 * parameter gets `{ numItems, cursor }`, and every other parameter the `args` entry of its name.
 */
export function paginatedArgs(name, args, pagination) {
  const handler = HANDLERS[name];
  if (!handler) throw new Error(`Zero has no query, mutation or action named ${name}.`);
  if (!handler.pagination) throw new Error(`${name} takes no PaginationOptions, so usePaginatedQuery cannot call it.`);
  return handler.inputs.map((key) => (key === handler.pagination ? pagination : args?.[key]));
}

/** Runs one 0.8 query, mutation or action as its v2 action and returns the result. */
export async function run(name, args = []) {
  const { res, parsed } = await authed("POST", `/actions/${encodeURIComponent(name)}`, input(name, args), { guest: true });
  if (!res.ok) throw failure(res, parsed, `Zero ${name} request failed.`);
  return parsed?.data ?? null;
}

// Reads: one cache entry per query and arguments, refreshed after every write.
const values = new Map();
const listeners = new Map();
const active = new Map();

function key(name, args) {
  return JSON.stringify([name, args]);
}

async function refresh(k) {
  const read = active.get(k);
  if (!read) return;
  try {
    const value = await run(read.name, read.args);
    values.set(k, value);
    for (const listener of listeners.get(k) ?? []) listener(value);
  } catch {
    // 0.8 kept the last value when a read failed.
  }
}

function refreshAll() {
  for (const k of active.keys()) void refresh(k);
}

globalThis.addEventListener?.("focus", refreshAll);

export function queryOptions(source, ...args) {
  return typeof source === "string" ? { name: source, args } : { name: source.name, args: [...source.args] };
}

export function useQuery(source, ...rest) {
  const { name, args } = queryOptions(source, ...rest);
  const k = key(name, args);
  const [value, setValue] = useState(() => values.get(k) ?? []);
  useEffect(() => {
    const set = listeners.get(k) ?? new Set();
    listeners.set(k, set);
    set.add(setValue);
    active.set(k, { name, args });
    if (values.has(k)) setValue(values.get(k));
    void refresh(k);
    return () => {
      set.delete(setValue);
      if (set.size === 0) {
        listeners.delete(k);
        active.delete(k);
      }
    };
  }, [k]);
  return value;
}

function writer(name) {
  return async (...args) => {
    const result = await run(name, args);
    refreshAll();
    return result;
  };
}

export function useMutation(name) {
  return useMemo(() => writer(name), [name]);
}

export function useAction(name) {
  return useMemo(() => writer(name), [name]);
}

export function usePaginatedQuery(name, args, { initialNumItems = 20 } = {}) {
  const scopeKey = JSON.stringify([name, args, initialNumItems]);
  const [scope, setScope] = useState(scopeKey);
  const [cursor, setCursor] = useState(null);
  const [pages, setPages] = useState([]);
  useEffect(() => {
    if (scope === scopeKey) return;
    setScope(scopeKey);
    setCursor(null);
    setPages([]);
  }, [scope, scopeKey]);
  const current = useQuery(name, ...paginatedArgs(name, args, { numItems: initialNumItems, cursor }));
  useEffect(() => {
    if (!current || !Array.isArray(current.page)) return;
    setPages((existing) => {
      const next = [...existing];
      next[cursor === null ? 0 : Math.max(0, existing.length - 1)] = current;
      return next;
    });
  }, [current, cursor, scopeKey]);
  const visible = scope === scopeKey ? pages : [];
  const last = visible.at(-1);
  return {
    page: visible.flatMap((p) => p.page),
    continueCursor: last?.continueCursor ?? null,
    isDone: last?.isDone ?? false,
    loadMore() {
      if (last?.continueCursor) {
        setCursor(last.continueCursor);
        setPages((existing) => [...existing, { page: [], continueCursor: null, isDone: false }]);
      }
    },
    reset() {
      setCursor(null);
      setPages([]);
    },
  };
}

const NOT_QUERIES = new Set(["$$typeof", "asymmetricMatch", "catch", "constructor", "finally", "hasOwnProperty", "inspect", "nodeType", "prototype", "then", "toJSON", "toString", "valueOf"]);

function builder(path) {
  const build = (...args) => ({ name: path.join("."), args });
  return new Proxy(build, {
    get(target, property) {
      if (typeof property !== "string" || NOT_QUERIES.has(property)) return Reflect.get(target, property);
      return builder([...path, property]);
    },
  });
}

export function createClient() {
  const hooks = { useAction, useMutation, usePaginatedQuery, useQuery };
  return new Proxy(hooks, {
    get(target, property) {
      if (typeof property !== "string" || property in target) return Reflect.get(target, property);
      return NOT_QUERIES.has(property) ? undefined : builder([property]);
    },
  });
}

// Auth: the v2 user behind this browser's session, in 0.8's shape.
const GUEST = { userId: null, displayName: "Guest", provider: "guest", isGuest: true, isAuthenticated: false, user: null };
let auth = { ...GUEST, isLoading: true };
const authListeners = new Set();

async function emitAuth() {
  let user = null;
  try {
    // Reading who this is never mints a guest, but an expired token is renewed like any request.
    const { res, parsed } = await authed("GET", "/me", undefined, { guest: false });
    if (res.ok) user = parsed?.data ?? null;
  } catch {
    // Unreachable: keep who this was, and stop loading.
    auth = { ...auth, isLoading: false };
    for (const listener of authListeners) listener(auth);
    return;
  }
  auth = user
    ? {
        userId: String(user.id),
        displayName: user.name || "Guest",
        provider: user.role === "guest" ? "guest" : "gravatar",
        isGuest: user.role === "guest",
        isAuthenticated: user.role !== "guest",
        user,
      }
    : { ...GUEST };
  for (const listener of authListeners) listener(auth);
}

/** Reads who this browser is (through `/me`, renewing an expired token) and returns 0.8's auth value. */
export async function currentAuth() {
  await emitAuth();
  return auth;
}

export function useAuth() {
  const [value, setValue] = useState(auth);
  useEffect(() => {
    authListeners.add(setValue);
    if (auth.isLoading) void emitAuth();
    return () => {
      authListeners.delete(setValue);
    };
  }, []);
  return value;
}

export async function signOut() {
  if (held()) await send("DELETE", "/auth/session").catch(() => null);
  hold(null);
  await emitAuth();
  return { url: null };
}

// Routing: paths are relative to the directory that serves index.html.
function base() {
  const dir = new URL("./", globalThis.document?.baseURI ?? "http://localhost/").pathname;
  return dir.replace(/\/+$/, "");
}

function current() {
  const loc = globalThis.location ?? { pathname: "/", search: "", hash: "" };
  let pathname = loc.pathname.startsWith(base()) ? loc.pathname.slice(base().length) || "/" : loc.pathname;
  if (pathname.endsWith("/index.html")) pathname = pathname.slice(0, -"index.html".length) || "/";
  return { pathname, search: loc.search, hash: loc.hash, href: `${pathname}${loc.search}${loc.hash}` };
}

function href(to) {
  if (/^[a-z][a-z0-9+.-]*:/i.test(to) || to.startsWith("//")) return to;
  const resolved = new URL(to, `http://app.local${current().href}`);
  return `${base()}${resolved.pathname}${resolved.search}${resolved.hash}`;
}

export function navigate(to, { replace = false } = {}) {
  const target = href(to);
  const parsed = new URL(target, globalThis.location.href);
  if (parsed.origin !== globalThis.location.origin) {
    globalThis.location.assign(parsed.toString());
    return;
  }
  globalThis.history[replace ? "replaceState" : "pushState"]({}, "", target);
  globalThis.dispatchEvent(new Event("zero08:locationchange"));
}

function useBrowserLocation() {
  const [location, setLocation] = useState(current);
  useEffect(() => {
    const update = () => setLocation(current());
    globalThis.addEventListener("popstate", update);
    globalThis.addEventListener("zero08:locationchange", update);
    return () => {
      globalThis.removeEventListener("popstate", update);
      globalThis.removeEventListener("zero08:locationchange", update);
    };
  }, []);
  return location;
}

const RouterContext = createContext(null);
const RouteContext = createContext({ params: {} });

export function Router({ children } = {}) {
  return h(RouterContext.Provider, { value: { location: useBrowserLocation(), navigate } }, children);
}

export function useLocation() {
  const context = useContext(RouterContext);
  const fallback = useBrowserLocation();
  return context?.location ?? fallback;
}

export function useNavigate() {
  return useContext(RouterContext)?.navigate ?? navigate;
}

export function useParams() {
  return useContext(RouteContext).params;
}

function segments(path) {
  return path.split("/").filter(Boolean);
}

export function match(pattern, pathname) {
  if (pattern === "*" || pattern === "/*") return { params: { "*": segments(pathname).map(decodeURIComponent).join("/") } };
  const want = segments(pattern);
  const have = segments(pathname);
  const params = {};
  for (let i = 0; i < want.length; i++) {
    if (want[i] === "*") {
      params["*"] = have.slice(i).map(decodeURIComponent).join("/");
      return { params };
    }
    if (have[i] === undefined) return null;
    if (want[i].startsWith(":")) params[want[i].slice(1)] = decodeURIComponent(have[i]);
    else if (want[i] !== have[i]) return null;
  }
  return want.length === have.length ? { params } : null;
}

function routes(children) {
  const out = [];
  for (const child of toChildArray(children)) {
    if (!child || typeof child !== "object") continue;
    if (child.props?.path !== undefined) out.push(child);
    else if (child.props?.children !== undefined) out.push(...routes(child.props.children));
  }
  return out;
}

export function Routes({ children } = {}) {
  const location = useLocation();
  for (const route of routes(children)) {
    if (typeof route.props.path !== "string") continue;
    const found = match(route.props.path, location.pathname);
    if (found) return h(RouteContext.Provider, { value: found }, route.props.element ?? null);
  }
  return null;
}

export function Route() {
  return null;
}

export function Link({ children, onClick, replace = false, target, to, ...props }) {
  const url = href(to);
  return h(
    "a",
    {
      ...props,
      href: url,
      target,
      onClick(event) {
        onClick?.(event);
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.altKey || event.ctrlKey || event.shiftKey) return;
        if (target && target !== "_self") return;
        if (new URL(url, globalThis.location.href).origin !== globalThis.location.origin) return;
        event.preventDefault();
        navigate(to, { replace });
      },
    },
    children,
  );
}
