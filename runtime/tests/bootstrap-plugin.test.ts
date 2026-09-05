import { expect, test } from "bun:test";
import { access, mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
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

async function restoreConfig(root: string, config: Record<string, string>, providerContext = true) {
  const entrypoint = new URL("../bootstrap-plugin/restore-config.php", import.meta.url).pathname;
  const process = Bun.spawn(
    ["php", "-d", `auto_prepend_file=${providerContext ? atomicPrependPath : ""}`, entrypoint],
    {
      cwd: root,
      stdin: new TextEncoder().encode(JSON.stringify(config)),
      stdout: "pipe",
      stderr: "pipe",
    },
  );
  const [stdout, stderr, exitCode] = await Promise.all([
    new Response(process.stdout).text(),
    new Response(process.stderr).text(),
    process.exited,
  ]);
  const result = JSON.parse(stdout);
  expect({ exitCode, stderr }).toEqual({ exitCode: result.error ? 1 : 0, stderr: "" });
  return result;
}

test("restoring missing runtime config preserves tenant files and existing matching config", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-config-repair-"));
  const content = path.join(root, ".stattic/storage/spaces/spc_owned/blobs/content");
  await mkdir(path.dirname(content), { recursive: true });
  await writeFile(content, "tenant-content");
  const config = {
    SPACEFAST_RUNTIME_INSTANCE_ID: "box_owned",
    SPACEFAST_API_BASE_URL: "https://api.example.test",
  };
  expect(await restoreConfig(root, config)).toEqual({ status: "restored" });
  const configPath = path.join(root, ".stattic/storage/config.php");
  const original = await readFile(configPath, "utf8");
  expect(
    await restoreConfig(root, { ...config, SPACEFAST_API_BASE_URL: "https://other.test" }),
  ).toEqual({ status: "unchanged" });
  expect(await readFile(configPath, "utf8")).toBe(original);
  expect(await readFile(content, "utf8")).toBe("tenant-content");
  expect(await restoreConfig(root, { SPACEFAST_RUNTIME_INSTANCE_ID: "box_other" })).toEqual({
    error: "bootstrap_config_runtime_id_conflict",
  });
  expect(await readFile(configPath, "utf8")).toBe(original);
  await rm(root, { recursive: true, force: true });
});

test("missing config does not permit replacing a provider-persisted runtime identity", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-config-conflict-"));
  await writeFile(
    path.join(root, ".atomic-persistent-data.json"),
    JSON.stringify({ SPACEFAST_RUNTIME_INSTANCE_ID: "box_other" }),
  );
  expect(await restoreConfig(root, { SPACEFAST_RUNTIME_INSTANCE_ID: "box_owned" })).toEqual({
    error: "bootstrap_config_runtime_id_conflict",
  });
  await expect(access(path.join(root, ".stattic/storage/config.php"))).rejects.toMatchObject({
    code: "ENOENT",
  });
  await rm(root, { recursive: true, force: true });
});

test("config restoration refuses to run without the provider prepend", async () => {
  const root = await mkdtemp(path.join(tmpdir(), "spacefast-config-no-provider-"));
  expect(await restoreConfig(root, { SPACEFAST_RUNTIME_INSTANCE_ID: "box_owned" }, false)).toEqual({
    error: "bootstrap_config_provider_context_missing",
  });
  await expect(access(path.join(root, ".stattic/storage/config.php"))).rejects.toMatchObject({
    code: "ENOENT",
  });
  await rm(root, { recursive: true, force: true });
});
