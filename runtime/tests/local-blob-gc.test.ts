import { afterAll, expect, test } from "bun:test";
import { spawn } from "node:child_process";
import { createHash } from "node:crypto";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  rmSync,
  utimesSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

const PHP_BINARY = process.env.PHP_BINARY ?? "php";
const RUNTIME_ROOT = path.resolve(import.meta.dir, "..");
const CONTEXT_PATH = path.join(RUNTIME_ROOT, "engine", "shared", "context.php");
const STORAGE_PATH = path.join(RUNTIME_ROOT, "engine", "shared", "storage.php");
const TIER_PATH = path.join(RUNTIME_ROOT, "engine", "admin", "tier.php");
const TRANSFER_PATH = path.join(RUNTIME_ROOT, "engine", "admin", "transfer.php");
const suiteRoot = mkdtempSync(path.join(os.tmpdir(), "spacefast-local-blob-gc-"));

type FailureMode = "glob_failure" | "mixed_stat_failure" | "stat_failure" | "unlink_failure";

type CoreOptions = {
  now: number;
  grace: number;
  interval: number;
  completedAt?: number;
  mode?: FailureMode | "concurrent_disappearance";
};

afterAll(() => rmSync(suiteRoot, { recursive: true, force: true }));

function privateRoot(): string {
  const root = path.join(mkdtempSync(path.join(suiteRoot, "case-")), ".stattic", "storage");
  mkdirSync(root, { recursive: true });
  // macOS exposes os.tmpdir() through /var while PHP resolves it through
  // /private/var. Exercise the runtime with one canonical path identity.
  return realpathSync(root);
}

function markerPath(root: string): string {
  return path.join(root, "runtime", "blob-gc.json");
}

function createBlob(root: string, label: string, mtime: number, spaceId = "spc_gc"): string {
  const contents = `blob:${label}`;
  const sha = createHash("sha256").update(contents).digest("hex");
  const blobPath = path.join(root, "spaces", spaceId, "blobs", sha.slice(0, 2), sha);
  mkdirSync(path.dirname(blobPath), { recursive: true });
  writeFileSync(blobPath, contents);
  utimesSync(blobPath, mtime, mtime);
  return blobPath;
}

function writeMarker(root: string, generatedAt: string): void {
  const marker = markerPath(root);
  mkdirSync(path.dirname(marker), { recursive: true });
  writeFileSync(marker, JSON.stringify({ generatedAt }));
}

function timestamp(epochSeconds: number): string {
  return new Date(epochSeconds * 1000).toISOString().replace(".000Z", "+00:00");
}

function phpResult(command: string[], env: Record<string, string> = {}) {
  const result = Bun.spawnSync({
    cmd: command,
    env: { ...process.env, ...env },
    stdout: "pipe",
    stderr: "pipe",
  });
  if (result.exitCode !== 0) {
    throw new Error(
      `PHP exited ${result.exitCode}: ${result.stderr.toString()}\n${result.stdout.toString()}`,
    );
  }
  const stdout = result.stdout.toString();
  if (stdout !== "") {
    throw new Error(`PHP unexpectedly wrote to stdout: ${stdout}`);
  }
}

function phpOutput(command: string[], env: Record<string, string> = {}): string {
  const result = Bun.spawnSync({
    cmd: command,
    env: { ...process.env, ...env },
    stdout: "pipe",
    stderr: "pipe",
  });
  if (result.exitCode !== 0) {
    throw new Error(
      `PHP exited ${result.exitCode}: ${result.stderr.toString()}\n${result.stdout.toString()}`,
    );
  }
  return result.stdout.toString();
}

