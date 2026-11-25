import { afterAll, beforeAll, expect, test } from "bun:test";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";

import { strToU8, zipSync } from "fflate";
import { z } from "zod";

import { FUNCTIONS_BUNDLE_MAX_BYTES } from "@spacefast/common/contracts/functions";
import { MAX_VERSION_TOTAL_BYTES } from "@spacefast/common/utils/publish-policy";
import { runtimeUploadFileUrl } from "@spacefast/common/utils/runtime-upload";

import {
  api,
  apiJson,
  blobGateToken,
  blobPath,
  blobsHave,
  blobsHaveMissing,
  createDeclaredSession,
  type DeployFiles,
  errorCode,
  finalize,
  getBlob,
  journalRecords,
  managementToken,
  PHP_BINARY,
  RUNTIME_HOST,
  manifestFor,
  putBlob,
  readBlob,
  RUNTIME_HTTP_API_BASE,
  RUNTIME_UPLOAD_API_BASE,
  type Runtime,
  runtimeHttpPath,
  selfSignedCertificate,
  sha256,
  startRuntime,
  storagePath,
  uploadSessionBlobs,
  uploadToken,
  versionRoot,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_up";
const ADMISSION_SPACE = "spc_up_adm";
const INGEST_LIMIT = 4;

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => rt?.stop());

function uploadUrl(subPath: string): string {
  return `${rt.baseUrl}${runtimeHttpPath(`${RUNTIME_UPLOAD_API_BASE}${subPath}`)}`;
}

function managementUrl(subPath: string): string {
  return `${rt.baseUrl}${runtimeHttpPath(`${RUNTIME_HTTP_API_BASE}${subPath}`)}`;
}

test("upload preflight is answered before compact-route parsing and authorization", async () => {
  const response = await fetch(`${rt.baseUrl}${RUNTIME_UPLOAD_API_BASE}?op=file`, {
    method: "OPTIONS",
    headers: {
      origin: "https://my.spacefast.com",
      "access-control-request-method": "PUT",
      "access-control-request-headers": "authorization",
    },
  });

  expect(response.status).toBe(204);
  expect(response.headers.get("access-control-allow-origin")).toBe("https://my.spacefast.com");
  expect(response.headers.get("access-control-allow-methods")).toContain("PUT");
  const allowed = (response.headers.get("access-control-allow-headers") ?? "").split(/,\s*/);
  expect(allowed).toEqual(
    expect.arrayContaining(["Authorization", "X-SF-Authorization", "SF-Authorization"]),
  );
});

