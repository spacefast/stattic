// Upload sessions: declared and open modes, JWT scope/auth failures, path policy,
// session caps (including staged chunked parts), batch tar uploads, chunked
// parts/complete, and finalize-derived manifests for open sessions.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { readdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  api,
  apiJson,
  createDeclaredSession,
  errorCode,
  get,
  managementToken,
  putFile,
  runtimeHttpPath,
  signToken,
  type Runtime,
  sha256,
  startRuntime,
  tarArchive,
  uploadToken,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_up";

beforeAll(async () => {
  rt = await startRuntime();
});

afterAll(() => rt?.stop());

async function createOpenSession(
  versionId: string,
  caps: Record<string, number> = {},
): Promise<{ uploadId: string; token: string }> {
  const created = await apiJson<{ upload_id: string; session_mode: string }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    { version_id: versionId, session_mode: "open", ...caps },
    201,
  );
  expect(created.session_mode).toBe("open");
  return {
    uploadId: created.upload_id,
    token: uploadToken(SPACE, created.upload_id, versionId, "open"),
  };
}

function uploadUrl(uploadId: string, suffix: string): string {
  return `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/upload.php/${uploadId}/${suffix}`)}`;
}

test("upload JWTs are verified: bearer, scope, instance, expiry, signature", async () => {
  const { uploadId } = await createDeclaredSession(rt, SPACE, "ver_auth", {
    "index.html": "<h1>auth</h1>\n",
  });
  const put = (token: string | null) =>
    fetch(uploadUrl(uploadId, "files/index.html"), {
      method: "PUT",
      headers: token === null ? {} : { authorization: `Bearer ${token}` },
      body: "<h1>auth</h1>\n",
    });

  expect((await put(null)).status).toBe(401);

  const wrongSession = await put(uploadToken(SPACE, "upl_other", "ver_auth", "declared"));
  expect(wrongSession.status).toBe(403);
  expect(await errorCode(wrongSession)).toBe("upload_scope_forbidden");

  const wrongMode = await put(uploadToken(SPACE, uploadId, "ver_auth", "open"));
  expect(wrongMode.status).toBe(403);
  expect(await errorCode(wrongMode)).toBe("upload_scope_forbidden");

  const wrongInstance = await put(
    uploadToken(SPACE, uploadId, "ver_auth", "declared", { runtime_instance_id: "rti_other" }),
  );
  expect(wrongInstance.status).toBe(403);
  expect(await errorCode(wrongInstance)).toBe("runtime_instance_mismatch");

  const expiredToken = uploadToken(SPACE, uploadId, "ver_auth", "declared");
  const expired = await fetch(uploadUrl(uploadId, "files/index.html"), {
    method: "PUT",
    headers: {
      authorization: `Bearer ${uploadToken(SPACE, uploadId, "ver_auth", "declared", {
        exp: Math.floor(Date.now() / 1000) - 60,
      })}`,
    },
    body: "x",
  });
  expect(expired.status).toBe(401);
  expect(await errorCode(expired)).toBe("runtime_token_expired");

  const [header, payload] = expiredToken.split(".");
  const forged = await put(`${header}.${payload}.${Buffer.alloc(64).toString("base64url")}`);
  expect(forged.status).toBe(401);
  expect(await errorCode(forged)).toBe("runtime_token_bad_signature");
});

test("management JWTs enforce action scope and reject jti replay", async () => {
  const token = managementToken("create_version", { space_id: SPACE });
  const create = (body: Record<string, unknown>) =>
    fetch(`${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE}/versions`)}`, {
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
  const wrongAction = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE}/versions/ver_replay/finalize`)}`,
    {
      method: "POST",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${managementToken("create_version", { space_id: SPACE })}`,
      },
      body: "{}",
    },
  );
  expect(wrongAction.status).toBe(403);
  expect(await errorCode(wrongAction)).toBe("runtime_action_forbidden");

  // Scope claims must match the request path.
  const wrongScope = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(`/__spacefast/api.php/spaces/${SPACE}/versions`)}`,
    {
      method: "POST",
      headers: {
        "content-type": "application/json",
        authorization: `Bearer ${managementToken("create_version", { space_id: "spc_other" })}`,
      },
      body: JSON.stringify({ files: [] }),
    },
  );
  expect(wrongScope.status).toBe(403);
  expect(await errorCode(wrongScope)).toBe("runtime_scope_forbidden");
});