function spawnPhpGate(script: string, args: string[], expectedLine: string, label: string) {
  const child = spawn(PHP_BINARY, ["-d", "display_errors=stderr", "-r", script, ...args], {
    stdio: ["pipe", "pipe", "pipe"],
  });
  let readySettled = false;
  let stdout = "";
  let stderr = "";
  child.stderr.on("data", (chunk: Buffer) => {
    stderr += chunk.toString();
  });
  const ready = new Promise<void>((resolve, reject) => {
    child.once("error", (error) => {
      if (!readySettled) {
        readySettled = true;
        reject(error);
      }
    });
    child.stdout.on("data", (chunk: Buffer) => {
      if (readySettled) {
        return;
      }
      stdout += chunk.toString();
      const newline = stdout.indexOf("\n");
      if (newline < 0) {
        return;
      }
      readySettled = true;
      const line = stdout.slice(0, newline).trim();
      if (line === expectedLine) {
        resolve();
      } else {
        reject(new Error(`unexpected ${label} output: ${stdout}`));
      }
    });
    child.once("exit", (code) => {
      if (!readySettled) {
        readySettled = true;
        reject(new Error(`${label} exited ${code} before becoming ready: ${stderr}`));
      }
    });
  });
  const exited = new Promise<void>((resolve, reject) => {
    child.once("error", reject);
    child.once("exit", (code) => {
      if (code === 0) {
        resolve();
      } else {
        reject(new Error(`${label} exited ${code}: ${stderr}`));
      }
    });
  });
  return {
    child,
    ready,
    async release(): Promise<void> {
      if (child.exitCode === null && !child.killed) {
        child.stdin.end("release\n");
      }
      await exited;
    },
  };
}

function runCore(root: string, options: CoreOptions): void {
  const script = String.raw`
require_once $argv[1];
require_once $argv[2];
require_once $argv[3];
$input = json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR);
$mode = (string) ($input['mode'] ?? 'success');
$glob = $mode === 'glob_failure' ? static fn (string $_pattern) => false : null;
$stat = null;
if ($mode === 'stat_failure') {
    $stat = static fn (string $_path) => false;
} elseif ($mode === 'mixed_stat_failure') {
    $stat = static function (string $path) {
        static $calls = 0;
        $calls += 1;
        return $calls === 2 ? false : @stat($path);
    };
}
$unlink = null;
if ($mode === 'unlink_failure') {
    $unlink = static fn (string $_path): bool => false;
} elseif ($mode === 'concurrent_disappearance') {
    $unlink = static function (string $path): bool {
        @unlink($path);
        return false;
    };
}
$clock = array_key_exists('completedAt', $input)
    ? static fn (): int => (int) $input['completedAt']
    : static fn (): int => (int) $input['now'];
_stattic_tier_local_blob_gc_run(
    $argv[4],
    (int) $input['now'],
    (int) $input['grace'],
    (int) $input['interval'],
    $glob,
    $stat,
    $unlink,
    $clock,
);
`;
  phpResult([
    PHP_BINARY,
    "-d",
    "display_errors=stderr",
    "-r",
    script,
    CONTEXT_PATH,
    STORAGE_PATH,
    TIER_PATH,
    root,
    JSON.stringify(options),
  ]);
}

function runPublic(root: string): void {
  const script = String.raw`
require_once $argv[1];
require_once $argv[2];
require_once $argv[3];
_stattic_runtime_job_housekeeping_local_blob_gc($argv[4]);
`;
  phpResult(
    [
      PHP_BINARY,
      "-d",
      "display_errors=stderr",
      "-r",
      script,
      CONTEXT_PATH,
      STORAGE_PATH,
      TIER_PATH,
      root,
    ],
    {
      SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS: "0",
      SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS: "0",
    },
  );
}

function journalEvents(root: string): Array<Record<string, unknown>> {
  const journal = path.join(root, "runtime", "journal.jsonl");
  if (!existsSync(journal)) {
    return [];
  }
  return readFileSync(journal, "utf8")
    .trim()
    .split("\n")
    .filter(Boolean)
    .map((line) => JSON.parse(line) as Record<string, unknown>);
}

test("a fresh marker skips the all-blob scan without rewriting the marker", () => {
  const root = privateRoot();
  const now = 2_000_000_000;
  const blob = createBlob(root, "fresh-marker", now - 10_000);
  const generatedAt = timestamp(now - 1);
  writeMarker(root, generatedAt);

  runCore(root, { now, grace: 60, interval: 30 });

  expect(existsSync(blob)).toBe(true);
  expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({ generatedAt });
});

