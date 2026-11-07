// The Cast transport for Zero v2 live updates on Spacefast. The engine serves
// this bundle at /__spacefast/zero/realtime.js with the Space's config appended,
// and loads it on every v2 page. It registers itself in the page-wide Zero
// realtime registry, so every generated client on the page (the host copy or an
// app's own bundle) receives change notices without any author setup.
//
// The notice is a doorbell: the Zero client re-runs its own access-checked
// reads. Nothing here trusts or renders the payload beyond handing it over.
import { castRoomTopic, phoenixEndpoint } from "../../packages/common/src/utils/cast-transport.js";

export interface ZeroRealtimeConfig {
  /** Cast's Phoenix socket URL (`wss://…/_ws/socket/websocket`). */
  wsUrl: string;
  resourceKey: string;
  /** Same-origin ticket route; it checks page access before minting. */
  ticketPath: string;
}

type Notice = { id: string; at: string; changes: readonly object[] };
type Json = string | number | boolean | null | Json[] | { [key: string]: Json };
type Transport = {
  connect(deliver: (notice: Notice) => void, status: (state: "live" | "down") => void): () => void;
};
type Registry = { transport?: Transport; waiters: Set<() => void> };

const KIND = "zero.changes";
const HEARTBEAT_MS = 30_000;
const JOIN_TIMEOUT_MS = 10_000;
const MAX_BACKOFF_MS = 30_000;
// Refresh at 60% of the ticket's life. On failure keep the socket and retry with backoff
// inside the rest of the life (first retry after a tenth of it, doubling), stopping 5% before
// expiry. Cast closes the socket itself if the ticket lapses, and onclose reconnects.
const REFRESH_AT = 0.6;
const RETRY_FIRST = 0.1;
const EXPIRY_MARGIN = 0.05;

class Refused extends Error {}

// The JSON decoders for this boundary. A schema library would cost every v2
// page ~400 KB for three field checks, so the checks are written out.
function field(value: Json | undefined, key: string): Json | undefined {
  return value !== null && value !== undefined && !Array.isArray(value) && typeof value === "object" // oxlint-disable-line anti-slop/no-runtime-typeof -- JSON decoder boundary; see above.
    ? value[key]
    : undefined;
}
function text(value: Json | undefined): string | null {
  // oxlint-disable-next-line anti-slop/no-runtime-typeof -- JSON decoder boundary; see above.
  return typeof value === "string" && value !== "" ? value : null;
}
function parse(raw: string): Json | undefined {
  try {
    const value: Json = JSON.parse(raw);
    return value;
  } catch {
    return undefined;
  }
}
/** A change notice is handed to the Zero client only in its documented shape. */
function notice(value: Json | undefined): Notice | null {
  const id = text(field(value, "id"));
  const at = text(field(value, "at"));
  const changes = field(value, "changes");
  if (!id || !at || !Array.isArray(changes)) return null;
  const objects = changes.filter(
    (c): c is { [key: string]: Json } => text(field(c, "collection")) !== null,
  );
  return objects.length === changes.length ? { id, at, changes: objects } : null;
}

type Ticket = { token: string; room: string; expiresAt: number };

async function ticket(path: string): Promise<Ticket> {
  const response = await fetch(path, {
    method: "POST",
    cache: "no-store",
    credentials: "same-origin",
    headers: { accept: "application/json", "content-type": "application/json" },
    body: JSON.stringify({ pagePath: location.pathname || "/" }),
  });
  if (!response.ok) {
    // Denied access or a bad request does not heal by retrying; throttling and timeouts do.
    if (
      response.status >= 400 &&
      response.status < 500 &&
      ![408, 425, 429].includes(response.status)
    ) {
      throw new Refused(`ticket refused: HTTP ${response.status}`);
    }
    throw new Error(`ticket failed: HTTP ${response.status}`);
  }
  const data = field(parse(await response.text()), "data");
  const token = text(field(data, "token"));
  const room = text(field(data, "room"));
  const expiresAt = Date.parse(text(field(data, "expiresAt")) ?? "");
  if (!token || !room) throw new Error("ticket failed: malformed response");
  // Measure the ticket's life on the server's clock, so a page clock that runs fast cannot
  // shrink it to nothing and turn refresh into a request loop. No usable life (an older mint
  // without expiresAt, or no Date header and a skewed clock): assume the shortest one we issue.
  const issuedAt = Date.parse(response.headers.get("date") ?? "");
  const life = expiresAt - (Number.isFinite(issuedAt) ? issuedAt : Date.now());
  return { token, room, expiresAt: Date.now() + (life > 0 ? life : 60_000) };
}

/**
 * One Cast connection per page, shared by every Zero client on it (the host copy
 * and an app's own bundle both connect here). The socket opens with the first
 * subscriber and closes with the last.
 */
export function castTransport(config: ZeroRealtimeConfig): Transport {
  type Subscriber = { deliver: (notice: Notice) => void; status: (state: "live" | "down") => void };
  const subscribers = new Set<Subscriber>();
  let state: "live" | "down" = "down";
  let stopConnection: (() => void) | null = null;
  const setState = (next: "live" | "down") => {
    state = next;
    for (const s of [...subscribers]) s.status(next);
  };
  const fanOut = (n: Notice) => {
    for (const s of [...subscribers]) s.deliver(n);
  };
  return {
    connect(deliver, status) {
      const subscriber = { deliver, status };
      subscribers.add(subscriber);
      if (stopConnection === null) stopConnection = open(config, fanOut, setState);
      else if (state === "live") status("live");
      return () => {
        if (!subscribers.delete(subscriber) || subscribers.size > 0) return;
        stopConnection?.();
        stopConnection = null;
        state = "down";
      };
    },
  };
}

