// Behavioral coverage for the shared subprocess runner in
// engine/shared/artifacts.php (_stattic_runtime_run_subprocess), which both
// the serve-time Zero runner (runtime/zero.php) and the finalize/compile lane
// (admin/generate.php) go through.
//
// The regression these guard: the runner used to blocking-fwrite() the whole
// stdin payload and then drain stdout to EOF before ever reading stderr. Both
// halves deadlock against a ~64KB OS pipe buffer — a child that fills stderr
// blocks before it can close stdout, and a stdin payload larger than the
// buffer stalls the parent before the child starts draining it. Every case
// below hangs forever against that implementation, so the timeouts are the
// failure signal, not decoration.
//
// The runner is pure process plumbing with no HTTP surface, so there is
// nothing for the manifest-driven startRuntime() harness to hit; these drive
// the real PHP function through subprocess-cli.php against its real repo
// path, the same way s3.test.ts drives s3-cli.php.
import { describe, expect, test } from "bun:test";
import { createHash } from "node:crypto";
import path from "node:path";

const SUBPROCESS_CLI_PATH = path.resolve(import.meta.dir, "subprocess-cli.php");

// Comfortably past the ~64KB pipe buffer on both Linux and macOS.
const OVER_PIPE_BUFFER = 300_000;
const LARGE_STDIN = 1_000_000;

type SubprocessResult = {
  spawned: boolean;
  exitCode: number;
  stdoutLength: number;
  stdoutSha256: string;
  stderrLength: number;
  stderrSha256: string;
  stdinSha256: string;
};

type SubprocessRequest = {
  child?: string;
  stdin_bytes?: number;
  missing_binary?: boolean;
  env?: Record<string, string>;
};

async function runSubprocessCli(request: SubprocessRequest): Promise<SubprocessResult> {
  const proc = Bun.spawn(["php", SUBPROCESS_CLI_PATH, JSON.stringify(request)], {
    stdout: "pipe",
    stderr: "pipe",
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(proc.stdout).text(),
    new Response(proc.stderr).text(),
    proc.exited,
  ]);
  if (exitCode !== 0) {
    throw new Error(`subprocess-cli.php exited ${exitCode}: ${stderr}`);
  }
  return JSON.parse(stdout.trim()) as SubprocessResult;
}

function sha256(value: string): string {
  return createHash("sha256").update(value).digest("hex");
}

// Mirrors subprocess_cli_payload() in subprocess-cli.php so the test can
// predict the digest of what it asked the driver to feed the child.
function expectedStdinSha(bytes: number): string {
  const unit = Buffer.from(Array.from({ length: 256 }, (_, i) => i));
  const repeated = Buffer.alloc(bytes);
  for (let offset = 0; offset < bytes; offset += 256) {
    unit.copy(repeated, offset, 0, Math.min(256, bytes - offset));
  }
  return createHash("sha256").update(repeated).digest("hex");
}

describe("shared/artifacts.php subprocess runner", () => {
  test("captures a stderr flood larger than the pipe buffer without stalling stdout", async () => {
    const result = await runSubprocessCli({
      child: `fwrite(STDERR, str_repeat("e", ${OVER_PIPE_BUFFER})); fwrite(STDOUT, "done");`,
    });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(0);
    expect(result.stderrLength).toBe(OVER_PIPE_BUFFER);
    expect(result.stdoutSha256).toBe(sha256("done"));
  }, 20_000);

  test("captures simultaneous stdout and stderr floods in full", async () => {
    const chunk = 20_000;
    const rounds = 15;
    const child = `for ($i = 0; $i < ${rounds}; $i++) { fwrite(STDOUT, str_repeat("o", ${chunk})); fwrite(STDERR, str_repeat("e", ${chunk})); }`;
    const result = await runSubprocessCli({ child });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(0);
    expect(result.stdoutLength).toBe(chunk * rounds);
    expect(result.stderrLength).toBe(chunk * rounds);
    expect(result.stdoutSha256).toBe(sha256("o".repeat(chunk * rounds)));
    expect(result.stderrSha256).toBe(sha256("e".repeat(chunk * rounds)));
  }, 20_000);

  test("round-trips a stdin payload far larger than the pipe buffer", async () => {
    const result = await runSubprocessCli({
      child: "stream_copy_to_stream(STDIN, STDOUT);",
      stdin_bytes: LARGE_STDIN,
    });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(0);
    expect(result.stdinSha256).toBe(expectedStdinSha(LARGE_STDIN));
    expect(result.stdoutLength).toBe(LARGE_STDIN);
    // Byte-exact echo: proves no short-write dropped part of the payload.
    expect(result.stdoutSha256).toBe(result.stdinSha256);
  }, 20_000);

  test("pumps a large stdin payload while the child also floods stderr", async () => {
    const result = await runSubprocessCli({
      child: `fwrite(STDERR, str_repeat("e", ${OVER_PIPE_BUFFER})); stream_copy_to_stream(STDIN, STDOUT);`,
      stdin_bytes: LARGE_STDIN,
    });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(0);
    expect(result.stderrLength).toBe(OVER_PIPE_BUFFER);
    expect(result.stdoutLength).toBe(LARGE_STDIN);
    expect(result.stdoutSha256).toBe(result.stdinSha256);
  }, 20_000);

  test("gives up on stdin when the child exits without reading it", async () => {
    // The rejection path: the runner refuses a payload and exits before
    // draining stdin. The parent must notice the broken pipe and finish with
    // the child's output rather than blocking on the unwritten remainder.
    const result = await runSubprocessCli({
      child: 'fwrite(STDOUT, "early"); exit(7);',
      stdin_bytes: LARGE_STDIN,
    });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(7);
    expect(result.stdoutSha256).toBe(sha256("early"));
  }, 20_000);

  test("propagates a non-zero child exit code", async () => {
    const result = await runSubprocessCli({
      child: 'fwrite(STDERR, "boom"); exit(3);',
    });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(3);
    expect(result.stderrSha256).toBe(sha256("boom"));
  });

  test("closes stdin immediately when there is no payload", async () => {
    const result = await runSubprocessCli({
      child: 'echo stream_get_contents(STDIN) === "" ? "eof" : "leaked";',
    });

    expect(result.spawned).toBe(true);
    expect(result.exitCode).toBe(0);
    expect(result.stdoutSha256).toBe(sha256("eof"));
  });

  test("reports a spawn failure instead of throwing", async () => {
    const result = await runSubprocessCli({ missing_binary: true });

    expect(result.spawned).toBe(false);
    expect(result.exitCode).toBe(-1);
    expect(result.stdoutLength).toBe(0);
    expect(result.stderrLength).toBe(0);
  });

  test("passes the caller-supplied environment through to the child", async () => {
    const result = await runSubprocessCli({
      child: 'echo (string) getenv("SPACEFAST_SUBPROCESS_PROBE");',
      env: { SPACEFAST_SUBPROCESS_PROBE: "probe-value" },
    });

    expect(result.spawned).toBe(true);
    expect(result.stdoutSha256).toBe(sha256("probe-value"));
  });
});
