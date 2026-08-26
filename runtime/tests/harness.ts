// Shared harness for the runtime test suite, on the schema-v4 engine.
//
// Self-contained: it installs the engine from runtime/engine-manifest.json into
// a temp web root, serves it with `php -S`, and signs management/upload/blob-gate
// JWTs with a per-process Ed25519 key delivered through the same
// Atomic_Persistent_Data surface WP.Cloud exposes.
//
// The only imports from outside runtime/ are contracts the engine mirrors rather
// than owns: the platform's problem-document derivation (packages/common) and
// the generated finalizer protocol (packages/routing, same codegen that writes
// shared/finalizer-protocol.generated.php). Re-declaring either here would
// create a second reading of a wire contract.
//
// v4 shapes this file speaks (private-notes:internal-docs/runtime-rewrite-contracts-2026-08-07):
//   * every admin route rides `?route=<path>` on /__spacefast/api.php and
//     /__spacefast/upload.php, with no path suffixes;
//   * publish is content-addressed: POST /spaces/{s}/versions declares the
//     manifest, POST /spaces/{s}/blobs/have negotiates, PUT /spaces/{s}/blobs/{sha}
//     streams bytes, POST .../finalize consumes the session. There is no
//     multipart/parts lane and no tar batch lane;
//   * artifacts are pointer + content-addressed PHP: routes/current.json ->
//     routes/shards/hosts-<xx>-<h16>.php, spaces/<s>/space.json ->
//     overlays/overlay-<h16>.php, versions/<v>/root.json -> root-<h16>.php ->
//     responses-<h16>.php;
//   * bytes live only in the CAS at spaces/<s>/blobs/<aa>/<sha>.
import { setDefaultTimeout } from "bun:test";
import { spawn, spawnSync } from "node:child_process";
import { generateKeyPairSync, randomUUID, sign } from "node:crypto";
import { once } from "node:events";
import {
  chmodSync,
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";

import { errorDocsUrl, errorTitle } from "../../packages/common/src/contracts/error-codes.ts";
import { FINALIZER_PROTOCOL } from "../../packages/routing/src/protocol.generated.ts";
import { writeActiveReleasePointer } from "./active-release.ts";

// The canonical runner (tests/run.sh) passes `--timeout 30000`; encode the
// same default here so direct `bun test tests/<suite>.test.ts` invocations
// behave identically. Several tests have a structural floor above bun's 5s
// default — the admission holder tests await a response that the engine
// deliberately holds for its full 5s test-hold cap (shared/admission.php),
// and suites that boot their own `php -S` runtime plus a deploy can cross 5s
// under load — so the 5s default guarantees spurious per-test timeouts.
setDefaultTimeout(30_000);

export const RUNTIME_DIR = path.resolve(import.meta.dir, "..");
export const RUNTIME_TEST_ROUTER = path.resolve(import.meta.dir, "php-router.php");
export const RUNTIME_TEST_ATOMIC_PREPEND = path.resolve(import.meta.dir, "atomic-prepend.php");
export const RUNTIME_INSTANCE_ID = "rti_test";
export const RUNTIME_HOST = "127.0.0.1";
export const RUNTIME_HTTP_API_BASE = "/__spacefast/api.php";
export const RUNTIME_UPLOAD_API_BASE = "/__spacefast/upload.php";
/** The blob token gate (contracts §7): GET /__stattic/blob/<jwt>. */
export const RUNTIME_BLOB_GATE_PREFIX = "/__stattic/blob/";
export const PHP_BINARY = process.env.PHP_BINARY ?? "php";

/**
 * The generated response-table contract (entry keys, lanes, cache classes,
 * reserved keys, the accel survivor set). Same codegen as
 * shared/finalizer-protocol.generated.php, so a protocol change lands on both
 * sides at once and never has to be restated in a test.
 */
export const RESPONSES = FINALIZER_PROTOCOL.responses;

const REPO_ROOT = path.resolve(RUNTIME_DIR, "..");
const nativeBinaryPaths = new Map<string, string>();
let runtimeStartTail = Promise.resolve();

function noOpRuntimeStartRelease() {}

export async function acquireRuntimeStartLock(): Promise<() => void> {
  const previous = runtimeStartTail;
  let release = noOpRuntimeStartRelease;
  runtimeStartTail = new Promise<void>((resolve) => {
    release = resolve;
  });
  await previous;
  return release;
}

function cargoDebugBinary(binary: string) {
  const metadata = spawnSync("cargo", ["metadata", "--format-version", "1", "--no-deps"], {
    cwd: REPO_ROOT,
    encoding: "utf8",
  });
  if (metadata.status !== 0) {
    throw new Error(`cargo metadata failed:\n${metadata.stdout}\n${metadata.stderr}`);
  }
  const targetDirectory = (JSON.parse(metadata.stdout) as { target_directory?: unknown })
    .target_directory;
  if (typeof targetDirectory !== "string" || targetDirectory.length === 0) {
    throw new Error("cargo metadata returned no target_directory");
  }
  return path.join(
    targetDirectory,
    "debug",
    `${binary}${process.platform === "win32" ? ".exe" : ""}`,
  );
}

function packagedBinaryRuns(binary: string, packaged: string) {
  // GitHub workspace artifacts preserve the packaged bytes but not Unix
  // executable modes. Restore the engine-manifest contract before probing.
  chmodSync(packaged, 0o755);
  const probe = spawnSync(packaged, ["--self-test"], { encoding: "utf8" });
  if (probe.status === 0) return true;
  if (probe.error && "code" in probe.error && probe.error.code === "ENOEXEC") {
    // A locally cached Linux bundle is not executable on a macOS developer
    // host. Build the host test binary; never mask a runnable packaged
    // binary that reports a failed self-test.
    return false;
  }
  throw new Error(
    `packaged runtime binary failed (${binary}):\n${probe.stdout ?? ""}\n${probe.stderr ?? ""}`,
  );
}

function runtimeNativeBinaryPath(binary: string, packageName: string, configured?: string) {
  const cached = nativeBinaryPaths.get(binary);
  if (cached) return cached;
  if (configured && existsSync(configured)) {
    nativeBinaryPaths.set(binary, configured);
    return configured;
  }
  // `runtime/bin` is a CI bootstrap artifact: built in the same workflow run,
  // from the same commit, so reusing it there is free and correct. It is also
  // gitignored, which means a developer host can hold one from any earlier
  // build — and preferring it made the suite silently test a stale compiler
  // against fresh PHP. Outside CI the checkout is the only trustworthy source;
  // cargo makes an unchanged rebuild a no-op anyway. `configured` above still
  // lets anyone point at a specific binary deliberately.
  const packaged = path.join(RUNTIME_DIR, "bin", binary);
  if (process.env.CI && existsSync(packaged) && packagedBinaryRuns(binary, packaged)) {
    nativeBinaryPaths.set(binary, packaged);
    return packaged;
  }
  const candidate = cargoDebugBinary(binary);
  const build = spawnSync("cargo", ["build", "--locked", "-p", packageName], {
    cwd: REPO_ROOT,
    encoding: "utf8",
  });
  if (build.status !== 0) {
    throw new Error(`runtime native build failed (${binary}):\n${build.stdout}\n${build.stderr}`);
  }
  if (!existsSync(candidate)) {
    throw new Error(`runtime native build produced no artifact: ${candidate}`);
  }
  nativeBinaryPaths.set(binary, candidate);
  return candidate;
}

function runtimeBinaryPath() {
  return runtimeNativeBinaryPath(
    "stattic-runtime",
    "stattic-runtime-compiler",
    process.env.SPACEFAST_RUNTIME_BIN,
  );
}

const KEY_ID = "test-key";
const { publicKey, privateKey } = generateKeyPairSync("ed25519");
const JWKS = JSON.stringify({
  keys: [{ ...publicKey.export({ format: "jwk" }), kid: KEY_ID, alg: "EdDSA", use: "sig" }],
});
// The entrypoint bootstrap turns this fake Atomic payload into the same
// constants used on WP.Cloud.
export const DASHBOARD_ORIGIN = "https://my.spacefast.com";

export const RUNTIME_ATOMIC_DATA = {
  SPACEFAST_API_BASE_URL: "https://api.spacefast.com",
  SPACEFAST_BROWSER_API_URL: "https://api.spacefast.com",
  // The one origin allowed to READ a blob-gate response with fetch(). Pushed as
  // provider persistent data like every other runtime config value.
  SPACEFAST_DASHBOARD_ORIGIN: DASHBOARD_ORIGIN,
  SPACEFAST_RUNTIME_INSTANCE_ID: RUNTIME_INSTANCE_ID,
  SPACEFAST_RUNTIME_JWKS_B64: Buffer.from(JWKS, "utf8").toString("base64"),
};
// The harness always serves over plain http (`php -S`); the access cookie
// Secure flag defaults to on (Spacefast never serves plain http in
// production), so without this dev escape a spec-compliant cookie jar would
// refuse to send the cookie back over http://127.0.0.1 and break every
// cookie-round-trip test. A genuine process env var (not Atomic Persistent
// Data) so it's visible on every request path via getenv(), including plain
// content serving that never loads bootstrap-config.php. Tests can override
// per-runtime with `startRuntime({ env: { SPACEFAST_INSECURE_COOKIES: "" } })`.
export const DEFAULT_ENV: Record<string, string> = {
  SPACEFAST_INSECURE_COOKIES: "1",
  SPACEFAST_RUNTIME_TEST_MODE: "1",
};

/**
 * The route config for a publicly readable Space.
 *
 * `public_exposure` is the control plane's own exposure descriptor and is the
 * ONLY authority generate.php accepts for the overlay's `open` flag (D34) —
 * "zero grants" is the most protected projection there is, never an open one.
 * Omitting it left every fixture Space closed, so the whole no-access-code fast
 * lane went unexercised. `open` additionally needs unconditional `live` AND
 * `all_versions` public grants, so only `live_and_all_versions` opens a Space.
 */
export function publicAccessConfig(
  config: Record<string, unknown> = {},
  target: "live" | "all_versions" | "live_and_all_versions" = "live",
): Record<string, unknown> {
  const targets = target === "live_and_all_versions" ? ["live", "all_versions"] : [target];
  return {
    ...config,
    public_exposure: {
      v: 1,
      public: true,
      authorizationDigest: "0".repeat(64),
      contentTypes: null,
      externalProxy: false,
      unmodeled: "",
    },
    projection_generation: 1,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/runtime-public",
      spaceClaimed: true,
      grants: targets.map((kind) => ({
        id: `grt_runtime_public_${kind}`,
        generation: 1,
        name: "Public",
        audience: { kind: "public" },
        resources: { include: ["/**"], exclude: [] },
        capabilities: ["page.view"],
        constraints: {},
        target: { kind },
        source: { kind: "managed", reference: "test:runtime-public" },
      })),
    },
  };
}
// A second, untrusted key for bad-signature tests.
const rogue = generateKeyPairSync("ed25519");

