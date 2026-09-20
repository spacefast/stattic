// sf_fetch()'s transport seam: what actually reaches the wire.
//
// php-functions.test.ts owns the serve-lane half — that the dispatch binds the
// Space's scope, and which targets are refused before anything connects. None
// of that can observe the transport, so the defects this file holds are the
// ones only a real TLS upstream can show: an ambient proxy choosing the peer
// behind the approved connect pin, a credential replayed to a different origin,
// and a relative `Location` resolving to the wrong resource.
//
// Every target here is a loopback address on the engine's explicit egress test
// allowlist, so the policy is exercised rather than bypassed, and nothing
// leaves the box.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { createServer as createTlsServer, type Server as TlsServer } from "node:https";
import type { AddressInfo } from "node:net";
import { createServer as createTcpServer, type Server as TcpServer } from "node:net";
import { connect } from "node:net";
import os from "node:os";
import path from "node:path";

const CLI = path.resolve(import.meta.dir, "fetch-cli.php");
const SECRET = "Bearer transport-secret";
const COOKIE = "session=transport-secret";

let workdir: string;
let caFile: string;
/** The origin every request starts at, and the one two hops below stay on. */
let origin: TlsServer;
/**
 * The redirect destination, bound on both loopback addresses: reached as
 * `127.0.0.1:other` it is a different port, and as `127.0.0.2:other` a
 * different host. Either way it is a different origin from `origin`.
 */
let otherPort: TlsServer;
let proxy: TcpServer;
let proxyConnects: string[] = [];
const ports = { origin: 0, other: 0, proxy: 0 };

/** What each upstream reports back, so a hop's real request is observable. */
type Echo = { path: string; authorization: string | null; cookie: string | null };

function upstream(redirects: (port: number) => Record<string, string>): Promise<TlsServer> {
  const server = createTlsServer(
    { key: readFileSync(path.join(workdir, "key.pem")), cert: readFileSync(caFile) },
    (request, response) => {
      const target = redirects(ports.other)[request.url ?? ""];
      if (target !== undefined) {
        response.writeHead(302, { Location: target, "Content-Length": "0" }).end();
        return;
      }
      const echo: Echo = {
        path: request.url ?? "",
        authorization: request.headers["authorization"] ?? null,
        cookie: request.headers["cookie"] ?? null,
      };
      const body = JSON.stringify(echo);
      response
        .writeHead(200, { "Content-Type": "application/json", "Content-Length": `${body.length}` })
        .end(body);
    },
  );
  return new Promise((resolve) => {
    // Both loopback addresses, so "a different host" and "a different port" are
    // policy decisions about the same reachable service rather than one of them
    // being a refused connection.
    server.listen(0, "0.0.0.0", () => resolve(server));
  });
}

/** A CONNECT proxy that records every tunnel it is asked to open. */
function connectProxy(): Promise<TcpServer> {
  const server = createTcpServer((client) => {
    client.once("data", (chunk) => {
      proxyConnects.push(chunk.toString("utf8").split("\r\n")[0] ?? "");
      const upstreamSocket = connect(ports.origin, "127.0.0.1", () => {
        client.write("HTTP/1.1 200 Connection established\r\n\r\n");
        client.pipe(upstreamSocket);
        upstreamSocket.pipe(client);
      });
      upstreamSocket.on("error", () => client.destroy());
    });
    client.on("error", () => {});
  });
  return new Promise((resolve) => {
    server.listen(0, "127.0.0.1", () => resolve(server));
  });
}

type FetchResult = { ok: boolean; code?: string; status?: number; body?: string };
/** sf_fetch()'s own option object, as a handler writes it. */
type FetchOptions = {
  method?: string;
  headers?: Record<string, string>;
  body?: string;
  timeoutMs?: number;
};

/** The port a fixture server actually bound. */
function boundPort(server: TlsServer | TcpServer): number {
  // SAFETY: `address()` answers a pipe name or null only for a server that was
  // not listened on a TCP socket; every server here is awaited through its own
  // `listen(0, <ipv4>)` callback before this runs, and a wrong port would fail
  // every assertion in the file.
  const { port } = server.address() as AddressInfo;
  return port;
}