test("scan cadence has a one-hour default, accepts zero, and rejects malformed overrides", () => {
  const script = String.raw`
require_once $argv[1];
require_once $argv[2];
require_once $argv[3];
putenv('SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS');
$default = _stattic_tier_gc_scan_interval_seconds();
putenv('SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS=0');
$zero = _stattic_tier_gc_scan_interval_seconds();
putenv('SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS=invalid');
$invalid = _stattic_tier_gc_scan_interval_seconds();
echo json_encode([$default, $zero, $invalid]);
`;
  const output = phpOutput([PHP_BINARY, "-r", script, CONTEXT_PATH, STORAGE_PATH, TIER_PATH]);

  expect(JSON.parse(output)).toEqual([3600, 0, 3600]);
});

test("a stale marker runs a complete scan and advances only after successful unlink", () => {
  const root = privateRoot();
  const now = 2_000_000_100;
  const blob = createBlob(root, "stale-marker", now - 10_000);
  writeMarker(root, timestamp(now - 30));

  runCore(root, { now, grace: 60, interval: 30 });

  expect(existsSync(blob)).toBe(false);
  expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({
    generatedAt: timestamp(now),
  });
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(1);
});

test("future and malformed markers fail open and are replaced by a completed scan", () => {
  const now = 2_000_000_200;
  for (const [label, marker] of [
    ["future", JSON.stringify({ generatedAt: timestamp(now + 1) })],
    ["malformed", '{"generatedAt":'],
    ["control-byte", JSON.stringify({ generatedAt: `2033-05-18T03:36:40+00:00\0` })],
    ["parseable-but-noncanonical", JSON.stringify({ generatedAt: "2033-05-18 03:36:39 UTC" })],
    ["normalized-invalid-date", JSON.stringify({ generatedAt: "2033-02-30T00:00:00+00:00" })],
  ] as const) {
    const root = privateRoot();
    const blob = createBlob(root, label, now - 10_000);
    mkdirSync(path.dirname(markerPath(root)), { recursive: true });
    writeFileSync(markerPath(root), marker);

    runCore(root, { now, grace: 60, interval: 30 });

    expect(existsSync(blob)).toBe(false);
    expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({
      generatedAt: timestamp(now),
    });
  }
});

test("scan cadence starts when the scan completes", () => {
  const root = privateRoot();
  const startedAt = 2_000_000_250;
  const completedAt = startedAt + 10;

  runCore(root, { now: startedAt, completedAt, grace: 60, interval: 5 });
  expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({
    generatedAt: timestamp(completedAt),
  });

  const blob = createBlob(root, "completion-cadence", startedAt - 10_000);
  runCore(root, { now: completedAt + 1, grace: 60, interval: 5 });

  expect(existsSync(blob)).toBe(true);
  expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({
    generatedAt: timestamp(completedAt),
  });
});

for (const mode of ["glob_failure", "stat_failure", "unlink_failure"] as const) {
  test(`${mode.replace("_", " ")} keeps the marker stale and the next scan retries`, () => {
    const root = privateRoot();
    const now = 2_000_000_300;
    const blob = createBlob(root, mode, now - 10_000);

    runCore(root, { now, grace: 60, interval: 30, mode });

    expect(existsSync(markerPath(root))).toBe(false);
    expect(existsSync(blob)).toBe(true);
    expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(0);

    runCore(root, { now, grace: 60, interval: 30 });

    expect(existsSync(blob)).toBe(false);
    expect(existsSync(markerPath(root))).toBe(true);
    expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(1);
  });
}

test("an unreadable blob subtree cannot advance the marker after a partial traversal", () => {
  const root = privateRoot();
  const now = 2_000_000_325;
  createBlob(root, "readable-subtree", now - 10_000);
  const blockedBlob = createBlob(root, "blocked-subtree", now - 10_000, "spc_blocked");
  const blockedRoot = path.join(root, "spaces", "spc_blocked", "blobs");

  chmodSync(blockedRoot, 0o000);
  try {
    runCore(root, { now, grace: 60, interval: 30 });
  } finally {
    chmodSync(blockedRoot, 0o755);
  }

  expect(existsSync(blockedBlob)).toBe(true);
  expect(existsSync(markerPath(root))).toBe(false);
});