export function base64url(data: string | Uint8Array): string {
  return Buffer.from(data).toString("base64url");
}

// The one compact-JWS (EdDSA) encoder for the suite. The harness's own
// management/upload/blob-gate minters and every test-local visitor-token factory
// build on it instead of re-rolling header/payload/sign per file.
export function signEd25519Jwt(
  signingKey: Parameters<typeof sign>[2],
  kid: string,
  claims: Record<string, unknown>,
): string {
  const header = base64url(JSON.stringify({ alg: "EdDSA", kid }));
  const payload = base64url(JSON.stringify(claims));
  const signature = sign(null, Buffer.from(`${header}.${payload}`), signingKey);
  return `${header}.${payload}.${base64url(signature)}`;
}

// The internal verification-key descriptor for a test-owned visitor keypair.
export function visitorIssuer(issuerKey: { export(options: { format: "jwk" }): JsonWebKey }): {
  kid: string;
  alg: string;
  publicKey: string;
} {
  const jwk = issuerKey.export({ format: "jwk" });
  return { kid: "spacefast-runtime-v1", alg: "EdDSA", publicKey: jwk.x ?? "" };
}

export function signToken(
  claims: Record<string, unknown>,
  { ttlSeconds = 300, rogueKey = false } = {},
): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(rogueKey ? rogue.privateKey : privateKey, KEY_ID, {
    exp: now + ttlSeconds,
    nbf: now - 30,
    ...claims,
  });
}

export function managementToken(action: string, scope: Record<string, unknown> = {}): string {
  return signToken({
    aud: "stattic-runtime-management",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    operation_id: `op_${action}`,
    action,
    jti: `jti_${randomUUID()}`,
    ...scope,
  });
}

export function uploadToken(
  spaceId: string,
  uploadId: string,
  versionId: string,
  overrides: Record<string, unknown> = {},
): string {
  return signToken({
    aud: "stattic-runtime-upload",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: spaceId,
    deploy_session_id: uploadId,
    version_id: versionId,
    ...overrides,
  });
}

/**
 * The blob token gate audience (contracts §7, D35→D75/D36/D37).
 *
 * Exactly one resolver: an uploads-store `record` id, an OPEN publish session's
 * `upload` id (the only thing that can authorize bytes for a version whose
 * catalog does not exist yet), or a `versionId` whose catalog lists the sha.
 * Deliberately unpinned to a runtime instance — the gate's own scope_valid
 * rejects a token carrying two resolvers or none, and that rejection is part of
 * what tests exercise.
 */