async function declareManifest(
  versionId: string,
  files: DeployFiles,
  spaceId = SPACE,
): Promise<Response> {
  return api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${spaceId}/versions`,
    "create_version",
    { space_id: spaceId },
    { version_id: versionId, files: manifestFor(files) },
  );
}

test("upload tokens are verified: bearer, audience, instance, expiry, signature", async () => {
  const content = "<h1>auth</h1>\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_auth", { "index.html": content });
  const digest = sha256(content);
  const put = (token: string | null) =>
    fetch(uploadUrl(`/spaces/${SPACE}/blobs/${digest}`), {
      method: "PUT",
      headers: token === null ? {} : { authorization: `Bearer ${token}` },
      body: content,
    });

  const anonymous = await put(null);
  expect(anonymous.status).toBe(401);
  expect(await errorCode(anonymous)).toBe("runtime_upload_bearer_required");

  const wrongInstance = await put(
    uploadToken(SPACE, session.uploadId, session.versionId, {
      runtime_instance_id: "rti_other",
    }),
  );
  expect(wrongInstance.status).toBe(403);
  expect(await errorCode(wrongInstance)).toBe("runtime_instance_mismatch");

  const expired = await put(
    uploadToken(SPACE, session.uploadId, session.versionId, {
      exp: Math.floor(Date.now() / 1000) - 60,
    }),
  );
  expect(expired.status).toBe(401);
  expect(await errorCode(expired)).toBe("runtime_token_expired");

  const [header, payload] = session.token.split(".");
  const forged = await put(`${header}.${payload}.${Buffer.alloc(64).toString("base64url")}`);
  expect(forged.status).toBe(401);
  expect(await errorCode(forged)).toBe("runtime_token_bad_signature");

  expect((await put(session.token)).status).toBe(200);
});

test("upload token scope is pinned to its space, session and version", async () => {
  const content = "<h1>scope</h1>\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_scope", { "index.html": content });
  const digest = sha256(content);
  const put = (token: string) => putBlob(rt, SPACE, token, digest, content);

  const otherSpace = await put(uploadToken("spc_other", session.uploadId, session.versionId));
  expect(otherSpace.status).toBe(403);
  expect(await errorCode(otherSpace)).toBe("upload_scope_forbidden");

  const noSession = await put(uploadToken(SPACE, "", session.versionId));
  expect(noSession.status).toBe(403);
  expect(await errorCode(noSession)).toBe("upload_scope_forbidden");

  const otherVersion = await put(uploadToken(SPACE, session.uploadId, "ver_other"));
  expect(otherVersion.status).toBe(403);
  expect(await errorCode(otherVersion)).toBe("upload_scope_forbidden");

  const unknownSession = await put(uploadToken(SPACE, "upl_ghost", session.versionId));
  expect(unknownSession.status).toBe(404);
  expect(await errorCode(unknownSession)).toBe("upload_not_found");
});

test("management JWTs enforce action scope and reject jti replay", async () => {
  const token = managementToken("create_version", { space_id: SPACE });
  const create = (body: Record<string, unknown>) =>
    fetch(managementUrl(`/spaces/${SPACE}/versions`), {
      method: "POST",
      headers: { "content-type": "application/json", authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });

  const first = await create({ version_id: "ver_replay", files: [] });
  expect(first.status).toBe(201);
  const replayed = await create({ version_id: "ver_replay2", files: [] });
  expect(replayed.status).toBe(403);
  expect(await errorCode(replayed)).toBe("runtime_jti_replayed");

  const wrongAction = await fetch(managementUrl(`/spaces/${SPACE}/versions/ver_replay/finalize`), {
    method: "POST",
    headers: {
      "content-type": "application/json",
      authorization: `Bearer ${managementToken("create_version", { space_id: SPACE })}`,
    },
    body: "{}",
  });
  expect(wrongAction.status).toBe(403);
  expect(await errorCode(wrongAction)).toBe("runtime_action_forbidden");

  const wrongScope = await fetch(managementUrl(`/spaces/${SPACE}/versions`), {
    method: "POST",
    headers: {
      "content-type": "application/json",
      authorization: `Bearer ${managementToken("create_version", { space_id: "spc_other" })}`,
    },
    body: JSON.stringify({ files: [] }),
  });
  expect(wrongScope.status).toBe(403);
  expect(await errorCode(wrongScope)).toBe("runtime_scope_forbidden");
});

test("create version replays return the original upload session and reject changed intent", async () => {
  const versionId = "ver_create_idempotent";
  const body = {
    version_id: versionId,
    files: manifestFor({ "index.html": "idempotent\n" }),
    retention: "none",
    metadata: { nested: { b: 2, a: 1 } },
  };
  const create = (nextBody: typeof body) =>
    api(
      rt,
      "POST",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
      "create_version",
      { space_id: SPACE },
      nextBody,
    );

  const firstReceipt = await apiJson<{ upload_id: string; created_at: string }>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    body,
    201,
  );

  const replay = await create({
    ...body,
    metadata: { nested: { a: 1, b: 2 } },
  });
  expect(replay.status).toBe(200);
  expect(await replay.json()).toMatchObject(firstReceipt);

  const conflict = await create({
    ...body,
    files: manifestFor({ "index.html": "changed!!!\n" }),
  });
  expect(conflict.status).toBe(409);
  expect(await errorCode(conflict)).toBe("version_create_conflict");
});

test("a version's session is found through its pointer, and sessions from before the index are indexed once", async () => {
  const versionId = "ver_indexed";
  const files = { "index.html": "indexed\n" };
  const indexRoot = storagePath(rt, "spaces", SPACE, "publish-session-index");
  const pointerPath = path.join(indexRoot, `${versionId}.json`);
  const markerPath = path.join(indexRoot, ".indexed");
  const pointerSchema = z.object({ upload_id: z.string() });
  const readPointer = () => pointerSchema.parse(JSON.parse(readFileSync(pointerPath, "utf8")));
  const getUploads = () =>
    api(
      rt,
      "GET",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/uploads`,
      "get_upload_session",
      { space_id: SPACE, version_id: versionId },
    );
  const indexBuilds = () =>
    journalRecords(rt).filter(
      (event) => event.event === "publish_session_index_built" && event.space_id === SPACE,
    ).length;

  const first = await createDeclaredSession(rt, SPACE, versionId, files);
  expect(readPointer().upload_id).toBe(first.uploadId);

  rmSync(indexRoot, { recursive: true, force: true });
  const buildsBefore = indexBuilds();
  const found = await getUploads();
  expect(found.status).toBe(200);
  expect(pointerSchema.parse(await found.json()).upload_id).toBe(first.uploadId);
  expect(readPointer().upload_id).toBe(first.uploadId);
  expect(existsSync(markerPath)).toBe(true);
  expect(indexBuilds()).toBe(buildsBefore + 1);

  const unknown = await api(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/ver_never_declared/uploads`,
    "get_upload_session",
    { space_id: SPACE, version_id: "ver_never_declared" },
  );
  expect(unknown.status).toBe(404);
  expect(await errorCode(unknown)).toBe("version_upload_not_found");
  expect(indexBuilds()).toBe(buildsBefore + 1);

  rmSync(storagePath(rt, "spaces", SPACE, "publish-sessions", `${first.uploadId}.json`));
  const gone = await getUploads();
  expect(gone.status).toBe(404);
  expect(readPointer().upload_id).toBe(first.uploadId);
  const second = await createDeclaredSession(rt, SPACE, versionId, files);
  expect(second.uploadId).not.toBe(first.uploadId);
  expect(readPointer().upload_id).toBe(second.uploadId);
  expect(indexBuilds()).toBe(buildsBefore + 1);

  await uploadSessionBlobs(rt, second, files);
  const finalized = await finalize(rt, SPACE, versionId, { upload_id: second.uploadId });
  expect(finalized.status).toBe(200);
  expect(existsSync(pointerPath)).toBe(false);
  const released = await getUploads();
  expect(released.status).toBe(404);
});

test("a session is never written without its pointer, and a legacy walk that could not read a record leaves the marker", async () => {
  const indexRoot = storagePath(rt, "spaces", SPACE, "publish-session-index");
  const sessionsRoot = storagePath(rt, "spaces", SPACE, "publish-sessions");
  const markerPath = path.join(indexRoot, ".indexed");
  const walks = () =>
    journalRecords(rt).filter(
      (event) => event.event === "publish_session_index_built" && event.space_id === SPACE,
    );

  rmSync(indexRoot, { recursive: true, force: true });
  writeFileSync(indexRoot, "");
  const sessionsBefore = readdirSync(sessionsRoot).length;
  try {
    const refused = await api(
      rt,
      "POST",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
      "create_version",
      { space_id: SPACE },
      { version_id: "ver_unpointed", files: manifestFor({ "index.html": "no pointer\n" }) },
    );
    expect(refused.status).toBe(500);
  } finally {
    rmSync(indexRoot, { force: true });
  }
  expect(readdirSync(sessionsRoot).length).toBe(sessionsBefore);

  const readable = await createDeclaredSession(rt, SPACE, "ver_legacy_readable", {
    "index.html": "readable\n",
  });
  const blocked = await createDeclaredSession(rt, SPACE, "ver_legacy_blocked", {
    "index.html": "blocked\n",
  });
  rmSync(indexRoot, { recursive: true, force: true });
  const blockedRecord = path.join(sessionsRoot, `${blocked.uploadId}.json`);
  chmodSync(blockedRecord, 0o000);
  try {
    const found = await api(
      rt,
      "GET",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/ver_legacy_readable/uploads`,
      "get_upload_session",
      { space_id: SPACE, version_id: "ver_legacy_readable" },
    );
    expect(found.status).toBe(200);
    expect(z.object({ upload_id: z.string() }).parse(await found.json()).upload_id).toBe(
      readable.uploadId,
    );
    expect(walks().at(-1)).toMatchObject({ complete: false });
    expect(existsSync(markerPath)).toBe(false);
  } finally {
    chmodSync(blockedRecord, 0o644);
  }
  writeFileSync(path.join(sessionsRoot, "upl_malformed_legacy.json"), "{not json");
  const unblocked = await api(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/ver_legacy_blocked/uploads`,
    "get_upload_session",
    { space_id: SPACE, version_id: "ver_legacy_blocked" },
  );
  expect(unblocked.status).toBe(200);
  expect(z.object({ upload_id: z.string() }).parse(await unblocked.json()).upload_id).toBe(
    blocked.uploadId,
  );
  expect(walks().at(-1)).toMatchObject({ complete: true });
  expect(existsSync(markerPath)).toBe(true);

  const pointerPath = path.join(indexRoot, "ver_legacy_readable.json");
  const walksBefore = walks().length;
  chmodSync(pointerPath, 0o000);
  try {
    const unreadablePointer = await api(
      rt,
      "GET",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/ver_legacy_readable/uploads`,
      "get_upload_session",
      { space_id: SPACE, version_id: "ver_legacy_readable" },
    );
    expect(unreadablePointer.status).toBe(200);
    expect(
      z.object({ upload_id: z.string() }).parse(await unreadablePointer.json()).upload_id,
    ).toBe(readable.uploadId);
  } finally {
    if (existsSync(pointerPath)) {
      chmodSync(pointerPath, 0o644);
    }
  }
  expect(walks().length).toBe(walksBefore + 1);
  expect(JSON.parse(readFileSync(pointerPath, "utf8"))).toMatchObject({
    upload_id: readable.uploadId,
  });
});

test("releasing an expired session leaves the version's pointer to create-version", async () => {
  const versionId = "ver_expired_release";
  const files = { "index.html": "expired\n" };
  const content = files["index.html"];
  const expired = await createDeclaredSession(rt, SPACE, versionId, files, {
    expiresAt: new Date(Date.now() - 60_000).toISOString(),
  });
  const pointerPath = storagePath(
    rt,
    "spaces",
    SPACE,
    "publish-session-index",
    `${versionId}.json`,
  );
  writeFileSync(
    pointerPath,
    JSON.stringify({
      upload_id: "upl_replacement",
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
    }),
  );
  const put = await putBlob(rt, SPACE, expired.token, sha256(content), content);
  expect(put.status).toBe(404);
  const record = storagePath(rt, "spaces", SPACE, "publish-sessions", `${expired.uploadId}.json`);
  if (existsSync(record)) {
    const kept = z
      .object({ expires_at: z.string() })
      .parse(JSON.parse(readFileSync(record, "utf8")));
    expect(Date.parse(kept.expires_at)).toBeLessThan(Date.now());
  }
  expect(
    z.object({ upload_id: z.string() }).parse(JSON.parse(readFileSync(pointerPath, "utf8")))
      .upload_id,
  ).toBe("upl_replacement");
  rmSync(pointerPath);
});

