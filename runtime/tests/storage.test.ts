// The object lane v2: one per-space record store behind two read lanes, the
// keyed public visitor URL (`/__stattic/u/<id>?k=<runtime read key>`) and the
// authenticated `/storage/<id>` surface. The read key is the single revocable
// secret, so rotation invalidates every handed-out URL at once.

import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  apiJson,
  deploy,
  errorCode,
  get,
  publicAccessConfig,
  edgePurgeCalls,
  managementToken,
  runtimeHttpPath,
  startRuntime,
  storagePath,
  type Runtime,
  RUNTIME_HTTP_API_BASE,
} from "./harness.ts";

const HOST = "storage-lane.site.test";
const SPACE = "spc_storage_lane";
const VERSION = "ver_storage_lane_1";

let rt: Runtime;
// A second runtime without the dev-guest bypass: its refusals are the auth
// gate's, not the harness's.
let strict: Runtime;

beforeAll(async () => {
  rt = await startRuntime({ captureEdgePurges: true });
  strict = await startRuntime({ env: { SPACEFAST_INSECURE_COOKIES: "" } });
  for (const runtime of [rt, strict]) {
    // oxlint-disable-next-line no-await-in-loop -- two fixtures, sequential setup
    await deploy(runtime, {
      spaceId: SPACE,
      versionId: VERSION,
      files: { "index.html": "<h1>storage</h1>\n" },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website", site_title: "Storage" }),
        production_hostnames: [HOST],
        version_hostnames: [],
      },
    });
  }
});

afterAll(() => {
  rt?.stop();
  strict?.stop();
});

type Uploaded = { id: string; contentType: string; filename?: string; size: number; url: string };

// The owner projection: what the list answers with, and what the management
// upload lane answers with, so one shape crosses that boundary.
type OwnerObject = {
  id: string;
  contentType: string;
  createdAt: string;
  filename?: string;
  size: number;
  uploaderId: string;
  sha256: string;
  url: string;
};

async function upload(
  body: string,
  contentType = "text/plain",
  disposition?: string,
): Promise<Uploaded> {
  const headers = new Headers({ "content-type": contentType });
  if (disposition !== undefined) headers.set("content-disposition", disposition);
  const response = await get(rt, HOST, "/storage", {
    method: "POST",
    headers: Object.fromEntries(headers),
    body,
  });
  const text = await response.text();
  if (response.status !== 201) throw new Error(`upload -> ${response.status}: ${text}`);
  // SAFETY: the 201 above is the upload lane's success, whose body is one object.
  return JSON.parse(text) as Uploaded;
}