export function blobGateToken(
  spaceId: string,
  sha256Hex: string,
  resolver: { record: string } | { upload: string } | { versionId: string; variantRoute?: string },
  { ttlSeconds = 600, rogueKey = false }: { ttlSeconds?: number; rogueKey?: boolean } = {},
): string {
  return signToken(
    {
      aud: "spacefast-blob-gate",
      space_id: spaceId,
      sha256: sha256Hex,
      ...("record" in resolver ? { record: resolver.record } : {}),
      ...("upload" in resolver ? { upload: resolver.upload } : {}),
      ...("versionId" in resolver
        ? {
            version_id: resolver.versionId,
            ...(resolver.variantRoute ? { route_name: resolver.variantRoute } : {}),
          }
        : {}),
    },
    { ttlSeconds, rogueKey },
  );
}

export type VersionFileView = "source" | "served";

/**
 * The gate's path lane: the same audience, resolved through the version catalog
 * instead of a sha. Carries no `sha256` and no `route_name` on purpose — those
 * belong to the content-addressed lane above, and the gate rejects a token that
 * mixes them.
 */
export function blobGatePathToken(
  spaceId: string,
  versionId: string,
  filePath: string,
  view: VersionFileView,
  { ttlSeconds = 600, rogueKey = false }: { ttlSeconds?: number; rogueKey?: boolean } = {},
): string {
  return signToken(
    {
      aud: "spacefast-blob-gate",
      space_id: spaceId,
      version_id: versionId,
      path: filePath,
      view,
    },
    { ttlSeconds, rogueKey },
  );
}

/**
 * Every admin path rides `?route=` on its entrypoint alias (contracts §3 route
 * table; shared/context.php `_stattic_runtime_*_api_route_path`). Both surfaces
 * use the identical convention for route-addressed operations. Path-addressed
 * file and URL uploads also accept their compact op=/upload_id=/path spelling.
 *
 * The upload surface accepts `route` or the compact `op`, `upload_id`, and
 * `path` parameters. Other query parameters remain deliberate 405 probes.
 */
export function runtimeHttpPath(apiPath: string): string {
  for (const base of [RUNTIME_HTTP_API_BASE, RUNTIME_UPLOAD_API_BASE]) {
    if (apiPath !== base && !apiPath.startsWith(`${base}/`)) continue;
    const route = apiPath === base ? "/" : apiPath.slice(base.length);
    if (route === "/") return base;
    const [pathname = "/", query = ""] = route.split("?", 2);
    const params = new URLSearchParams(query);
    params.set("route", pathname);
    return `${base}?${params.toString()}`;
  }
  return apiPath;
}

/** One edge-cache purge POST the runtime made, as the capture server saw it. */
export type EdgePurgeCall = {
  hostname: string;
  reason: string;
  uris: string[];
};

export type Runtime = {
  baseUrl: string;
  root: string;
  engineRoot: string;
  storageRoot: string;
  processId: number;
  /** Present only when startRuntime({ captureEdgePurges: true }): the purge POSTs the runtime made. */
  edgePurges?: EdgePurgeCall[];
  stop: () => void;
};

export type RuntimeOptions = {
  env?: Record<string, string>;
  atomicData?: Record<string, string>;
  autoPrependInit?: boolean;
  phpBinary?: string;
  phpIni?: Record<string, string>;
  /**
   * Stand up a loopback capture server for the edge-cache purge API and point
   * the runtime at it (shared/purge.php `SPACEFAST_EDGE_CACHE_API_BASE`), so a
   * mutation's provider purge is observable as `rt.edgePurges`. Off by default:
   * without it the platform site env is absent and a purge is a no-op, so tests
   * that don't assert on purges pay nothing.
   */
  captureEdgePurges?: boolean;
};

async function freePort(): Promise<number> {
  const server = net.createServer();
  server.listen(0, "127.0.0.1");
  await new Promise((resolve) => server.once("listening", resolve));
  const port = (server.address() as net.AddressInfo).port;
  server.close();
  return port;
}

function installEngine(root: string): void {
  const manifest = JSON.parse(
    readFileSync(path.join(RUNTIME_DIR, "engine-manifest.json"), "utf8"),
  ) as { files: string[]; aliases: Array<{ source: string; path: string }> };
  // The manifest is the single authority for what ships, and it is edited by
  // the orchestrator at commit time rather than by the streams that add or
  // delete engine files. A drifted manifest therefore fails here first — report
  // it as drift naming the entries, not as a bare ENOENT from cpSync.
  const missing = manifest.files.filter(
    (file) => file !== "bin/stattic-runtime" && !existsSync(path.join(RUNTIME_DIR, file)),
  );
  if (missing.length > 0) {
    throw new Error(
      `engine-manifest.json names files that do not exist: ${missing.join(", ")} — ` +
        "the manifest is stale relative to the engine tree",
    );
  }
  const release = "releases/test";
  const releaseRoot = path.join(root, ".stattic", release);
  for (const file of manifest.files) {
    const target = path.join(releaseRoot, file);
    mkdirSync(path.dirname(target), { recursive: true });
    const source =
      file === "bin/stattic-runtime" ? runtimeBinaryPath() : path.join(RUNTIME_DIR, file);
    cpSync(source, target);
  }
  for (const alias of manifest.aliases) {
    const target = path.join(root, alias.path);
    mkdirSync(path.dirname(target), { recursive: true });
    cpSync(path.join(RUNTIME_DIR, alias.source), target);
  }
  writeActiveReleasePointer(path.join(root, ".stattic"), release);
  mkdirSync(path.join(root, ".stattic", "storage"), { recursive: true });
}

function writeGeneratedConfig(root: string): void {
  writeFileSync(
    path.join(root, ".stattic/storage/config.generated.php"),
    ["<?php", "declare(strict_types=1);", ""].join("\n"),
  );
}

