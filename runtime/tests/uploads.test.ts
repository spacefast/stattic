// The v4 ingest surface (contracts §9): a manifest declared once on the
// management lane, then two path-blind blob routes on the upload lane —
// `POST /spaces/{s}/blobs/have` and `PUT /spaces/{s}/blobs/{sha}` — plus
// path-addressed direct-byte and URL-fetch lanes. Covered here: the
// upload lane's JWT/audience/scope boundary, blob negotiation and resume,
// streamed sha verification, the ingest admission cap (D25), path policy at
// its new seam (manifest declaration, D26), and finalize consuming the
// publish session.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

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
  RUNTIME_HOST,
  manifestFor,
  putBlob,
  readBlob,
  RUNTIME_HTTP_API_BASE,
  RUNTIME_UPLOAD_API_BASE,
  type Runtime,
  runtimeHttpPath,
  sha256,
  startRuntime,
  uploadSessionBlobs,
  uploadToken,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_up";
// Its own space so seeding the per-space admission counter cannot disturb the
// other tests' uploads.
const ADMISSION_SPACE = "spc_up_adm";
// STATTIC_RUNTIME_INGEST_CONCURRENCY_PER_SPACE (D25).
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
  expect(response.headers.get("access-control-allow-headers")).toContain("Authorization");
});

/** Raw `POST /spaces/{s}/versions` so a rejected manifest can be inspected. */
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

  // The right token still works: the boundary is the token, not the route.
  expect((await put(session.token)).status).toBe(200);
});

