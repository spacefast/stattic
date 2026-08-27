// The entrypoint set comes from engine-manifest.json's alias table, run through
// the same `entrypointAliases` the generator routes with. Re-implementing that
// filter here would make the probe-set-equality assertion compare one reading of
// the rule against another.
//
// Each probe's response can only come from the entrypoint's own script. A path
// missing from the routing table falls through to init.php, which 403s the
// private namespace with a plain-text page, so the script's own signal is what
// distinguishes a routed entrypoint from a swallowed one.
import { readFileSync } from "node:fs";
import path from "node:path";

import { entrypointAliases } from "../../scripts/check-runtime-entrypoints.mjs";
import { RUNTIME_DIR, runtimeHttpPath, type Runtime } from "./harness.ts";

const manifest: unknown = JSON.parse(
  readFileSync(path.join(RUNTIME_DIR, "engine-manifest.json"), "utf8"),
);

export const ENTRYPOINT_PATHS: string[] = entrypointAliases(manifest)
  .map((entry) => entry.servedPath)
  .toSorted();

export type EntrypointProbe = {
  request: (rt: Runtime) => Promise<Response>;
  verify: (response: Response) => Promise<void>;
};

function entrypointProbeCatalog(
  probes: Record<string, EntrypointProbe>,
): Record<string, EntrypointProbe> {
  return probes;
}

async function expectErrorCode(response: Response, status: number, code: string): Promise<void> {
  // Report from the raw body, not response.json(): a swallowed entrypoint
  // answers with init.php's plain-text page, and "JSON parse error" is a useless
  // way to learn the routing table lost a path.
  const body = await response.text();
  if (response.status !== status) {
    throw new Error(`expected ${status}, got ${response.status}: ${body}`);
  }
  let actual: unknown;
  try {
    // SAFETY: The optional field is used only for an exact runtime error-code comparison.
    actual = (JSON.parse(body) as { code?: unknown }).code;
  } catch {
    throw new Error(`expected the ${code} problem document, got a non-JSON body: ${body}`);
  }
  if (actual !== code) throw new Error(`expected error code ${code}, got ${String(actual)}`);
}

export const ENTRYPOINT_PROBES = entrypointProbeCatalog({
  "/__spacefast/api.php": {
    request: (rt) =>
      fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/api.php/state")}`, {
        redirect: "manual",
      }),
    verify: (response) => expectErrorCode(response, 401, "runtime_unauthorized"),
  },
  "/__spacefast/content-admin.php": {
    request: (rt) =>
      fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/content-admin.php")}`, {
        redirect: "manual",
      }),
    verify: (response) => expectErrorCode(response, 401, "content_admin_ticket_invalid"),
  },
  "/__spacefast/content.php": {
    request: (rt) =>
      fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/content.php")}`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ operation: "schema.activate", revision: null }),
        redirect: "manual",
      }),
    verify: (response) => expectErrorCode(response, 401, "runtime_unauthorized"),
  },
  "/__spacefast/health.php": {
    request: (rt) => fetch(`${rt.baseUrl}/__spacefast/health.php`, { redirect: "manual" }),
    verify: async (response) => {
      const body = await response.text();
      if (response.status !== 200) throw new Error(`expected 200, got ${response.status}: ${body}`);
      // SAFETY: The probe checks every field it consumes before accepting the response.
      const payload = JSON.parse(body) as {
        ok?: boolean;
        runtime?: string;
        site_state?: string;
      };
      if (payload.ok !== true || payload.runtime !== "stattic-php") {
        throw new Error(`health did not answer for itself: ${body}`);
      }
      // Every harness root has a storage tree, so anything but "configured"
      // means health resolved a root it should not have.
      if (payload.site_state !== "configured") {
        throw new Error(`health reported an unexpected site_state: ${body}`);
      }
    },
  },
  "/__spacefast/upload.php": {
    request: (rt) =>
      fetch(
        `${rt.baseUrl}${runtimeHttpPath("/__spacefast/upload.php/spaces/spc_probe/blobs/have")}`,
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ shas: [] }),
          redirect: "manual",
        },
      ),
    verify: (response) => expectErrorCode(response, 401, "runtime_upload_bearer_required"),
  },
});