export async function startRuntime(options: RuntimeOptions = {}): Promise<Runtime> {
  // realpathSync: macOS tmpdirs live behind the /var -> /private/var symlink.
  // The engine compares textually-normalized candidate paths against
  // realpath'd roots (shared/storage.php private-path asserts) and
  // get_included_files() reports resolved paths, so every path a test hands
  // to PHP (e.g. rt.storageRoot into a CLI spawn) must already be resolved —
  // in-server requests get this for free because getcwd() resolves symlinks.
  const root = realpathSync(mkdtempSync(path.join(os.tmpdir(), "stattic-runtime-test-")));
  installEngine(root);
  const atomicData = { ...RUNTIME_ATOMIC_DATA, ...options.atomicData };
  writeFileSync(path.join(root, ".atomic-persistent-data.json"), `${JSON.stringify(atomicData)}\n`);
  writeGeneratedConfig(root);
  if (options.autoPrependInit) {
    writeFileSync(
      path.join(root, ".stattic/test-prepend.php"),
      [
        "<?php",
        `require ${JSON.stringify(RUNTIME_TEST_ATOMIC_PREPEND)};`,
        `require ${JSON.stringify(path.join(root, ".stattic/releases/test/engine/init.php"))};`,
        "",
      ].join("\n"),
    );
  }
  const phpArgs = [
    "-d",
    "opcache.enable_cli=0",
    "-d",
    `auto_prepend_file=${RUNTIME_TEST_ATOMIC_PREPEND}`,
  ];
  if (options.autoPrependInit) {
    phpArgs[3] = `auto_prepend_file=${path.join(root, ".stattic/test-prepend.php")}`;
  }
  for (const [name, value] of Object.entries(options.phpIni ?? {})) {
    phpArgs.push("-d", `${name}=${value}`);
  }
  // freePort() necessarily closes its reservation before PHP can bind. Tests in
  // one Bun process can call startRuntime concurrently, so serialize only the
  // allocate+bind window; once health responds, every fixture runs in parallel.
  const edgePurges: EdgePurgeCall[] = [];
  const edgeCapture = options.captureEdgePurges ? startEdgePurgeCapture(edgePurges) : null;
  // Point the runtime's purge lane at the capture server (shared/purge.php reads
  // these three). Only when capture is on: otherwise the platform env is absent
  // and a purge is a no-op, which is what off-box (dev/CI) sees anyway.
  const edgeCaptureEnv: Record<string, string> = edgeCapture
    ? {
        ATOMIC_SITE_ID: "152088120",
        ATOMIC_SITE_API_KEY: "test-edge-cache-key",
        SPACEFAST_EDGE_CACHE_API_BASE: edgeCapture.apiBase,
      }
    : {};

  const releaseStartLock = await acquireRuntimeStartLock();
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  phpArgs.push("-S", `127.0.0.1:${port}`, RUNTIME_TEST_ROUTER);
  const server = spawn(options.phpBinary ?? PHP_BINARY, phpArgs, {
    cwd: root,
    // The CLI server logs every request. Nobody consumes these streams, so
    // pipes eventually fill and can reset an otherwise valid long-running
    // fixture request. Keep the harness silent instead of backpressuring PHP.
    stdio: "ignore",
    // PHP_CLI_SERVER_WORKERS forks children. Give the fixture its own process
    // group so stop() can reap the whole server instead of leaking listeners
    // after killing only the parent process.
    detached: process.platform !== "win32",
    env: {
      ...process.env,
      ...DEFAULT_ENV,
      SPACEFAST_RUNTIME_BIN: options.env?.SPACEFAST_RUNTIME_BIN ?? runtimeBinaryPath(),
      ...edgeCaptureEnv,
      ...options.env,
    },
  });
  const stopServer = () => {
    if (process.platform !== "win32" && server.pid !== undefined) {
      try {
        process.kill(-server.pid, "SIGKILL");
        return;
      } catch {
        // The parent may have exited before cleanup; fall back to the handle.
      }
    }
    server.kill("SIGKILL");
  };

  try {
    const deadline = Date.now() + 10_000;
    for (;;) {
      if (server.exitCode !== null) {
        throw new Error(`php_exited:${server.exitCode}`);
      }
      // oxlint-disable-next-line no-await-in-loop -- readiness poll: each probe depends on the previous one failing
      const response = await fetch(`${baseUrl}/__spacefast/health.php`).catch(() => null);
      if (response?.ok) {
        break;
      }
      if (Date.now() > deadline) {
        stopServer();
        throw new Error("php_server_start_timeout");
      }
      // oxlint-disable-next-line no-await-in-loop -- readiness poll backoff
      await new Promise((resolve) => setTimeout(resolve, 25));
    }
  } finally {
    releaseStartLock();
  }

  if (server.pid === undefined) throw new Error("php_server_pid_missing");
  return {
    baseUrl,
    root,
    engineRoot: path.join(root, ".stattic/releases/test/engine"),
    storageRoot: path.join(root, ".stattic", "storage"),
    processId: server.pid,
    edgePurges: edgeCapture ? edgePurges : undefined,
    stop: () => {
      stopServer();
      edgeCapture?.stop();
      rmSync(root, { recursive: true, force: true });
    },
  };
}

// A loopback stand-in for the wp.cloud edge-cache purge gateway. It records each
// POST the runtime makes — hostname (path segment), reason (wp_action), and any
// purge_uris — into `sink`, and answers the gateway's `{"message":"OK"}` so the
// runtime counts the purge accepted. The live wire is covered by the
// credential-gated e2e suite; this only proves the runtime's own decisions.
type EdgePurgeCapture = { apiBase: string; stop: () => void };

function startEdgePurgeCapture(sink: EdgePurgeCall[]): EdgePurgeCapture {
  const server = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const url = new URL(request.url);
      const match = url.pathname.match(/\/edge-cache\/\d+\/purge\/([^/]+)$/);
      if (request.method !== "POST" || match === null) {
        return new Response('{"message":"Invalid action","data":[]}', { status: 400 });
      }
      const hostname = decodeURIComponent(match[1] ?? "");
      const form = new URLSearchParams(await request.text());
      const uris: string[] = [];
      for (const [key, value] of form) {
        if (key.startsWith("purge_uris")) {
          uris.push(value);
        }
      }
      const action = form.get("wp_action") ?? "";
      sink.push({
        hostname,
        reason: action.startsWith("spacefast:") ? action.slice("spacefast:".length) : action,
        uris,
      });
      return new Response('{"message":"OK","data":[]}', {
        headers: { "content-type": "application/json" },
      });
    },
  });
  return {
    apiBase: `http://127.0.0.1:${server.port}/api/v1.0`,
    stop: () => void server.stop(true),
  };
}