test("declared uploads verify path, size, and hash against the manifest", async () => {
  const content = "<h1>declared</h1>\n";
  const { uploadId, token } = await createDeclaredSession(rt, SPACE, "ver_decl", {
    "index.html": content,
  });

  const undeclared = await putFile(rt, uploadId, token, "extra.txt", "nope");
  expect(undeclared.status).toBe(422);
  expect(await errorCode(undeclared)).toBe("upload_path_not_declared");

  const oversized = await putFile(rt, uploadId, token, "index.html", content + "overflow");
  expect(oversized.status).toBe(422);
  expect(await errorCode(oversized)).toBe("upload_size_mismatch");

  // Same byte length as the declared content, different bytes.
  const wrongHash = await putFile(rt, uploadId, token, "index.html", "<h1>tampered</h1>\n");
  expect(wrongHash.status).toBe(422);
  expect(await errorCode(wrongHash)).toBe("upload_hash_mismatch");

  const nonCanonical = await fetch(uploadUrl(uploadId, "files/%69ndex.html"), {
    method: "PUT",
    headers: { authorization: `Bearer ${token}` },
    body: content,
  });
  expect(nonCanonical.status).toBe(403);
  expect(await errorCode(nonCanonical)).toBe("upload_path_not_canonical");

  // Finalizing before the file arrives fails closed.
  const incomplete = await api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_decl/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: "ver_decl" },
    { upload_id: uploadId },
  );
  expect(incomplete.status).toBe(409);
  const incompletePayload = await incomplete.json();
  expect(incompletePayload.error.code).toBe("version_upload_incomplete");
  expect(incompletePayload.error.details).toEqual({
    missingPaths: ["index.html"],
    missingCount: 1,
  });

  const ok = await putFile(rt, uploadId, token, "index.html", content);
  expect(ok.status).toBe(200);
  expect(ok.headers.get("etag")).toBe(`"${sha256(content)}"`);

  const finalized = await apiJson<{
    status: string;
    manifest: Array<{ path: string; size: number; sha256: string; contentType: string }>;
  }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_decl/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: "ver_decl" },
    { upload_id: uploadId },
  );
  expect(finalized.status).toBe("ready");
  expect(finalized.manifest).toEqual([
    {
      path: "index.html",
      size: content.length,
      sha256: sha256(content),
      contentType: "text/html; charset=utf-8",
    },
  ]);

  // Idempotency for control-plane retries after a successful runtime commit:
  // finalize removes the upload session, so a duplicate finalize must observe
  // the already-materialized version instead of failing with upload_not_found.
  const replayedFinalize = await apiJson<{
    status: string;
    manifest: Array<{ path: string; size: number; sha256: string; contentType: string }>;
  }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_decl/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: "ver_decl" },
    { upload_id: uploadId },
  );
  expect(replayedFinalize.status).toBe("ready");
  expect(replayedFinalize.manifest).toEqual(finalized.manifest);
});

test("finalize with callback claims records only version_finalized", async () => {
  const versionId = "ver_finalize_callback";
  const content = "<h1>callback</h1>\n";
  const { uploadId, token } = await createDeclaredSession(rt, SPACE, versionId, {
    "index.html": content,
  });
  expect((await putFile(rt, uploadId, token, "index.html", content)).status).toBe(200);

  const finalized = await apiJson<{ status: string }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    {
      space_id: SPACE,
      version_id: versionId,
      callback_url: "https://callbacks.example.test/runtime",
      callback_token: "callback-secret",
    },
    { upload_id: uploadId },
  );
  expect(finalized.status).toBe("ready");

  type FinalizeEvent = {
    event?: string;
    operation_action?: string;
    version_id?: string;
  };
  const isThisFinalize = (event: FinalizeEvent | undefined): event is FinalizeEvent =>
    event?.operation_action === "finalize_version" && event.version_id === versionId;
  const journalEvents = readFileSync(path.join(rt.storageRoot, "runtime", "journal.jsonl"), "utf8")
    .split("\n")
    .filter((line) => line !== "")
    .map((line) => JSON.parse(line) as FinalizeEvent)
    .filter(isThisFinalize);
  expect(journalEvents.map((event) => event.event)).toEqual(["version_finalized"]);

  const pendingCallbackEvents = readdirSync(
    path.join(rt.storageRoot, "runtime", "callbacks", "pending"),
  )
    .map((name) =>
      JSON.parse(
        readFileSync(path.join(rt.storageRoot, "runtime", "callbacks", "pending", name), "utf8"),
      ),
    )
    .map((callback: { event?: FinalizeEvent }) => callback.event)
    .filter(isThisFinalize);
  expect(pendingCallbackEvents.map((event) => event.event)).toEqual(["version_finalized"]);
});

