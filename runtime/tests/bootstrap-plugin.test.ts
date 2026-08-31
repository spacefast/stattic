import { expect, test } from "bun:test";
import { mkdtemp, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";

const pluginPath = new URL("../bootstrap-plugin/spacefast-bootstrap.php", import.meta.url).pathname;
const atomicPrependPath = new URL("./atomic-prepend.php", import.meta.url).pathname;

async function readTrustAnchor(input: { prelude: string; cwd?: string }): Promise<string> {
  const php = `
    define('WP_CLI', true);
    ${input.prelude}
    require $argv[1];
    require $argv[2];
    echo spacefast_bootstrap_jwks_b64();
  `;
  const process = Bun.spawn(["php", "-r", php, atomicPrependPath, pluginPath], {
    cwd: input.cwd,
    stdout: "pipe",
    stderr: "pipe",
  });
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
    process.exited,
  ]);
  expect({ exitCode, stderr }).toEqual({ exitCode: 0, stderr: "" });
  return stdout;
}

test("a fresh box reads its trust anchor through Atomic_Persistent_Data", async () => {
  // The provider never define()s persistent data or exports it as env; the
  // class is the only exposure a pre-engine box has (live-verified 2026-08-31).
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-bootstrap-"));
  await writeFile(
    path.join(root, ".atomic-persistent-data.json"),
    JSON.stringify({ SPACEFAST_RUNTIME_JWKS_B64: "persistent-data-jwks" }),
  );
  expect(await readTrustAnchor({ prelude: "", cwd: root })).toBe("persistent-data-jwks");
});

test("an installed engine's constant shadows persistent data", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-bootstrap-"));
  await writeFile(
    path.join(root, ".atomic-persistent-data.json"),
    JSON.stringify({ SPACEFAST_RUNTIME_JWKS_B64: "persistent-data-jwks" }),
  );
  expect(
    await readTrustAnchor({
      prelude: "define('SPACEFAST_RUNTIME_JWKS_B64', 'engine-shim-jwks');",
      cwd: root,
    }),
  ).toBe("engine-shim-jwks");
});