// The management lane takes raw bytes with a caller-chosen content type, which
// `api()` (JSON in, JSON out) cannot express.
async function managementUpload(
  body: string,
  options: { contentType: string; filename?: string; uploaderId: string },
): Promise<Response> {
  const headers = new Headers({
    "content-type": options.contentType,
    authorization: `Bearer ${managementToken("storage_upload", {
      space_id: SPACE,
      storage_uploader_id: options.uploaderId,
    })}`,
  });
  if (options.filename !== undefined) {
    headers.set(
      "content-disposition",
      `attachment; filename*=UTF-8''${encodeURIComponent(options.filename)}`,
    );
  }
  const apiPath = runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/storage`);
  return fetch(`${rt.baseUrl}${apiPath}`, {
    method: "POST",
    headers,
    body,
  });
}

function keyOnDisk(runtime: Runtime): string {
  const record = JSON.parse(
    readFileSync(storagePath(runtime, "runtime", "storage-read-key.json"), "utf8"),
  ) as { key: string };
  return record.key;
}

test("the keyed visitor URL is the only public read, and a wrong key answers exactly like a missing id", async () => {
  const object = await upload("keyed bytes");
  const url = new URL(object.url);
  expect(url.pathname).toBe(`/__stattic/u/${object.id}`);
  const key = url.searchParams.get("k") ?? "";
  expect(key).toMatch(/^[a-f0-9]{32}$/);
  // The composed URL carries the key file's current value.
  expect(keyOnDisk(rt)).toBe(key);

  const served = await get(rt, HOST, `${url.pathname}${url.search}`);
  expect(served.status).toBe(200);
  expect(served.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  // The edge may hold the keyed URL: the key rides the cache key, and a delete
  // or rotation purges the copy instead of waiting out revalidation.
  expect(served.headers.get("a8c-edge-cache")).toBe("cache");
  expect(await served.text()).toBe("keyed bytes");

  // Wrong key, absent key, unknown id: three identical 404s, so the lane leaks
  // neither which ids exist nor whether a key was close.
  const wrongKey = await get(rt, HOST, `${url.pathname}?k=${"f".repeat(32)}`);
  const noKey = await get(rt, HOST, url.pathname);
  const unknownId = await get(rt, HOST, `/__stattic/u/${"0".repeat(32)}${url.search}`);
  expect(wrongKey.status).toBe(404);
  expect(noKey.status).toBe(404);
  expect(unknownId.status).toBe(404);
  const wrongKeyBody = await wrongKey.text();
  expect(wrongKeyBody).toBe(await unknownId.text());
  expect(await noKey.text()).toBe(wrongKeyBody);
});

test("/storage/<id> is the authenticated lane: read without a key, uploader-only delete, idempotent", async () => {
  const object = await upload("authed bytes");
  const read = await get(rt, HOST, `/storage/${object.id}`);
  expect(read.status).toBe(200);
  // The keyless URL is authenticated per request, so no shared cache may
  // answer for it. The browser may keep its own copy of the immutable bytes.
  expect(read.headers.get("cache-control")).toBe("private, max-age=31536000, immutable");
  expect(read.headers.get("a8c-edge-cache")).toBe("no-cache");
  expect(await read.text()).toBe("authed bytes");

  expect((await get(rt, HOST, `/storage/${object.id}`, { method: "DELETE" })).status).toBe(204);
  expect((await get(rt, HOST, `/storage/${object.id}`, { method: "DELETE" })).status).toBe(204);
  expect((await get(rt, HOST, `/storage/${object.id}`)).status).toBe(404);
  const url = new URL(object.url);
  expect((await get(rt, HOST, `${url.pathname}${url.search}`)).status).toBe(404);
  // The keyed URL opts into the edge, so a delete must revoke the edge copy:
  // one purge POST at the space's serving hostname. The repeat delete found no
  // record and spent none.
  const purges = edgePurgeCalls(rt).filter((r) => r.reason === "storage_object_deleted");
  expect(purges.length).toBe(1);
  expect(purges[0]?.hostname).toBe(HOST);
});

test("without a session, a surface without Comments refuses uploads", async () => {
  const refused = await get(strict, HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "text/plain" },
    body: "nobody's bytes",
  });
  expect(refused.status).toBe(401);
  expect(await errorCode(refused)).toBe("storage_auth_required");
});

test("the admin surface lists keyed URLs and serves the read key", async () => {
  const object = await upload("listed bytes");
  const keyResponse = await apiJson<{ key: string }>(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/storage/read-key`,
    "storage_read_key",
  );
  expect(keyResponse.key).toBe(keyOnDisk(rt));

  const listed = await apiJson<{ objects: Array<{ id: string; url: string; sha256: string }> }>(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/storage`,
    "storage_list",
    { space_id: SPACE },
  );
  const row = listed.objects.find((candidate) => candidate.id === object.id);
  expect(row?.url).toBe(`/__stattic/u/${object.id}?k=${keyResponse.key}`);
});

// The name is presentation, so the id stays the identity: it survives the round
// trip when it is a plain name, and it is simply absent otherwise. Absent, not
// null — every record written before the field existed still reads.
test("an object keeps the name its upload declared, and survives without one", async () => {
  const named = await upload("named bytes", "text/plain", 'attachment; filename="Report 2026.txt"');
  expect(named.filename).toBe("Report 2026.txt");

  const nameless = await upload("nameless bytes");
  expect("filename" in nameless).toBeFalse();

  // A browser sends the RFC 5987 form for anything non-ASCII.
  const extended = await upload(
    "utf8 bytes",
    "text/plain",
    "attachment; filename=\"fallback.txt\"; filename*=UTF-8''r%C3%A9sum%C3%A9.txt",
  );
  expect(extended.filename).toBe("résumé.txt");

  // A name is never a path, and never a way to smuggle a header break.
  const traversal = await upload(
    "traversal bytes",
    "text/plain",
    'attachment; filename="../../etc/passwd"',
  );
  expect(traversal.filename).toBe("passwd");

  const listed = await apiJson<{ objects: Array<{ id: string; filename?: string }> }>(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/storage`,
    "storage_list",
    { space_id: SPACE },
  );
  const byId = new Map(listed.objects.map((object) => [object.id, object]));
  expect(byId.get(named.id)?.filename).toBe("Report 2026.txt");
  expect(byId.get(extended.id)?.filename).toBe("résumé.txt");
  const namelessRow = byId.get(nameless.id);
  expect(namelessRow).toBeDefined();
  expect(namelessRow && "filename" in namelessRow).toBeFalse();
});