test("build source archives persist, stream, scope, and delete on the provider runtime", async () => {
  const buildId = "bld_source_archive";
  const bytes = Buffer.from("provider-owned build source\n");
  const route = `/spaces/${SPACE}/build-sources/${buildId}`;
  const auth = (action: string, scopedBuildId = buildId) => ({
    authorization: `Bearer ${managementToken(action, {
      space_id: SPACE,
      build_id: scopedBuildId,
    })}`,
  });

  const put = await fetch(managementUrl(route), {
    method: "PUT",
    headers: { ...auth("build_source_put"), "content-type": "application/gzip" },
    body: bytes,
  });
  expect(put.status).toBe(200);
  expect(await put.json()).toEqual({ sha256: sha256(bytes), size: bytes.byteLength });

  const metadata = await fetch(managementUrl(route), {
    headers: auth("build_source_get"),
  });
  expect(metadata.status).toBe(200);
  expect(await metadata.json()).toEqual({ sha256: sha256(bytes), size: bytes.byteLength });

  const body = await fetch(managementUrl(`${route}/body`), {
    headers: auth("build_source_read"),
  });
  expect(body.status).toBe(200);
  expect(body.headers.get("content-type")).toBe("application/gzip");
  expect(Buffer.from(await body.arrayBuffer())).toEqual(bytes);

  const wrongScope = await fetch(managementUrl(route), {
    headers: auth("build_source_get", "bld_other"),
  });
  expect(wrongScope.status).toBe(403);
  expect(await errorCode(wrongScope)).toBe("runtime_scope_forbidden");

  const deleted = await fetch(managementUrl(route), {
    method: "DELETE",
    headers: auth("build_source_delete"),
  });
  expect(deleted.status).toBe(200);
  expect(await deleted.json()).toEqual({ build_id: buildId, deleted: true });

  const missing = await fetch(managementUrl(route), {
    headers: auth("build_source_get"),
  });
  expect(missing.status).toBe(404);
  expect(await errorCode(missing)).toBe("build_source_not_found");
});

test("a token for one auth lane never authorizes another lane's route", async () => {
  const content = "<h1>lanes</h1>\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_lanes", { "index.html": content });
  const digest = sha256(content);
  const gateToken = blobGateToken(SPACE, digest, { versionId: session.versionId });

  const managementRoute = managementUrl(`/spaces/${SPACE}/versions/${session.versionId}/files`);
  for (const crossToken of [session.token, gateToken]) {
    const crossManagement = await fetch(managementRoute, {
      headers: { authorization: `Bearer ${crossToken}` },
    });
    expect(crossManagement.status).toBe(401);
    expect(await errorCode(crossManagement)).toBe("runtime_token_expired");
  }

  for (const crossToken of [managementToken("read_state"), gateToken]) {
    const crossPut = await putBlob(rt, SPACE, crossToken, digest, content);
    expect(crossPut.status).toBe(401);
    expect(await errorCode(crossPut)).toBe("runtime_token_expired");

    const crossHave = await blobsHave(rt, { spaceId: SPACE, token: crossToken }, [digest]);
    expect(crossHave.status).toBe(401);
    expect(await errorCode(crossHave)).toBe("runtime_token_expired");
  }

  expect((await putBlob(rt, SPACE, session.token, digest, content)).status).toBe(200);
});