function shellQuote(value: string): string {
  return `'${value.replace(/'/g, `'\\''`)}'`;
}

// Runs one management request through the SSH CLI dispatcher
// (engine/admin/dispatch.php) as its own OS process — genuine process-level
// concurrency between two calls, unlike racing fetch() against a shared
// dev-server connection pool. The request envelope goes in a temp file under
// rt.root and stdout/stderr are captured to sibling files; `elapsedMs` is
// measured around the child so callers can assert write-lock contention
// windows.
//
// Minimal env by default (PATH + HOME only): the atomic prepend resolves
// runtime config from getcwd(). This deliberately uses a non-login shell: a
// login shell is allowed to replace caller-controlled HOME and chdir while it
// sources system profiles, which breaks both runtime discovery and the
// provider scan-log contract on Linux CI. Callers that need a narrower env
// pass their own.
export function dispatchCli(
  rt: Runtime,
  stdin: string,
  options: { env?: Record<string, string | undefined>; phpFlags?: readonly string[] } = {},
): Promise<{ exitCode: number; stdout: string; stderr: string; elapsedMs: number }> {
  const env = options.env ?? { PATH: process.env.PATH, HOME: process.env.HOME };
  const requestPath = path.join(rt.root, `.dispatch-${Date.now()}-${Math.random()}.json`);
  const stdoutPath = `${requestPath}.stdout`;
  const stderrPath = `${requestPath}.stderr`;
  writeFileSync(requestPath, stdin);
  const start = Date.now();
  const child = spawn(
    "sh",
    [
      "-c",
      [
        shellQuote(PHP_BINARY),
        "-d display_errors=stderr",
        ...(options.phpFlags ?? []),
        `-d auto_prepend_file=${shellQuote(RUNTIME_TEST_ATOMIC_PREPEND)}`,
        shellQuote(path.join(rt.engineRoot, "admin/dispatch.php")),
        ">",
        shellQuote(stdoutPath),
        "2>",
        shellQuote(stderrPath),
      ].join(" "),
    ],
    {
      cwd: rt.root,
      env: { ...env, SPACEFAST_RUNTIME_DISPATCH_REQUEST_PATH: requestPath } as NodeJS.ProcessEnv,
      stdio: "ignore",
    },
  );
  return once(child, "exit").then(([code]) => ({
    exitCode: (code as number | null) ?? 1,
    stdout: readFileSync(stdoutPath, "utf8"),
    stderr: readFileSync(stderrPath, "utf8"),
    elapsedMs: Date.now() - start,
  }));
}

/**
 * The dispatch envelope for a management route, with the `?route=` spelling and
 * the action-scoped JWT already applied. dispatch.php derives its route from
 * `path`'s query exactly like HTTP does, so a hand-built envelope that forgets
 * `?route=` is a 400 rather than the route under test.
 */
export function dispatchEnvelope(
  method: string,
  apiPath: string,
  action: string,
  scope: Record<string, unknown> = {},
  body?: unknown,
): Record<string, unknown> {
  return {
    method,
    path: runtimeHttpPath(apiPath),
    authorization: `Bearer ${managementToken(action, scope)}`,
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  };
}

// Management API request. `scope` becomes action-scoped JWT claims.
export async function api(
  rt: Runtime,
  method: string,
  apiPath: string,
  action: string,
  scope: Record<string, unknown> = {},
  body?: unknown,
): Promise<Response> {
  return fetch(`${rt.baseUrl}${runtimeHttpPath(apiPath)}`, {
    method,
    headers: {
      "content-type": "application/json",
      authorization: `Bearer ${managementToken(action, scope)}`,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
}

export async function apiJson<T = Record<string, unknown>>(
  rt: Runtime,
  method: string,
  apiPath: string,
  action: string,
  scope: Record<string, unknown> = {},
  body?: unknown,
  expectedStatus = 200,
): Promise<T> {
  const response = await api(rt, method, apiPath, action, scope, body);
  const text = await response.text();
  if (response.status !== expectedStatus) {
    throw new Error(`${method} ${apiPath} -> ${response.status}: ${text}`);
  }
  return JSON.parse(text) as T;
}

// Public serving request against a virtual host.
export async function get(
  rt: Runtime,
  host: string,
  requestPath: string,
  init: Omit<RequestInit, "headers"> & { headers?: Record<string, string> } = {},
): Promise<Response> {
  return fetch(`${rt.baseUrl}${runtimeHttpPath(requestPath)}`, {
    redirect: "manual",
    ...init,
    // PHP's CLI server closes some bodyless responses (notably 304) in a way
    // that can leave Bun's Linux fetch pool holding a stale HTTP/1.1 socket.
    // The next behavioral probe then sees ECONNRESET before it reaches PHP.
    // This fixture is not a connection-pooling test, so make every request own
    // its transport and keep the assertions focused on runtime behavior.
    headers: { Connection: "close", Host: host, ...init.headers },
  });
}

/** One blob-gate fetch. The token IS the path segment (contracts §7). */
export async function getBlob(
  rt: Runtime,
  host: string,
  token: string,
  init: Omit<RequestInit, "headers"> & { headers?: Record<string, string> } = {},
): Promise<Response> {
  return get(rt, host, `${RUNTIME_BLOB_GATE_PREFIX}${token}`, init);
}

export async function postAccessCallback(
  rt: Runtime,
  host: string,
  token: string,
  returnTo = "/",
  cookie?: string,
): Promise<Response> {
  return get(rt, host, "/__sf/redeem", {
    method: "POST",
    headers: {
      "content-type": "application/x-www-form-urlencoded",
      origin: `https://${host}`,
      ...(cookie ? { cookie } : {}),
    },
    body: new URLSearchParams({ token, return: returnTo }),
  });
}

export function sha256(content: string | Uint8Array): string {
  return new Bun.CryptoHasher("sha256").update(content).digest("hex");
}

export async function errorCode(response: Response): Promise<string> {
  const body = (await response.json()) as { code?: string };
  return body.code ?? "";
}

// The RFC 9457 body the engine emits, derived the same way
// runtime/engine/shared/problem.php derives it. Building expectations from the
// TypeScript rules is what keeps the PHP mirror honest; `detail` is optional
// there, so a code with nothing to add beyond its title omits it here too.
export function problemDocument(
  status: number,
  code: string,
  detail?: string,
  extra: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    type: errorDocsUrl(code),
    title: errorTitle(code),
    status,
    ...(detail ? { detail } : {}),
    code,
    ...extra,
  };
}

// ---------------------------------------------------------------------------
// Publish: declared session -> path or content-addressed blobs -> finalize (contracts §9)
// ---------------------------------------------------------------------------

export type DeployFile =
  | string
  | Uint8Array
  | { content: string | Uint8Array; contentType?: string };

export type DeployFiles = Record<string, DeployFile>;

export type ManifestEntry = {
  path: string;
  size: number;
  sha256: string;
  contentType?: string;
};

export type PublishSession = {
  spaceId: string;
  versionId: string;
  uploadId: string;
  /** The `stattic-runtime-upload` bearer for this session's blob routes. */
  token: string;
  manifest: ManifestEntry[];
};

function fileBytes(file: DeployFile): Uint8Array {
  const content = typeof file === "object" && "content" in file ? file.content : file;
  return typeof content === "string" ? Buffer.from(content, "utf8") : content;
}

function fileContentType(file: DeployFile): string | undefined {
  return typeof file === "object" && "content" in file ? file.contentType : undefined;
}

/** The declared manifest a publish session is created with (path + size + sha). */
export function manifestFor(files: DeployFiles): ManifestEntry[] {
  return Object.entries(files).map(([filePath, file]) => {
    const bytes = fileBytes(file);
    const contentType = fileContentType(file);
    const entry: ManifestEntry = {
      path: filePath,
      size: bytes.byteLength,
      sha256: sha256(bytes),
    };
    if (contentType) entry.contentType = contentType;
    return entry;
  });
}

export type CreateSessionOptions = {
  metadata?: Record<string, unknown>;
  retainedFiles?: ManifestEntry[];
  reusableVersionId?: string;
  /** Explicit retention mode; required whenever `reusableVersionId` is set. */
  retention?: "all" | "list" | "none";
  manifestHash?: string;
  expiresAt?: string;
  /** Extra top-level fields on the create-version body. */
  extra?: Record<string, unknown>;
};

/**
 * POST /spaces/{spaceId}/versions — declares the manifest ONCE (path policy is
 * validated here, D26) and returns the durable publish session that holds the GC
 * pin (D92). A declared digest uses the CAS lane; an omitted digest uploads by
 * path and is bound to the runtime-computed digest.
 */