test("upload token scope is pinned to its space, session and version", async () => {
  const content = "<h1>scope</h1>\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_scope", { "index.html": content });
  const digest = sha256(content);
  const put = (token: string) => putBlob(rt, SPACE, token, digest, content);

  // Path-blind routes address the space, so the space claim is checked against
  // the URL before the session record is even loaded.
  const otherSpace = await put(uploadToken("spc_other", session.uploadId, session.versionId));
  expect(otherSpace.status).toBe(403);
  expect(await errorCode(otherSpace)).toBe("upload_scope_forbidden");

  const noSession = await put(uploadToken(SPACE, "", session.versionId));
  expect(noSession.status).toBe(403);
  expect(await errorCode(noSession)).toBe("upload_scope_forbidden");

  const otherVersion = await put(uploadToken(SPACE, session.uploadId, "ver_other"));
  expect(otherVersion.status).toBe(403);
  expect(await errorCode(otherVersion)).toBe("upload_scope_forbidden");

  // A well-formed token for a session that does not exist is a 404, not a
  // scope failure: there is nothing to compare against.
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

  // A token minted for one action cannot drive another.
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

  // Scope claims must match the request path.
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

// Auth lanes are hard security boundaries in the unified admin dispatcher
// (engine/admin/api.php): every lane pins its own JWT `aud`, so a token minted
// for one lane must never authorize another lane's route. Wrong-aud tokens fail
// the audience check (401 runtime_token_expired) before any claim, replay
// marker, or byte of storage is touched. The third audience is the blob gate
// (contracts §7); it must be just as powerless on the admin surfaces.
test("a token for one auth lane never authorizes another lane's route", async () => {
  const content = "<h1>lanes</h1>\n";
  const session = await createDeclaredSession(rt, SPACE, "ver_lanes", { "index.html": content });
  const digest = sha256(content);
  const gateToken = blobGateToken(SPACE, digest, { versionId: session.versionId });

  // Management lane: the version file list, the one metadata read every file
  // surface goes through.
  const managementRoute = managementUrl(`/spaces/${SPACE}/versions/${session.versionId}/files`);
  for (const crossToken of [session.token, gateToken]) {
    const crossManagement = await fetch(managementRoute, {
      headers: { authorization: `Bearer ${crossToken}` },
    });
    expect(crossManagement.status).toBe(401);
    expect(await errorCode(crossManagement)).toBe("runtime_token_expired");
  }

  // Upload lane: both blob routes.
  for (const crossToken of [managementToken("read_state"), gateToken]) {
    const crossPut = await putBlob(rt, SPACE, crossToken, digest, content);
    expect(crossPut.status).toBe(401);
    expect(await errorCode(crossPut)).toBe("runtime_token_expired");

    const crossHave = await blobsHave(rt, { spaceId: SPACE, token: crossToken }, [digest]);
    expect(crossHave.status).toBe(401);
    expect(await errorCode(crossHave)).toBe("runtime_token_expired");
  }

  // The right-lane token still works.
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

  // Malformed negotiation input is rejected rather than coerced. It belongs to
  // the live session: the route resolves the session before it reads the body,
  // so probing this after finalize would only prove the session was consumed.
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

  // Half the publish lands, then the client dies.
  expect((await putBlob(rt, SPACE, session.token, indexSha, files["index.html"])).status).toBe(200);

  // Resume is a re-negotiation, not a re-upload: the accepted blob drops out of
  // `missing` and finalize takes the session as-is (D23, no re-hash).
  expect(await blobsHaveMissing(rt, session, [indexSha, appSha])).toEqual([appSha]);
  await uploadSessionBlobs(rt, session, files);
  const finalized = await finalize(rt, SPACE, "ver_resume", { upload_id: session.uploadId });
  expect(finalized.status).toBe(200);

  // A later publish of the same bytes needs no PUT at all: negotiation both
  // reports the CAS hit and marks the session's declaration accepted.
  const deduped = await createDeclaredSession(rt, SPACE, "ver_resume_dedupe", files);
  expect(await blobsHaveMissing(rt, deduped, [indexSha, appSha])).toEqual([]);
  const dedupedFinalize = await finalize(rt, SPACE, "ver_resume_dedupe", {
    upload_id: deduped.uploadId,
  });
  expect(dedupedFinalize.status).toBe(200);
});

test("a CAS object damaged to another length is renegotiated and repaired, not deduped away", async () => {
  // The production wedge this covers: a version's blob was left on disk under a
  // name its bytes do not hash to (the write-through hardlink bug, since fixed;
  // the catalogs and CAS objects it damaged are permanent). Every recovery path
  // a publisher has runs through the two presence checks below, and both used to
  // answer on `is_file` alone — so negotiation said "already have it", the PUT
  // that followed was discarded as a duplicate, and finalize refused the version
  // for a file the client had just sent. Nothing the client could do repaired it.
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
  // A resident length that is not the declared one is not a CAS hit.
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
  // The read finalize itself depends on: the control plane compiles `sf.jsonc`
  // and `_pages/*` out of bytes that exist ONLY in the open session, because the
  // version's catalog is written by the very finalize those reads feed.
  // There is deliberately no deployed base or route pointer: a first publish
  // must read these bytes through the runtime host before it can create one.

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

  // Declared is not accepted: until the runtime has hashed and committed the
  // bytes, the session authorizes nothing.
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
  // The version lane cannot stand in for it — this is exactly the read that
  // silently returned "file absent" and made finalize ignore a fresh config.
  expect((await fromVersion()).status).toBe(404);

  expect((await finalize(rt, SPACE, versionId, { upload_id: session.uploadId })).status).toBe(200);

  // Finalize hands authority over: the catalog answers, the consumed session
  // does not.
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

// The inference this replaced was silent and lossy, so its absence is loud: a
// caller that names a base without stating what to keep is refused, never
// reinterpreted as "keep everything".
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

  // Same byte length as the declared content, different bytes.
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

  // Finalizing before the bytes arrive fails closed, naming what is missing.
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
  // The receipt carries what the finalizer COMPILED, because it is the only
  // compiler: the control plane stores these instead of recompiling the same
  // files itself.
  expect(finalized.catalog_digests.source).toMatch(/^sha256:[a-f0-9]{64}$/);
  expect(finalized.catalog_digests.served).toMatch(/^sha256:[a-f0-9]{64}$/);
  expect(finalized.variable_digests).toEqual({ REGION: sha256("eu") });
  expect(finalized.system_variable_dependencies).toEqual([]);
  expect(finalized.routing).toEqual({
    redirect_rule_count: 1,
    header_rule_count: 1,
    proxy_rule_count: 1,
    proxy_rules: [{ source: "/api/*", destination: "https://api.example.com/:splat" }],
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

  // Idempotency for control-plane retries after a successful runtime commit:
  // finalize removes the publish session, so a duplicate finalize must observe
  // the already-materialized version instead of failing with upload_not_found.
  // The replay never re-runs the finalizer, so every compiled field it answers
  // with has to come from what the first call recorded.
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

  // The journal is the only sink (D53): one record for the commit, none for the
  // replay that committed nothing.
  const finalizeEvents = journalRecords(rt).filter(
    (record) => record.operation_action === "finalize_version" && record.version_id === versionId,
  );
  expect(finalizeEvents.map((record) => record.event)).toEqual(["version_finalized"]);
  expect(finalizeEvents[0]?.upload_id).toBe(session.uploadId);
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

  // A real upload both proves the ingest lane takes a slot and creates the
  // counter generation the seeding below writes into.
  expect((await put(sha256("a"), "a", 0)).status).toBe(200);

  // At capacity minus one an upload still admits: the cap is 4, not 3.
  seedIngestCounter(ADMISSION_SPACE, INGEST_LIMIT - 1);
  expect((await put(sha256("bb"), "bb", INGEST_LIMIT - 1)).status).toBe(200);

  // At capacity the next upload sheds with the ingest-specific code (D25).
  seedIngestCounter(ADMISSION_SPACE, INGEST_LIMIT);
  const shed = await fetch(uploadUrl(`/spaces/${ADMISSION_SPACE}/blobs/${sha256("ccc")}`), {
    method: "PUT",
    headers: { authorization: `Bearer ${session.token}`, accept: "application/json" },
    body: "ccc",
  });
  expect(shed.status).toBe(429);
  expect(shed.headers.get("retry-after")).toBe("2");
  expect(await errorCode(shed)).toBe("ingest_admission_exceeded");

  // A refusal reserves nothing: back under capacity, the same upload lands.
  seedIngestCounter(ADMISSION_SPACE, 0);
  expect((await put(sha256("ccc"), "ccc", 0)).status).toBe(200);
}, 15_000);

// The per-space ingest counter is the file-backed windowed counter in
// shared/admission.php: a generation pointer plus the counted file it names.
// Writing the count directly is how a single-process test reaches capacity
// without racing real concurrent uploads against each other.
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

// Path policy moved to the manifest declaration (D26): the blob routes are
// path-blind, so `POST /spaces/{s}/versions` is the one place a control path
// can be refused — and refusing it there rejects the whole publish before a
// single byte is negotiated.
test("manifest declaration is where upload path policy is enforced", async () => {
  // WHICH paths the policy denies is settled by
  // packages/common/src/utils/publish-policy.fixtures.json, which
  // apps/control-plane/src/runtime/php-policy-parity.test.ts runs through both
  // the TypeScript policy and this runtime's upload-policy.php. What this test
  // adds is the HTTP seam: that declaration is where a refusal happens, and that
  // each verdict reaches the caller as a 422 carrying the policy's own code. One
  // path per code is enough for that — more would re-walk the fixture.
  const rejected: Array<[string, string]> = [
    [".htaccess", "static_control_file_not_supported"],
    ["__sf/open.html", "static_runtime_control_path_not_supported"],
    ["nested/../evil.html", "invalid_file_path"],
  ];
  let index = 0;
  for (const [filePath, code] of rejected) {
    index += 1;
    const response = await declareManifest(`ver_policy_${index}`, { [filePath]: "x" });
    expect(response.status).toBe(422);
    expect([filePath, await errorCode(response)]).toEqual([filePath, code]);
  }

  // The build artifacts a publisher IS allowed to write under `__spacefast/`
  // reach 201 through the same seam.
  const allowed = await declareManifest("ver_policy_allowed", {
    "__spacefast/zero/deploy.json": '{"digest":"sha256:zero"}\n',
    "__spacefast/functions/bundles/app.js": "export default 1;\n",
    "index.php": "x",
  });
  expect(allowed.status).toBe(201);

  // The manifest must also be internally consistent before anything is pinned.
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
});

// URL fetch is the path-addressed remote ingest route. Unlike direct-byte path
// uploads, it needs its own egress guard.
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

  // Each blob route pins its own method...
  const wrongMethod = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/have`), { headers: auth });
  expect(wrongMethod.status).toBe(405);
  expect(wrongMethod.headers.get("allow")).toBe("POST");
  expect(await errorCode(wrongMethod)).toBe("runtime_upload_operation_not_supported");

  // ...and anything else on this surface is not a route at all.
  const unknown = await fetch(uploadUrl(`/spaces/${SPACE}/blobs/${digest}/parts/1`), {
    method: "PUT",
    headers: auth,
    body: content,
  });
  expect(unknown.status).toBe(404);
  expect(await errorCode(unknown)).toBe("runtime_upload_route_not_found");
  // A caller that sent no Origin is not a browser (CLI, MCP, the control
  // plane) and gets no CORS grant.
  expect(unknown.headers.get("access-control-allow-origin")).toBeNull();

  // The dashboard uploads from my.spacefast.com straight to the box, so every
  // answer this surface can give a browser has to be readable by one. The
  // preflight comes first and carries no credentials, so it is answered before
  // the route is resolved: a preflight that 404s blocks the upload outright,
  // and a 404 emitted without the grant reaches the caller as an opaque CORS
  // failure naming the wrong problem.
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

  // Nothing here resolves to a route, so the refusal is raised before dispatch
  // — the seam where the grant used to be skipped.
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