async function fetchThroughEngine(
  url: string,
  options: FetchOptions = {},
  env: Record<string, string> = {},
): Promise<FetchResult> {
  const proc = Bun.spawn(
    ["php", `-d`, `curl.cainfo=${caFile}`, CLI, JSON.stringify({ url, options })],
    {
      stdout: "pipe",
      stderr: "pipe",
      env: {
        ...process.env,
        // The loopback targets this suite uses, so the policy runs for real
        // instead of being skipped.
        SPACEFAST_EGRESS_TEST_ALLOWLIST: [
          `127.0.0.1:${ports.origin}`,
          `127.0.0.1:${ports.other}`,
          `127.0.0.2:${ports.other}`,
          `127.0.0.1:${ports.proxy}`,
        ].join(","),
        HTTPS_PROXY: "",
        ALL_PROXY: "",
        NO_PROXY: "",
        ...env,
      },
    },
  );
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);
  if (exitCode !== 0) throw new Error(`fetch-cli.php exited ${exitCode}: ${stderr}`);
  // SAFETY: fetch-cli.php's own documented one-line protocol; every field read
  // below is asserted, so a shape drift fails rather than hides.
  return JSON.parse(stdout) as FetchResult;
}

function echoed(result: FetchResult): Echo {
  expect(result.ok).toBe(true);
  expect(result.status).toBe(200);
  // SAFETY: the upstream in this file is the fixture above; it answers this
  // shape or the assertions on it fail.
  return JSON.parse(result.body ?? "{}") as Echo;
}

beforeAll(async () => {
  workdir = mkdtempSync(path.join(os.tmpdir(), "sf-fetch-transport-"));
  caFile = path.join(workdir, "cert.pem");
  // One certificate covering both loopback hostnames, so a cross-origin hop is
  // a policy decision rather than a TLS failure.
  const openssl = Bun.spawnSync([
    "openssl",
    "req",
    "-x509",
    "-newkey",
    "rsa:2048",
    "-nodes",
    "-keyout",
    path.join(workdir, "key.pem"),
    "-out",
    caFile,
    "-days",
    "1",
    "-subj",
    "/CN=sf-fetch-transport",
    "-addext",
    "subjectAltName=IP:127.0.0.1,IP:127.0.0.2",
  ]);
  if (openssl.exitCode !== 0) throw new Error(`openssl failed: ${openssl.stderr.toString()}`);

  otherPort = await upstream(() => ({}));
  ports.other = boundPort(otherPort);
  origin = await upstream((other) => ({
    "/same-origin": "/echo",
    "/other-port": `https://127.0.0.1:${other}/echo`,
    "/other-host": `https://127.0.0.2:${other}/echo`,
    "/dir/item?old=1": "?new=2",
  }));
  ports.origin = boundPort(origin);
  proxy = await connectProxy();
  ports.proxy = boundPort(proxy);
});

afterAll(() => {
  origin?.close();
  otherPort?.close();
  proxy?.close();
  if (workdir) rmSync(workdir, { recursive: true, force: true });
});

test("an ambient proxy cannot choose the peer behind the approved connect pin", async () => {
  proxyConnects = [];
  // The pin names 127.0.0.1; a CONNECT proxy would re-resolve the target for
  // itself and hand the request to whatever it liked. The whole point of
  // resolving and pinning is lost if the transport still asks a proxy.
  const result = await fetchThroughEngine(
    `https://127.0.0.1:${ports.origin}/echo`,
    { headers: { Authorization: SECRET } },
    {
      HTTPS_PROXY: `http://127.0.0.1:${ports.proxy}`,
      ALL_PROXY: `http://127.0.0.1:${ports.proxy}`,
    },
  );

  expect(echoed(result).path).toBe("/echo");
  expect(proxyConnects).toEqual([]);
});

test("a redirect carries credentials only while the origin is unchanged", async () => {
  const headers = { Authorization: SECRET, Cookie: COOKIE };

  const same = echoed(
    await fetchThroughEngine(`https://127.0.0.1:${ports.origin}/same-origin`, { headers }),
  );
  expect(same.path).toBe("/echo");
  expect(same.authorization).toBe(SECRET);
  expect(same.cookie).toBe(COOKIE);

  // A port is part of an origin: another service, possibly another operator,
  // answers there.
  const port = echoed(
    await fetchThroughEngine(`https://127.0.0.1:${ports.origin}/other-port`, { headers }),
  );
  expect(port.path).toBe("/echo");
  expect(port.authorization).toBeNull();
  expect(port.cookie).toBeNull();

  const host = echoed(
    await fetchThroughEngine(`https://127.0.0.1:${ports.origin}/other-host`, { headers }),
  );
  expect(host.authorization).toBeNull();
  expect(host.cookie).toBeNull();
});

test("a relative Location resolves as a URI reference, not as a path suffix", async () => {
  // A query-only reference keeps the current path; appending it to the
  // directory silently fetches a different resource. The remaining reference
  // forms are proven against the resolver itself in unit.php — this one is here
  // because only a real hop shows which resource was actually requested.
  const query = echoed(
    await fetchThroughEngine(`https://127.0.0.1:${ports.origin}/dir/item?old=1`),
  );
  expect(query.path).toBe("/dir/item?new=2");
});