export async function createDeclaredSession(
  rt: Runtime,
  spaceId: string,
  versionId: string,
  files: DeployFiles,
  options: CreateSessionOptions = {},
): Promise<PublishSession> {
  const manifest = manifestFor(files);
  const created = await apiJson<{ upload_id: string }>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/versions`,
    "create_version",
    { space_id: spaceId },
    {
      version_id: versionId,
      files: manifest,
      ...(options.metadata ? { metadata: options.metadata } : {}),
      ...(options.retention ? { retention: options.retention } : {}),
      ...(options.retainedFiles ? { retained_files: options.retainedFiles } : {}),
      ...(options.reusableVersionId ? { reusable_version_id: options.reusableVersionId } : {}),
      ...(options.manifestHash ? { manifest_hash: options.manifestHash } : {}),
      ...(options.expiresAt ? { expires_at: options.expiresAt } : {}),
      ...options.extra,
    },
    201,
  );
  return {
    spaceId,
    versionId,
    uploadId: created.upload_id,
    token: uploadToken(spaceId, created.upload_id, versionId),
    manifest,
  };
}

/**
 * POST /spaces/{spaceId}/blobs/have — the path-blind negotiation (D22). Returns
 * the raw Response so a test can assert its own rejection; `blobsHaveMissing`
 * is the happy-path reader.
 *
 * Side effect worth knowing: every sha the CAS already holds AND the session
 * declared is marked accepted by this call, which is how a retained/deduped file
 * completes without a PUT.
 */
export async function blobsHave(
  rt: Runtime,
  session: Pick<PublishSession, "spaceId" | "token">,
  shas: string[],
): Promise<Response> {
  return fetch(
    `${rt.baseUrl}${runtimeHttpPath(`${RUNTIME_UPLOAD_API_BASE}/spaces/${session.spaceId}/blobs/have`)}`,
    {
      method: "POST",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({ shas }),
    },
  );
}

export async function blobsHaveMissing(
  rt: Runtime,
  session: Pick<PublishSession, "spaceId" | "token">,
  shas: string[],
): Promise<string[]> {
  const response = await blobsHave(rt, session, shas);
  const text = await response.text();
  if (response.status !== 200) {
    throw new Error(`blobs/have -> ${response.status}: ${text}`);
  }
  return (JSON.parse(text) as { missing: string[] }).missing;
}

/**
 * PUT /spaces/{spaceId}/blobs/{sha} — raw streamed body, sha verified during
 * streaming (D23). The engine caps concurrency at 4 per space and answers 429
 * above it (D25).
 */
export async function putBlob(
  rt: Runtime,
  spaceId: string,
  token: string,
  shaHex: string,
  content: string | Uint8Array,
): Promise<Response> {
  return fetch(
    `${rt.baseUrl}${runtimeHttpPath(`${RUNTIME_UPLOAD_API_BASE}/spaces/${spaceId}/blobs/${shaHex}`)}`,
    {
      method: "PUT",
      headers: { authorization: `Bearer ${token}` },
      body: content,
    },
  );
}

// `_stattic_runtime_upload_blobs_have` caps one negotiation at 2048 shas.
const BLOBS_HAVE_MAX = 2048;

/**
 * The full ingest half of a publish: negotiate, then PUT exactly the missing
 * blobs, deduplicated by sha. Throws naming the failing sha (and the paths that
 * carry it) so a broken upload does not surface as an opaque finalize 409.
 */
export async function uploadSessionBlobs(
  rt: Runtime,
  session: PublishSession,
  files: DeployFiles,
): Promise<void> {
  const bytesBySha = new Map<string, Uint8Array>();
  const pathsBySha = new Map<string, string[]>();
  for (const [filePath, file] of Object.entries(files)) {
    const bytes = fileBytes(file);
    const sha = sha256(bytes);
    bytesBySha.set(sha, bytes);
    pathsBySha.set(sha, [...(pathsBySha.get(sha) ?? []), filePath]);
  }
  const shas = [...bytesBySha.keys()];
  const missing: string[] = [];
  for (let index = 0; index < shas.length; index += BLOBS_HAVE_MAX) {
    // oxlint-disable-next-line eslint/no-await-in-loop -- one negotiation per chunk, sequential so a failure names its chunk
    const chunkMissing = await blobsHaveMissing(
      rt,
      session,
      shas.slice(index, index + BLOBS_HAVE_MAX),
    );
    missing.push(...chunkMissing);
  }
  for (const sha of missing) {
    const bytes = bytesBySha.get(sha);
    if (bytes === undefined) {
      throw new Error(`blobs/have reported a sha the session never declared: ${sha}`);
    }
    // oxlint-disable-next-line no-await-in-loop -- uploads stay sequential so a failure names the exact blob
    const response = await putBlob(rt, session.spaceId, session.token, sha, bytes);
    if (response.status !== 200) {
      // oxlint-disable-next-line no-await-in-loop -- error path reads the failing response body
      const body = await response.text();
      throw new Error(
        `PUT blob ${sha} (${(pathsBySha.get(sha) ?? []).join(", ")}) -> ${response.status}: ${body}`,
      );
    }
  }
}

/** POST /spaces/{s}/versions/{v}/finalize — raw, no status assertion. */
export async function finalize(
  rt: Runtime,
  spaceId: string,
  versionId: string,
  body: Record<string, unknown>,
): Promise<Response> {
  return api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: spaceId, version_id: versionId },
    body,
  );
}

export type DeploySpec = {
  spaceId: string;
  versionId: string;
  files: DeployFiles;
  metadata?: Record<string, unknown>;
  serving?: Record<string, unknown>;
  /** Finalize-rendered Pages documents stored outside the CAS manifest. */
  pageArtifacts?: Record<string, string>;
  zero?: Record<string, unknown>;
  functions?: Record<string, unknown>;
  activate?: Record<string, unknown>;
  /** Passed to the create-version call (retained files, convention files, ...). */
  session?: CreateSessionOptions;
  /** Extra top-level fields on the finalize body. */
  finalize?: Record<string, unknown>;
};

/** Full declared publish: declare -> negotiate -> upload blobs -> finalize (+activate). */
export async function deploy(rt: Runtime, spec: DeploySpec): Promise<void> {
  const session = await createDeclaredSession(rt, spec.spaceId, spec.versionId, spec.files, {
    ...spec.session,
    metadata: spec.metadata ?? spec.session?.metadata,
  });
  await uploadSessionBlobs(rt, session, spec.files);
  await apiJson(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spec.spaceId}/versions/${spec.versionId}/finalize`,
    "finalize_version",
    { space_id: spec.spaceId, version_id: spec.versionId },
    {
      upload_id: session.uploadId,
      ...(spec.serving ? { serving: spec.serving } : {}),
      ...(spec.pageArtifacts ? { page_artifacts: spec.pageArtifacts } : {}),
      ...(spec.zero ? { zero: spec.zero } : {}),
      ...(spec.functions ? { functions: spec.functions } : {}),
      ...(spec.activate ? { activate: spec.activate } : {}),
      ...spec.finalize,
    },
  );
}

