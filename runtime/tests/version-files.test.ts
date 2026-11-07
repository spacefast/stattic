// The version file authority: one catalog, one resolver, two readers.
//
// `GET .../versions/{v}/files` is the only file list: the dashboard browser
// reads `view=source`, the abuse scanner reads `view=served&public_only=1`.
// The blob gate's path lane is the only way bytes leave, and it resolves
// through the same resolver, so a list row and a download cannot disagree.
//
// Not provable here: Range/206, 416, conditional 304, and the dashboard CORS
// header surviving real provider delivery. This fixture runs `php -S`, where
// every request takes the PHP body fallback. The credential-gated wp.cloud
// suite owns those provider facts.
import { afterAll, beforeAll, expect, test } from "bun:test";
import {
  existsSync,
  lstatSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  api,
  apiJson,
  blobGatePathToken,
  blobPath,
  DASHBOARD_ORIGIN,
  deploy,
  errorCode,
  getBlob,
  manifestFor,
  managementToken,
  runtimeHttpPath,
  versionRoot,
  RUNTIME_HTTP_API_BASE,
  type Runtime,
  sha256,
  signToken,
  startRuntime,
  type VersionFileView,
  versionCatalog,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_files";
const VERSION = "ver_files_1";
const HOST = "files.test";

// One published version carrying every distinction the catalog has to record:
// a plain public file, a substituted file whose source and served bytes differ,
// and a hidden path that exists in source and can never be served.
const UPLOADED_CONFIG = "export const API = '{{ vars.API }}';\n";
const SERVED_CONFIG = "export const API = 'substituted';\n";
const CONFIG_FILE = '{ "templates": ["config.js"] }\n';
// Variable values are publish-time input, never a version property: every
// publish that carries the template forward resolves them again.
const VARIABLE_SCOPES = [{ kind: "space", values: { API: { value: "substituted" } } }];
const INDEX = "<h1>files</h1>\n";
const NOTE = "private note\n";
const STYLE = "body{color:red}\n";
const SCRIPT = "console.log('app');\n";
const INTERNAL_ARTIFACT = "__spacefast/zero/deploy.json";
const FUNCTIONS_CODE = JSON.stringify({
  format: "spacefast.functions.bundle.v1",
  mainModule: "worker.js",
  modules: {
    "worker.js": {
      js: "export default {fetch: request => new Response(request.headers.get('cookie'))};",
    },
  },
});
const FUNCTIONS_CODE_PATH = `__spacefast/functions/bundles/${sha256(FUNCTIONS_CODE)}/bundle.json`;

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: {
      "index.html": INDEX,
      "config.js": UPLOADED_CONFIG,
      "assets/app.js": SCRIPT,
      "assets/style.css": STYLE,
      [INTERNAL_ARTIFACT]: '{"digest":"sha256:private"}\n',
      [FUNCTIONS_CODE_PATH]: FUNCTIONS_CODE,
      ".hidden/note.txt": NOTE,
      "sf.jsonc": CONFIG_FILE,
    },
    // Substitution makes source and served two answers for one path: the
    // uploaded byte lands in the catalog's `source`, the compiled byte in its
    // `served`.
    finalize: { variable_scopes: VARIABLE_SCOPES },
    activate: { route_name: "production", production_hostnames: [HOST] },
  });
});

afterAll(() => rt?.stop());

type FileRow = {
  path: string;
  size: number;
  sha256: string;
  content_type: string;
  public: boolean;
  variant_route?: string;
};

type FilesPage = { view: string; files: FileRow[]; next_cursor: string | null };

function filesUrl(query = "", spaceId = SPACE, versionId = VERSION): string {
  return `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/versions/${versionId}/files${query}`;
}

async function listFiles(query = "", spaceId = SPACE, versionId = VERSION): Promise<FilesPage> {
  return apiJson<FilesPage>(rt, "GET", filesUrl(query, spaceId, versionId), "list_version_files", {
    space_id: spaceId,
    version_id: versionId,
  });
}

function pathToken(filePath: string, view: VersionFileView, ttlSeconds = 600): string {
  return blobGatePathToken(SPACE, VERSION, filePath, view, { ttlSeconds });
}

test("management code artifact reads bind action, identities, exact bytes and a strict code-only path", async () => {
  const read = (
    path: string,
    content: string,
    overrides: {
      scope?: Record<string, string>;
      action?: string;
      digest?: string;
      size?: number;
      maxBytes?: number;
    } = {},
  ) =>
    api(
      rt,
      "GET",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${VERSION}/investigation-code-artifact?${new URLSearchParams({ path, sha256: overrides.digest ?? sha256(content), size: String(overrides.size ?? Buffer.byteLength(content)), max_bytes: String(overrides.maxBytes ?? 32768) })}`,
      overrides.action ?? "read_version_investigation_code_artifact",
      {
        ...{ space_id: SPACE, version_id: VERSION },
        ...overrides.scope,
        iat: Math.floor(Date.now() / 1000),
        exp: Math.floor(Date.now() / 1000) + 60,
      },
    );
  for (const [path, content] of [[FUNCTIONS_CODE_PATH, FUNCTIONS_CODE]]) {
    const allowed = await read(path, content);
    expect(allowed.status).toBe(200);
    expect(new Uint8Array(await allowed.arrayBuffer())).toEqual(new TextEncoder().encode(content));
  }
  const wrongAction = await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE, {
    action: "read_version_source",
  });
  expect(wrongAction.status).toBe(403);
  const wrongSpace = await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE, {
    scope: { space_id: "spc_other", version_id: VERSION },
  });
  expect(wrongSpace.status).toBe(403);
  const wrongVersion = await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE, {
    scope: { space_id: SPACE, version_id: "ver_other" },
  });
  expect(wrongVersion.status).toBe(403);
  for (const path of [
    INTERNAL_ARTIFACT,
    "__spacefast/zero/config.json",
    "__spacefast/functions/seeds/secret/seed.json",
    "__spacefast/zero/bundles/../config.json",
    "index.html",
  ]) {
    const denied = await read(path, "secret");
    expect(denied.status).toBe(404);
    expect(await denied.text()).not.toContain("secret");
  }
  expect((await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE, { digest: "0".repeat(64) })).status).toBe(
    409,
  );
  expect((await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE, { size: 1 })).status).toBe(409);
  expect((await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE, { maxBytes: 1 })).status).toBe(204);
  const generic = await api(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${VERSION}/source?${new URLSearchParams({ path: FUNCTIONS_CODE_PATH, max_bytes: "32768" })}`,
    "read_version_source",
    { space_id: SPACE, version_id: VERSION },
  );
  expect(generic.status).toBe(204);
  const blob = blobPath(rt, SPACE, sha256(FUNCTIONS_CODE));
  try {
    writeFileSync(blob, "x".repeat(Buffer.byteLength(FUNCTIONS_CODE)));
    expect((await read(FUNCTIONS_CODE_PATH, FUNCTIONS_CODE)).status).toBe(503);
  } finally {
    writeFileSync(blob, FUNCTIONS_CODE);
  }
});