test("upload path policy rejects control files and runtime paths", async () => {
  const { uploadId, token } = await createOpenSession("ver_paths");
  const cases: Array<[string, string]> = [
    [".htaccess", "static_control_file_not_supported"],
    ["nested/.user.ini", "static_control_file_not_supported"],
    ["__spacefast/x.txt", "static_runtime_control_path_not_supported"],
    [".stattic/storage/x.txt", "static_runtime_control_path_not_supported"],
    [".stattic/engine/evil.php", "static_runtime_control_path_not_supported"],
    ["custom-redirects.php", "static_runtime_control_path_not_supported"],
    ["installer.php", "static_runtime_control_path_not_supported"],
    [".well-known/stattic-runtime", "static_runtime_control_path_not_supported"],
    // The `__stattic` prefix is reserved the same way `__spacefast` is: the
    // serve path already treats `/__stattic_probe` as a control path
    // (runtime/serve.php), and more `/__stattic/*` routes are planned.
    ["__stattic_probe", "static_runtime_control_path_not_supported"],
    ["__stattic/x.txt", "static_runtime_control_path_not_supported"],
    ["nested/__STATTIC_probe/x.txt", "static_runtime_control_path_not_supported"],
  ];
  for (const [filePath, code] of cases) {
    const response = await putFile(rt, uploadId, token, filePath, "x");
    expect(response.status).toBe(422);
    expect(await errorCode(response)).toBe(code);
  }
  // Ordinary dotfiles upload fine (they are blocked at serve time instead).
  expect((await putFile(rt, uploadId, token, ".env.example", "A=1\n")).status).toBe(200);
  expect((await putFile(rt, uploadId, token, "docs/installer.php", "x")).status).toBe(200);
  expect((await putFile(rt, uploadId, token, "src/runtime/README.md", "x")).status).toBe(200);
  // The reservation is a prefix on the path SEGMENT, not a substring match on
  // the whole filename: a name that merely contains "__stattic" mid-string
  // (not at the start of a segment) is ordinary content, not a control path.
  expect((await putFile(rt, uploadId, token, "my__stattic_thing.txt", "x")).status).toBe(200);
});