/**
 * Negative-path finalize: declare a session, upload every blob (throwing on a
 * failed upload — a precondition every reject-at-finalize test already assumes)
 * and POST finalize with the caller's raw body, returning the Response WITHOUT
 * asserting its status. The happy path lives in deploy(); this exists for the
 * tests that need to inspect the finalize rejection.
 */
export async function finalizeRaw(
  rt: Runtime,
  spaceId: string,
  versionId: string,
  files: DeployFiles,
  finalizeBody: Record<string, unknown>,
  sessionOptions: CreateSessionOptions = {},
): Promise<Response> {
  const session = await createDeclaredSession(rt, spaceId, versionId, files, sessionOptions);
  await uploadSessionBlobs(rt, session, files);
  return finalize(rt, spaceId, versionId, { upload_id: session.uploadId, ...finalizeBody });
}

export async function putRoute(
  rt: Runtime,
  spaceId: string,
  routeName: string,
  body: Record<string, unknown>,
  expectedStatus = 200,
): Promise<Response> {
  const response = await api(
    rt,
    "PUT",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/routes/${routeName}`,
    "update_route",
    { space_id: spaceId, route_name: routeName },
    body,
  );
  if (response.status !== expectedStatus) {
    throw new Error(
      `PUT route ${spaceId}/${routeName} -> ${response.status}: ${await response.text()}`,
    );
  }
  return response;
}

// ---------------------------------------------------------------------------
// Journal pull lane (contracts §10, D53)
// ---------------------------------------------------------------------------

export type JournalCursor = { file: string; offset: number; inode: number };

export type DrainedEvent = {
  delivery_id: string;
  /** The position to ack through this event; the page cursor is the last one. */
  cursor: JournalCursor;
  event_id: string;
  operation_id?: string;
  event: Record<string, unknown>;
};

export type DrainResponse = {
  delivered_count: number;
  failed_count: number;
  expired_count: number;
  returned_count: number;
  pending_count: number;
  cursor: JournalCursor;
  events: DrainedEvent[];
};

/** POST /events/drain — reads journal.jsonl from the persisted {file, offset}. */
export async function drainEvents(
  rt: Runtime,
  body: Record<string, unknown> = {},
): Promise<DrainResponse> {
  return apiJson<DrainResponse>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/events/drain`,
    "drain_events",
    {},
    { session_id: `ses_${randomUUID().replace(/-/g, "")}`, page_id: "page_1", ...body },
  );
}

/** POST /events/ack — commits a read position; the cursor never moves backwards. */
export async function ackEvents(
  rt: Runtime,
  body: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  return apiJson(rt, "POST", `${RUNTIME_HTTP_API_BASE}/events/ack`, "ack_events", {}, body);
}

// ---------------------------------------------------------------------------
// Artifact readers (contracts §1–§5)
//
// Pointers are JSON and read directly. PHP artifacts are `return`-ing arrays, so
// they are decoded by running PHP — the only way to read one without
// re-implementing var_export. Keys AND string values ride base64 because table
// keys are deliberately NUL-prefixed ("\0spa", "\0404:<dir>") and would not
// survive JSON.
// ---------------------------------------------------------------------------

const DUMP_PHP = `
$file = (string) getenv('SF_DUMP_FILE');
$value = is_file($file) ? (@include $file) : null;
$encode = function ($node) use (&$encode) {
    if (is_array($node)) {
        $pairs = [];
        foreach ($node as $key => $child) {
            $pairs[] = [is_string($key) ? base64_encode($key) : $key, $encode($child)];
        }
        return ['a', $pairs];
    }
    if (is_string($node)) {
        return ['s', base64_encode($node)];
    }
    return ['v', $node];
};
echo json_encode($encode($value));
`;

type DumpNode = ["a", Array<[string | number, DumpNode]>] | ["s", string] | ["v", unknown];

function decodeDump(node: DumpNode): unknown {
  if (node[0] === "v") return node[1];
  if (node[0] === "s") return Buffer.from(node[1], "base64").toString("binary");
  const out: Record<string, unknown> = {};
  for (const [key, child] of node[1]) {
    const name =
      typeof key === "number" ? String(key) : Buffer.from(key, "base64").toString("binary");
    out[name] = decodeDump(child);
  }
  return out;
}

/**
 * Decode one content-addressed PHP artifact into a plain object. Returns null
 * when the file is absent or does not `return` an array.
 *
 * Strings come back as latin-1/binary-safe JS strings so a NUL-prefixed table
 * key round-trips exactly; a UTF-8 path key therefore reads as its raw bytes —
 * compare against `Buffer.from(key, "utf8").toString("binary")` when a test uses
 * non-ASCII paths.
 */
export function phpArtifact(file: string): unknown {
  const proc = Bun.spawnSync([PHP_BINARY, "-d", "opcache.enable_cli=0", "-r", DUMP_PHP], {
    env: { ...process.env, SF_DUMP_FILE: file },
  });
  const stdout = proc.stdout.toString();
  if (proc.exitCode !== 0) {
    throw new Error(`php artifact dump failed (${file}):\n${stdout}\n${proc.stderr.toString()}`);
  }
  const decoded = decodeDump(JSON.parse(stdout) as DumpNode);
  return decoded !== null && typeof decoded === "object" ? decoded : null;
}

function readJsonFile(file: string): unknown {
  if (!existsSync(file)) return null;
  return JSON.parse(readFileSync(file, "utf8"));
}

export function storagePath(rt: Runtime, ...segments: string[]): string {
  return path.join(rt.storageRoot, ...segments);
}

/**
 * The edge-cache purge POSTs the runtime has made, captured by the loopback
 * stand-in (startRuntime({ captureEdgePurges: true })). Throws if the runtime
 * was not started with capture on, so a silent empty array can't read as
 * "nothing purged".
 */
export function edgePurgeCalls(rt: Runtime): EdgePurgeCall[] {
  if (rt.edgePurges === undefined) {
    throw new Error("edgePurgeCalls requires startRuntime({ captureEdgePurges: true })");
  }
  return rt.edgePurges;
}

export function spaceRoot(rt: Runtime, spaceId: string): string {
  return storagePath(rt, "spaces", spaceId);
}

export function versionRoot(rt: Runtime, spaceId: string, versionId: string): string {
  return storagePath(rt, "spaces", spaceId, "versions", versionId);
}

/** The one CAS layout: spaces/<s>/blobs/<aa>/<sha> (contracts §8, derived never stored). */
export function blobPath(rt: Runtime, spaceId: string, shaHex: string): string {
  return storagePath(rt, "spaces", spaceId, "blobs", shaHex.slice(0, 2), shaHex);
}

export function readBlob(rt: Runtime, spaceId: string, shaHex: string): Buffer | null {
  const file = blobPath(rt, spaceId, shaHex);
  return existsSync(file) ? readFileSync(file) : null;
}