test("the catalog is the version's file identity: source is what was uploaded, served is what ships", async () => {
  const catalog = versionCatalog(rt, SPACE, VERSION);
  expect(catalog?.format).toBe("spacefast.runtime.file-catalog.v1");
  expect(catalog?.paths[INTERNAL_ARTIFACT]).toBeDefined();

  const source = await listFiles("?view=source");
  expect(source.view).toBe("source");
  const sourcePaths = source.files.map((file) => file.path);
  expect(sourcePaths).toEqual([
    ".hidden/note.txt",
    "assets/app.js",
    "assets/style.css",
    "config.js",
    "index.html",
    "sf.jsonc",
  ]);
  expect(source.next_cursor).toBeNull();
  expect(sourcePaths).not.toContain(INTERNAL_ARTIFACT);

  const runtimePlanning = await listFiles("?view=source&include_internal=1");
  expect(runtimePlanning.files.map((file) => file.path)).toContain(INTERNAL_ARTIFACT);

  // The uploaded object, not the compiled one. Size and type travel with the
  // row, so a browser needs no second lookup.
  const sourceConfig = source.files.find((file) => file.path === "config.js");
  expect(sourceConfig).toEqual({
    path: "config.js",
    size: Buffer.byteLength(UPLOADED_CONFIG),
    sha256: sha256(UPLOADED_CONFIG),
    content_type: expect.stringContaining("javascript"),
    public: true,
  });

  // Visibility is recorded once by the finalizer and read here, never
  // recomputed from the path.
  expect(source.files.find((file) => file.path === ".hidden/note.txt")?.public).toBe(false);

  const served = await listFiles("?view=served");
  expect(served.files.find((file) => file.path === "config.js")?.sha256).toBe(
    sha256(SERVED_CONFIG),
  );
  // A private input exists in source and not in served: no byte a visitor can
  // receive.
  expect(served.files.map((file) => file.path)).not.toContain(".hidden/note.txt");
});

test("path, public_only, prefix, q and cursor bound what a reader has to hold", async () => {
  // The scanner's enumeration: served bytes a visitor could fetch.
  const scannable = await listFiles("?view=served&public_only=1");
  expect(scannable.files.every((file) => file.public)).toBe(true);
  expect(scannable.files.map((file) => file.path)).not.toContain(".hidden/note.txt");

  expect((await listFiles("?prefix=assets/")).files.map((file) => file.path)).toEqual([
    "assets/app.js",
    "assets/style.css",
  ]);
  expect((await listFiles("?q=STYLE")).files.map((file) => file.path)).toEqual([
    "assets/style.css",
  ]);

  // A per-file lookup is one bounded page. A path the view does not hold comes
  // back as an empty page, not an error.
  expect((await listFiles("?path=config.js")).files.map((file) => file.sha256)).toEqual([
    sha256(UPLOADED_CONFIG),
  ]);
  expect((await listFiles("?view=served&path=.hidden/note.txt")).files).toEqual([]);

  // Paging walks the row sort order, so no row repeats or is skipped across a
  // page boundary.
  const walked: string[] = [];
  let cursor: string | null = null;
  for (let page = 0; page < 10; page += 1) {
    const next: FilesPage = await listFiles(
      `?limit=2${cursor === null ? "" : `&cursor=${encodeURIComponent(cursor)}`}`,
    );
    walked.push(...next.files.map((file) => file.path));
    cursor = next.next_cursor;
    if (cursor === null) break;
  }
  expect(cursor).toBeNull();
  expect(walked).toEqual([
    ".hidden/note.txt",
    "assets/app.js",
    "assets/style.css",
    "config.js",
    "index.html",
    "sf.jsonc",
  ]);

  // A channel selects template-variant bytes, which exist only in the served
  // view. Asking for them with `view=source` is refused, not ignored.
  const contradiction = await api(
    rt,
    "GET",
    filesUrl("?view=source&channel=preview"),
    "list_version_files",
    {
      space_id: SPACE,
      version_id: VERSION,
    },
  );
  expect(contradiction.status).toBe(422);
  expect(await errorCode(contradiction)).toBe("runtime_version_files_request_invalid");
});