test("an empty successful glob advances the marker", () => {
  const root = privateRoot();
  const now = 2_000_000_350;

  runCore(root, { now, grace: 60, interval: 30 });

  expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({
    generatedAt: timestamp(now),
  });
  expect(journalEvents(root)).toEqual([]);
});

test("a partial scan keeps its marker stale and retries without duplicate success journals", () => {
  const root = privateRoot();
  const now = 2_000_000_375;
  const blobs = [
    createBlob(root, "mixed-first", now - 10_000),
    createBlob(root, "mixed-second", now - 10_000),
  ];

  runCore(root, { now, grace: 60, interval: 30, mode: "mixed_stat_failure" });

  expect(blobs.filter(existsSync)).toHaveLength(1);
  expect(existsSync(markerPath(root))).toBe(false);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(1);

  runCore(root, { now, grace: 60, interval: 30 });

  expect(blobs.filter(existsSync)).toHaveLength(0);
  expect(existsSync(markerPath(root))).toBe(true);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(2);
});

test("a concurrent disappearance is not journaled and completes on the next scan", () => {
  const root = privateRoot();
  const now = 2_000_000_400;
  const blob = createBlob(root, "concurrent-disappearance", now - 10_000);

  runCore(root, { now, grace: 60, interval: 30, mode: "concurrent_disappearance" });

  expect(existsSync(blob)).toBe(false);
  expect(existsSync(markerPath(root))).toBe(false);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(0);

  runCore(root, { now, grace: 60, interval: 30 });
  expect(existsSync(markerPath(root))).toBe(true);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(0);
});

test("age grace and scan cadence are independent and bound reclamation by their sum", () => {
  const root = privateRoot();
  const firstScan = 2_000_000_500;
  const grace = 60;
  const interval = 10;
  const blob = createBlob(root, "two-window-bound", firstScan - grace + 1);

  runCore(root, { now: firstScan, grace, interval });
  expect(existsSync(blob)).toBe(true);
  expect(JSON.parse(readFileSync(markerPath(root), "utf8")).generatedAt).toBe(timestamp(firstScan));

  runCore(root, { now: firstScan + interval - 1, grace, interval });
  expect(existsSync(blob)).toBe(true);

  runCore(root, { now: firstScan + interval, grace, interval });
  expect(existsSync(blob)).toBe(false);
  expect(firstScan + interval - (firstScan - grace + 1)).toBeLessThanOrEqual(grace + interval);
});

test("grace zero preserves the every-call test pin even with a nonzero cadence default", () => {
  const root = privateRoot();
  const now = 2_000_000_600;
  const first = createBlob(root, "grace-zero-first", now);

  runCore(root, { now, grace: 0, interval: 3600 });
  expect(existsSync(first)).toBe(false);

  const second = createBlob(root, "grace-zero-second", now);
  runCore(root, { now, grace: 0, interval: 3600 });

  expect(existsSync(second)).toBe(false);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(2);
});