function open(
  config: ZeroRealtimeConfig,
  deliver: (notice: Notice) => void,
  status: (state: "live" | "down") => void,
): () => void {
  let stopped = false;
  let socket: WebSocket | null = null;
  let attempt = 0;
  let ref = 0;
  const timers = new Set<ReturnType<typeof setTimeout>>();
  const later = (fn: () => void, ms: number, repeat = false) => {
    const t = repeat ? setInterval(fn, ms) : setTimeout(fn, ms);
    timers.add(t);
    return t;
  };
  const clearAll = () => {
    for (const t of timers) (clearTimeout(t), clearInterval(t));
    timers.clear();
  };
  const reconnect = () => {
    status("down");
    clearAll();
    socket?.close();
    socket = null;
    if (stopped) return;
    const delay = Math.min(MAX_BACKOFF_MS, 1000 * 2 ** attempt++) * (0.5 + Math.random() / 2);
    later(connectOnce, delay);
  };
  const connectOnce = () => {
    if (stopped) return;
    void ticket(config.ticketPath).then(
      (first) => {
        if (stopped) return;
        const url = new URL(`${phoenixEndpoint(config.wsUrl)}/websocket`);
        url.searchParams.set("vsn", "2.0.0");
        const ws = new WebSocket(url);
        socket = ws;
        const topic = castRoomTopic(config.resourceKey, first.room);
        const joinRef = String(++ref);
        const send = (event: string, payload: Json) =>
          ws.readyState === WebSocket.OPEN &&
          ws.send(JSON.stringify([joinRef, String(++ref), topic, event, payload]));
        const heartbeat = () =>
          later(
            () =>
              ws.readyState === WebSocket.OPEN &&
              ws.send(JSON.stringify([null, String(++ref), "phoenix", "heartbeat", {}])),
            HEARTBEAT_MS,
            true,
          );
        // Refresh before the held ticket lapses. A failure keeps the working socket and
        // retries with backoff; only a refusal (access gone) or expiry ends it.
        const scheduleRefresh = (expiresAt: number) => {
          const lifetime = Math.max(0, expiresAt - Date.now());
          const deadline = expiresAt - lifetime * EXPIRY_MARGIN;
          let retry = lifetime * RETRY_FIRST;
          const attemptRefresh = () => {
            if (stopped || socket !== ws) return;
            void ticket(config.ticketPath).then(
              (next) => {
                send("auth:refresh", { token: next.token });
                scheduleRefresh(next.expiresAt);
              },
              (error) => {
                if (error instanceof Refused) return reconnect();
                const left = deadline - Date.now();
                const wait = Math.min(retry * (0.5 + Math.random() / 2), left);
                retry = Math.min(retry * 2, MAX_BACKOFF_MS);
                if (wait > 0) later(attemptRefresh, wait);
                // Out of time: Cast ends the socket when the ticket lapses, and onclose reconnects.
              },
            );
          };
          later(attemptRefresh, lifetime * REFRESH_AT);
        };
        const id = `zero:${crypto.randomUUID()}`;
        ws.onopen = () => {
          ws.send(
            JSON.stringify([
              joinRef,
              joinRef,
              topic,
              "phx_join",
              { token: first.token, anonymous_id: id, session_id: id },
            ]),
          );
          heartbeat();
          later(() => ws.readyState === WebSocket.OPEN && reconnect(), JOIN_TIMEOUT_MS);
        };
        ws.onmessage = (event) => {
          const frame = parse(String(event.data));
          if (!Array.isArray(frame) || frame[2] !== topic) return;
          const [, msgRef, , name, payload] = frame;
          if (name === "phx_reply" && msgRef === joinRef) {
            if (text(field(payload, "status")) !== "ok") return reconnect();
            clearAll();
            attempt = 0;
            heartbeat();
            scheduleRefresh(first.expiresAt);
            status("live");
            return;
          }
          if (name === "phx_close" || name === "phx_error") return reconnect();
          // Cast's ephemeral room broadcast: { event: kind, payload: notice }.
          if (name === "broadcast" && text(field(payload, "event")) === KIND) {
            const delivered = notice(field(payload, "payload"));
            if (delivered) deliver(delivered);
          }
        };
        ws.onclose = () => {
          if (socket === ws) reconnect();
        };
      },
      (error) => {
        // A refused ticket stays refused for this page: polling carries it.
        if (error instanceof Refused) {
          stopped = true;
          status("down");
          return;
        }
        reconnect();
      },
    );
  };
  connectOnce();
  return () => {
    stopped = true;
    clearAll();
    socket?.close();
    socket = null;
  };
}

/** Called by the served script with the Space's config. Registers once per page. */
declare global {
  // The page-wide registry the generated Zero client reads (`registerRealtimeTransport`).
  var __zeroRealtime: Registry | undefined;
  var __spacefastZeroRealtime: true | undefined;
}

export function startZeroRealtime(config: ZeroRealtimeConfig | null): void {
  if (!config || globalThis.__spacefastZeroRealtime) return;
  globalThis.__spacefastZeroRealtime = true;
  const registry = (globalThis.__zeroRealtime ??= { waiters: new Set() });
  registry.transport = castTransport(config);
  for (const wake of [...registry.waiters]) wake();
}

// The engine wraps this bundle in a function whose one parameter carries the Space's settings.
declare const __SPACEFAST_ZERO_REALTIME__: ZeroRealtimeConfig | null;
startZeroRealtime(__SPACEFAST_ZERO_REALTIME__);