test("a path claim serves the view it names, as an attachment the dashboard may read", async () => {
  // Downloads use the box hostname, which has no Space serving route.
  const served = await getBlob(rt, "box.files.test", pathToken("config.js", "served"));
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(SERVED_CONFIG);

  // The same path in the other view returns different bytes, which is why
  // `view` is part of the claim.
  const source = await getBlob(rt, HOST, pathToken("config.js", "source"));
  expect(source.status).toBe(200);
  expect(await source.text()).toBe(UPLOADED_CONFIG);

  // The gate answers on the public site hostname before any host or access
  // check, so everything it hands back is an attachment and can never run as a
  // page on the Space's own origin. The pinned dashboard origin rides beside
  // it, because a `fetch()` that cannot read the response cannot preview it.
  expect(source.headers.get("content-disposition")).toBe("attachment");
  expect(source.headers.get("cache-control")).toBe("private, no-store");
  expect(source.headers.get("access-control-allow-origin")).toBe(DASHBOARD_ORIGIN);
  expect(source.headers.get("etag")).toBe(`"${sha256(UPLOADED_CONFIG)}"`);

  const head = await getBlob(rt, HOST, pathToken("index.html", "source"), { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-length")).toBe(String(Buffer.byteLength(INDEX)));
  expect(head.headers.get("content-disposition")).toBe("attachment");
  expect(await head.text()).toBe("");

  // A private input has a source byte and no served byte, and the gate answers
  // per view rather than per file.
  expect((await getBlob(rt, HOST, pathToken(".hidden/note.txt", "source"))).status).toBe(200);
  const noServed = await getBlob(rt, HOST, pathToken(".hidden/note.txt", "served"));
  expect(noServed.status).toBe(404);
  expect(await errorCode(noServed)).toBe("version_file_not_found");

  const internal = await getBlob(rt, HOST, pathToken(INTERNAL_ARTIFACT, "source"));
  expect(internal.status).toBe(404);
  expect(await errorCode(internal)).toBe("version_file_not_found");
});

test("a path claim buys exactly one path in one version, and nothing outside it", async () => {
  // Everything that fails before the capability is verified answers the same
  // bare 404, with no code and no hint at which check failed.
  const bare = async (token: string) => {
    const response = await getBlob(rt, HOST, token);
    expect(response.status).toBe(404);
    expect(response.headers.get("content-type")).toBe("text/plain; charset=utf-8");
    await response.text();
  };
  await bare(pathToken("index.html", "source", -30));
  await bare(blobGatePathToken(SPACE, VERSION, "index.html", "source", { rogueKey: true }));
  await bare(blobGatePathToken(SPACE, VERSION, "index.html", "sideways" as VersionFileView));
  // A sha claim and a path claim in one token would let a caller borrow the
  // other lane's resolution, so scope validation refuses the mixture.
  await bare(
    signToken({
      aud: "spacefast-blob-gate",
      space_id: SPACE,
      version_id: VERSION,
      path: "index.html",
      view: "source",
      sha256: sha256(INDEX),
    }),
  );

  // Past the capability check the answer is named: the control plane mints
  // tokens blind, and the holder was already told this triple exists.
  const wrongPath = await getBlob(rt, HOST, pathToken("nope.html", "source"));
  expect(wrongPath.status).toBe(404);
  expect(await errorCode(wrongPath)).toBe("version_file_not_found");

  // A traversal fails normalization and gets the same refusal as an unknown
  // path.
  const traversal = await getBlob(rt, HOST, pathToken("../ver_other/index.html", "source"));
  expect(await errorCode(traversal)).toBe("version_file_not_found");

  for (const wrongScope of [
    blobGatePathToken("spc_other", VERSION, "index.html", "source"),
    blobGatePathToken(SPACE, "ver_files_absent", "index.html", "source"),
  ]) {
    const denied = await getBlob(rt, HOST, wrongScope);
    expect(denied.status).toBe(404);
    await denied.text();
  }
});

test("a reusable version with no list republishes everything the runtime already holds", async () => {
  const reusedVersion = "ver_files_retained";
  // With `retention: "all"` and neither `files` nor `retained_files`, the
  // runtime materializes the path list from its own catalog.
  await deploy(rt, {
    spaceId: SPACE,
    versionId: reusedVersion,
    files: {},
    session: { reusableVersionId: VERSION, retention: "all" },
    finalize: { variable_scopes: VARIABLE_SCOPES },
  });

  const retained = await listFiles("?view=source", SPACE, reusedVersion);
  expect(retained.files.map((file) => file.path)).toEqual([
    ".hidden/note.txt",
    "assets/app.js",
    "assets/style.css",
    "config.js",
    "index.html",
    "sf.jsonc",
  ]);
  // Retention carries the uploaded object forward, not the previous publish's
  // substituted byte, which would bake those variable values in permanently.
  expect(retained.files.find((file) => file.path === "config.js")?.sha256).toBe(
    sha256(UPLOADED_CONFIG),
  );
});

// The runtime has no delete list: a dropped path is a path missing from the
// retained set. When the publisher declares the only surviving file that set is
// empty, which must not be read as "retain everything".
test("a pruning publish with an empty retained set drops the base's other paths", async () => {
  const baseVersion = "ver_files_prune_base";
  const prunedVersion = "ver_files_pruned";
  await deploy(rt, {
    spaceId: SPACE,
    versionId: baseVersion,
    files: { "a.html": "<h1>a</h1>\n", "b.html": "<h1>b</h1>\n" },
  });

  await deploy(rt, {
    spaceId: SPACE,
    versionId: prunedVersion,
    files: { "a.html": "<h1>a2</h1>\n" },
    session: { reusableVersionId: baseVersion, retention: "list" },
  });

  const pruned = await listFiles("?view=source", SPACE, prunedVersion);
  expect(pruned.files.map((file) => file.path)).toEqual(["a.html"]);
});

test("a pruning publish can retain runtime-owned artifacts without exposing them to file browsing", async () => {
  const prunedVersion = "ver_files_internal_retained";
  await deploy(rt, {
    spaceId: SPACE,
    versionId: prunedVersion,
    files: { "index.html": "<h1>pruned</h1>\n" },
    session: {
      reusableVersionId: VERSION,
      retention: "list",
      retainedFiles: manifestFor({ [INTERNAL_ARTIFACT]: '{"digest":"sha256:private"}\n' }),
    },
  });

  expect(versionCatalog(rt, SPACE, prunedVersion)?.paths[INTERNAL_ARTIFACT]).toBeDefined();
  expect(
    (await listFiles("?view=source", SPACE, prunedVersion)).files.map((file) => file.path),
  ).toEqual(["index.html"]);
  expect(
    (await listFiles("?view=source&include_internal=1", SPACE, prunedVersion)).files.map(
      (file) => file.path,
    ),
  ).toEqual([INTERNAL_ARTIFACT, "index.html"]);
});

test("case-distinct tenant paths are not mistaken for runtime artifacts", async () => {
  const versionId = "ver_files_uppercase_zero";
  await deploy(rt, {
    spaceId: SPACE,
    versionId,
    files: { "Zero/index.html": "<h1>tenant Zero</h1>\n" },
  });

  expect(
    (await listFiles("?view=source", SPACE, versionId)).files.map((file) => file.path),
  ).toEqual(["Zero/index.html"]);
});

// Compare content, inode and change times: an identical rewrite is still a
// mutation. Access times are excluded because the filesystem owns read atime.
function storageSnapshot(root: string) {
  const result = new Map<
    string,
    {
      mode: string;
      inode: string;
      size: string;
      mtime: string;
      ctime: string;
      digest: string | null;
    }
  >();
  function visit(dir: string) {
    for (const name of readdirSync(dir).toSorted()) {
      const filename = path.join(dir, name);
      const stat = lstatSync(filename, { bigint: true });
      result.set(path.relative(root, filename), {
        mode: String(stat.mode),
        inode: String(stat.ino),
        size: String(stat.size),
        mtime: String(stat.mtimeNs),
        ctime: String(stat.ctimeNs),
        digest: stat.isFile() ? sha256(readFileSync(filename)) : null,
      });
      if (stat.isDirectory()) visit(filename);
    }
  }
  visit(root);
  return result;
}

function evidenceToken(action: string, versionId: string) {
  const now = Math.floor(Date.now() / 1000);
  return managementToken(action, {
    space_id: SPACE,
    version_id: versionId,
    iat: now,
    exp: now + 60,
  });
}

function evidenceRequest(
  versionId: string,
  endpoint: "files" | "source",
  query: string,
  token = evidenceToken(
    endpoint === "files" ? "list_version_evidence" : "read_version_evidence",
    versionId,
  ),
) {
  return fetch(
    `${rt.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/evidence/${endpoint}?${query}`)}`,
    {
      headers: { authorization: `Bearer ${token}` },
    },
  );
}

test("cold and warm evidence reads leave small and shard-sized catalogs and authentication storage unchanged", async () => {
  const source = Buffer.from("<?php throw new Exception('must remain inert');\0");
  const served = Buffer.from([0x00, 0xff, 0x80, 0x3c, 0x3f]);
  const object = (bytes: Buffer) => ({
    sha256: sha256(bytes),
    size: bytes.length,
    contentType: "application/x-httpd-php",
  });
  for (const count of [1, 4000]) {
    const versionId = `ver_evidence_${count}`;
    const root = versionRoot(rt, SPACE, versionId);
    mkdirSync(root, { recursive: true });
    const paths = Object.fromEntries(
      Array.from({ length: count }, (_, i) => [
        i === 0 ? "evidence.php" : `assets/${String(i).padStart(6, "0")}-${"x".repeat(160)}.txt`,
        { public: true, source: object(source), served: object(served) },
      ]),
    );
    const metadata = {
      catalog: {
        format: "spacefast.runtime.file-catalog.v1",
        spaceId: SPACE,
        versionId,
        paths,
        variants: {},
      },
    };
    writeFileSync(path.join(root, "metadata.json"), JSON.stringify(metadata));
    writeFileSync(path.join(root, "root.json"), "{}");
    for (const bytes of [source, served]) {
      const blob = blobPath(rt, SPACE, sha256(bytes));
      mkdirSync(path.dirname(blob), { recursive: true });
      writeFileSync(blob, bytes);
    }
    rmSync(path.join(rt.storageRoot, "runtime/jwks.json"), { force: true });
    const read = async () => {
      const token = evidenceToken("list_version_evidence", versionId);
      // The same read token can be reused until expiry without a replay write.
      for (let attempt = 0; attempt < 2; attempt++) {
        const list = await evidenceRequest(
          versionId,
          "files",
          "path=evidence.php&view=source",
          token,
        );
        expect(list.status).toBe(200);
        const page = z
          .object({
            files: z.array(
              z.object({
                path: z.string(),
                size: z.number(),
                sha256: z.string(),
                content_type: z.string(),
                public: z.boolean(),
              }),
            ),
          })
          .parse(await list.json());
        expect(page.files).toEqual([
          {
            path: "evidence.php",
            ...{
              size: source.length,
              sha256: sha256(source),
              content_type: "application/x-httpd-php",
              public: true,
            },
          },
        ]);
        for (const [view, bytes] of [
          ["source", source],
          ["served", served],
        ] as const) {
          const response = await evidenceRequest(
            versionId,
            "source",
            `path=evidence.php&view=${view}&max_bytes=128`,
          );
          expect(response.status).toBe(200);
          expect(response.headers.get("content-type")).toBe("application/octet-stream");
          expect(response.headers.get("cache-control")).toBe("private, no-store");
          expect(response.headers.get("x-content-type-options")).toBe("nosniff");
          expect(Buffer.from(await response.arrayBuffer())).toEqual(bytes);
        }
      }
    };
    const cold = storageSnapshot(path.join(rt.root, ".stattic"));
    await read();
    expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(cold);
    expect(existsSync(path.join(root, "catalog-cache.php"))).toBe(false);
    expect(existsSync(path.join(root, "path-cache"))).toBe(false);

    // Ordinary management reads still intentionally create their derived cache.
    expect((await listFiles("?path=evidence.php", SPACE, versionId)).files[0]?.sha256).toBe(
      sha256(source),
    );
    if (count === 1) expect(existsSync(path.join(root, "catalog-cache.php"))).toBe(true);
    else expect(readdirSync(path.join(root, "path-cache")).length).toBe(256);
    const warm = storageSnapshot(path.join(rt.root, ".stattic"));
    await read();
    expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(warm);

    // Sidecars are executable PHP. Evidence must ignore them even when poisoned.
    const sidecar = path.join(root, "catalog-cache.php");
    const shardDir = path.join(root, "path-cache");
    mkdirSync(shardDir, { recursive: true });
    const poison = `<?php file_put_contents(__DIR__ . '/executed', 'unsafe'); return [];`;
    writeFileSync(sidecar, poison);
    writeFileSync(path.join(shardDir, `${sha256("evidence.php").slice(0, 2)}.php`), poison);
    const poisoned = storageSnapshot(path.join(rt.root, ".stattic"));
    await read();
    expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(poisoned);

    for (const damaged of ["missing", "corrupt", "foreign"] as const) {
      if (damaged === "missing") rmSync(path.join(root, "metadata.json"));
      else
        writeFileSync(
          path.join(root, "metadata.json"),
          damaged === "corrupt"
            ? "{broken"
            : JSON.stringify({ catalog: { ...metadata.catalog, spaceId: "spc_foreign" } }),
        );
      const before = storageSnapshot(path.join(rt.root, ".stattic"));
      const list = await evidenceRequest(versionId, "files", "path=evidence.php");
      expect(list.status).toBe(409);
      expect(await errorCode(list)).toBe("runtime_file_catalog_invalid");
      const bytes = await evidenceRequest(versionId, "source", "path=evidence.php&max_bytes=128");
      expect(bytes.status).toBe(503);
      expect(await errorCode(bytes)).toBe("runtime_version_source_unavailable");
      expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
    }
  }
});

test("evidence rejects malformed nested catalog facts instead of inventing identities or absence", async () => {
  const versionId = "ver_evidence_invalid_objects";
  const root = versionRoot(rt, SPACE, versionId);
  mkdirSync(root, { recursive: true });
  writeFileSync(path.join(root, "root.json"), "{}");
  const bytes = Buffer.from("test");
  const object = {
    sha256: sha256(bytes),
    size: bytes.length,
    contentType: "text/plain",
  };
  for (const content of [bytes, Buffer.alloc(0)]) {
    const blob = blobPath(rt, SPACE, sha256(content));
    mkdirSync(path.dirname(blob), { recursive: true });
    writeFileSync(blob, content);
  }
  const valid = { public: false, source: object, served: null };
  const malformedObjects = [
    { ...object, sha256: "invalid" },
    { ...object, sha256: 64 },
    { ...object, sha256: sha256(Buffer.alloc(0)), size: -7 },
    { ...object, size: 4.9 },
    { ...object, size: "4" },
    { sha256: object.sha256, contentType: object.contentType },
    { ...object, contentType: null },
  ];
  const invalidCatalogs = [
    ...malformedObjects.map((source) => ({
      paths: { "test.txt": { ...valid, source } },
      variants: {},
    })),
    { paths: { "test.txt": [] }, variants: {} },
    { paths: { "test.txt": { ...valid, source: null } }, variants: {} },
    { paths: { "test.txt": { ...valid, public: "false" } }, variants: {} },
    { paths: { "test.txt": { ...valid, served: { ...object, size: "4" } } }, variants: {} },
    { paths: { "test.txt": valid }, variants: "invalid" },
    {
      paths: { "test.txt": valid },
      variants: { preview: { "test.txt": { ...object, size: -1 } } },
    },
  ];
  const metadata = (catalog: { paths: unknown; variants: unknown }) =>
    JSON.stringify({
      catalog: {
        format: "spacefast.runtime.file-catalog.v1",
        spaceId: SPACE,
        versionId,
        ...catalog,
      },
    });
  for (const catalog of invalidCatalogs) {
    writeFileSync(path.join(root, "metadata.json"), metadata(catalog));
    const before = storageSnapshot(path.join(rt.root, ".stattic"));
    const list = await evidenceRequest(versionId, "files", "path=test.txt");
    expect(list.status).toBe(409);
    expect(await errorCode(list)).toBe("runtime_file_catalog_invalid");
    const source = await evidenceRequest(versionId, "source", "path=test.txt&max_bytes=128");
    expect(source.status).toBe(503);
    expect(await errorCode(source)).toBe("runtime_version_source_unavailable");
    expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
  }

  // A valid catalog still distinguishes an absent path or private served view.
  // Decimal filenames must survive PHP's JSON object-key conversion, too.
  writeFileSync(
    path.join(root, "metadata.json"),
    metadata({
      paths: {
        "test.txt": valid,
        "private.txt": { public: false, source: object },
        "123": valid,
      },
      variants: { preview: { "generated.txt": { ...object, contentType: "" } } },
    }),
  );
  const before = storageSnapshot(path.join(rt.root, ".stattic"));
  const source = await evidenceRequest(versionId, "source", "path=test.txt&max_bytes=128");
  expect(source.status).toBe(200);
  expect(Buffer.from(await source.arrayBuffer())).toEqual(bytes);
  const numericList = await evidenceRequest(versionId, "files", "path=123");
  expect(numericList.status).toBe(200);
  expect(
    z.object({ files: z.array(z.object({ path: z.string() })) }).parse(await numericList.json())
      .files,
  ).toEqual([{ path: "123" }]);
  const variantList = await evidenceRequest(
    versionId,
    "files",
    "path=generated.txt&view=served&channel=preview",
  );
  expect(variantList.status).toBe(200);
  expect(
    z
      .object({ files: z.array(z.object({ path: z.string(), content_type: z.string() })) })
      .parse(await variantList.json()).files,
  ).toEqual([{ path: "generated.txt", content_type: "" }]);
  for (const query of [
    "path=missing.txt",
    "path=test.txt&view=served",
    "path=private.txt&view=served",
  ]) {
    const missing = await evidenceRequest(versionId, "source", `${query}&max_bytes=128`);
    expect(missing.status).toBe(204);
    expect(await missing.text()).toBe("");
  }
  expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
});

test("evidence actions pin authorization, catalog membership and external paths without writes", async () => {
  const before = storageSnapshot(path.join(rt.root, ".stattic"));
  const wrongAction = await evidenceRequest(
    VERSION,
    "files",
    "path=config.js",
    evidenceToken("list_version_files", VERSION),
  );
  expect(wrongAction.status).toBe(403);
  expect(await errorCode(wrongAction)).toBe("runtime_action_forbidden");
  const wrongScope = await evidenceRequest(
    VERSION,
    "files",
    "path=config.js",
    evidenceToken("list_version_evidence", "ver_other"),
  );
  expect(wrongScope.status).toBe(403);
  await wrongScope.text();
  const now = Math.floor(Date.now() / 1000);
  const claims = {
    aud: "stattic-runtime-management",
    runtime_instance_id: "rti_test",
    operation_id: "op_evidence",
    action: "list_version_evidence",
    jti: "synthetic-repeatable-read",
    space_id: SPACE,
    version_id: VERSION,
    iat: now,
    exp: now + 60,
  };
  for (const token of [
    signToken({ ...claims, exp: now + 300 }),
    signToken({ ...claims, iat: now + 3600, exp: now + 3660 }),
    signToken({ ...claims, exp: now - 1 }),
    signToken({ ...claims, runtime_instance_id: "rti_other" }),
    signToken(claims, { rogueKey: true }),
  ]) {
    const response = await evidenceRequest(VERSION, "files", "path=config.js", token);
    expect([401, 403]).toContain(response.status);
    await response.text();
  }
  const missingPaths = [
    "not-in-catalog.txt",
    INTERNAL_ARTIFACT,
    "zero/admin.php",
    "../metadata.json",
  ];
  for (const filePath of missingPaths) {
    const response = await evidenceRequest(
      VERSION,
      "source",
      new URLSearchParams({ path: filePath, max_bytes: "128" }).toString(),
    );
    expect(response.status).toBe(204);
    expect(await response.text()).toBe("");
  }
  const privateServed = await evidenceRequest(
    VERSION,
    "source",
    "path=.hidden/note.txt&view=served&max_bytes=128",
  );
  expect(privateServed.status).toBe(204);
  await privateServed.text();
  const upload = await evidenceRequest(
    VERSION,
    "source",
    `upload_id=upl_absent&sha256=${sha256(NOTE)}&max_bytes=128`,
  );
  expect(upload.status).toBe(422);
  await upload.text();
  const smallCap = await evidenceRequest(VERSION, "source", "path=config.js&max_bytes=1");
  expect(smallCap.status).toBe(204);
  await smallCap.text();
  expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
});

test("Unicode evidence never journals without intl while ordinary reads retain the diagnostic", async () => {
  const runtime = await startRuntime({
    phpIni: {
      disable_functions: "class_exists",
      auto_prepend_file: path.join(import.meta.dir, "fixtures/without-intl-prepend.php"),
    },
  });
  try {
    const versionId = "ver_evidence_no_intl";
    const root = versionRoot(runtime, SPACE, versionId);
    mkdirSync(root, { recursive: true });
    writeFileSync(path.join(root, "root.json"), "{}");
    const bytes = Buffer.from("inert Unicode evidence");
    const object = { sha256: sha256(bytes), size: bytes.length, contentType: "text/plain" };
    const blob = blobPath(runtime, SPACE, object.sha256);
    mkdirSync(path.dirname(blob), { recursive: true });
    writeFileSync(blob, bytes);
    const entry = { public: false, source: object };
    const catalog = (unicode: boolean) => ({
      catalog: {
        format: "spacefast.runtime.file-catalog.v1",
        spaceId: SPACE,
        versionId,
        paths: unicode ? { "test.txt": entry, "café.txt": entry } : { "test.txt": entry },
        variants: unicode ? { preview: { "généré.txt": object } } : {},
      },
    });
    const read = (endpoint: "files" | "source", filePath: string) => {
      const query = new URLSearchParams({ path: filePath, max_bytes: "128" });
      return fetch(
        `${runtime.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/evidence/${endpoint}?${query}`)}`,
        {
          headers: {
            authorization: `Bearer ${evidenceToken(endpoint === "files" ? "list_version_evidence" : "read_version_evidence", versionId)}`,
          },
        },
      );
    };
    for (const unicode of [true, false]) {
      writeFileSync(path.join(root, "metadata.json"), JSON.stringify(catalog(unicode)));
      const before = storageSnapshot(path.join(runtime.root, ".stattic"));
      for (const filePath of unicode ? ["test.txt", "café.txt"] : ["test.txt"]) {
        const list = await read("files", filePath);
        expect(list.status).toBe(200);
        expect(
          z.object({ files: z.array(z.object({ path: z.string() })) }).parse(await list.json())
            .files,
        ).toEqual([{ path: filePath }]);
        const source = await read("source", filePath);
        expect(source.status).toBe(200);
        expect(Buffer.from(await source.arrayBuffer())).toEqual(bytes);
      }
      for (const filePath of ["absént.txt", "absent\u0301.txt"]) {
        const list = await read("files", filePath);
        expect(list.status).toBe(200);
        expect(
          z.object({ files: z.array(z.object({ path: z.string() })) }).parse(await list.json())
            .files,
        ).toEqual([]);
        const source = await read("source", filePath);
        expect(source.status).toBe(204);
        expect(await source.text()).toBe("");
      }
      const invalidList = await read("files", "café/../private.txt");
      expect(invalidList.status).toBe(422);
      const invalidSource = await read("source", "café/../private.txt");
      expect(invalidSource.status).toBe(204);
      expect(await invalidSource.text()).toBe("");
      if (unicode) {
        const served = await fetch(
          `${runtime.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/evidence/source?${new URLSearchParams({ path: "généré.txt", view: "served", channel: "preview", max_bytes: "128" })}`)}`,
          {
            headers: {
              authorization: `Bearer ${evidenceToken("read_version_evidence", versionId)}`,
            },
          },
        );
        expect(served.status).toBe(200);
        expect(Buffer.from(await served.arrayBuffer())).toEqual(bytes);
        const artifacts = await fetch(
          `${runtime.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/execution-artifacts`)}`,
          {
            headers: {
              authorization: `Bearer ${evidenceToken("list_version_execution_artifacts", versionId)}`,
            },
          },
        );
        expect(artifacts.status).toBe(200);
        expect(
          z.object({ artifacts: z.array(z.json()) }).parse(await artifacts.json()).artifacts,
        ).toEqual([]);
        const code = await fetch(
          `${runtime.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/investigation-code-artifact?${new URLSearchParams({ path: "__spacefast/functions/bundles/café/bundle.json", sha256: object.sha256, size: String(object.size), max_bytes: "128" })}`)}`,
          {
            headers: {
              authorization: `Bearer ${evidenceToken("read_version_investigation_code_artifact", versionId)}`,
            },
          },
        );
        expect(code.status).toBe(204);
        await code.text();
      }
      expect(storageSnapshot(path.join(runtime.root, ".stattic"))).toEqual(before);
    }
    // The environment seam really selects missing-intl handling. Its ordinary
    // diagnostic remains observable, and must not be globally suppressed.
    const ordinary = await api(
      runtime,
      "GET",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/files?${new URLSearchParams({ path: "absént.txt" })}`,
      "list_version_files",
      { space_id: SPACE, version_id: versionId },
    );
    expect(ordinary.status).toBe(200);
    const events = readFileSync(path.join(runtime.storageRoot, "runtime/journal.jsonl"), "utf8")
      .trim()
      .split("\n")
      .map((line) => z.object({ event: z.string() }).parse(JSON.parse(line)).event);
    expect(events).toContain("runtime_intl_missing");
  } finally {
    runtime.stop();
  }
});