test("a copy-fallback transfer lease restores CAS after crash-window GC", () => {
  const root = privateRoot();
  const contents = "copy-fallback-transfer-bytes";
  const script = String.raw`
require_once $argv[1];
require_once $argv[2];
require_once $argv[3];
require_once $argv[4];
$root = $argv[5];
$contents = $argv[6];
$sha = hash('sha256', $contents);
$cas = _stattic_runtime_blob_path($root, 'spc_gc', $sha);
_stattic_runtime_mkdir(dirname($cas));
file_put_contents($cas, $contents);
touch($cas, time() - 10000);
$lease = _stattic_transfer_blob_lease_path($root, 'spc_gc', $sha);
_stattic_runtime_mkdir(dirname($lease));
copy($cas, $lease);
$stagedVersion = _stattic_transfer_staging_root($root, 'spc_gc') . '/versions/ver_gc';
$staged = $stagedVersion . '/files/index.html';
_stattic_runtime_mkdir(dirname($staged));
copy($cas, $staged);

_stattic_tier_local_blob_gc_run($root, time(), 0, 0);
$afterGc = [
    'cas' => is_file($cas),
    'lease' => is_file($lease),
    'staged' => is_file($staged),
];
// A retry starts stage from scratch. Its durable recovery source must be the
// transaction lease, not the staged tree that stage itself removes.
_stattic_runtime_rm_recursive($stagedVersion);
$recovered = _stattic_transfer_lease_blob($root, 'spc_gc', $sha);
echo json_encode([
    'after_gc' => $afterGc,
    'recovered' => $recovered && is_file($cas) && hash_file('sha256', $cas) === $sha,
    'lease_after_retry' => is_file($lease),
    'staged_after_retry' => is_file($staged),
]);
`;
  const output = phpOutput([
    PHP_BINARY,
    "-d",
    "display_errors=stderr",
    "-r",
    script,
    CONTEXT_PATH,
    STORAGE_PATH,
    TRANSFER_PATH,
    TIER_PATH,
    root,
    contents,
  ]);

  expect(JSON.parse(output)).toEqual({
    after_gc: { cas: false, lease: true, staged: true },
    recovered: true,
    lease_after_retry: true,
    staged_after_retry: false,
  });
});

test("a partial interrupted marker write cannot suppress retry", () => {
  const root = privateRoot();
  const now = 2_000_000_700;
  const blob = createBlob(root, "interrupted-marker", now - 10_000);
  mkdirSync(path.dirname(markerPath(root)), { recursive: true });
  writeFileSync(markerPath(root), '{"generatedAt":');
  writeFileSync(`${markerPath(root)}.tmp`, '{"generatedAt":"interrupted');

  runCore(root, { now, grace: 60, interval: 30 });

  expect(existsSync(blob)).toBe(false);
  expect(JSON.parse(readFileSync(markerPath(root), "utf8"))).toEqual({
    generatedAt: timestamp(now),
  });
  expect(existsSync(`${markerPath(root)}.tmp`)).toBe(false);
});

test("a concurrent scan lock cannot advance the marker and a later call completes", async () => {
  const root = privateRoot();
  const blob = createBlob(root, "concurrent-lock", Math.floor(Date.now() / 1000) - 10_000);
  const lockPath = path.join(root, "runtime", "blob-gc.lock");
  mkdirSync(path.dirname(lockPath), { recursive: true });
  const holderScript = String.raw`
$handle = fopen($argv[1], 'c');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "lock failed\n");
    exit(1);
}
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
fgets(STDIN);
flock($handle, LOCK_UN);
fclose($handle);
`;
  const holder = spawnPhpGate(holderScript, [lockPath], "locked", "lock holder");
  try {
    await holder.ready;
    runPublic(root);
    expect(existsSync(blob)).toBe(true);
    expect(existsSync(markerPath(root))).toBe(false);
  } finally {
    await holder.release();
  }

  runPublic(root);
  expect(existsSync(blob)).toBe(false);
  expect(existsSync(markerPath(root))).toBe(true);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(1);
});

