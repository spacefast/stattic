// Minimal HTTP-to-FastCGI bridge backed by a real `php-fpm` process.
// Gives runtime tests real `fastcgi_finish_request()` behavior without nginx.
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";

import { RUNTIME_TEST_ATOMIC_PREPEND, RUNTIME_TEST_ROUTER } from "./harness.ts";

const FCGI_VERSION_1 = 1;
const FCGI_BEGIN_REQUEST = 1;
const FCGI_END_REQUEST = 3;
const FCGI_PARAMS = 4;
const FCGI_STDIN = 5;
const FCGI_STDOUT = 6;
const FCGI_STDERR = 7;
const FCGI_RESPONDER = 1;
const FCGI_MAX_CHUNK = 65535;

export function findPhpFpmBinary(): string {
  const candidates = [
    process.env.PHP_FPM_BINARY,
    Bun.which("php-fpm"),
    Bun.which("php-fpm8.5"),
    "/usr/sbin/php-fpm8.5",
    "/usr/sbin/php-fpm",
    "/opt/homebrew/sbin/php-fpm",
    "/usr/local/sbin/php-fpm",
  ];
  for (const candidate of candidates) {
    if (candidate && existsSync(candidate)) {
      return candidate;
    }
  }
  throw new Error(
    "php-fpm binary not found. Install php-fpm (e.g. php8.5-fpm) or set PHP_FPM_BINARY.",
  );
}

function encodeFcgiRecord(type: number, requestId: number, payload: Uint8Array): Uint8Array {
  const paddingLength = (8 - (payload.byteLength % 8)) % 8;
  const record = new Uint8Array(8 + payload.byteLength + paddingLength);
  record[0] = FCGI_VERSION_1;
  record[1] = type;
  record[2] = (requestId >> 8) & 0xff;
  record[3] = requestId & 0xff;
  record[4] = (payload.byteLength >> 8) & 0xff;
  record[5] = payload.byteLength & 0xff;
  record[6] = paddingLength;
  record[7] = 0;
  record.set(payload, 8);
  return record;
}

function encodeFcgiLength(length: number): Uint8Array {
  if (length < 128) {
    return Uint8Array.of(length);
  }
  return Uint8Array.of(
    ((length >>> 24) & 0x7f) | 0x80,
    (length >>> 16) & 0xff,
    (length >>> 8) & 0xff,
    length & 0xff,
  );
}

function encodeFcgiParams(params: ReadonlyMap<string, string>): Uint8Array {
  const chunks: Uint8Array[] = [];
  let total = 0;
  for (const [name, value] of params) {
    const nameBytes = Buffer.from(name, "utf8");
    const valueBytes = Buffer.from(value, "utf8");
    const nameLen = encodeFcgiLength(nameBytes.byteLength);
    const valueLen = encodeFcgiLength(valueBytes.byteLength);
    chunks.push(nameLen, valueLen, nameBytes, valueBytes);
    total +=
      nameLen.byteLength + valueLen.byteLength + nameBytes.byteLength + valueBytes.byteLength;
  }
  const out = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    out.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return out;
}