export type RoutePointer = {
  schema: number;
  gen: number;
  has_wildcards: boolean;
  shards: Record<string, string>;
  wildcards: string;
  owners?: string;
};

export function routePointer(rt: Runtime): RoutePointer | null {
  return readJsonFile<RoutePointer>(storagePath(rt, "routes", "current.json"));
}

export function previousRoutePointer(rt: Runtime): RoutePointer | null {
  return readJsonFile<RoutePointer>(storagePath(rt, "routes", "previous.json"));
}

export type HostEntry = {
  space_id?: string;
  version_id?: string;
  route_name?: string;
  route_action?: Record<string, unknown>;
  [key: string]: unknown;
};

export type HostShard = {
  hostnames: Record<string, HostEntry>;
  host_routes: Record<string, unknown>;
};

/** The host shard that owns `host`: shards[sha256(host)[0:2]] (contracts §3). */
export function routeShard(rt: Runtime, host: string): HostShard | null {
  const pointer = routePointer(rt);
  const name = pointer?.shards?.[sha256(host).slice(0, 2)];
  return name ? phpArtifact<HostShard>(storagePath(rt, "routes", name)) : null;
}

export function wildcardShard(rt: Runtime): HostShard | null {
  const pointer = routePointer(rt);
  return pointer?.wildcards
    ? phpArtifact<HostShard>(storagePath(rt, "routes", pointer.wildcards))
    : null;
}

/** The exact-host entry (no wildcard fallback — assert that shard directly). */
export function hostEntry(rt: Runtime, host: string): HostEntry | null {
  return routeShard(rt, host)?.hostnames?.[host] ?? null;
}

export type SpacePointer = { schema: number; gen: number; overlay: string };

export function spacePointer(rt: Runtime, spaceId: string): SpacePointer | null {
  return readJsonFile<SpacePointer>(path.join(spaceRoot(rt, spaceId), "space.json"));
}

export type SpaceOverlay = {
  schema: number;
  open: boolean;
  fence: string | null;
  access_gen: number;
  session_ver: number;
  versions: Record<string, string>;
  grants: Record<string, unknown> | null;
  exchange: Record<string, unknown>;
  [key: string]: unknown;
};

export function spaceOverlay(rt: Runtime, spaceId: string): SpaceOverlay | null {
  const pointer = spacePointer(rt, spaceId);
  return pointer?.overlay
    ? phpArtifact<SpaceOverlay>(path.join(spaceRoot(rt, spaceId), pointer.overlay))
    : null;
}

export type VersionRootPointer = { root: string };

export function versionRootPointer(
  rt: Runtime,
  spaceId: string,
  versionId: string,
): VersionRootPointer | null {
  return readJsonFile<VersionRootPointer>(
    path.join(versionRoot(rt, spaceId, versionId), "root.json"),
  );
}

export type VersionRootArtifact = {
  schema: number;
  compiler?: string;
  /** `{"*": "responses-<h16>.php"}`, or `{"<xx>": "responses-<xx>-<h16>.php"}` when split. */
  tables: Record<string, string>;
};

export function versionRootArtifact(
  rt: Runtime,
  spaceId: string,
  versionId: string,
): VersionRootArtifact | null {
  const pointer = versionRootPointer(rt, spaceId, versionId);
  return pointer?.root
    ? phpArtifact<VersionRootArtifact>(path.join(versionRoot(rt, spaceId, versionId), pointer.root))
    : null;
}

/** The response-table files this version's root names (one, or the split set). */
export function responseTableFiles(
  rt: Runtime,
  spaceId: string,
  versionId: string,
): Record<string, string> {
  return versionRootArtifact(rt, spaceId, versionId)?.tables ?? {};
}

export type ResponseEntry = {
  /** status */ s?: number;
  /** headers, lower-case keys */ h?: Record<string, string>;
  /** body blob sha256, absent for pure-header responses */ b?: string;
  /** precomputed ETag payload, no quotes */ et?: string;
  /** body length */ len?: number;
  /** 0 = accel, 1 = php-body */ lane?: number;
  /** cache class: "imm" | "html" | "no" */ cc?: string;
  /** action */ a?: Record<string, unknown> | null;
  /** rules-first marker */ r?: unknown;
  /** provider-allowlisted client-URL extension */ xa?: unknown;
  [key: string]: unknown;
};

/**
 * Every compiled entry of a version, merged across its response tables. Keys are
 * raw byte strings, so the reserved ones are exactly RESPONSES.specialKeys
 * ("\0spa", "\0404", "\0404:<dir>", "\0rules", "\0robots").
 */
export function responseEntries(
  rt: Runtime,
  spaceId: string,
  versionId: string,
): Record<string, ResponseEntry> {
  const dir = versionRoot(rt, spaceId, versionId);
  const entries: Record<string, ResponseEntry> = {};
  for (const file of Object.values(responseTableFiles(rt, spaceId, versionId))) {
    Object.assign(entries, phpArtifact<Record<string, ResponseEntry>>(path.join(dir, file)) ?? {});
  }
  return entries;
}

export function responseEntry(
  rt: Runtime,
  spaceId: string,
  versionId: string,
  key: string,
): ResponseEntry | null {
  return responseEntries(rt, spaceId, versionId)[key] ?? null;
}

/** The management-lane version manifest the scan lane and GC read (contracts §2). */
export function versionMetadata(
  rt: Runtime,
  spaceId: string,
  versionId: string,
): Record<string, unknown> | null {
  return readJsonFile<Record<string, unknown>>(
    path.join(versionRoot(rt, spaceId, versionId), "metadata.json"),
  );
}

export type CatalogObject = { sha256: string; size: number; contentType: string };

export type VersionCatalog = {
  format: string;
  spaceId: string;
  versionId: string;
  paths: Record<string, { source: CatalogObject; served: CatalogObject | null; public: boolean }>;
  variants?: Record<string, Record<string, CatalogObject>>;
};

/**
 * The finalizer-written file catalog. It rides INSIDE metadata.json so the blob
 * collector's shape-agnostic sweep reaches every hash it names. Nothing in the
 * test suite writes one — a missing catalog is a real finalize defect, not a
 * fixture gap.
 */
export function versionCatalog(
  rt: Runtime,
  spaceId: string,
  versionId: string,
): VersionCatalog | null {
  const metadata = versionMetadata(rt, spaceId, versionId);
  return (metadata?.catalog as VersionCatalog | undefined) ?? null;
}

/** The one event sink (D53): runtime/journal.jsonl, decoded line by line. */
export function journalRecords(rt: Runtime): Array<Record<string, unknown>> {
  const file = storagePath(rt, "runtime", "journal.jsonl");
  if (!existsSync(file)) return [];
  return readFileSync(file, "utf8")
    .split("\n")
    .filter((line) => line.trim() !== "")
    .map((line) => JSON.parse(line) as Record<string, unknown>);
}
