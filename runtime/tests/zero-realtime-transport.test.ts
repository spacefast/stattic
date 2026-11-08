// The Zero v2 Cast transport against a local Phoenix-shaped socket: it mints a
// same-origin ticket, joins the topic Cast routes by, hands `zero.changes`
// broadcasts to the registered Zero client, and stops on a refused ticket. The
// bundle under test is the checked-in one, run the way the engine serves it.
import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";

import { z } from "zod";

import { castRoomTopic } from "../../packages/common/src/utils/cast-transport.ts";
import { buildZeroRealtime } from "../../scripts/build-zero-realtime.mjs";

const BUNDLE = new URL("../engine/runtime/zero-realtime.js", import.meta.url);

const frameSchema = z.tuple([
  z.string().nullable(),
  z.string().nullable(),
  z.string(),
  z.string(),
  z.object({ token: z.string().optional() }).passthrough(),
]);
type Frame = z.infer<typeof frameSchema>;
type Notice = {
  id: string;
  at: string;
  changes: { collection: string; ops: string[]; ids: number[] }[];
};
type Transport = {
  connect(deliver: (notice: Notice) => void, status: (state: "live" | "down") => void): () => void;
};

declare global {
  var __zeroRealtime: { transport?: Transport; waiters: Set<() => void> } | undefined;
  var __spacefastZeroRealtime: true | undefined;
}

/**
 * A Cast-and-ticket stand-in. `ticketStatus` refuses every mint; `replies` scripts the mints in
 * order (an HTTP status, or a ticket lifetime in ms) before the default 60 s ticket. `skewMs`
 * sets the server's clock that far from the page's (a negative value: the page runs fast).
 */
function cast(
  options: {
    ticketStatus?: number;
    replies?: ({ status: number } | { lifeMs: number })[];
    skewMs?: number;
  } = {},
) {
  const joins: Frame[] = [];
  const refreshes: Frame[] = [];
  const tickets: { pagePath: string }[] = [];
  const sockets: { close(): void }[] = [];
  const refreshed: (() => void)[] = [];
  const closes = { count: 0 };
  let socket: { send(data: string): void } | null = null;
  const server = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request, srv) {
      const url = new URL(request.url);
      if (url.pathname === "/__zero/realtime-ticket") {
        tickets.push(z.object({ pagePath: z.string() }).parse(await request.json()));
        if (options.ticketStatus) return new Response("{}", { status: options.ticketStatus });
        const reply = options.replies?.shift() ?? { lifeMs: 60_000 };
        if ("status" in reply) return new Response("{}", { status: reply.status });
        // Whole seconds, like the Date header, so the ticket's life is exactly lifeMs.
        const issuedAt = Math.floor((Date.now() + (options.skewMs ?? 0)) / 1000) * 1000;
        const expiresAt = new Date(issuedAt + reply.lifeMs).toISOString();
        return Response.json(
          { data: { token: `t${tickets.length}`, room: "zero2:spc_a", expiresAt } },
          { headers: { date: new Date(issuedAt).toUTCString() } },
        );
      }
      if (url.pathname === "/_ws/socket/websocket" && srv.upgrade(request)) return undefined;
      return new Response("not found", { status: 404 });
    },
    websocket: {
      open(ws) {
        socket = ws;
        sockets.push(ws);
      },
      message(ws, raw) {
        const frame = frameSchema.parse(JSON.parse(String(raw)));
        if (frame[3] === "phx_join") {
          joins.push(frame);
          ws.send(
            JSON.stringify([
              frame[0],
              frame[1],
              frame[2],
              "phx_reply",
              { status: "ok", response: {} },
            ]),
          );
        }
        if (frame[3] === "auth:refresh") {
          refreshes.push(frame);
          refreshed.splice(0).forEach((wake) => wake());
        }
      },
      close() {
        closes.count++;
      },
    },
  });
  return {
    server,
    joins,
    refreshes,
    tickets,
    sockets,
    closes,
    /** Resolves on the next auth:refresh frame. */
    nextRefresh: () => new Promise<void>((resolve) => refreshed.push(resolve)),
    broadcast(topic: string, payload: { event: string; payload: Notice }) {
      socket?.send(JSON.stringify([null, null, topic, "broadcast", payload]));
    },
  };
}

/** Runs the served script the way the engine wraps it, on a page whose origin is the local server. */
function load(origin: string) {
  delete globalThis.__zeroRealtime;
  delete globalThis.__spacefastZeroRealtime;
  const location = Object.getOwnPropertyDescriptor(globalThis, "location");
  Object.defineProperty(globalThis, "location", {
    configurable: true,
    value: new URL(`${origin}/todos`),
  });
  const originalFetch = globalThis.fetch;
  globalThis.fetch = Object.assign(
    (input: RequestInfo | URL, init?: RequestInit) =>
      originalFetch(new URL(String(input), origin), init),
    { preconnect: originalFetch.preconnect },
  );
  const config = {
    wsUrl: `${origin.replace("http", "ws")}/_ws/socket/websocket`,
    resourceKey: "res_a",
    ticketPath: "/__zero/realtime-ticket",
  };
  new Function("__SPACEFAST_ZERO_REALTIME__", readFileSync(BUNDLE, "utf8"))(config);
  const transport = globalThis.__zeroRealtime?.transport;
  if (!transport) throw new Error("the served script registered no transport");
  return {
    transport,
    restore: () => {
      globalThis.fetch = originalFetch;
      delete globalThis.__zeroRealtime;
      delete globalThis.__spacefastZeroRealtime;
      if (location) Object.defineProperty(globalThis, "location", location);
      else Reflect.deleteProperty(globalThis, "location");
    },
  };
}