function sendFcgiRequest(
  socketPath: string,
  params: ReadonlyMap<string, string>,
  body: Uint8Array,
  onStderr: (text: string) => void,
): Promise<{ status: number; headers: Headers; body: Buffer }> {
  return new Promise((resolve, reject) => {
    const requestId = 1;
    const socket = net.createConnection({ path: socketPath });
    const stdoutChunks: Buffer[] = [];
    let buffer = Buffer.alloc(0);
    let finished = false;

    const finish = (error?: Error) => {
      if (finished) return;
      finished = true;
      socket.destroy();
      if (error) {
        reject(error);
        return;
      }
      const raw = Buffer.concat(stdoutChunks);
      let sep = raw.indexOf("\r\n\r\n");
      let sepLen = 4;
      if (sep === -1) {
        sep = raw.indexOf("\n\n");
        sepLen = 2;
      }
      if (sep === -1) {
        resolve({ status: 200, headers: new Headers(), body: raw });
        return;
      }
      const headerText = raw.subarray(0, sep).toString("utf8");
      const responseBody = raw.subarray(sep + sepLen);
      const headers = new Headers();
      let status = 200;
      for (const line of headerText.split(/\r?\n/)) {
        const colon = line.indexOf(":");
        if (colon === -1) continue;
        const name = line.slice(0, colon).trim();
        const value = line.slice(colon + 1).trim();
        if (name.toLowerCase() === "status") {
          const parsed = Number.parseInt(value.split(" ")[0] ?? "", 10);
          if (Number.isFinite(parsed) && parsed >= 100 && parsed <= 599) {
            status = parsed;
          }
          continue;
        }
        headers.append(name, value);
      }
      resolve({ status, headers, body: responseBody });
    };

    socket.on("error", (err) => finish(err));

    socket.on("connect", () => {
      const beginBody = Uint8Array.of(0, FCGI_RESPONDER, 0, 0, 0, 0, 0, 0);
      socket.write(encodeFcgiRecord(FCGI_BEGIN_REQUEST, requestId, beginBody));

      const encodedParams = encodeFcgiParams(params);
      for (let offset = 0; offset < encodedParams.byteLength; offset += FCGI_MAX_CHUNK) {
        socket.write(
          encodeFcgiRecord(
            FCGI_PARAMS,
            requestId,
            encodedParams.subarray(offset, offset + FCGI_MAX_CHUNK),
          ),
        );
      }
      socket.write(encodeFcgiRecord(FCGI_PARAMS, requestId, new Uint8Array(0)));

      for (let offset = 0; offset < body.byteLength; offset += FCGI_MAX_CHUNK) {
        socket.write(
          encodeFcgiRecord(FCGI_STDIN, requestId, body.subarray(offset, offset + FCGI_MAX_CHUNK)),
        );
      }
      socket.write(encodeFcgiRecord(FCGI_STDIN, requestId, new Uint8Array(0)));
    });

    socket.on("data", (chunk: Buffer) => {
      buffer = buffer.byteLength === 0 ? chunk : Buffer.concat([buffer, chunk]);
      while (buffer.byteLength >= 8) {
        const type = buffer[1];
        const contentLength = buffer.readUInt16BE(4);
        const paddingLength = buffer.readUInt8(6);
        const totalLength = 8 + contentLength + paddingLength;
        if (buffer.byteLength < totalLength) {
          break;
        }
        const payload = buffer.subarray(8, 8 + contentLength);
        buffer = buffer.subarray(totalLength);
        if (type === FCGI_STDOUT && contentLength > 0) {
          stdoutChunks.push(Buffer.from(payload));
        } else if (type === FCGI_STDERR && contentLength > 0) {
          onStderr(payload.toString("utf8"));
        } else if (type === FCGI_END_REQUEST) {
          finish();
          return;
        }
      }
    });

    // Success is FCGI_END_REQUEST only: a child that exits early must not pass as an empty 200.
    const closedEarly = () =>
      finish(new Error("php-fpm closed the connection before FCGI_END_REQUEST"));
    socket.on("end", closedEarly);
    socket.on("close", closedEarly);
  });
}

export type PhpFpmServer = {
  baseUrl: string;
  diagnostics: () => string;
  stop: () => void;
};

/**
 * Start a real `php-fpm` pool serving an existing runtime fixture root through
 * a local HTTP-to-FastCGI bridge. Requests hit the same `php-router.php` gate
 * as `startRuntime()`, but execute under FPM with real `fastcgi_finish_request()`.
 */