test("blob negotiation reports the missing shas and lets an interrupted publish resume", async () => {
  const files = {
    "index.html": "<h1>resume</h1>\n",
    "app.js": "console.log('resume');\n",
  };
  const indexSha = sha256(files["index.html"]);
  const appSha = sha256(files["app.js"]);
  const session = await createDeclaredSession(rt, SPACE, "ver_resume", files);

  expect((await blobsHaveMissing(rt, session, [indexSha, appSha])).toSorted()).toEqual(
    [indexSha, appSha].toSorted(),
  );

  const notAList = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/have`), {
    method: "POST",
    headers: { "content-type": "application/json", authorization: `Bearer ${session.token}` },
    body: JSON.stringify({ shas: indexSha }),
  });
  expect(notAList.status).toBe(422);
  expect(await errorCode(notAList)).toBe("invalid_blob_shas");

  const badSha = await blobsHave(rt, session, ["not-a-sha"]);
  expect(badSha.status).toBe(422);
  expect(await errorCode(badSha)).toBe("invalid_blob_sha");

  expect((await putBlob(rt, SPACE, session.token, indexSha, files["index.html"])).status).toBe(200);

  expect(await blobsHaveMissing(rt, session, [indexSha, appSha])).toEqual([appSha]);
  await uploadSessionBlobs(rt, session, files);
  const finalized = await finalize(rt, SPACE, "ver_resume", { upload_id: session.uploadId });
  expect(finalized.status).toBe(200);

  const deduped = await createDeclaredSession(rt, SPACE, "ver_resume_dedupe", files);
  expect(await blobsHaveMissing(rt, deduped, [indexSha, appSha])).toEqual([]);
  const dedupedFinalize = await finalize(rt, SPACE, "ver_resume_dedupe", {
    upload_id: deduped.uploadId,
  });
  expect(dedupedFinalize.status).toBe(200);
});

test("bulk CAS ZIP accepts declared blobs in one upload and completes the session", async () => {
  const files = {
    "index.html": "<h1>bulk zip</h1>\n",
    "assets/app.js": "const highlyCompressibleBuildOutput = true;\n".repeat(20_000),
  };
  const session = await createDeclaredSession(rt, SPACE, "ver_bulk_zip", files);
  const archive = zipSync(
    Object.fromEntries(Object.values(files).map((content) => [sha256(content), strToU8(content)])),
  );

  const uploaded = await fetch(uploadUrl(`/${session.uploadId}/blobs`), {
    method: "PUT",
    headers: {
      authorization: `Bearer ${session.token}`,
      "content-type": "application/zip",
      "content-length": String(archive.byteLength),
    },
    body: archive,
  });

  expect({ status: uploaded.status, body: await uploaded.json() }).toEqual({
    status: 200,
    body: {
      ok: true,
      accepted: {
        blobs: 2,
        bytes: Buffer.byteLength(files["index.html"] + files["assets/app.js"]),
      },
    },
  });
  expect((await finalize(rt, SPACE, "ver_bulk_zip", { upload_id: session.uploadId })).status).toBe(
    200,
  );
});

test("a CAS object damaged to another length is renegotiated and repaired, not deduped away", async () => {
  const files = {
    "index.html": "<h1>damaged</h1>\n",
    "confetti.js": "console.log('confetti');\n",
  };
  const confettiSha = sha256(files["confetti.js"]);
  const confettiSize = Buffer.byteLength(files["confetti.js"]);
  const base = await createDeclaredSession(rt, SPACE, "ver_cas_damage_base", files);
  await uploadSessionBlobs(rt, base, files);
  expect(
    (await finalize(rt, SPACE, "ver_cas_damage_base", { upload_id: base.uploadId })).status,
  ).toBe(200);

  writeFileSync(blobPath(rt, SPACE, confettiSha), "");
  expect(readBlob(rt, SPACE, confettiSha)?.length).toBe(0);

  const repair = await createDeclaredSession(rt, SPACE, "ver_cas_damage_repair", files);
  expect(await blobsHaveMissing(rt, repair, [confettiSha])).toEqual([confettiSha]);
  expect((await putBlob(rt, SPACE, repair.token, confettiSha, files["confetti.js"])).status).toBe(
    200,
  );
  expect(readBlob(rt, SPACE, confettiSha)?.length).toBe(confettiSize);

  await uploadSessionBlobs(rt, repair, files);
  expect(
    (await finalize(rt, SPACE, "ver_cas_damage_repair", { upload_id: repair.uploadId })).status,
  ).toBe(200);
});

test("an open session is what authorizes reads of the bytes it accepted", async () => {
  const config = '{ "headers": [] }\n';
  const files = { "index.html": "<h1>session gate</h1>\n", "sf.jsonc": config };
  const versionId = "ver_session_gate";
  const session = await createDeclaredSession(rt, SPACE, versionId, files);
  const digest = sha256(config);
  const fromSession = () =>
    getBlob(rt, RUNTIME_HOST, blobGateToken(SPACE, digest, { upload: session.uploadId }));
  const fromVersion = () => getBlob(rt, RUNTIME_HOST, blobGateToken(SPACE, digest, { versionId }));
  const fromManagement = (maxBytes = 64 * 1024) =>
    fetch(
      managementUrl(
        `/spaces/${SPACE}/versions/${versionId}/source?upload_id=${session.uploadId}&sha256=${digest}&max_bytes=${maxBytes}`,
      ),
      {
        headers: {
          authorization: `Bearer ${managementToken("read_version_source", {
            space_id: SPACE,
            version_id: versionId,
          })}`,
        },
      },
    );

  expect((await fromSession()).status).toBe(404);
  const unavailableManagementSource = await fromManagement();
  expect({
    status: unavailableManagementSource.status,
    body: await unavailableManagementSource.text(),
  }).toEqual({ status: 204, body: "" });

  await uploadSessionBlobs(rt, session, files);

  const served = await fromSession();
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(config);

  const managementSource = await fromManagement();
  const managementBody = await managementSource.text();
  expect({
    status: managementSource.status,
    contentType: managementSource.headers.get("content-type"),
    cacheControl: managementSource.headers.get("cache-control"),
    contentLength: managementSource.headers.get("content-length"),
    nosniff: managementSource.headers.get("x-content-type-options"),
    body: managementBody,
  }).toEqual({
    status: 200,
    contentType: "application/octet-stream",
    cacheControl: "private, no-store",
    contentLength: String(Buffer.byteLength(config)),
    nosniff: "nosniff",
    body: config,
  });
  expect((await fromManagement(config.length - 1)).status).toBe(204);
  expect((await fromVersion()).status).toBe(404);

  expect((await finalize(rt, SPACE, versionId, { upload_id: session.uploadId })).status).toBe(200);

  expect((await fromVersion()).status).toBe(200);
  expect((await fromSession()).status).toBe(404);
  expect((await fromManagement()).status).toBe(204);
});

test("retained-only lazy finalize preserves the empty accepted object", async () => {
  const files = { "retained.html": "<h1>retained lazy session</h1>\n" };
  const reusableVersionId = "ver_lazy_retained_base";
  const base = await createDeclaredSession(rt, SPACE, reusableVersionId, files);
  await uploadSessionBlobs(rt, base, files);
  expect(
    (
      await finalize(rt, SPACE, reusableVersionId, {
        upload_id: base.uploadId,
      })
    ).status,
  ).toBe(200);

  const versionId = "ver_lazy_retained_next";
  const uploadId = "upl_lazy_retained_next";
  const finalized = await finalize(rt, SPACE, versionId, {
    upload_id: uploadId,
    session: {
      upload_id: uploadId,
      space_id: SPACE,
      version_id: versionId,
      files: [],
      retention: "list",
      retained_files: manifestFor(files),
      reusable_version_id: reusableVersionId,
    },
  });

  expect(finalized.status).toBe(200);
});

test("lazy finalize refreshes the locked session and restores a missing blob pin when retention is unchanged", async () => {
  const versionId = "ver_lazy_pin_recover";
  const uploadId = "upl_lazy_pin_recover";
  const files = {
    "index.html": "<h1>lazy pin recovery</h1>\n",
    "second.html": "<h1>second file</h1>\n",
  };
  const manifest = manifestFor(files);
  const descriptor = {
    upload_id: uploadId,
    space_id: SPACE,
    version_id: versionId,
    files: manifest,
    retention: "none",
    retained_files: [],
    reusable_version_id: null,
  };
  const token = uploadToken(SPACE, uploadId, versionId, { session: descriptor });
  const firstSha = sha256(files["index.html"]);
  const secondSha = sha256(files["second.html"]);
  expect((await putBlob(rt, SPACE, token, firstSha, files["index.html"])).status).toBe(200);

  // Confirm the real pin record exists, then delete it to simulate an
  // interrupted session creation that persisted the session without its pin.
  const pinPath = storagePath(rt, "spaces", SPACE, "pins", `publish-${uploadId}.json`);
  expect(existsSync(pinPath)).toBe(true);
  rmSync(pinPath);
  expect(existsSync(pinPath)).toBe(false);

  // Finalize while second.html is still missing: `_stattic_runtime_publish_session_sync_retention`
  // runs before the completeness refusal and must restore the missing pin on disk.
  const incomplete = await finalize(rt, SPACE, versionId, {
    upload_id: uploadId,
    session: descriptor,
  });
  expect(incomplete.status).toBe(409);
  expect(existsSync(pinPath)).toBe(true);
  const restoredPin = z
    .object({ shas: z.array(z.string()) })
    .parse(JSON.parse(readFileSync(pinPath, "utf8")));
  expect(restoredPin.shas).toContain(firstSha);

  // Uploading the remaining file and finalizing completes cleanly and releases the pin.
  expect((await putBlob(rt, SPACE, token, secondSha, files["second.html"])).status).toBe(200);
  const finalized = await finalize(rt, SPACE, versionId, {
    upload_id: uploadId,
    session: descriptor,
  });
  expect(finalized.status).toBe(200);
  expect(existsSync(pinPath)).toBe(false);
});

test("naming a reusable version without a retention mode is refused", async () => {
  const refused = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    {
      version_id: "ver_retention_unstated",
      files: [],
      reusable_version_id: "ver_lazy_retained_base",
    },
  );

  expect(refused.status).toBe(422);
  expect(await errorCode(refused)).toBe("retention_required");
});

test("blob PUT verifies the streamed bytes against the URL sha and the declared size", async () => {
  const content = "<h1>declared</h1>\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_bytes", { "index.html": content });
  const digest = sha256(content);
  const put = (sha: string, body: string) => putBlob(rt, SPACE, session.token, sha, body);

  const undeclared = await put(sha256("nope"), "nope");
  expect(undeclared.status).toBe(422);
  expect(await errorCode(undeclared)).toBe("upload_sha_not_declared");

  const oversized = await put(digest, `${content}overflow`);
  expect(oversized.status).toBe(422);
  expect(await errorCode(oversized)).toBe("upload_size_mismatch");

  const undersized = await put(digest, content.slice(0, -1));
  expect(undersized.status).toBe(422);
  expect(await errorCode(undersized)).toBe("upload_size_mismatch");

  const wrongHash = await put(digest, "<h1>tampered</h1>\n");
  expect(wrongHash.status).toBe(422);
  expect(await errorCode(wrongHash)).toBe("upload_hash_mismatch");

  const ok = await put(digest, content);
  expect(ok.status).toBe(200);
  expect(ok.headers.get("etag")).toBe(`"${digest}"`);
  expect(await ok.json()).toEqual({ ok: true, sha256: digest, size: content.length });
});

test("path PUT hashes an undeclared digest and binds it into the publish session", async () => {
  const content = "<h1>hash at runtime</h1>\n";
  const versionId = "ver_hashless_path";
  const filePath = "docs/café file.html";
  const session = await createDeclaredSession(
    rt,
    SPACE,
    versionId,
    { [filePath]: content },
    {
      extra: {
        files: [{ path: filePath, size: Buffer.byteLength(content) }],
      },
    },
  );
  const response = await fetch(
    runtimeUploadFileUrl({ baseUrl: rt.baseUrl, uploadId: session.uploadId, path: filePath }),
    {
      method: "PUT",
      headers: { authorization: `Bearer ${session.token}` },
      body: content,
    },
  );

  const digest = sha256(content);
  expect(response.status).toBe(200);
  expect(response.headers.get("etag")).toBe(`"${digest}"`);
  expect(await response.json()).toEqual({ ok: true, sha256: digest, size: content.length });

  const state = await apiJson<{
    files: Array<{ path: string; size: number; sha256?: string; uploaded: boolean }>;
  }>(
    rt,
    "GET",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/uploads`,
    "get_upload_session",
    { space_id: SPACE, version_id: versionId },
  );
  expect(state.files).toEqual([
    { path: filePath, size: content.length, sha256: digest, uploaded: true },
  ]);
});