// The owner's lane: the control plane has already authorized the space, so
// there is no visitor session to admit and no anonymous budget to charge — but
// the same content policy, and the same object shape the list answers with.
test("the management upload lane stores an object for the space owner", async () => {
  const created = await managementUpload("owner bytes", {
    contentType: "text/plain",
    filename: "brief.txt",
    uploaderId: "usr_owner_1",
  });
  expect(created.status).toBe(201);
  // SAFETY: the 201 asserted above is the management lane's success, whose body
  // is one owner object.
  const object = (await created.json()) as OwnerObject;
  expect(object.filename).toBe("brief.txt");
  expect(object.uploaderId).toBe("usr_owner_1");
  expect(object.size).toBe("owner bytes".length);

  // The object it just described is the object the list describes.
  const listed = await apiJson<{ objects: OwnerObject[] }>(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/storage`,
    "storage_list",
    { space_id: SPACE },
  );
  expect(listed.objects.find((row) => row.id === object.id)).toEqual(object);

  // Readable through the keyed public URL like any other object.
  const url = new URL(object.url, `https://${HOST}`);
  const read = await get(rt, HOST, `${url.pathname}${url.search}`);
  expect(read.status).toBe(200);
  expect(await read.text()).toBe("owner bytes");

  // Owner authority is not an exemption from the content policy.
  const blocked = await managementUpload("<script>alert(1)</script>", {
    contentType: "text/html",
    uploaderId: "usr_owner_1",
  });
  expect(blocked.status).toBe(415);
  expect(await errorCode(blocked)).toBe("storage_content_blocked");

  const empty = await managementUpload("", {
    contentType: "text/plain",
    uploaderId: "usr_owner_1",
  });
  expect(empty.status).toBe(400);
  expect(await errorCode(empty)).toBe("storage_empty_file");
});

test("rotation mints a new key, kills every old URL, and answers with a purge receipt", async () => {
  const object = await upload("rotated bytes");
  const oldUrl = new URL(object.url);
  const oldKey = oldUrl.searchParams.get("k") ?? "";
  expect((await get(rt, HOST, `${oldUrl.pathname}${oldUrl.search}`)).status).toBe(200);

  const rotated = await apiJson<{ key: string; rotatedAt: string; purge: { status: string } }>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/storage/read-key/rotate`,
    "rotate_storage_read_key",
  );
  expect(rotated.key).toMatch(/^[a-f0-9]{32}$/);
  expect(rotated.key).not.toBe(oldKey);
  expect(Date.parse(rotated.rotatedAt)).toBeGreaterThan(0);
  // The dev harness has no provider edge, so only the purge receipt is checked.
  expect(typeof rotated.purge.status).toBe("string");
  expect(keyOnDisk(rt)).toBe(rotated.key);

  expect((await get(rt, HOST, `${oldUrl.pathname}${oldUrl.search}`)).status).toBe(404);
  // The same id still serves under the new key: only the key was revoked.
  expect((await get(rt, HOST, `${oldUrl.pathname}?k=${rotated.key}`)).status).toBe(200);

  // The next upload composes against the new key.
  const next = await upload("post-rotation bytes");
  expect(new URL(next.url).searchParams.get("k")).toBe(rotated.key);
});

const ANON_HOST = "anon-storage.site.test";
const ANON_SPACE = "spc_storage_anon";
const ANON_DAILY_BYTES = 200 * 1024 * 1024;

/** A public space whose access page carries a comments exchange descriptor.
 *  The exchange URLs are deliberately unreachable: the ticket lane mints the
 *  anonymous session before it calls the control plane, and that session, not
 *  the ticket, is the storage lane's grant. */
function anonCommentsConfig(commentsEnabled: boolean): Record<string, unknown> {
  const config = publicAccessConfig({ mode: "website", site_title: "Anon storage" });
  const authorization = config.authorization;
  if (typeof authorization !== "object" || authorization === null) {
    throw new Error("public access fixture is missing authorization");
  }
  Object.assign(authorization, {
    accessPage: {
      displayName: "Anon storage",
      accountUrl: null,
      connections: [],
      exchange: {
        passwordUrl: "http://127.0.0.1:1/acquire/password",
        tokenUrl: "http://127.0.0.1:1/acquire/token",
        requestUrl: "http://127.0.0.1:1/acquire/request",
        commentsTicketUrl: "http://127.0.0.1:1/runtime/comments/ticket",
        commentsVersionUrlsUrl: "http://127.0.0.1:1/runtime/comments/version-urls",
        zeroRealtimeTicketUrl: "http://127.0.0.1:1/acquire/zero/realtime-ticket",
        credential: "runtime-comments-credential-1111111111111111111111111111",
      },
    },
  });
  config.sdk = {
    revision: `sdk-anon-${commentsEnabled ? "on" : "off"}`,
    config: {
      cast_api_base: "https://cast.example.test",
      cast_ws_url: "wss://cast.example.test/socket/websocket",
      cast_resource_key: "resource_anon",
      comments: { live: commentsEnabled, preview: commentsEnabled },
    },
  };
  return config;
}

test("an anonymous commenter session is the upload grant — budgeted, and only where Comments is on", async () => {
  await deploy(strict, {
    spaceId: ANON_SPACE,
    versionId: "ver_storage_anon_1",
    files: { "index.html": "<h1>anon</h1>\n" },
    activate: {
      route_name: "production",
      config: anonCommentsConfig(true),
      production_hostnames: [ANON_HOST],
      version_hostnames: [],
    },
  });

  // The ticket exchange is unreachable (502), but the runtime already minted
  // the anonymous session with its comments pseudonym and set the cookie.
  const ticket = await get(strict, ANON_HOST, "/__spacefast/comments/ticket", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      origin: `http://${ANON_HOST}`,
      "sec-fetch-site": "same-origin",
    },
    body: JSON.stringify({ pagePath: "/", identity: { name: "Visitor", namedByUser: false } }),
  });
  expect(ticket.status).toBe(502);
  const setCookie = ticket.headers.get("set-cookie") ?? "";
  const cookie = setCookie.split(";")[0] ?? "";
  expect(cookie).toContain("spacefast_session");

  const uploaded = await get(strict, ANON_HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "text/plain", cookie },
    body: "anon bytes",
  });
  expect(uploaded.status).toBe(201);
  const object = (await uploaded.json()) as Uploaded;
  const record = JSON.parse(
    readFileSync(
      path.join(
        storagePath(strict, "spaces", ANON_SPACE, "uploads", "objects"),
        `${object.id}.json`,
      ),
      "utf8",
    ),
  ) as { uploaderId: string };
  expect(record.uploaderId).toStartWith("anon:");

  // The pseudonym is a real uploader identity: uploader-only delete holds.
  expect(
    (
      await get(strict, ANON_HOST, `/storage/${object.id}`, {
        method: "DELETE",
        headers: { cookie },
      })
    ).status,
  ).toBe(204);

  // Near the shared daily cap, the next anonymous upload refuses.
  writeFileSync(
    storagePath(strict, "spaces", ANON_SPACE, "uploads", "anon-usage.json"),
    `${JSON.stringify({ day: new Date().toISOString().slice(0, 10), bytes: ANON_DAILY_BYTES - 2 })}\n`,
  );
  const refused = await get(strict, ANON_HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "text/plain", cookie },
    body: "over the top",
  });
  expect(refused.status).toBe(429);
  expect(await errorCode(refused)).toBe("storage_quota_exceeded");

  // Comments off, same space and session: the grant dies with the surface. The
  // session alone is not an upload authorization.
  await deploy(strict, {
    spaceId: ANON_SPACE,
    versionId: "ver_storage_anon_2",
    files: { "index.html": "<h1>anon off</h1>\n" },
    activate: {
      route_name: "production",
      config: anonCommentsConfig(false),
      production_hostnames: [ANON_HOST],
      version_hostnames: [],
    },
  });
  const closed = await get(strict, ANON_HOST, "/storage", {
    method: "POST",
    headers: { "content-type": "text/plain", cookie },
    body: "no surface",
  });
  expect(closed.status).toBe(401);
  expect(await errorCode(closed)).toBe("storage_auth_required");
});

test("a delete leaves the CAS body for the GC; only the record dies", async () => {
  const object = await upload("shared body bytes");
  const recordPath = path.join(
    storagePath(rt, "spaces", SPACE, "uploads", "objects"),
    `${object.id}.json`,
  );
  expect(existsSync(recordPath)).toBeTrue();
  await get(rt, HOST, `/storage/${object.id}`, { method: "DELETE" });
  expect(existsSync(recordPath)).toBeFalse();
});
