// The object lane v2: one per-space record store behind two read lanes — the
// keyed public visitor URL (`/__stattic/u/<id>?k=<runtime read key>`) and the
// authenticated `/storage/<id>` surface — with the read key as the single
// revocable secret. Rotation invalidates every handed-out URL at once.

import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  apiJson,
  deploy,
  errorCode,
  get,
  publicAccessConfig,
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
  rt = await startRuntime();
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

type Uploaded = { id: string; contentType: string; size: number; url: string };

async function upload(body: string, contentType = "text/plain"): Promise<Uploaded> {
  const response = await get(rt, HOST, "/storage", {
    method: "POST",
    headers: { "content-type": contentType },
    body,
  });
  const text = await response.text();
  if (response.status !== 201) throw new Error(`upload -> ${response.status}: ${text}`);
  return JSON.parse(text) as Uploaded;
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
  // The composed URL is the key file's current value — one secret, one source.
  expect(keyOnDisk(rt)).toBe(key);

  const served = await get(rt, HOST, `${url.pathname}${url.search}`);
  expect(served.status).toBe(200);
  expect(served.headers.get("cache-control")).toBe("public, max-age=31536000, immutable");
  expect(await served.text()).toBe("keyed bytes");

  // Wrong key, absent key, unknown id: three identical 404s — the lane leaks
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
  expect(await read.text()).toBe("authed bytes");

  expect((await get(rt, HOST, `/storage/${object.id}`, { method: "DELETE" })).status).toBe(204);
  expect((await get(rt, HOST, `/storage/${object.id}`, { method: "DELETE" })).status).toBe(204);
  expect((await get(rt, HOST, `/storage/${object.id}`)).status).toBe(404);
  // The record died; the keyed visitor URL dies with it.
  const url = new URL(object.url);
  expect((await get(rt, HOST, `${url.pathname}${url.search}`)).status).toBe(404);
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
  // The dev harness has no provider edge; what matters is that a purge was
  // attempted and receipted, not that the fake edge accepted it.
  expect(typeof rotated.purge.status).toBe("string");
  expect(keyOnDisk(rt)).toBe(rotated.key);

  // Every URL handed out under the old key answers 404 now…
  expect((await get(rt, HOST, `${oldUrl.pathname}${oldUrl.search}`)).status).toBe(404);
  // …and the same id serves under the new key: bytes were never the casualty.
  expect((await get(rt, HOST, `${oldUrl.pathname}?k=${rotated.key}`)).status).toBe(200);

  // Fresh-URLs-everywhere: the next upload composes against the new key.
  const next = await upload("post-rotation bytes");
  expect(new URL(next.url).searchParams.get("k")).toBe(rotated.key);
});

const ANON_HOST = "anon-storage.site.test";
const ANON_SPACE = "spc_storage_anon";
const ANON_DAILY_BYTES = 200 * 1024 * 1024;

/** A public space whose access page carries a comments exchange descriptor.
 *  The exchange URLs are deliberately unreachable: the ticket lane mints the
 *  anonymous session BEFORE it calls the control plane, and the session — not
 *  the ticket — is the storage lane's grant. */
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

  // The ticket exchange is unreachable (502) — but the runtime already minted
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

  // The shared daily budget: near the cap, the next anonymous upload refuses.
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

  // Comments off (same space, same credential, same session): the grant dies
  // with the surface — the session alone is not an upload authorization.
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
