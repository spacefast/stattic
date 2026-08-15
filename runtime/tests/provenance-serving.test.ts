// Provenance and readiness at the serving seam (contracts §5/§6, §14).
//
// Two claims live here, and nothing else:
//
//  * every response the visitor lane produces — object, redirect, 404, denial,
//    probe — carries `X-Spacefast-Runtime` and, once a version is selected,
//    `X-Spacefast-Version` (shared/context.php `_stattic_emit_runtime_identity`).
//    On a denial that pair is the only thing separating our access wall from an
//    upstream proxy's;
//  * the readiness target finalize hands back (`metadata.readinessTarget`, read
//    by admin/management.php) is a promise about what the serving lane will
//    answer for one path. This file is where that promise is checked against the
//    real response — the compiler's projection math itself is proven in
//    `crates/stattic-runtime-core/src/site_finalize.rs` unit tests and is not
//    restated here.
//
// Deliberately absent: Range. §15 deleted the PHP Range lane because the
// platform never delivers Range to the origin (§16, measured), so there is
// nothing left to prove locally. Conditional requests are the same story.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { writeFileSync } from "node:fs";
import path from "node:path";

import {
  deploy,
  errorCode,
  finalize,
  finalizeRaw,
  get,
  publicAccessConfig,
  type Runtime,
  startRuntime,
  versionMetadata,
  versionRoot,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_provenance";
const VERSION = "ver_provenance_1";
const HOST = "provenance.test";
const VERSION_HOST = "v1--provenance.test";

const PROBE_SPACE = "spc_probe_redirect";
const PROBE_VERSION = "ver_probe_redirect_1";
const PROBE_HOST = "probe-redirect.test";

const PAGE = "<h1>public provenance</h1>\n";
const ASSET = new Uint8Array(Array.from({ length: 65_536 }, (_, index) => index % 251));

function expectProvenance(response: Response, versionId: string, label = ""): void {
  expect(response.headers.get("x-spacefast-runtime"), label).toBe("1");
  expect(response.headers.get("x-spacefast-version"), label).toBe(versionId);
}

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    files: {
      "index.html": PAGE,
      "assets/public.bin": ASSET,
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }, "live_and_all_versions"),
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [{ hostname: VERSION_HOST, version_id: VERSION }],
    },
  });

  // Everything on this host redirects, so it can only answer the probe by
  // terminating before route actions are consulted.
  await deploy(rt, {
    spaceId: PROBE_SPACE,
    versionId: PROBE_VERSION,
    files: {
      "index.html": "<h1>redirected apex</h1>\n",
      _redirects: "/* /elsewhere 302\n",
    },
    activate: {
      route_name: "production",
      config: publicAccessConfig({ mode: "website" }),
      production_hostnames: [PROBE_HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

test("public objects carry exact provenance and bytes on every hostname", async () => {
  const page = await get(rt, HOST, "/index.html");
  expect(page.status).toBe(200);
  expect(await page.text()).toBe(PAGE);
  expectProvenance(page, VERSION);

  // The bytes come out of the CAS through the compiled entry, so a whole-object
  // read is also the proof that the blob the table names is the blob served.
  for (const host of [HOST, VERSION_HOST]) {
    const asset = await get(rt, host, "/assets/public.bin");
    expect(asset.status, host).toBe(200);
    expectProvenance(asset, VERSION, host);
    expect(new Uint8Array(await asset.arrayBuffer()), host).toEqual(ASSET);
  }

  // HEAD advertises what the GET would send, without a body.
  const head = await get(rt, HOST, "/assets/public.bin", { method: "HEAD" });
  expect(head.status).toBe(200);
  expect(head.headers.get("content-length")).toBe(String(ASSET.byteLength));
  expectProvenance(head, VERSION);
  expect((await head.arrayBuffer()).byteLength).toBe(0);
});

test("the production probe answers before any route action, with runtime identity only", async () => {
  const redirected = await get(rt, PROBE_HOST, "/ordinary");
  expect(redirected.status).toBe(302);
  expect(redirected.headers.get("location")).toBe("/elsewhere");

  const probe = await get(rt, PROBE_HOST, "/__stattic_probe", { method: "HEAD" });
  expect(probe.status).toBe(204);
  // The probe proves the route pointer itself, so it terminates before the host
  // entry and overlay are resolved: there is no selected version to name.
  expect(probe.headers.get("x-spacefast-runtime")).toBe("1");
  expect(probe.headers.get("x-spacefast-version")).toBeNull();
  expect((await probe.arrayBuffer()).byteLength).toBe(0);
});

test("every finalized readiness target names a status the serving lane answers", async () => {
  const rows = [
    {
      // The plain case: one public object, no rules, no access wall.
      suffix: "object",
      files: { "probe.txt": "probe\n" },
      open: true,
      target: { path: "/probe.txt", expected_statuses: [200, 302, 401, 403] },
      status: 200,
      location: null,
    },
    {
      // A redirect entry owns the selected path; the readiness set carries its
      // final status instead of the object's 200.
      suffix: "redirect",
      files: {
        "proof.html": "redirected proof\n",
        _redirects: "/proof.html /destination 303\n",
      },
      open: true,
      target: { path: "/proof.html", expected_statuses: [302, 303, 401, 403] },
      status: 303,
      location: "/destination",
    },
    {
      // A forced notFound makes the object unreachable at its own path.
      suffix: "not-found",
      files: {
        "probe.txt": "probe\n",
        _redirects: "/probe.txt /missing 404!\n",
      },
      open: true,
      target: { path: "/probe.txt", expected_statuses: [302, 401, 403, 404] },
      status: 404,
      location: null,
    },
    {
      // Access is mutable route state, not a finalized-version property: the
      // compiler admits 401/403 for a path it otherwise projects as 200, and a
      // closed overlay then answers exactly that.
      suffix: "denied",
      files: { "proof.html": "protected proof\n" },
      open: false,
      target: { path: "/proof.html", expected_statuses: [200, 302, 401, 403] },
      status: 403,
      location: null,
    },
    {
      // No public object at all: readiness falls back to root, which the
      // nearest-404 chain answers.
      suffix: "no-object",
      files: { "sf.jsonc": "{}\n" },
      open: true,
      target: { path: "/", expected_statuses: [302, 401, 403, 404] },
      status: 404,
      location: null,
    },
  ] as const;

  for (const row of rows) {
    const spaceId = `spc_readiness_${row.suffix.replace(/-/g, "_")}`;
    const versionId = `ver_readiness_${row.suffix.replace(/-/g, "_")}`;
    const hostname = `${row.suffix}-readiness.test`;
    const finalized = await finalizeRaw(rt, spaceId, versionId, row.files, {
      activate: {
        route_name: "production",
        config: row.open
          ? publicAccessConfig({ mode: "website" }, "live_and_all_versions")
          : { mode: "website" },
        production_hostnames: [hostname],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
    const body = (await finalized.json()) as { readiness_target: { expected_statuses: number[] } };
    expect(finalized.status, `${row.suffix}: ${JSON.stringify(body)}`).toBe(200);
    expect(body.readiness_target, row.suffix).toEqual(row.target);

    const response = await get(rt, hostname, row.target.path, { method: "HEAD" });
    expect(response.status, row.suffix).toBe(row.status);
    expect(body.readiness_target.expected_statuses, row.suffix).toContain(response.status);
    expect(response.headers.get("location"), row.suffix).toBe(row.location);
    expectProvenance(response, versionId, row.suffix);
  }
});

test("a replayed finalize repeats the readiness target, and refuses to report an unusable one", async () => {
  const spaceId = "spc_readiness_replay";
  const versionId = "ver_readiness_replay";
  await deploy(rt, {
    spaceId,
    versionId,
    files: { "probe.txt": "probe\n" },
  });

  // The control plane retries finalize with a session the runtime has already
  // consumed; the replay must hand back the same target it probes against.
  const replay = await finalize(rt, spaceId, versionId, { upload_id: "upl_readiness_replay_gone" });
  expect(replay.status).toBe(200);
  expect(((await replay.json()) as { readiness_target: unknown }).readiness_target).toEqual({
    path: "/probe.txt",
    expected_statuses: [200, 302, 401, 403],
  });

  // A target the runtime cannot honor (418 is not a readiness status it can
  // ever answer with) is never reported as ready.
  const metadata = versionMetadata(rt, spaceId, versionId);
  expect(metadata).not.toBeNull();
  writeFileSync(
    path.join(versionRoot(rt, spaceId, versionId), "metadata.json"),
    JSON.stringify({
      ...metadata,
      readinessTarget: { path: "/probe.txt", expected_statuses: [418] },
    }),
  );

  const unusable = await finalize(rt, spaceId, versionId, {
    upload_id: "upl_readiness_replay_gone",
  });
  expect(unusable.status).toBe(500);
  expect(await errorCode(unusable)).toBe("runtime_readiness_target_missing");
});