test("the checked-in transport bundle is freshly generated", async () => {
  const before = readFileSync(BUNDLE);
  await buildZeroRealtime();
  expect(readFileSync(BUNDLE)).toEqual(before);
}, 60_000);

test("the Cast transport joins the Space room with a minted ticket and delivers zero.changes only", async () => {
  const c = cast();
  const page = load(`http://127.0.0.1:${c.server.port}`);
  try {
    const delivered: Notice[] = [];
    const live = Promise.withResolvers<void>();
    const arrived = Promise.withResolvers<void>();
    const stop = page.transport.connect(
      (notice) => {
        delivered.push(notice);
        arrived.resolve();
      },
      (state) => state === "live" && live.resolve(),
    );
    await live.promise;
    const topic = castRoomTopic("res_a", "zero2:spc_a");
    expect(c.tickets).toEqual([{ pagePath: "/todos" }]);
    expect(c.joins.map((f) => [f[2], f[4].token])).toEqual([[topic, "t1"]]);

    const notice: Notice = {
      id: "0123456789abcdef01234567",
      at: "2026-11-07T00:00:00.000Z",
      changes: [{ collection: "todos", ops: ["create"], ids: [1] }],
    };
    // Frames arrive in order: the two ignored ones precede the delivered one.
    c.broadcast(topic, { event: "app.other", payload: notice });
    c.broadcast(castRoomTopic("res_a", "zero2:spc_b"), { event: "zero.changes", payload: notice });
    c.broadcast(topic, { event: "zero.changes", payload: notice });
    await arrived.promise;
    expect(delivered).toEqual([notice]);
    stop();
  } finally {
    page.restore();
    c.server.stop(true);
  }
});

test("a refused ticket stops the transport and reports it down, so the client polls", async () => {
  const c = cast({ ticketStatus: 403 });
  const page = load(`http://127.0.0.1:${c.server.port}`);
  try {
    const down = Promise.withResolvers<void>();
    page.transport.connect(
      () => {},
      (state) => state === "down" && down.resolve(),
    );
    await down.promise;
    expect(c.joins).toEqual([]);
    expect(c.tickets.length).toBe(1);
  } finally {
    page.restore();
    c.server.stop(true);
  }
});

test("two Zero clients on one page share one socket; it closes with the last of them", async () => {
  const c = cast();
  const page = load(`http://127.0.0.1:${c.server.port}`);
  try {
    const first = Promise.withResolvers<void>();
    const second = Promise.withResolvers<void>();
    const stopHost = page.transport.connect(
      () => {},
      (s) => s === "live" && first.resolve(),
    );
    await first.promise;
    // The app's own bundle connects later: it joins the live socket instead of opening one.
    const stopApp = page.transport.connect(
      () => {},
      (s) => s === "live" && second.resolve(),
    );
    await second.promise;
    expect(c.sockets.length).toBe(1);
    expect(c.tickets.length).toBe(1);
    stopHost();
    expect(c.closes.count).toBe(0);
    const closed = Promise.withResolvers<void>();
    const poll = setInterval(() => c.closes.count > 0 && closed.resolve(), 5);
    stopApp();
    await closed.promise;
    clearInterval(poll);
    expect(c.closes.count).toBe(1);
  } finally {
    page.restore();
    c.server.stop(true);
  }
});

test("a failed ticket refresh keeps the working socket and retries before the ticket expires", async () => {
  // The first ticket lives 1 s: its refresh is due at 600 ms and is throttled. The retry,
  // backed off inside the ticket's remaining life, succeeds on the same socket. The page's
  // clock runs 20 minutes fast, so a life read off it would already be over: the refresh
  // would fire at once, its 429 would leave no time to retry, and no refresh would arrive.
  const c = cast({
    replies: [{ lifeMs: 1_000 }, { status: 429 }, { lifeMs: 60_000 }],
    skewMs: -20 * 60_000,
  });
  const page = load(`http://127.0.0.1:${c.server.port}`);
  try {
    const states: string[] = [];
    const live = Promise.withResolvers<void>();
    const stop = page.transport.connect(
      () => {},
      (s) => {
        states.push(s);
        if (s === "live") live.resolve();
      },
    );
    await live.promise;
    await c.nextRefresh();
    expect(c.tickets.length).toBe(3);
    expect(c.refreshes.map((f) => f[4].token)).toEqual(["t3"]);
    expect(c.sockets.length).toBe(1);
    expect(c.closes.count).toBe(0);
    expect(states).toEqual(["live"]);
    stop();
  } finally {
    page.restore();
    c.server.stop(true);
  }
});
