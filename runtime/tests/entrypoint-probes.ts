// THE entrypoint set, taken from the one authority that decides it:
// runtime/engine-manifest.json's alias table, run through the SAME derivation
// the generator routes with (`entrypointAliases` in
// scripts/check-runtime-entrypoints.mjs). Re-implementing the "under the
// namespace and ending in .php" filter here would make the probe-set-equality
// assertion compare one reading of the rule against another; importing it makes
// the single-authority claim literal. Reading the manifest is a fixture read;
// the probes below are what prove behavior.
//
// Each probe is a request whose response can ONLY come from the entrypoint's own
// script. That is the whole point: when a path is missing from the routing
// table, the gate hands it to init.php, which 403s the private namespace with a
// plain-text platform page — so "the script's own signal" is exactly the signal
// that distinguishes a routed entrypoint from a swallowed one.
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

async function expectErrorCode(response: Response, status: number, code: string): Promise<void> {
  // Reported from the raw body, not response.json(): a swallowed entrypoint
  // answers with init.php's plain-text platform page, and "JSON parse error" is
  // a useless way to learn that the routing table lost a path.
  const body = await response.text();
  if (response.status !== status) {
    throw new Error(`expected ${status}, got ${response.status}: ${body}`);
  }
  let actual: unknown;
  try {
    actual = (JSON.parse(body) as { code?: unknown }).code;
  } catch {
    throw new Error(`expected the ${code} problem document, got a non-JSON body: ${body}`);
  }
  if (actual !== code) throw new Error(`expected error code ${code}, got ${String(actual)}`);
}

export const ENTRYPOINT_PROBES: Record<string, EntrypointProbe> = {
  "/__spacefast/api.php": {
    request: (rt) =>
      fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/api.php/state")}`, {
        redirect: "manual",
      }),
    verify: (response) => expectErrorCode(response, 401, "runtime_unauthorized"),
  },
  "/__spacefast/health.php": {
    request: (rt) => fetch(`${rt.baseUrl}/__spacefast/health.php`, { redirect: "manual" }),
    verify: async (response) => {
      const body = await response.text();
      if (response.status !== 200) throw new Error(`expected 200, got ${response.status}: ${body}`);
      const payload = JSON.parse(body) as {
        ok?: boolean;
        runtime?: string;
        site_state?: string;
      };
      if (payload.ok !== true || payload.runtime !== "stattic-php") {
        throw new Error(`health did not answer for itself: ${body}`);
      }
      // Health reports the site's real provisioning state, and every harness
      // root has a storage tree — a "unconfigured" here means health ran
      // against a root it should not have resolved.
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
};
