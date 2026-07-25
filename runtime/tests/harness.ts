// Shared harness for the runtime test suite. Self-contained: it installs the engine
// from runtime/engine-manifest.json into a temp web root, serves it with `php -S`,
// and signs management/upload JWTs with a per-process Ed25519 key delivered
// through the same Atomic_Persistent_Data surface WP.Cloud exposes before
// direct PHP entrypoints run. No imports from outside runtime/.
import { setDefaultTimeout } from "bun:test";
import { spawn, spawnSync } from "node:child_process";
import { createHash, generateKeyPairSync, randomUUID, sign } from "node:crypto";
import { once } from "node:events";
import {
  chmodSync,
  cpSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  readFileSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";

import { strToU8, zipSync } from "fflate";

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
export const MANAGEMENT_HOST = "127.0.0.1";
export const RUNTIME_HTTP_API_BASE = "/__spacefast/api.php";
export const RUNTIME_UPLOAD_API_BASE = "/__spacefast/upload.php";
const PHP_BINARY = process.env.PHP_BINARY ?? "php";
const REPO_ROOT = path.resolve(RUNTIME_DIR, "..");
const nativeBinaryPaths = new Map<string, string>();

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
  const packaged = path.join(RUNTIME_DIR, "bin", binary);
  if (existsSync(packaged) && packagedBinaryRuns(binary, packaged)) {
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

function runtimeFinalizerPath() {
  return runtimeNativeBinaryPath(
    "stattic-runtime-compiler",
    "stattic-runtime-compiler",
    process.env.SPACEFAST_RUNTIME_FINALIZER_BIN,
  );
}

function zeroRunnerPath() {
  return runtimeNativeBinaryPath(
    "stattic-zero-runner",
    "stattic-zero-runner",
    process.env.SPACEFAST_ZERO_RUNNER,
  );
}

const KEY_ID = "test-key";
const { publicKey, privateKey } = generateKeyPairSync("ed25519");
const JWKS = JSON.stringify({
  keys: [{ ...publicKey.export({ format: "jwk" }), kid: KEY_ID, alg: "EdDSA", use: "sig" }],
});
// The entrypoint bootstrap turns this fake Atomic payload into the same
// constants used on WP.Cloud.
export const RUNTIME_ATOMIC_DATA = {
  SPACEFAST_API_BASE_URL: "https://api.spacefast.com",
  SPACEFAST_MANAGEMENT_HOSTNAME: MANAGEMENT_HOST,
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
};
// A second, untrusted key for bad-signature tests.
const rogue = generateKeyPairSync("ed25519");

export function base64url(data: string | Uint8Array): string {
  return Buffer.from(data).toString("base64url");
}

// The one compact-JWS (EdDSA) encoder for the suite. The harness's own
// management/upload minters and every test-local visitor-token factory build
// on it instead of re-rolling header/payload/sign per file.
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

// The issuer descriptor a route policy's `issuers` entry carries for a
// test-owned visitor-token keypair (kid matches the platform signer's).
export function visitorIssuer(
  issuerKey: { export(options: { format: "jwk" }): JsonWebKey },
  grantNamespaces: string[],
): { kid: string; alg: string; publicKey: string; grantNamespaces: string[] } {
  const jwk = issuerKey.export({ format: "jwk" });
  return { kid: "spacefast-runtime-v1", alg: "EdDSA", publicKey: jwk.x ?? "", grantNamespaces };
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
  sessionMode: "declared" | "open",
  overrides: Record<string, unknown> = {},
): string {
  return signToken({
    aud: "stattic-runtime-upload",
    runtime_instance_id: RUNTIME_INSTANCE_ID,
    space_id: spaceId,
    deploy_session_id: uploadId,
    version_id: versionId,
    session_mode: sessionMode,
    ...overrides,
  });
}

export function runtimeHttpPath(apiPath: string): string {
  if (apiPath === RUNTIME_HTTP_API_BASE || apiPath.startsWith(`${RUNTIME_HTTP_API_BASE}/`)) {
    const route =
      apiPath === RUNTIME_HTTP_API_BASE ? "/" : apiPath.slice(RUNTIME_HTTP_API_BASE.length);
    if (route === "/") {
      return RUNTIME_HTTP_API_BASE;
    }
    const [pathname = "/", query = ""] = route.split("?", 2);
    const params = new URLSearchParams(query);
    params.set("route", pathname);
    return `${RUNTIME_HTTP_API_BASE}?${params.toString()}`;
  }
  if (apiPath === RUNTIME_UPLOAD_API_BASE || apiPath.startsWith(`${RUNTIME_UPLOAD_API_BASE}/`)) {
    return runtimeUploadHttpPath(apiPath);
  }
  return apiPath;
}

export function runtimeUploadHttpPath(apiPath: string): string {
  if (!apiPath.startsWith(`${RUNTIME_UPLOAD_API_BASE}/`)) {
    return apiPath;
  }
  const [suffix = "", query = ""] = apiPath.slice(RUNTIME_UPLOAD_API_BASE.length + 1).split("?", 2);
  const batch = suffix.match(/^([^/]+)\/batch$/);
  if (batch) {
    return appendRuntimeUploadQuery(
      `${RUNTIME_UPLOAD_API_BASE}?${new URLSearchParams({ op: "batch", upload_id: batch[1] ?? "" })}`,
      query,
    );
  }
  const part = suffix.match(/^([^/]+)\/files\/(.+)\/parts\/([0-9]{1,5})$/);
  if (part) {
    return appendRuntimeUploadQuery(
      runtimeUploadQueryPath(part[1] ?? "", part[2] ?? "", { part_number: part[3] ?? "" }),
      query,
    );
  }
  const complete = suffix.match(/^([^/]+)\/files\/(.+)\/complete$/);
  if (complete) {
    return appendRuntimeUploadQuery(
      runtimeUploadQueryPath(complete[1] ?? "", complete[2] ?? "", { complete: "1" }),
      query,
    );
  }
  const fetch = suffix.match(/^([^/]+)\/files\/(.+)\/fetch$/);
  if (fetch) {
    return appendRuntimeUploadQuery(
      runtimeUploadQueryPath(fetch[1] ?? "", fetch[2] ?? "", { op: "fetch" }),
      query,
    );
  }
  const file = suffix.match(/^([^/]+)\/files\/(.+)$/);
  if (file) {
    return appendRuntimeUploadQuery(runtimeUploadQueryPath(file[1] ?? "", file[2] ?? ""), query);
  }
  return apiPath;
}

function appendRuntimeUploadQuery(basePath: string, query: string): string {
  return query === "" ? basePath : `${basePath}&${query}`;
}

function runtimeUploadQueryPath(
  uploadId: string,
  encodedPath: string,
  extra: Record<string, string> = {},
): string {
  const params = new URLSearchParams({ op: "file", upload_id: uploadId, ...extra });
  return `${RUNTIME_UPLOAD_API_BASE}?${params.toString()}&path=${encodedPath}`;
}

export type Runtime = {
  baseUrl: string;
  root: string;
  storageRoot: string;
  stop: () => void;
};

export type RuntimeOptions = {
  env?: Record<string, string>;
  atomicData?: Record<string, string>;
  autoPrependInit?: boolean;
  phpIni?: Record<string, string>;
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
  for (const file of manifest.files) {
    const target = path.join(root, ".stattic", file);
    mkdirSync(path.dirname(target), { recursive: true });
    const source =
      file === "bin/stattic-runtime-compiler"
        ? runtimeFinalizerPath()
        : file === "bin/stattic-zero-runner"
          ? zeroRunnerPath()
          : path.join(RUNTIME_DIR, file);
    cpSync(source, target);
  }
  for (const alias of manifest.aliases) {
    const target = path.join(root, alias.path);
    mkdirSync(path.dirname(target), { recursive: true });
    cpSync(path.join(RUNTIME_DIR, alias.source), target);
  }
  mkdirSync(path.join(root, ".stattic", "storage"), { recursive: true });
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
  writeFileSync(
    path.join(root, ".atomic-persistent-data.json"),
    `${JSON.stringify({ ...RUNTIME_ATOMIC_DATA, ...options.atomicData })}\n`,
  );
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  if (options.autoPrependInit) {
    writeFileSync(
      path.join(root, ".stattic/test-prepend.php"),
      [
        "<?php",
        `require ${JSON.stringify(RUNTIME_TEST_ATOMIC_PREPEND)};`,
        `require ${JSON.stringify(path.join(root, ".stattic/engine/shared/bootstrap-config.php"))};`,
        `require ${JSON.stringify(path.join(root, ".stattic/engine/init.php"))};`,
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
  phpArgs.push("-S", `127.0.0.1:${port}`, RUNTIME_TEST_ROUTER);
  const server = spawn("php", phpArgs, {
    cwd: root,
    stdio: "pipe",
    env: {
      ...process.env,
      ...DEFAULT_ENV,
      SPACEFAST_RUNTIME_FINALIZER_BIN:
        options.env?.SPACEFAST_RUNTIME_FINALIZER_BIN ?? runtimeFinalizerPath(),
      SPACEFAST_ZERO_RUNNER: options.env?.SPACEFAST_ZERO_RUNNER ?? zeroRunnerPath(),
      ...options.env,
    },
  });

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
      server.kill();
      throw new Error("php_server_start_timeout");
    }
    // oxlint-disable-next-line no-await-in-loop -- readiness poll backoff
    await new Promise((resolve) => setTimeout(resolve, 25));
  }

  return {
    baseUrl,
    root,
    storageRoot: path.join(root, ".stattic", "storage"),
    stop: () => {
      server.kill();
      rmSync(root, { recursive: true, force: true });
    },
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
// runtime config from getcwd(), and `sh -l` on CI runners sources profile
// hooks that can chdir when the full CI env (GITHUB_* vars) is present —
// passing process.env wholesale moves cwd off rt.root there, config resolves
// empty, and every dispatch 404s (runtime_api_not_found) at the
// management-hostname assert. Callers that need a narrower env pass their own.
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
      "-lc",
      [
        shellQuote(PHP_BINARY),
        "-d display_errors=stderr",
        ...(options.phpFlags ?? []),
        `-d auto_prepend_file=${shellQuote(RUNTIME_TEST_ATOMIC_PREPEND)}`,
        shellQuote(path.join(rt.root, ".stattic/engine/admin/dispatch.php")),
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

// Management API request. `scope` becomes action-scoped JWT claims.
export async function api(
  rt: Runtime,
  method: string,
  apiPath: string,
  action: string,
  scope: Record<string, unknown> = {},
  body?: unknown,
): Promise<Response> {
  const staticMountRoutes =
    body &&
    typeof body === "object" &&
    "static_mount_routes" in body &&
    Array.isArray(body.static_mount_routes) &&
    body.static_mount_routes.length > 0
      ? body.static_mount_routes
      : null;
  const boundScope = staticMountRoutes
    ? {
        ...scope,
        static_mount_routes_sha256: createHash("sha256")
          .update(JSON.stringify(staticMountRoutes))
          .digest("hex"),
      }
    : scope;
  return fetch(`${rt.baseUrl}${runtimeHttpPath(apiPath)}`, {
    method,
    headers: {
      "content-type": "application/json",
      authorization: `Bearer ${managementToken(action, boundScope)}`,
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
    headers: { Host: host, ...init.headers },
  });
}

export function sha256(content: string | Uint8Array): string {
  return new Bun.CryptoHasher("sha256").update(content).digest("hex");
}

export async function errorCode(response: Response): Promise<string> {
  const body = (await response.json()) as { error?: { code?: string } };
  return body.error?.code ?? "";
}

// Mirrors the shard entry shape stamped by
// `_stattic_runtime_build_file_meta`/`_stattic_tier_remote_for` in
// runtime/engine/shared/storage.php + runtime/engine/admin/tier.php.
export type TierRemoteLocator = { bucket: string; key: string; enc: string };
export type TierCompressedEntry = {
  disk_path: string;
  size: number;
  sha256: string;
  local: boolean;
  tier_class: string;
  remote?: TierRemoteLocator;
};
export type ShardFileEntry = {
  disk_path: string;
  size: number;
  mime: string;
  sha256: string;
  local: boolean;
  tier_class: string;
  remote?: TierRemoteLocator;
  compressed?: Partial<Record<"br" | "gzip", TierCompressedEntry>>;
};
export type ShardFiles = Record<string, ShardFileEntry>;

export function shardFiles(runtime: Runtime, spaceId: string, versionId: string): ShardFiles {
  const root = path.join(
    runtime.storageRoot,
    "spaces",
    spaceId,
    "versions",
    versionId,
    "file-shards",
  );
  const files: ShardFiles = {};
  for (const name of readdirSync(root)) {
    if (!name.endsWith(".php")) continue;
    const file = path.join(root, name);
    if (!existsSync(file)) continue;
    const proc = Bun.spawnSync([
      "php",
      "-r",
      `echo json_encode(include ${JSON.stringify(file)}, JSON_UNESCAPED_SLASHES);`,
    ]);
    const shard = JSON.parse(proc.stdout.toString()) as { files: ShardFiles };
    Object.assign(files, shard.files);
  }
  return files;
}

export type DeploySpec = {
  spaceId: string;
  versionId: string;
  files: Record<string, string | Uint8Array>;
  metadata?: Record<string, unknown>;
  serving?: Record<string, unknown>;
  /** Finalize-rendered Pages documents stored outside the public file tree. */
  pageArtifacts?: Record<string, string>;
  zero?: Record<string, unknown>;
  zeroMode?: "active" | "activating";
  activate?: Record<string, unknown>;
};

export async function createDeclaredSession(
  rt: Runtime,
  spaceId: string,
  versionId: string,
  files: Record<string, string | Uint8Array>,
  metadata?: Record<string, unknown>,
): Promise<{ uploadId: string; token: string }> {
  const manifest = Object.entries(files).map(([filePath, content]) => ({
    path: filePath,
    size: Buffer.byteLength(content),
    sha256: sha256(content),
  }));
  const created = await apiJson<{ upload_id: string }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions`,
    "create_version",
    { space_id: spaceId },
    { version_id: versionId, files: manifest, ...(metadata ? { metadata } : {}) },
    201,
  );
  return {
    uploadId: created.upload_id,
    token: uploadToken(spaceId, created.upload_id, versionId, "declared"),
  };
}

export async function putFile(
  rt: Runtime,
  uploadId: string,
  token: string,
  filePath: string,
  content: string | Uint8Array,
): Promise<Response> {
  return fetch(
    `${rt.baseUrl}${runtimeUploadHttpPath(`${RUNTIME_UPLOAD_API_BASE}/${uploadId}/files/${filePath}`)}`,
    {
      method: "PUT",
      headers: { authorization: `Bearer ${token}` },
      body: content,
    },
  );
}

// Full declared deploy: create session, upload every file, finalize (+activate).
export async function deploy(rt: Runtime, spec: DeploySpec): Promise<void> {
  const { uploadId, token } = await createDeclaredSession(
    rt,
    spec.spaceId,
    spec.versionId,
    spec.files,
    spec.metadata,
  );
  for (const [filePath, content] of Object.entries(spec.files)) {
    // oxlint-disable-next-line no-await-in-loop -- uploads stay sequential so a failure names the exact file
    const response = await putFile(rt, uploadId, token, filePath, content);
    if (response.status !== 200) {
      // oxlint-disable-next-line no-await-in-loop -- error path reads the failing response body
      throw new Error(`upload ${filePath} -> ${response.status}: ${await response.text()}`);
    }
  }
  await apiJson(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spec.spaceId}/versions/${spec.versionId}/finalize`,
    "finalize_version",
    { space_id: spec.spaceId, version_id: spec.versionId },
    {
      upload_id: uploadId,
      ...(spec.zeroMode ? { zero_mode: spec.zeroMode } : {}),
      ...(spec.serving ? { serving: spec.serving } : {}),
      ...(spec.pageArtifacts ? { page_artifacts: spec.pageArtifacts } : {}),
      ...(spec.zero ? { zero: spec.zero } : {}),
      ...(spec.activate ? { activate: spec.activate } : {}),
    },
  );
}

// Negative-path finalize: create a declared session, upload every file
// (throwing on a non-200 upload — a precondition every reject-at-finalize test
// already assumes) and POST finalize with the caller's raw body, returning the
// Response WITHOUT asserting its status. The happy path lives in deploy();
// this exists for the tests that need to inspect the finalize rejection.
export async function finalizeRaw(
  rt: Runtime,
  spaceId: string,
  versionId: string,
  files: Record<string, string | Uint8Array>,
  finalizeBody: Record<string, unknown>,
): Promise<Response> {
  const { uploadId, token } = await createDeclaredSession(rt, spaceId, versionId, files);
  for (const [filePath, content] of Object.entries(files)) {
    // oxlint-disable-next-line no-await-in-loop -- uploads stay sequential so a failure names the exact file
    const response = await putFile(rt, uploadId, token, filePath, content);
    if (response.status !== 200) {
      // oxlint-disable-next-line no-await-in-loop -- error path reads the failing response body
      throw new Error(`upload ${filePath} -> ${response.status}: ${await response.text()}`);
    }
  }
  return api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${spaceId}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: spaceId, version_id: versionId },
    { upload_id: uploadId, ...finalizeBody },
  );
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
    `/__spacefast/api.php/spaces/${spaceId}/routes/${routeName}`,
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

// Minimal ustar archive for batch-upload tests.
export function tarArchive(files: Array<{ path: string; content: string; type?: string }>): Buffer {
  const blocks: Buffer[] = [];
  for (const file of files) {
    const content = Buffer.from(file.content);
    const header = Buffer.alloc(512);
    header.write(file.path, 0, "utf8");
    header.write("0000644\0", 100, "utf8");
    header.write("0000000\0", 108, "utf8");
    header.write("0000000\0", 116, "utf8");
    header.write(`${content.length.toString(8).padStart(11, "0")}\0`, 124, "utf8");
    header.write("00000000000\0", 136, "utf8");
    header.write("        ", 148, "utf8");
    header.write(file.type ?? "0", 156, "utf8");
    let checksum = 0;
    for (const byte of header) {
      checksum += byte;
    }
    header.write(`${checksum.toString(8).padStart(6, "0")}\0 `, 148, "utf8");
    blocks.push(header, content);
    const padding = (512 - (content.length % 512)) % 512;
    if (padding > 0) {
      blocks.push(Buffer.alloc(padding));
    }
  }
  blocks.push(Buffer.alloc(1024));
  return Buffer.concat(blocks);
}

// Builds crafted ZIP archives in-process. Entries with `zeros` are filled with
// that many zero bytes (highly compressible).
export function buildZip(
  targetPath: string,
  entries: Array<{ name: string; content?: string; zeros?: number }>,
): void {
  const archiveEntries: Record<string, Uint8Array> = {};
  for (const entry of entries) {
    archiveEntries[entry.name] =
      entry.zeros === undefined
        ? strToU8(entry.content ?? "")
        : new Uint8Array(Math.max(0, entry.zeros));
  }
  writeFileSync(targetPath, zipSync(archiveEntries, { level: 6 }));
}