test("evidence bytes require the exact catalog size and digest, including when the CAS is damaged", async () => {
  const blob = blobPath(rt, SPACE, sha256(UPLOADED_CONFIG));
  const original = readFileSync(blob);
  try {
    for (const bytes of [Buffer.alloc(original.length, 0x78), Buffer.from("short"), null]) {
      if (bytes === null) rmSync(blob);
      else writeFileSync(blob, bytes);
      const before = storageSnapshot(path.join(rt.root, ".stattic"));
      const response = await evidenceRequest(
        VERSION,
        "source",
        "path=config.js&view=source&max_bytes=1024",
      );
      expect(response.status).toBe(503);
      expect(await errorCode(response)).toBe("runtime_version_source_unavailable");
      expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
    }
  } finally {
    writeFileSync(blob, original);
  }
});

test("evidence authentication refuses missing or corrupt local keys without fetching or persisting them", async () => {
  const atomicPath = path.join(rt.root, ".atomic-persistent-data.json");
  const original = readFileSync(atomicPath, "utf8");
  const configured = z.record(z.string(), z.string()).parse(JSON.parse(original));
  configured.SPACEFAST_RUNTIME_JWKS_B64 = "";
  configured.SPACEFAST_API_BASE_URL = "https://127.0.0.1:1";
  const keyCache = path.join(rt.storageRoot, "runtime/jwks.json");
  try {
    writeFileSync(atomicPath, JSON.stringify(configured));
    for (const cache of [null, "{broken"]) {
      if (cache === null) rmSync(keyCache, { force: true });
      else writeFileSync(keyCache, cache);
      const before = storageSnapshot(path.join(rt.root, ".stattic"));
      const response = await evidenceRequest(VERSION, "files", "path=config.js");
      expect(response.status).toBe(401);
      expect(await errorCode(response)).toBe("runtime_jwks_key_missing");
      expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
    }
  } finally {
    writeFileSync(atomicPath, original);
    rmSync(keyCache, { force: true });
  }
});