test("finalize consumes the publish session, is idempotent, and journals once", async () => {
  const content = "<h1>finalize</h1>\n";
  const redirects = "/api/* https://api.example.com/:splat 200\n";
  const headers = "/*\n  x-region: {{ vars.REGION }}\n";
  const versionId = "ver_final";
  const files = { "index.html": content, _redirects: redirects, _headers: headers };
  const session = await createDeclaredSession(rt, SPACE, versionId, files);
  const finalizeBody = {
    upload_id: session.uploadId,
    // Empty maps must survive the PHP -> native boundary as objects rather
    // than becoming JSON lists. Resolution falls through the empty space scope
    // to the principal scope, whose empty channel map exercises the same seam.
    variable_scopes: [
      { kind: "space", values: {} },
      {
        kind: "principal",
        values: { REGION: { value: "eu", secret: false, channelValues: {} } },
      },
    ],
    system_variables: {},
  };

  const incomplete = await finalize(rt, SPACE, versionId, { upload_id: session.uploadId });
  expect(incomplete.status).toBe(409);
  const incompletePayload = (await incomplete.json()) as {
    code: string;
    details: Record<string, unknown>;
  };
  expect(incompletePayload.code).toBe("version_upload_incomplete");
  expect(incompletePayload.details).toEqual({
    missingPaths: ["index.html", "_redirects", "_headers"],
    missingCount: 3,
  });

  await uploadSessionBlobs(rt, session, files);

  type FinalizeResult = {
    status: string;
    manifest: Array<{ path: string; size: number; sha256: string; contentType: string }>;
    catalog_digests: { source: string; served: string };
    variable_digests: Record<string, string>;
    system_variable_dependencies: string[];
    routing: {
      redirect_rule_count: number;
      header_rule_count: number;
      proxy_rule_count: number;
      proxy_rules: Array<{ source: string; destination: string }>;
      placement: Array<{
        kind: string;
        origin: string;
        ruleOrder: number;
        at: string;
        reason?: string;
        source: string;
        destination?: string;
        status?: number;
        headers?: string[];
      }>;
    };
    route_inventory: {
      format: "stattic.route-inventory.v1";
      routes: Array<{ id: string; kind: string; source: string; path: string }>;
    };
  };
  const finalized = await apiJson<FinalizeResult>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: versionId },
    finalizeBody,
  );
  expect(finalized.status).toBe("ready");
  expect(finalized.manifest).toContainEqual({
    path: "index.html",
    size: content.length,
    sha256: sha256(content),
    contentType: "text/html; charset=utf-8",
  });
  expect(finalized.catalog_digests.source).toMatch(/^sha256:[a-f0-9]{64}$/);
  expect(finalized.catalog_digests.served).toMatch(/^sha256:[a-f0-9]{64}$/);
  expect(finalized.variable_digests).toEqual({ REGION: sha256("eu") });
  expect(finalized.system_variable_dependencies).toEqual([]);
  expect(finalized.routing).toEqual({
    redirect_rule_count: 1,
    header_rule_count: 1,
    proxy_rule_count: 1,
    proxy_rules: [{ source: "/api/*", destination: "https://api.example.com/:splat" }],
    placement: [
      {
        kind: "rewrite",
        origin: "file",
        ruleOrder: 0,
        at: "origin",
        reason: "placement_off",
        source: "/api/*",
        destination: "https://api.example.com/:splat",
        status: 200,
        force: false,
      },
      {
        kind: "header",
        origin: "file",
        ruleOrder: 0,
        at: "origin",
        reason: "placement_off",
        source: "/*",
        headers: ["X-Region"],
      },
    ],
  });
  expect(finalized.route_inventory.format).toBe("stattic.route-inventory.v1");
  expect(finalized.route_inventory.routes).toContainEqual(
    expect.objectContaining({
      kind: "proxy",
      source: "_redirects",
      path: "/api/*",
    }),
  );
  expect(finalized.route_inventory.routes[0]?.id).toMatch(/^sha256:[a-f0-9]{64}$/);

  const replayed = await apiJson<FinalizeResult>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: versionId },
    finalizeBody,
  );
  expect(replayed.status).toBe("ready");
  expect(replayed.manifest).toEqual(finalized.manifest);
  expect(replayed.catalog_digests).toEqual(finalized.catalog_digests);
  expect(replayed.variable_digests).toEqual(finalized.variable_digests);
  expect(replayed.routing).toEqual(finalized.routing);
  expect(replayed.route_inventory).toEqual(finalized.route_inventory);

  const finalizeEvents = journalRecords(rt).filter(
    (record) => record.operation_action === "finalize_version" && record.version_id === versionId,
  );
  expect(finalizeEvents.map((record) => record.event)).toEqual(["version_finalized"]);
  expect(finalizeEvents[0]?.upload_id).toBe(session.uploadId);
});