export async function startPhpFpmServer(input: {
  root: string;
  env?: Record<string, string>;
  phpIni?: Record<string, string>;
  maxChildren?: number;
}): Promise<PhpFpmServer> {
  const fpmBinary = findPhpFpmBinary();
  // Keep Unix socket path under Linux's 108-byte sun_path limit.
  const baseTmp = os.tmpdir().length > 75 && existsSync("/tmp") ? "/tmp" : os.tmpdir();
  const runDir = path.join(
    baseTmp,
    `sf-fpm-${process.pid}-${Math.random().toString(36).slice(2, 8)}`,
  );
  mkdirSync(runDir, { recursive: true });
  const socketPath = path.join(runDir, "fpm.sock");
  const logPath = path.join(runDir, "fpm.log");
  const configPath = path.join(runDir, "php-fpm.conf");
  const entryPath = path.join(input.root, ".stattic", "fpm-router.php");
  mkdirSync(path.dirname(entryPath), { recursive: true });
  writeFileSync(
    entryPath,
    [
      "<?php",
      "declare(strict_types=1);",
      `chdir(${JSON.stringify(input.root)});`,
      `require ${JSON.stringify(RUNTIME_TEST_ROUTER)};`,
      "",
    ].join("\n"),
  );

  const iniLines = [
    // The same Atomic stand-in startRuntime() prepends.
    `php_admin_value[auto_prepend_file] = ${RUNTIME_TEST_ATOMIC_PREPEND}`,
    "php_admin_value[opcache.enable] = 0",
    "php_admin_value[log_errors] = 1",
    "php_admin_value[display_errors] = 0",
  ];
  for (const [key, value] of Object.entries(input.phpIni ?? {})) {
    iniLines.push(`php_admin_value[${key}] = ${value}`);
  }
  const maxChildren = input.maxChildren ?? 4;
  writeFileSync(
    configPath,
    [
      "[global]",
      `error_log = ${logPath}`,
      "daemonize = no",
      "",
      "[www]",
      `listen = ${socketPath}`,
      `chdir = ${input.root}`,
      "pm = static",
      `pm.max_children = ${maxChildren}`,
      "clear_env = no",
      "catch_workers_output = yes",
      "decorate_workers_output = no",
      ...iniLines,
      "",
    ].join("\n"),
  );

  let diagnostics = "";
  const appendDiagnostics = (chunk: string) => {
    diagnostics = (diagnostics + chunk).slice(-16384);
  };
  const readDiagnostics = (): string => {
    const fileLog = existsSync(logPath) ? readFileSync(logPath, "utf8").slice(-8192) : "";
    return [diagnostics, fileLog].filter(Boolean).join("\n");
  };

  const fpm = spawn(fpmBinary, ["-F", "-O", "-y", configPath], {
    cwd: input.root,
    stdio: ["ignore", "pipe", "pipe"],
    detached: process.platform !== "win32",
    env: {
      ...process.env,
      SPACEFAST_INSECURE_COOKIES: "1",
      SPACEFAST_RUNTIME_TEST_MODE: "1",
      SPACEFAST_TEST_VISITOR_SCHEME: "http",
      ...input.env,
    },
  });
  let launchError: Error | undefined;
  fpm.on("error", (error) => {
    launchError = error;
  });
  fpm.stdout.on("data", (chunk: Buffer) => appendDiagnostics(chunk.toString("utf8")));
  fpm.stderr.on("data", (chunk: Buffer) => appendDiagnostics(chunk.toString("utf8")));

  const killFpm = () => {
    if (process.platform !== "win32" && fpm.pid !== undefined) {
      try {
        process.kill(-fpm.pid, "SIGKILL");
        return;
      } catch {}
    }
    fpm.kill("SIGKILL");
  };

  // Wait for php-fpm to create and listen on its Unix socket.
  const deadline = Date.now() + 10_000;
  while (Date.now() < deadline) {
    if (launchError || fpm.exitCode !== null || fpm.signalCode !== null) {
      const logs = readDiagnostics();
      rmSync(runDir, { recursive: true, force: true });
      throw new Error(
        `php-fpm exited early (${launchError?.message ?? fpm.exitCode ?? fpm.signalCode}):\n${logs}`,
      );
    }
    if (existsSync(socketPath)) {
      break;
    }
    // oxlint-disable-next-line no-await-in-loop -- bounded socket readiness poll
    await Bun.sleep(25);
  }
  if (!existsSync(socketPath)) {
    const logs = readDiagnostics();
    killFpm();
    rmSync(runDir, { recursive: true, force: true });
    throw new Error(`php-fpm did not create ${socketPath} in time:\n${logs}`);
  }

  const httpServer = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const url = new URL(request.url);
      const bodyBytes = new Uint8Array(await request.arrayBuffer());
      const hostHeader = request.headers.get("host") ?? url.host;
      const [serverName, serverPort = String(httpServer.port)] = hostHeader.split(":", 2);
      const params = new Map<string, string>([
        ["GATEWAY_INTERFACE", "CGI/1.1"],
        ["SERVER_PROTOCOL", "HTTP/1.1"],
        ["SERVER_SOFTWARE", "spacefast-test-fcgi"],
        ["REQUEST_METHOD", request.method],
        ["REQUEST_URI", `${url.pathname}${url.search}`],
        ["SCRIPT_FILENAME", entryPath],
        ["SCRIPT_NAME", "/.stattic/fpm-router.php"],
        ["DOCUMENT_ROOT", input.root],
        ["QUERY_STRING", url.search.startsWith("?") ? url.search.slice(1) : ""],
        ["SERVER_NAME", serverName || "127.0.0.1"],
        ["SERVER_PORT", serverPort],
        ["REMOTE_ADDR", "127.0.0.1"],
        ["REMOTE_PORT", "0"],
        ["CONTENT_LENGTH", String(bodyBytes.byteLength)],
      ]);
      const contentType = request.headers.get("content-type");
      if (contentType !== null) {
        params.set("CONTENT_TYPE", contentType);
      }
      request.headers.forEach((value, key) => {
        const lower = key.toLowerCase();
        if (lower === "content-type" || lower === "content-length") return;
        params.set(`HTTP_${lower.toUpperCase().replace(/-/g, "_")}`, value);
      });
      const result = await sendFcgiRequest(socketPath, params, bodyBytes, appendDiagnostics);
      return new Response(result.body, {
        status: result.status,
        headers: result.headers,
      });
    },
  });

  const baseUrl = `http://127.0.0.1:${httpServer.port}`;
  try {
    const health = await fetch(`${baseUrl}/__spacefast/health.php`, {
      signal: AbortSignal.timeout(10_000),
    });
    if (!health.ok) {
      throw new Error(`php-fpm health check failed: HTTP ${health.status}`);
    }
  } catch (error) {
    const logs = readDiagnostics();
    httpServer.stop(true);
    killFpm();
    rmSync(runDir, { recursive: true, force: true });
    throw new Error(`php-fpm startup failed:\n${logs}`, { cause: error });
  }

  return {
    baseUrl,
    diagnostics: readDiagnostics,
    stop: () => {
      httpServer.stop(true);
      killFpm();
      rmSync(runDir, { recursive: true, force: true });
    },
  };
}