test("the outer loader refuses a missing publication lock without creating a file", async () => {
  const lock = path.join(rt.root, ".stattic/publication.lock");
  const original = readFileSync(lock);
  rmSync(lock);
  try {
    const before = storageSnapshot(path.join(rt.root, ".stattic"));
    const response = await evidenceRequest(VERSION, "files", "path=config.js");
    expect(response.status).toBe(503);
    expect(await errorCode(response)).toBe("runtime_engine_update_busy");
    expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
  } finally {
    writeFileSync(lock, original);
  }
});

// Last on purpose: it unlinks a live CAS object every test above needs intact.
test("bytes momentarily unreachable are a 503, never a 404 that reads as deletion", async () => {
  const blob = blobPath(rt, SPACE, sha256(STYLE));
  expect(existsSync(blob)).toBe(true);
  rmSync(blob);

  // Authorization succeeded and the catalog still names the object. A scanner
  // reading a 404 here would report a live version's files as deleted.
  const unavailable = await getBlob(rt, HOST, pathToken("assets/style.css", "served"));
  expect(unavailable.status).toBe(503);
  expect(unavailable.headers.get("retry-after")).toBe("5");
  expect(unavailable.headers.get("access-control-allow-origin")).toBe(DASHBOARD_ORIGIN);
  await unavailable.text();
});