test("finalize adds a generated Functions bundle the control plane wrote to the space store", async () => {
  const bundle = JSON.stringify({
    format: "bundle",
    modules: { "worker.js": "export default {}" },
  });
  const digest = sha256(bundle);
  const bundlePath = `__spacefast/functions/bundles/${digest}/bundle.json`;
  const putGenerated = (body: string, sha = digest) =>
    fetch(managementUrl(`/spaces/${SPACE}/generated-blobs?sha256=${sha}`), {
      method: "PUT",
      headers: {
        authorization: `Bearer ${managementToken("put_generated_blob", { space_id: SPACE })}`,
        "content-type": "application/octet-stream",
      },
      body,
    });

  const mismatched = await putGenerated("other bytes");
  expect(mismatched.status).toBe(422);
  expect(await errorCode(mismatched)).toBe("upload_hash_mismatch");
  expect(readBlob(rt, SPACE, digest)).toBeNull();

  const atCap = await putGenerated("x".repeat(FUNCTIONS_BUNDLE_MAX_BYTES));
  expect(atCap.status).toBe(422);
  expect(await errorCode(atCap)).toBe("upload_hash_mismatch");
  const overCap = await putGenerated("x".repeat(FUNCTIONS_BUNDLE_MAX_BYTES + 1));
  expect(overCap.status).toBe(413);
  expect(await errorCode(overCap)).toBe("generated_blob_too_large");

  const files = { "index.html": "<h1>generated</h1>\n" };
  const session = await createDeclaredSession(rt, SPACE, "ver_generated", files);
  const generated = [{ path: bundlePath, sha256: digest, size: bundle.length }];

  const staleBundle = JSON.stringify({ format: "bundle", modules: { "worker.js": "stale" } });
  const staleDigest = sha256(staleBundle);
  expect((await putGenerated(staleBundle, staleDigest)).status).toBe(200);
  const incomplete = await finalize(rt, SPACE, session.versionId, {
    upload_id: session.uploadId,
    generated_files: [
      {
        path: `__spacefast/functions/bundles/${staleDigest}/bundle.json`,
        sha256: staleDigest,
        size: staleBundle.length,
      },
    ],
  });
  expect(incomplete.status).toBe(409);
  expect(await errorCode(incomplete)).toBe("version_upload_incomplete");
  await uploadSessionBlobs(rt, session, files);

  const unwritten = await finalize(rt, SPACE, session.versionId, {
    upload_id: session.uploadId,
    generated_files: generated,
  });
  expect(unwritten.status).toBe(409);
  expect(await errorCode(unwritten)).toBe("generated_file_missing");

  const stored = await putGenerated(bundle);
  expect(stored.status).toBe(200);
  expect(await stored.json()).toEqual({ sha256: digest, size: bundle.length });
  expect(readBlob(rt, SPACE, digest)?.toString()).toBe(bundle);

  const wrongPath = await finalize(rt, SPACE, session.versionId, {
    upload_id: session.uploadId,
    generated_files: [{ ...generated[0], path: "index.html" }],
  });
  expect(wrongPath.status).toBe(422);
  expect(await errorCode(wrongPath)).toBe("generated_files_invalid");

  const finalized = await apiJson<{ status: string }>(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${session.versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: session.versionId },
    { upload_id: session.uploadId, generated_files: generated },
  );
  expect(finalized.status).toBe("ready");
  const catalogFiles = async (versionId: string) =>
    (
      await apiJson<{ files: Array<{ path: string; sha256: string; size: number }> }>(
        rt,
        "GET",
        `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/files?include_internal=1`,
        "list_version_files",
        { space_id: SPACE, version_id: versionId },
      )
    ).files.map(({ path, sha256, size }) => ({ path, sha256, size }));
  expect(await catalogFiles(session.versionId)).toEqual([
    { path: bundlePath, sha256: digest, size: bundle.length },
    { path: "index.html", sha256: sha256(files["index.html"]), size: files["index.html"].length },
  ]);

  const lazyVersionId = "ver_generated_lazy";
  const lazyUploadId = "upl_generated_lazy";
  const lazy = await finalize(rt, SPACE, lazyVersionId, {
    upload_id: lazyUploadId,
    session: {
      upload_id: lazyUploadId,
      space_id: SPACE,
      version_id: lazyVersionId,
      files: [],
      retention: "list",
      retained_files: manifestFor(files),
      reusable_version_id: session.versionId,
    },
    generated_files: generated,
  });
  expect(lazy.status).toBe(200);
  expect(await catalogFiles(lazyVersionId)).toEqual(await catalogFiles(session.versionId));
});

test("ingest sheds above four concurrent uploads per space", async () => {
  const files = { "a.txt": "a", "b.txt": "bb", "c.txt": "ccc" };
  const session = await createDeclaredSession(rt, ADMISSION_SPACE, "ver_adm", files);
  // The upload's slot is released in the request's shutdown, which can outlive
  // the response the client already has. Settling back to the count the seeding
  // left behind — a release gives back one slot, not the seeded ones — is what
  // makes the next seeded value the one the engine reads.
  const put = async (sha: string, body: string, seeded: number) => {
    const response = await putBlob(rt, ADMISSION_SPACE, session.token, sha, body);
    await response.text();
    await waitForIngestCounter(ADMISSION_SPACE, seeded);
    return response;
  };

  expect((await put(sha256("a"), "a", 0)).status).toBe(200);

  seedIngestCounter(ADMISSION_SPACE, INGEST_LIMIT - 1);
  expect((await put(sha256("bb"), "bb", INGEST_LIMIT - 1)).status).toBe(200);

  seedIngestCounter(ADMISSION_SPACE, INGEST_LIMIT);
  const shed = await fetch(uploadUrl(`/spaces/${ADMISSION_SPACE}/blobs/${sha256("ccc")}`), {
    method: "PUT",
    headers: { authorization: `Bearer ${session.token}`, accept: "application/json" },
    body: "ccc",
  });
  expect(shed.status).toBe(429);
  expect(shed.headers.get("retry-after")).toBe("2");
  expect(await errorCode(shed)).toBe("ingest_admission_exceeded");

  seedIngestCounter(ADMISSION_SPACE, 0);
  expect((await put(sha256("ccc"), "ccc", 0)).status).toBe(200);
}, 15_000);

function ingestCounterPath(spaceId: string): string {
  return path.join(rt.storageRoot, "runtime/admission", `${spaceId}.json`);
}

function ingestCounterGenerationFile(spaceId: string): string | null {
  const counterPath = ingestCounterPath(spaceId);
  const pointerPath = `${counterPath}.generation`;
  if (!existsSync(pointerPath)) {
    return null;
  }
  const raw = readFileSync(pointerPath, "utf8");
  if (raw.trim() === "") {
    return null;
  }
  const pointer = JSON.parse(raw) as { generation?: string };
  return typeof pointer.generation === "string" ? `${counterPath}.${pointer.generation}` : null;
}

function seedIngestCounter(spaceId: string, count: number): void {
  const generationFile = ingestCounterGenerationFile(spaceId);
  if (generationFile === null || !existsSync(generationFile)) {
    throw new Error(`ingest admission counter for ${spaceId} has no active generation`);
  }
  writeFileSync(
    generationFile,
    `${JSON.stringify({ count, updated_at: Math.floor(Date.now() / 1000) })}\n`,
  );
}

function readIngestCounter(spaceId: string): number | null {
  try {
    const generationFile = ingestCounterGenerationFile(spaceId);
    if (generationFile === null) {
      return null;
    }
    const raw = readFileSync(generationFile, "utf8");
    if (raw.trim() === "") {
      return null;
    }
    return (JSON.parse(raw) as { count?: number }).count ?? null;
  } catch (error) {
    // PHP rewrites both snapshots under flock by truncating first, so this
    // lock-free reader can catch an empty or half-written file. A file can also
    // vanish between the existsSync and the read.
    if (error instanceof SyntaxError) {
      return null;
    }
    if (error instanceof Error && "code" in error && error.code === "ENOENT") {
      return null;
    }
    throw error;
  }
}