test("source URL uploads reject egress-unsafe fetch targets before streaming", async () => {
  const { uploadId, token } = await createDeclaredSession(rt, SPACE, "ver_source_url_egress", {
    "index.html": "safe\n",
  });
  const postSource = (url: string) =>
    fetch(uploadUrl(uploadId, "files/index.html/fetch"), {
      method: "POST",
      headers: { authorization: `Bearer ${token}`, "content-type": "application/json" },
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

test("declared sessions reject open-session inputs and vice versa", async () => {
  const manifestOnOpen = await api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    { version_id: "ver_mix", session_mode: "open", files: [{ path: "index.html", size: 5 }] },
  );
  expect(manifestOnOpen.status).toBe(422);
  expect(await errorCode(manifestOnOpen)).toBe("open_session_manifest_not_allowed");

  const capsOnDeclared = await api(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions`,
    "create_version",
    { space_id: SPACE },
    { version_id: "ver_mix", files: [], max_total_bytes: 100 },
  );
  expect(capsOnDeclared.status).toBe(422);
  expect(await errorCode(capsOnDeclared)).toBe("invalid_session_cap");
});

test("open sessions enforce caps and derive the manifest at finalize", async () => {
  const versionId = "ver_open";
  const { uploadId, token } = await createOpenSession(versionId, {
    max_total_bytes: 120,
    max_file_count: 4,
  });
  const auth = { authorization: `Bearer ${token}` };

  // Undeclared paths stream with a hash ETag.
  const indexContent = "<h1>open</h1>\n";
  const putIndex = await putFile(rt, uploadId, token, "index.html", indexContent);
  expect(putIndex.status).toBe(200);
  expect(putIndex.headers.get("etag")).toBe(`"${sha256(indexContent)}"`);

  // Batch tar upload of undeclared entries.
  const batch = await fetch(uploadUrl(uploadId, "batch"), {
    method: "POST",
    headers: auth,
    body: tarArchive([
      { path: "a.txt", content: "alpha\n" },
      { path: "nested/b.txt", content: "bravo\n" },
    ]),
  });
  expect(batch.status).toBe(200);
  expect(await batch.json()).toEqual({ ok: true, uploaded: 2 });

  // Chunked upload: parts then complete.
  const chunkA = "chunk-one-";
  const chunkB = "chunk-two\n";
  for (const [index, body] of [chunkA, chunkB].entries()) {
    const part = await fetch(uploadUrl(uploadId, `files/big.bin/parts/${index + 1}`), {
      method: "PUT",
      headers: auth,
      body,
    });
    expect(part.status).toBe(200);
  }
  const complete = await fetch(uploadUrl(uploadId, "files/big.bin/complete"), {
    method: "POST",
    headers: auth,
  });
  expect(complete.status).toBe(200);
  expect(complete.headers.get("etag")).toBe(`"${sha256(chunkA + chunkB)}"`);

  // File-count cap (4) is now exhausted.
  const fifth = await putFile(rt, uploadId, token, "fifth.txt", "x");
  expect(fifth.status).toBe(413);
  expect(await errorCode(fifth)).toBe("upload_session_cap_exceeded");

  // Byte cap: replacing index.html with an oversized body trips the limit.
  const overCap = await putFile(rt, uploadId, token, "index.html", "y".repeat(200));
  expect(overCap.status).toBe(413);
  expect(await errorCode(overCap)).toBe("upload_session_cap_exceeded");

  // Finalize derives the manifest from what was actually uploaded.
  const finalized = await apiJson<{
    manifest: Array<{ path: string; size: number; sha256: string; contentType?: string }>;
  }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: versionId },
    {
      upload_id: uploadId,
      activate: {
        route_name: "production",
        config: { mode: "website" },
        production_hostnames: ["open.test"],
        version_hostnames: [],
      },
    },
  );
  expect(finalized.manifest.map((entry) => entry.path).toSorted()).toEqual([
    "a.txt",
    "big.bin",
    "index.html",
    "nested/b.txt",
  ]);
  expect(finalized.manifest.every((entry) => /^[a-f0-9]{64}$/.test(entry.sha256))).toBe(true);
  const metadataPath = path.join(
    rt.storageRoot,
    "spaces",
    SPACE,
    "versions",
    versionId,
    "metadata.json",
  );
  const metadata = JSON.parse(readFileSync(metadataPath, "utf8")) as {
    files: Record<string, unknown>;
    manifest?: unknown;
  };
  expect(Object.keys(metadata.files).toSorted()).toEqual([
    "a.txt",
    "big.bin",
    "index.html",
    "nested/b.txt",
  ]);
  delete metadata.manifest;
  writeFileSync(metadataPath, `${JSON.stringify(metadata)}\n`);
  const replayed = await apiJson<{
    manifest: Array<{ path: string; size: number; sha256: string; contentType: string }>;
  }>(
    rt,
    "POST",
    `/__spacefast/api.php/spaces/${SPACE}/versions/${versionId}/finalize`,
    "finalize_version",
    { space_id: SPACE, version_id: versionId },
    { upload_id: uploadId },
  );
  expect(replayed.manifest.map((entry) => entry.path)).toEqual([
    "a.txt",
    "big.bin",
    "index.html",
    "nested/b.txt",
  ]);

  const manifestToken = signToken({
    aud: "stattic-runtime-file-fetch",
    runtime_instance_id: "rti_test",
    space_id: SPACE,
    version_id: versionId,
    scope: "scan_manifest",
    variant_route: "production",
  });
  const scanManifest = await fetch(
    `${rt.baseUrl}${runtimeHttpPath(
      `/__spacefast/api.php/spaces/${SPACE}/versions/${versionId}/scan-manifest?${new URLSearchParams({ variant_route: "production" })}`,
    )}`,
    { headers: { authorization: `Bearer ${manifestToken}` } },
  );
  expect(scanManifest.status).toBe(200);
  const scanManifestBody = (await scanManifest.json()) as {
    files: Array<Record<string, unknown> & { path: string; sha256: string }>;
  };
  expect(
    scanManifestBody.files
      .map((file) => [file.path, file.sha256])
      .toSorted(([left], [right]) => String(left).localeCompare(String(right))),
  ).toEqual([
    ["a.txt", sha256("alpha\n")],
    ["big.bin", sha256(chunkA + chunkB)],
    ["index.html", sha256(indexContent)],
    ["nested/b.txt", sha256("bravo\n")],
  ]);
  for (const file of scanManifestBody.files) {
    expect(Object.keys(file).toSorted()).toEqual(["content_type", "path", "sha256", "size"]);
  }

  const served = await get(rt, "open.test", "/");
  expect(served.status).toBe(200);
  expect(await served.text()).toBe(indexContent);
  const nested = await get(rt, "open.test", "/nested/b.txt");
  expect(await nested.text()).toBe("bravo\n");
});

test("staged chunked parts are charged against open-session caps", async () => {
  const { uploadId, token } = await createOpenSession("ver_staged", {
    max_total_bytes: 64,
    max_file_count: 10,
  });
  const auth = { authorization: `Bearer ${token}` };

  // A single staged part larger than the byte cap is refused outright.
  const oversizedPart = await fetch(uploadUrl(uploadId, "files/big.bin/parts/1"), {
    method: "PUT",
    headers: auth,
    body: "z".repeat(100),
  });
  expect(oversizedPart.status).toBe(413);
  expect(await errorCode(oversizedPart)).toBe("upload_session_cap_exceeded");

  // Staged bytes for one file reduce the allowance for other uploads.
  const stage = await fetch(uploadUrl(uploadId, "files/big.bin/parts/1"), {
    method: "PUT",
    headers: auth,
    body: "z".repeat(60),
  });
  expect(stage.status).toBe(200);
  const blocked = await putFile(rt, uploadId, token, "other.txt", "w".repeat(20));
  expect(blocked.status).toBe(413);
  expect(await errorCode(blocked)).toBe("upload_session_cap_exceeded");
});

test("chunked uploads require contiguous parts", async () => {
  const { uploadId, token } = await createOpenSession("ver_parts");
  const auth = { authorization: `Bearer ${token}` };
  const part2 = await fetch(uploadUrl(uploadId, "files/file.bin/parts/2"), {
    method: "PUT",
    headers: auth,
    body: "data",
  });
  expect(part2.status).toBe(200);

  const complete = await fetch(uploadUrl(uploadId, "files/file.bin/complete"), {
    method: "POST",
    headers: auth,
  });
  expect(complete.status).toBe(422);
  expect(await errorCode(complete)).toBe("upload_parts_incomplete");

  const badPartNumber = await fetch(uploadUrl(uploadId, "files/file.bin/parts/0"), {
    method: "PUT",
    headers: auth,
    body: "data",
  });
  expect(badPartNumber.status).toBe(422);
  expect(await errorCode(badPartNumber)).toBe("upload_part_number_invalid");
});

test("batch uploads verify declared manifests and reject non-file entries", async () => {
  const content = "batch-content\n";
  const { uploadId, token } = await createDeclaredSession(rt, SPACE, "ver_batch", {
    "batch.txt": content,
  });
  const auth = { authorization: `Bearer ${token}` };

  const wrongSize = await fetch(uploadUrl(uploadId, "batch"), {
    method: "POST",
    headers: auth,
    body: tarArchive([{ path: "batch.txt", content: "a-longer-batch-body\n" }]),
  });
  expect(wrongSize.status).toBe(422);
  expect(await errorCode(wrongSize)).toBe("upload_size_mismatch");

  const directory = await fetch(uploadUrl(uploadId, "batch"), {
    method: "POST",
    headers: auth,
    body: tarArchive([{ path: "dir/", content: "", type: "5" }]),
  });
  expect(directory.status).toBe(422);
  expect(await errorCode(directory)).toBe("upload_batch_invalid_entry");

  const ok = await fetch(uploadUrl(uploadId, "batch"), {
    method: "POST",
    headers: auth,
    body: tarArchive([{ path: "batch.txt", content }]),
  });
  expect(ok.status).toBe(200);
  expect(await ok.json()).toEqual({ ok: true, uploaded: 1 });
});

test("upload endpoints reject S3-style control operations", async () => {
  const { uploadId, token } = await createOpenSession("ver_s3");
  const withQuery = await fetch(uploadUrl(uploadId, "files/index.html?acl"), {
    method: "PUT",
    headers: { authorization: `Bearer ${token}` },
    body: "x",
  });
  expect(withQuery.status).toBe(405);
  expect(await errorCode(withQuery)).toBe("runtime_upload_operation_not_supported");

  const copySource = await fetch(uploadUrl(uploadId, "files/index.html"), {
    method: "PUT",
    headers: { authorization: `Bearer ${token}`, "x-amz-copy-source": "/bucket/key" },
    body: "x",
  });
  expect(copySource.status).toBe(405);
});

// Auth lanes are hard security boundaries in the unified admin dispatcher
// (engine/admin/api.php): every lane pins its own JWT `aud`, so a token
// minted for one lane must never authorize another lane's route. Wrong-aud
// tokens fail the audience check (401 runtime_token_expired) before any
// claim, replay marker, or byte of storage is touched — exactly the
// pre-unification behavior of the three sibling dispatchers.
test("a token for one auth lane never authorizes another lane's route", async () => {
  const { uploadId, token: uploadSessionToken } = await createDeclaredSession(
    rt,
    SPACE,
    "ver_lanes",
    { "index.html": "<h1>lanes</h1>\n" },
  );
  const digest = sha256("<h1>lanes</h1>\n");
  const fileFetchRoutes = [
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_lanes/file?path=index.html`,
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_lanes/files/by-hash/${digest}`,
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_lanes/scan-manifest?variant_route=production`,
  ];

  // Management-aud tokens against every file-fetch route: rejected at the
  // audience pin, exactly as the standalone file-fetch dispatcher did.
  for (const route of fileFetchRoutes) {
    const withManagementToken = await fetch(`${rt.baseUrl}${runtimeHttpPath(route)}`, {
      headers: { authorization: `Bearer ${managementToken("read_state")}` },
    });
    expect(withManagementToken.status).toBe(401);
    expect(await errorCode(withManagementToken)).toBe("runtime_token_expired");

    // Upload-aud tokens are equally locked out of the file-fetch lane.
    const withUploadToken = await fetch(`${rt.baseUrl}${runtimeHttpPath(route)}`, {
      headers: { authorization: `Bearer ${uploadSessionToken}` },
    });
    expect(withUploadToken.status).toBe(401);
    expect(await errorCode(withUploadToken)).toBe("runtime_token_expired");
  }

  // File-fetch and upload tokens against a management route.
  const fileFetchToken = signToken({
    aud: "stattic-runtime-file-fetch",
    runtime_instance_id: "rti_test",
    space_id: SPACE,
    version_id: "ver_lanes",
    path: "index.html",
  });
  const managementRoute = `${rt.baseUrl}${runtimeHttpPath(
    `/__spacefast/api.php/spaces/${SPACE}/versions/ver_lanes/uploads`,
  )}`;
  for (const crossToken of [fileFetchToken, uploadSessionToken]) {
    const crossManagement = await fetch(managementRoute, {
      headers: { authorization: `Bearer ${crossToken}` },
    });
    expect(crossManagement.status).toBe(401);
    expect(await errorCode(crossManagement)).toBe("runtime_token_expired");
  }

  // Management and file-fetch tokens against an upload route.
  for (const crossToken of [managementToken("read_state"), fileFetchToken]) {
    const crossUpload = await fetch(uploadUrl(uploadId, "files/index.html"), {
      method: "PUT",
      headers: { authorization: `Bearer ${crossToken}` },
      body: "<h1>lanes</h1>\n",
    });
    expect(crossUpload.status).toBe(401);
    expect(await errorCode(crossUpload)).toBe("runtime_token_expired");
  }

  // The right-lane token still works: the boundary is the audience, not the route.
  const rightLane = await putFile(
    rt,
    uploadId,
    uploadSessionToken,
    "index.html",
    "<h1>lanes</h1>\n",
  );
  expect(rightLane.status).toBe(200);
});