test("inert execution discovery and typed reads preserve cold catalogs, generated Zero and exact 8 MiB bundles", async () => {
  const versionId = "ver_inert_execution";
  const legacyPath = "__spacefast/functions/bundles/legacy/worker.js";
  const prefix =
    '{"format":"spacefast.functions.bundle.v1","mainModule":"worker.js","modules":{"worker.js":{"js":"';
  const suffix = '"}}}';
  const bundle = prefix + " ".repeat(8 * 1024 * 1024 - prefix.length - suffix.length) + suffix;
  await deploy(rt, {
    spaceId: SPACE,
    versionId,
    files: {
      "index.html": INDEX,
      "variant.txt": NOTE,
      [legacyPath]: bundle,
      [INTERNAL_ARTIFACT]: "{}",
    },
    serving: {
      zero_endpoints: [
        {
          execution_mode: "read",
          method: "GET",
          path: "/api/inert",
          source: "globalThis.__statticZeroResult = JSON.stringify({status:200,body:'inert'});",
          capabilities: { db: false },
        },
      ],
    },
  });
  const root = versionRoot(rt, SPACE, versionId);
  const snapshot = () => storageSnapshot(path.join(rt.root, ".stattic"));
  // Remove derived caches before the observation; investigation reads must not rebuild them.
  for (const path of readdirSync(root))
    if (path.endsWith(".php") || path.includes("shard") || path === "path-cache")
      rmSync(`${root}/${path}`, { recursive: true, force: true });
  const before = snapshot();
  const base = `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}`;
  const scope = {
    space_id: SPACE,
    version_id: versionId,
    iat: Math.floor(Date.now() / 1000),
    exp: Math.floor(Date.now() / 1000) + 60,
  };
  type Artifact = {
    id: string;
    kind: string;
    path: string;
    sha256: string | null;
    size: number | null;
    state: string;
  };
  let cursor: string | null = null;
  const artifacts: Artifact[] = [];
  do {
    const page: { artifacts: Artifact[]; next_cursor: string | null } = await apiJson(
      rt,
      "GET",
      `${base}/execution-artifacts?limit=1${cursor ? `&cursor=${cursor}` : ""}`,
      "list_version_execution_artifacts",
      scope,
    );
    artifacts.push(...page.artifacts);
    cursor = page.next_cursor;
  } while (cursor);
  expect(artifacts.filter((row) => row.kind === "functions_bundle").map((row) => row.path)).toEqual(
    [legacyPath],
  );
  expect(artifacts.find((row) => row.path === INTERNAL_ARTIFACT)?.state).toBe("unsupported");
  expect(artifacts.filter((row) => row.kind === "zero_source").length).toBe(1);
  expect(artifacts.filter((row) => row.kind === "zero_bytecode").length).toBe(1);
  for (const artifact of artifacts.filter((row) => row.state === "available")) {
    const response = await api(
      rt,
      "GET",
      `${base}/investigation-code-artifact?${new URLSearchParams({ path: artifact.path, sha256: artifact.sha256 ?? "", size: String(artifact.size), max_bytes: String(8 * 1024 * 1024) })}`,
      "read_version_investigation_code_artifact",
      scope,
    );
    expect(response.status).toBe(200);
    const bytes = new Uint8Array(await response.arrayBuffer());
    expect(bytes.byteLength).toBe(artifact.size);
    expect(sha256(bytes)).toBe(artifact.sha256);
    if (artifact.kind === "functions_bundle") expect(bytes.byteLength).toBe(8 * 1024 * 1024);
  }
  const rejected = await api(
    rt,
    "GET",
    `${base}/execution-artifacts?limit=101`,
    "list_version_execution_artifacts",
    scope,
  );
  expect(rejected.status).toBe(422);
  expect(snapshot()).toEqual(before);
});