test("GC cannot unlink a reused CAS blob while a management writer is linking it", async () => {
  const root = privateRoot();
  const contents = "blob:writer-reuse";
  const blob = createBlob(root, "writer-reuse", Math.floor(Date.now() / 1000) - 10_000);
  const sha = path.basename(blob);
  const versionFile = path.join(
    root,
    "spaces",
    "spc_gc",
    "versions",
    "ver_writer",
    "files",
    "index.html",
  );
  // The writer holds the blob's OWNING space lock (spaces/{spaceId}/write.lock)
  // — what the space-scoped finalize_version dispatch actually holds across
  // blob_has -> blob_put -> blob_link — and the GC must key its per-blob
  // exclusion on that same lock.
  const writerScript = String.raw`
require_once $argv[1];
require_once $argv[2];
$root = $argv[3];
$sha = $argv[4];
$target = $argv[5];
$tmp = $root . '/runtime/blob-staging/writer-' . $sha;
_stattic_runtime_mkdir(dirname($tmp));
file_put_contents($tmp, $argv[6]);
$handle = fopen($root . '/spaces/spc_gc/write.lock', 'c');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "writer lock failed\n");
    exit(1);
}
_stattic_runtime_blob_put($root, 'spc_gc', $tmp, $sha);
fwrite(STDOUT, "put\n");
fflush(STDOUT);
fgets(STDIN);
_stattic_runtime_blob_link($root, 'spc_gc', $sha, $target);
flock($handle, LOCK_UN);
fclose($handle);
if (!is_file($target)) {
    fwrite(STDERR, "writer link did not land\n");
    exit(2);
}
`;
  const writer = spawnPhpGate(
    writerScript,
    [CONTEXT_PATH, STORAGE_PATH, root, sha, versionFile, contents],
    "put",
    "writer",
  );
  try {
    await writer.ready;
    runPublic(root);
    expect(existsSync(blob)).toBe(true);
    expect(existsSync(markerPath(root))).toBe(false);
  } finally {
    await writer.release();
  }

  expect(existsSync(versionFile)).toBe(true);
  runPublic(root);
  expect(existsSync(blob)).toBe(true);
  expect(existsSync(markerPath(root))).toBe(true);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(0);
});

test("a held space write lock shields only that space's blobs from GC", async () => {
  const root = privateRoot();
  const now = Math.floor(Date.now() / 1000);
  const lockedBlob = createBlob(root, "locked-space", now - 10_000, "spc_locked");
  const openBlob = createBlob(root, "open-space", now - 10_000, "spc_open");
  // An in-flight finalize on spc_locked: an external process holding that
  // space's write lock, exactly what the space-scoped management dispatch
  // holds for the whole finalize.
  const lockPath = path.join(root, "spaces", "spc_locked", "write.lock");
  const holderScript = String.raw`
$handle = fopen($argv[1], 'c');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "lock failed\n");
    exit(1);
}
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
fgets(STDIN);
flock($handle, LOCK_UN);
fclose($handle);
`;
  const holder = spawnPhpGate(holderScript, [lockPath], "locked", "space lock holder");
  try {
    await holder.ready;
    runPublic(root);
    // Non-blocking skip for the locked space, normal collection elsewhere.
    expect(existsSync(lockedBlob)).toBe(true);
    expect(existsSync(openBlob)).toBe(false);
    // The skipped blob keeps the scan partial so the marker cannot advance.
    expect(existsSync(markerPath(root))).toBe(false);
  } finally {
    await holder.release();
  }

  runPublic(root);
  expect(existsSync(lockedBlob)).toBe(false);
  expect(existsSync(markerPath(root))).toBe(true);
  expect(journalEvents(root).filter((event) => event.event === "local_blob_gc")).toHaveLength(2);
});

test("a vanished copy source fails the request loudly instead of landing a silent gap", () => {
  const root = privateRoot();
  // The corruption window the lock contract protects against: a blob-store
  // source disappearing after blob_link's is_file() check drops it to the
  // copy() fallback. That copy MUST fail the request — never a 200 finalize
  // with the file silently missing from the version tree.
  const target = path.join(root, "spaces", "spc_gc", "versions", "ver_copy", "files", "index.html");
  const script = String.raw`
define('STATTIC_RUNTIME_DISPATCH_CLI', true);
require_once $argv[1];
require_once $argv[2];
$root = $argv[3];
_stattic_runtime_mkdir($root . '/runtime/blob-staging');
_stattic_runtime_copy_private_file($root . '/runtime/blob-staging/vanished-source', $argv[4]);
fwrite(STDERR, "copy failure did not surface\n");
exit(9);
`;
  const output = phpOutput([
    PHP_BINARY,
    "-d",
    "display_errors=stderr",
    "-r",
    script,
    CONTEXT_PATH,
    STORAGE_PATH,
    root,
    target,
  ]);

  const envelope = JSON.parse(output) as { status: number; body: { error: { code: string } } };
  expect(envelope.status).toBe(500);
  expect(envelope.body.error.code).toBe("runtime_copy_failed");
  expect(existsSync(target)).toBe(false);
});