async function waitForIngestCounter(spaceId: string, expected: number): Promise<void> {
  const deadline = Date.now() + 5000;
  for (;;) {
    if (readIngestCounter(spaceId) === expected) {
      return;
    }
    if (Date.now() > deadline) {
      throw new Error(
        `ingest admission counter for ${spaceId} never settled at ${expected} (last read ${readIngestCounter(spaceId)})`,
      );
    }
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}

test("manifest declaration is where upload path policy is enforced", async () => {
  const rejected: Array<[string, string]> = [
    [".htaccess", "static_control_file_not_supported"],
    ["__zero/open.html", "static_runtime_control_path_not_supported"],
    ["nested/../evil.html", "invalid_file_path"],
  ];
  let index = 0;
  for (const [filePath, code] of rejected) {
    index += 1;
    const response = await declareManifest(`ver_policy_${index}`, { [filePath]: "x" });
    expect(response.status).toBe(422);
    expect([filePath, await errorCode(response)]).toEqual([filePath, code]);
  }

  const allowed = await declareManifest("ver_policy_allowed", {
    "__spacefast/zero/deploy.json": '{"digest":"sha256:zero"}\n',
    "__spacefast/functions/bundles/app.js": "export default 1;\n",
    "index.php": "x",
  });
  expect(allowed.status).toBe(201);

  const duplicate = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    {
      version_id: "ver_policy_dupe",
      files: [
        { path: "index.html", size: 1, sha256: sha256("a") },
        { path: "index.html", size: 1, sha256: sha256("a") },
      ],
    },
  );
  expect(duplicate.status).toBe(422);
  expect(await errorCode(duplicate)).toBe("manifest_duplicate_path");

  const conflicting = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    {
      version_id: "ver_policy_conflict",
      files: [
        { path: "a.txt", size: 1, sha256: sha256("a") },
        { path: "b.txt", size: 2, sha256: sha256("a") },
      ],
    },
  );
  expect(conflicting.status).toBe(422);
  expect(await errorCode(conflicting)).toBe("manifest_sha_size_conflict");

  // A declared size is a JSON integer or it is nothing. PHP's `(int)` would
  // read a plausible number out of every one of these, and the `max(0, ...)`
  // that used to follow it turned a negative declaration into a free path.
  let bad = 0;
  for (const size of ["9e99", 1.5, -1]) {
    bad += 1;
    const coerced = await api(
      rt,
      "POST",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
      "create_version",
      { space_id: SPACE },
      {
        version_id: `ver_policy_size_${bad}`,
        files: [{ path: "index.html", size, sha256: sha256("a") }],
      },
    );
    expect([size, coerced.status]).toEqual([size, 422]);
    expect([size, await errorCode(coerced)]).toEqual([size, "invalid_file"]);
  }

  const chunk = 64 * 1024 * 1024;
  const atCeiling = Array.from({ length: MAX_VERSION_TOTAL_BYTES / chunk }, (_, entry) => ({
    path: `media/chunk-${entry}.bin`,
    size: chunk,
    sha256: sha256(`chunk-${entry}`),
  }));
  const admitted = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    { version_id: "ver_policy_at_ceiling", files: atCeiling },
  );
  expect(admitted.status).toBe(201);

  const overCeiling = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    {
      version_id: "ver_policy_over_ceiling",
      files: [...atCeiling, { path: "one-byte.txt", size: 1, sha256: sha256("one-byte") }],
    },
  );
  expect(overCeiling.status).toBe(413);
  expect(await errorCode(overCeiling)).toBe("version_total_bytes_exceeded");

  const base = versionRoot(rt, SPACE, "ver_policy_oversized_base");
  mkdirSync(base, { recursive: true });
  const oversizedPaths = [0, 1, 2].map((index) => ({
    path: `media/big-${index}.bin`,
    size: 1024 * 1024 * 1024,
    sha256: sha256(`big-${index}`),
  }));
  writeFileSync(
    path.join(base, "metadata.json"),
    JSON.stringify({
      spaceId: SPACE,
      versionId: "ver_policy_oversized_base",
      catalog: {
        format: "spacefast.runtime.file-catalog.v1",
        spaceId: SPACE,
        versionId: "ver_policy_oversized_base",
        paths: Object.fromEntries(
          oversizedPaths.map((file) => [
            file.path,
            {
              source: {
                sha256: file.sha256,
                size: file.size,
                contentType: "application/octet-stream",
              },
              served: null,
              public: true,
            },
          ]),
        ),
        variants: {},
      },
    }),
  );
  const shrinking = oversizedPaths.map((file, index) => ({
    ...file,
    size: index === 2 ? file.size / 2 : file.size,
    sha256: sha256(`shrunk-${index}`),
  }));
  const reusing = {
    retention: "all",
    reusable_version_id: "ver_policy_oversized_base",
  };
  const shrunk = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    { version_id: "ver_policy_shrinking", files: shrinking, ...reusing },
  );
  expect(shrunk.status).toBe(201);

  const grown = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    {
      version_id: "ver_policy_oversized_grown",
      files: [
        ...shrinking,
        { path: "extra.bin", size: 1024 * 1024 * 1024, sha256: sha256("extra") },
      ],
      ...reusing,
    },
  );
  expect(grown.status).toBe(413);
  expect(await errorCode(grown)).toBe("version_total_bytes_exceeded");

  const baseless = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    { version_id: "ver_policy_baseless", files: shrinking },
  );
  expect(baseless.status).toBe(413);
  expect(await errorCode(baseless)).toBe("version_total_bytes_exceeded");

  const lazyUpload = "upl_policy_lazy_shrink";
  const lazyVersion = "ver_policy_lazy_shrink";
  const lazy = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/have`), {
    method: "POST",
    headers: {
      authorization: `Bearer ${uploadToken(SPACE, lazyUpload, lazyVersion, {
        session: {
          upload_id: lazyUpload,
          space_id: SPACE,
          version_id: lazyVersion,
          files: shrinking,
          reusable_version_id: "ver_policy_oversized_base",
        },
      })}`,
      "content-type": "application/json",
    },
    body: JSON.stringify({ shas: shrinking.map((file) => file.sha256) }),
  });
  expect(lazy.status).toBe(200);
  expect(await lazy.json()).toEqual({ missing: shrinking.map((file) => file.sha256) });
});

test("URL uploads reject egress-unsafe fetch targets before streaming", async () => {
  const session = await createDeclaredSession(rt, SPACE, "ver_source_url_egress", {
    "index.html": "safe\n",
  });
  const postSource = (url: string) =>
    fetch(uploadUrl(`/${session.uploadId}/fetch/files/index.html`), {
      method: "POST",
      headers: {
        authorization: `Bearer ${session.token}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({ url }),
    });

  const loopback = await postSource("https://127.0.0.1/private");
  expect(loopback.status).toBe(422);
  expect(await errorCode(loopback)).toBe("upload_source_url_forbidden");

  const metadata = await postSource("https://169.254.169.254/latest/meta-data/");
  expect(metadata.status).toBe(422);
  expect(await errorCode(metadata)).toBe("upload_source_url_forbidden");

  const internalHost = await postSource("https://site.view.fast/index.html");
  expect(internalHost.status).toBe(422);
  expect(await errorCode(internalHost)).toBe("upload_source_url_forbidden");
});

test("URL fetch allowance survives workers and resident bytes recover the receipt", async () => {
  const content = "url allowance recovery\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_url_allowance", {
    "index.html": content,
  });
  const claim = async () => {
    const process = Bun.spawn([
      PHP_BINARY,
      "-r",
      "require $argv[1]; echo json_encode(_stattic_runtime_publish_session_claim_url_fetch($argv[2], $argv[3], $argv[4], $argv[5]));",
      path.join(rt.engineRoot, "admin/upload.php"),
      rt.storageRoot,
      SPACE,
      session.uploadId,
      "index.html",
    ]);
    const output = await new Response(process.stdout).text();
    expect(await process.exited).toBe(0);
    return JSON.parse(output).status;
  };
  expect((await Promise.all([claim(), claim()])).sort()).toEqual(["already_attempted", "claimed"]);
  expect(await claim()).toBe("already_attempted");
  const record = JSON.parse(
    readFileSync(
      path.join(rt.storageRoot, "spaces", SPACE, "publish-sessions", `${session.uploadId}.json`),
      "utf8",
    ),
  );
  expect(record.accepted).toEqual({});
  const refused = await fetch(uploadUrl(`/${session.uploadId}/fetch/files/index.html`), {
    method: "POST",
    headers: {
      authorization: `Bearer ${session.token}`,
      "content-type": "application/json",
    },
    body: JSON.stringify({ url: "https://source-never-resolves.invalid/index.html" }),
  });
  expect(refused.status).toBe(409);
  expect(await errorCode(refused)).toBe("upload_source_url_fetch_failed");

  const digest = sha256(content);
  expect((await putBlob(rt, SPACE, session.token, digest, content)).status).toBe(200);
  expect(await claim()).toBe("resident");
  const recovered = await fetch(uploadUrl(`/${session.uploadId}/fetch/files/index.html`), {
    method: "POST",
    headers: {
      authorization: `Bearer ${session.token}`,
      "content-type": "application/json",
    },
    body: JSON.stringify({ url: "https://127.0.0.1/unreachable" }),
  });
  expect(recovered.status).toBe(200);
  expect(recovered.headers.get("etag")).toBe(`"${digest}"`);
  expect(readBlob(rt, SPACE, digest)?.toString()).toBe(content);
  expect(
    (await finalize(rt, SPACE, session.versionId, { upload_id: session.uploadId })).status,
  ).toBe(200);
});

