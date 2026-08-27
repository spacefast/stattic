import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync } from "node:fs";

import { strToU8, zipSync } from "fflate";

import {
  blobPath,
  errorCode,
  managementToken,
  RUNTIME_HTTP_API_BASE,
  type Runtime,
  runtimeHttpPath,
  sha256,
  startRuntime,
  storagePath,
} from "./harness.ts";

const SPACE = "spc_static_zip";
let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => rt?.stop());

function ingest(zip: Uint8Array, action = "ingest_static_zip") {
  return fetch(
    `${rt.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/static-zip`)}`,
    {
      method: "POST",
      headers: {
        authorization: `Bearer ${managementToken(action, {
          space_id: SPACE,
          static_zip_caps: {
            max_files: 1000,
            max_file_bytes: 50 * 1024 * 1024,
            max_total_bytes: 100 * 1024 * 1024,
          },
        })}`,
        "content-type": "application/zip",
      },
      body: zip,
    },
  );
}

test("static zip ingest normalizes paths, commits CAS blobs, and pins the result", async () => {
  const index = strToU8("<main>runtime zip</main>\n");
  const script = strToU8("console.log('runtime zip');\n");
  const image = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 1]);
  const response = await ingest(
    zipSync({
      "site/index.html": index,
      "site/assets/app.js": script,
      "site/assets/pixel.data": image,
      "site/.DS_Store": strToU8("finder"),
      "__MACOSX/site/._index.html": strToU8("fork"),
    }),
  );
  // SAFETY: The runtime fixture returns the static ZIP response shape asserted by this test.
  const body = (await response.json()) as {
    space_id: string;
    files: Array<{ path: string; size: number; sha256: string; sniff: string }>;
    pin_expires_at: string;
  };
  expect(response.status, JSON.stringify(body)).toBe(200);
  expect(body.space_id).toBe(SPACE);
  expect(body.files.toSorted((left, right) => left.path.localeCompare(right.path))).toEqual([
    { path: "assets/app.js", size: script.byteLength, sha256: sha256(script), sniff: "text" },
    {
      path: "assets/pixel.data",
      size: image.byteLength,
      sha256: sha256(image),
      sniff: "png",
    },
    { path: "index.html", size: index.byteLength, sha256: sha256(index), sniff: "text" },
  ]);
  expect(readFileSync(blobPath(rt, SPACE, sha256(index)))).toEqual(Buffer.from(index));
  expect(readFileSync(blobPath(rt, SPACE, sha256(script)))).toEqual(Buffer.from(script));
  expect(readFileSync(blobPath(rt, SPACE, sha256(image)))).toEqual(Buffer.from(image));
  expect(
    readFileSync(
      storagePath(
        rt,
        "spaces",
        SPACE,
        "pins",
        `archive-${sha256("op_ingest_static_zip").slice(0, 32)}.json`,
      ),
      "utf8",
    ),
  ).toContain(sha256(index));
  expect(Date.parse(body.pin_expires_at)).toBeGreaterThan(Date.now());
});

test("static zip paths use decode-once manifest canonicalization", async () => {
  const response = await ingest(
    zipSync({
      "/site//./index.html": strToU8("canonical"),
      "/site/assets/a%20b.css": strToU8("body{}"),
    }),
  );
  // SAFETY: The runtime fixture returns the static ZIP response shape asserted by this test.
  const body = (await response.json()) as { files: Array<{ path: string }> };
  expect(response.status, JSON.stringify(body)).toBe(200);
  expect(body.files.map((file) => file.path).toSorted()).toEqual(["assets/a b.css", "index.html"]);
});

test("static zip ingest rejects unsafe paths before writing blobs", async () => {
  const response = await ingest(
    zipSync({
      "index.html": strToU8("safe"),
      "../escape.html": strToU8("escape"),
    }),
  );
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("invalid_publish_path");
});

test("static zip ingest rejects compiled server bundles", async () => {
  const response = await ingest(
    zipSync({
      "site/index.html": strToU8("safe"),
      "site/.open-next/worker.js": strToU8("export default { fetch() {} };"),
    }),
  );
  expect(response.status).toBe(422);
  expect(await errorCode(response)).toBe("build_output_contains_server_bundle");
});

test("static zip ingest requires its management action", async () => {
  const response = await ingest(zipSync({ "index.html": strToU8("safe") }), "create_version");
  expect(response.status).toBe(403);
  expect(await errorCode(response)).toBe("runtime_action_forbidden");
});
