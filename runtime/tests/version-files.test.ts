// The version file authority: one catalog, one resolver, two readers.
//
// The runtime owns what a version contains. `GET .../versions/{v}/files` is the
// only file list — the dashboard browser reads `view=source`, the abuse scanner
// reads `view=served&public_only=1` — and the blob gate's path lane is the
// only way bytes leave, resolved through the SAME resolver so a list row and a
// download can never disagree.
//
// Deliberately NOT here, and not provable here: Range/206, 416, conditional
// 304, and the dashboard CORS header surviving real provider delivery. This
// fixture runs `php -S`, where every request uses the PHP body fallback. The
// credential-gated wp.cloud suite owns both provider facts: ordinary downloads
// retain the Nginx X-Accel lane, while an exact dashboard-origin fetch stays on
// the PHP body lane so wp.cloud cannot strip its CORS header.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, rmSync } from "node:fs";

import {
  api,
  apiJson,
  blobGatePathToken,
  blobPath,
  DASHBOARD_ORIGIN,
  deploy,
  errorCode,
  getBlob,
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
      ".hidden/note.txt": NOTE,
      "sf.jsonc": CONFIG_FILE,
    },
    // Substitution is what makes source and served two different answers for one
    // path: the uploaded byte lands in the catalog's `source`, the compiled byte
    // in its `served`. The version declares its own template; only the values
    // come from outside.
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

test("the catalog is the version's file identity: source is what was uploaded, served is what ships", async () => {
  const catalog = versionCatalog(rt, SPACE, VERSION);
  expect(catalog?.format).toBe("spacefast.runtime.file-catalog.v1");

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

  // The uploaded object, not the compiled one — and the size and type travel
  // with it, so a browser row needs no second lookup.
  const sourceConfig = source.files.find((file) => file.path === "config.js");
  expect(sourceConfig).toEqual({
    path: "config.js",
    size: Buffer.byteLength(UPLOADED_CONFIG),
    sha256: sha256(UPLOADED_CONFIG),
    content_type: expect.stringContaining("javascript"),
    public: true,
  });

  // Visibility is recorded once, by the finalizer, and read here — never
  // recomputed from the path.
  expect(source.files.find((file) => file.path === ".hidden/note.txt")?.public).toBe(false);

  const served = await listFiles("?view=served");
  expect(served.files.find((file) => file.path === "config.js")?.sha256).toBe(
    sha256(SERVED_CONFIG),
  );
  // A private input exists in source and is absent from served: it has no byte a
  // visitor can ever receive.
  expect(served.files.map((file) => file.path)).not.toContain(".hidden/note.txt");
});

test("path, public_only, prefix, q and cursor bound what a reader has to hold", async () => {
  // The scanner's enumeration: served bytes a visitor could actually fetch.
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

  // A per-file lookup is one bounded page, and a path the view does not hold is
  // an empty one — absence is the answer, not an error.
  expect((await listFiles("?path=config.js")).files.map((file) => file.sha256)).toEqual([
    sha256(UPLOADED_CONFIG),
  ]);
  expect((await listFiles("?view=served&path=.hidden/note.txt")).files).toEqual([]);

  // Paging walks the same total order the rows are sorted in, so no row is
  // delivered twice and none is skipped across a boundary.
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

  // A channel selects template-variant bytes, which only exist in the served
  // view; asking for them alongside the uploaded objects is a contradiction, not
  // a silently ignored parameter.
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
  const served = await getBlob(rt, HOST, pathToken("config.js", "served"));
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(SERVED_CONFIG);

  // The same path, the other view, different bytes — this is the whole reason
  // `view` is a claim and not a convenience.
  const source = await getBlob(rt, HOST, pathToken("config.js", "source"));
  expect(source.status).toBe(200);
  expect(await source.text()).toBe(UPLOADED_CONFIG);

  // Unconditional attachment: the gate answers on the public site hostname
  // before any host or access check, so nothing it hands back may run as a page
  // on the Space's own origin. And the pinned dashboard origin rides beside it,
  // because a `fetch()` that cannot read the response cannot render a preview.
  expect(source.headers.get("content-disposition")).toBe("attachment");
  expect(source.headers.get("cache-control")).toBe("private, no-store");
  expect(source.headers.get("access-control-allow-origin")).toBe(DASHBOARD_ORIGIN);
  expect(source.headers.get("etag")).toBe(`"${sha256(UPLOADED_CONFIG)}"`);

  const head = await getBlob(rt, HOST, pathToken("index.html", "source"), { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-length")).toBe(String(Buffer.byteLength(INDEX)));
  expect(head.headers.get("content-disposition")).toBe("attachment");
  expect(await head.text()).toBe("");

  // A private input has a source byte and no served byte, and the gate says so
  // per view rather than per file.
  expect((await getBlob(rt, HOST, pathToken(".hidden/note.txt", "source"))).status).toBe(200);
  const noServed = await getBlob(rt, HOST, pathToken(".hidden/note.txt", "served"));
  expect(noServed.status).toBe(404);
  expect(await errorCode(noServed)).toBe("version_file_not_found");
});

test("a path claim buys exactly one path in one version, and nothing outside it", async () => {
  // Everything that fails BEFORE the capability is verified answers the same
  // bare 404 — never a code, never a hint at which check failed.
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
  // other lane's resolution — a deleted record laundered through a version, or
  // a path answered by a sha the version merely happens to hold. Scope
  // validation refuses the mixture outright.
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

  // Past the capability check the answer is named, because the control plane
  // mints these blind and the holder was already told this triple exists.
  const wrongPath = await getBlob(rt, HOST, pathToken("nope.html", "source"));
  expect(wrongPath.status).toBe(404);
  expect(await errorCode(wrongPath)).toBe("version_file_not_found");

  // A traversal never becomes a filesystem path: it fails normalization, and
  // that is the same stable refusal as an unknown path.
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
  // No `files` and no `retained_files`, with `retention: "all"`: the caller
  // names the version to carry forward and the runtime materializes the path
  // list from its own catalog.
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
  // Retention carries the UPLOADED object forward, not the previous publish's
  // substituted byte — otherwise the last publish's variable values would be
  // baked in permanently.
  expect(retained.files.find((file) => file.path === "config.js")?.sha256).toBe(
    sha256(UPLOADED_CONFIG),
  );
});

// Last on purpose: it unlinks a live CAS object, and every test above needs the
// version's bytes intact.
// The delete case: the runtime has no delete list, so a dropped path is a path
// missing from the retained set. When the publisher declares the only surviving
// file, that set is EMPTY — and an empty set used to be read as "retain
// everything", which republished the very file the publish deleted.
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

test("bytes momentarily unreachable are a 503, never a 404 that reads as deletion", async () => {
  const blob = blobPath(rt, SPACE, sha256(STYLE));
  expect(existsSync(blob)).toBe(true);
  rmSync(blob);

  // Authorization succeeded and the catalog still names the object; a scanner
  // that read this as "file gone" would report a live version's files deleted.
  const unavailable = await getBlob(rt, HOST, pathToken("assets/style.css", "served"));
  expect(unavailable.status).toBe(503);
  expect(unavailable.headers.get("retry-after")).toBe("5");
  expect(unavailable.headers.get("access-control-allow-origin")).toBe(DASHBOARD_ORIGIN);
  await unavailable.text();
});