test("URL transport rejects error headers without consuming the body and removes the staged file", async () => {
  const { certificate, key } = selfSignedCertificate(rt.root, "url-transport");
  const received = Promise.withResolvers<void>();
  const server = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    tls: { cert: Bun.file(certificate), key: Bun.file(key) },
    fetch() {
      received.resolve();
      return new Response(
        new ReadableStream({
          start(controller) {
            controller.enqueue(new Uint8Array([1]));
          },
        }),
        { status: 503 },
      );
    },
  });
  const temporary = path.join(rt.root, "url-error-body.tmp");
  const process = Bun.spawn([
    PHP_BINARY,
    "-d",
    `curl.cainfo=${certificate}`,
    "-r",
    "require $argv[1]; _stattic_runtime_stream_url_to_tmp(['url' => $argv[2], 'resolve' => []], $argv[3], 10);",
    path.join(rt.engineRoot, "admin/upload.php"),
    server.url.toString(),
    temporary,
  ]);
  try {
    const output = await new Response(process.stdout).text();
    expect(await process.exited).toBe(0);
    expect(JSON.parse(output)).toMatchObject({
      status: 422,
      code: "upload_source_url_fetch_failed",
    });
    await received.promise;
    expect(existsSync(temporary)).toBe(false);
  } finally {
    process.kill();
    server.stop(true);
  }
});

test("the upload surface rejects unsupported operations, and refusals stay readable to a browser", async () => {
  const content = "x";
  const session = await createDeclaredSession(rt, SPACE, "ver_ops", { "index.html": content });
  const digest = sha256(content);
  const auth = { authorization: `Bearer ${session.token}` };

  // S3 control operations arrive as query parameters or x-amz-* headers.
  const withQuery = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/have?acl`), {
    method: "POST",
    headers: { ...auth, "content-type": "application/json" },
    body: JSON.stringify({ shas: [] }),
  });
  expect(withQuery.status).toBe(405);
  expect(await errorCode(withQuery)).toBe("runtime_upload_operation_not_supported");

  const copySource = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/${digest}`), {
    method: "PUT",
    headers: { ...auth, "x-amz-copy-source": "/bucket/key" },
    body: content,
  });
  expect(copySource.status).toBe(405);
  expect(await errorCode(copySource)).toBe("runtime_upload_operation_not_supported");

  const wrongMethod = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/have`), { headers: auth });
  expect(wrongMethod.status).toBe(405);
  expect(wrongMethod.headers.get("allow")).toBe("POST");
  expect(await errorCode(wrongMethod)).toBe("runtime_upload_operation_not_supported");

  const unknown = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/${digest}/parts/1`), {
    method: "PUT",
    headers: auth,
    body: content,
  });
  expect(unknown.status).toBe(404);
  expect(await errorCode(unknown)).toBe("runtime_upload_route_not_found");
  expect(unknown.headers.get("access-control-allow-origin")).toBeNull();

  const origin = "https://my.spacefast.com";
  const preflight = await fetch(
    runtimeUploadFileUrl({ baseUrl: rt.baseUrl, uploadId: session.uploadId, path: "index.html" }),
    {
      method: "OPTIONS",
      headers: {
        origin,
        "access-control-request-method": "PUT",
        "access-control-request-headers": "authorization,content-type",
      },
    },
  );
  expect(preflight.status).toBe(204);
  expect(preflight.headers.get("access-control-allow-origin")).toBe(origin);
  expect(preflight.headers.get("access-control-allow-methods")).toContain("PUT");
  expect(preflight.headers.get("access-control-allow-headers")).toContain("Authorization");

  const unroutable = `${rt.baseUrl}${RUNTIME_UPLOAD_API_BASE}?op=nonsense&upload_id=${session.uploadId}`;
  const unroutablePreflight = await fetch(unroutable, {
    method: "OPTIONS",
    headers: { origin, "access-control-request-method": "PUT" },
  });
  expect(unroutablePreflight.status).toBe(204);
  expect(unroutablePreflight.headers.get("access-control-allow-origin")).toBe(origin);

  const unroutablePut = await fetch(unroutable, {
    method: "PUT",
    headers: { ...auth, origin },
    body: content,
  });
  expect(unroutablePut.status).toBe(404);
  expect(await errorCode(unroutablePut)).toBe("runtime_upload_route_not_found");
  expect(unroutablePut.headers.get("access-control-allow-origin")).toBe(origin);
});

test("finalize retains the upload session when a post-finalize artifact write fails", async () => {
  const versionId = "ver_post_finalize_session_keep";
  const files = { "index.html": "<h1>post-finalize</h1>\n" };
  const session = await createDeclaredSession(rt, SPACE, versionId, files);
  await uploadSessionBlobs(rt, session, files);
  const getUploadSession = () =>
    api(
      rt,
      "GET",
      `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/uploads`,
      "get_upload_session",
      { space_id: SPACE, version_id: versionId },
    );
  expect((await getUploadSession()).status).toBe(200);

  const finalizeBody = {
    upload_id: session.uploadId,
    connectors_token: "tok_post_finalize_retry",
    serving: {
      zero_endpoints: [
        {
          execution_mode: "write",
          method: "POST",
          path: "/api/post-finalize/db",
          source: `globalThis.__statticZeroResult = JSON.stringify({ status: 200, headers: {}, body: "ok" });`,
          capabilities: { db: true },
          db: {
            schemaHash: "sha256:post-finalize-test",
            migrationOperations: [],
            tables: {
              items: {
                physicalName: "sf_post_finalize_items",
                primaryKey: "id",
                columns: { id: "item_id", title: { physicalName: "item_title" } },
              },
            },
          },
        },
      ],
    },
  };

  const failed = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: versionId },
    finalizeBody,
  );
  const versionDir = versionRoot(rt, SPACE, versionId);
  const migrationsPath = path.join(versionDir, "zero", "migrations.json");
  expect(failed.status).toBe(500);
  expect(await errorCode(failed)).toBe("zero_migration_failed");
  expect(existsSync(migrationsPath)).toBe(true);
  expect((await getUploadSession()).status).toBe(200);

  rmSync(migrationsPath);
  const succeeded = await api(
    rt,
    "POST",
    `${RUNTIME_HTTP_API_BASE}/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: versionId },
    finalizeBody,
  );
  expect(succeeded.status).toBe(200);
  expect(readFileSync(path.join(versionDir, "connectors", "token"), "utf8")).toBe(
    "tok_post_finalize_retry",
  );
  expect((await getUploadSession()).status).toBe(404);
});