test("served evidence channel selects only the exact immutable variant without base fallback", async () => {
  const versionId = "ver_evidence_variants";
  await deploy(rt, {
    spaceId: SPACE,
    versionId,
    files: { "index.html": INDEX, "variant.txt": NOTE },
  });
  const root = versionRoot(rt, SPACE, versionId);
  const catalog = versionCatalog(rt, SPACE, versionId);
  if (!catalog?.paths["variant.txt"]) throw new Error("fixture variant missing");
  writeFileSync(
    path.join(root, "metadata.json"),
    JSON.stringify({
      catalog: {
        ...catalog,
        variants: { production: { "index.html": catalog.paths["variant.txt"].source } },
      },
    }),
  );
  const before = storageSnapshot(path.join(rt.root, ".stattic"));
  const base = await evidenceRequest(
    versionId,
    "source",
    "view=served&path=index.html&max_bytes=1000",
  );
  expect(base.status).toBe(200);
  expect(await base.text()).toBe(INDEX);
  const variant = await evidenceRequest(
    versionId,
    "source",
    "view=served&channel=production&path=index.html&max_bytes=1000",
  );
  expect(variant.status).toBe(200);
  expect(await variant.text()).toBe(NOTE);
  for (const query of ["channel=absent&path=index.html", "channel=production&path=variant.txt"]) {
    const absent = await evidenceRequest(
      versionId,
      "source",
      `view=served&${query}&max_bytes=1000`,
    );
    expect(absent.status).toBe(204);
    expect(await absent.text()).toBe("");
  }
  const invalid = await evidenceRequest(
    versionId,
    "source",
    "view=source&channel=production&path=index.html&max_bytes=1000",
  );
  expect(invalid.status).toBe(422);
  await invalid.text();
  expect(storageSnapshot(path.join(rt.root, ".stattic"))).toEqual(before);
});
