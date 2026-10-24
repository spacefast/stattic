import { expect, test } from "bun:test";
import { mkdtempSync, mkdirSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";

test("disk measurement resumes a bounded walk and deduplicates hardlinks across ticks", async () => {
  const root = mkdtempSync(path.join(tmpdir(), "sf-disk-report-"));
  const storage = path.join(root, ".stattic", "storage");
  mkdirSync(storage, { recursive: true });
  try {
    const process = Bun.spawn(
      [Bun.env.PHP_BINARY ?? "php", path.join(import.meta.dir, "disk-report.php"), storage],
      { stdout: "pipe", stderr: "pipe" },
    );
    const [exit, stdout, stderr] = await Promise.all([
      process.exited,
      new Response(process.stdout).text(),
      new Response(process.stderr).text(),
    ]);
    expect(stderr).toContain("json_decode_failed");
    expect(stderr.trim().split("\n")).toHaveLength(1);
    expect(exit).toBe(0);
    const result: {
      passes: Array<{ bytes: number; inodes: number; complete: boolean }>;
      checkpointExists: boolean;
      recovered: Record<string, { bytes: number; inodes: number; complete: boolean }>;
    } = JSON.parse(stdout);
    expect(result.passes[0]?.complete).toBe(false);
    expect(result.passes.at(-1)).toEqual({ bytes: 17, inodes: 4, complete: true });
    expect(result.checkpointExists).toBe(false);
    expect(Object.keys(result.recovered)).toEqual([
      "corrupt_json",
      "missing_stack",
      "wrong_root",
      "bad_frame",
      "bad_seen",
      "bad_bytes",
      "bad_inodes",
      "unsafe_path",
    ]);
    for (const usage of Object.values(result.recovered)) {
      expect(usage).toEqual({ bytes: 17, inodes: 4, complete: true });
    }
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
