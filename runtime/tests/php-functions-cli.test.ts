import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  deploy,
  PHP_BINARY,
  publicAccessConfig,
  type Runtime,
  RUNTIME_TEST_ATOMIC_PREPEND,
  startRuntime,
} from "./harness.ts";

const HOST = "phpfx-cli.test";
const SPACE = "spc_phpfx_cli";
const HANDLER = `<?php
file_put_contents(__DIR__ . '/handler-executed', 'executed');
sf_json(['rawCookie' => getenv('HTTP_COOKIE'), 'dispatchToken' => getenv('SPACEFAST_FUNCTIONS_DISPATCH_TOKEN')]);
`;
let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
  writeFileSync(
    path.join(rt.root, "cli-prepend.php"),
    `<?php require ${JSON.stringify(RUNTIME_TEST_ATOMIC_PREPEND)};`,
  );
  await deploy(rt, {
    spaceId: SPACE,
    versionId: "ver_phpfx_cli_1",
    files: { "index.html": "<h1>static home</h1>\n", "functions/hello.php": HANDLER },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website", site_title: "PHP Functions" }),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("CLI invoke refuses PHP before authored code can run or read credentials", () => {
  for (const method of ["GET", "POST", "HEAD", "TRACE", "CONNECT"]) {
    const result = spawnSync(
      process.env.SPACEFAST_REAL_PHP ?? PHP_BINARY,
      [
        "-d",
        "opcache.enable_cli=0",
        "-d",
        `auto_prepend_file=${path.join(rt.root, "cli-prepend.php")}`,
        path.join(rt.engineRoot, "entrypoints/invoke.php"),
        `--private-root=${rt.storageRoot}`,
        `--host=${HOST}`,
        "--path=/hello",
        `--method=${method}`,
        "--header=content-type: application/json",
        "--header=cookie: __Host-spacefast_session=fixture-cookie",
        "--header=x-spacefast-cron: forged.0.signature",
      ],
      {
        cwd: rt.root,
        encoding: "utf8",
        input: '{"name":"fixture"}',
        env: {
          PATH: process.env.PATH,
          HOME: process.env.HOME,
          SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fixture-control-credential",
        },
      },
    );
    expect(result.status, result.stderr).toBe(1);
    const line = result.stderr
      .split("\n")
      .find((entry) => entry.startsWith("spacefast-invoke-result: "));
    if (!line) throw new Error(`CLI receipt missing: ${result.stderr}`);
    const receipt = z
      .object({ method: z.string(), path: z.string(), status: z.number() })
      .parse(JSON.parse(line.slice("spacefast-invoke-result: ".length)));
    const status = method === "TRACE" || method === "CONNECT" ? 405 : 503;
    expect(receipt).toEqual({ method, path: "/hello", status });
    if (method === "HEAD") {
      expect(result.stdout).toBe("");
    } else if (status === 503) {
      expect(JSON.parse(result.stdout)).toMatchObject({
        status: 503,
        code: "tenant_php_unavailable",
      });
    }
    const sha = createHash("sha256").update(HANDLER).digest("hex");
    expect(
      existsSync(
        path.join(rt.storageRoot, "spaces", SPACE, "blobs", sha.slice(0, 2), "handler-executed"),
      ),
    ).toBe(false);
  }
});
