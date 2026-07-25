import { unwrapPrepareResponse } from "./prepare-protocol.js";
import type { FinalizerProtocolMetadata } from "./protocol.generated.js";
import { invokeFinalizerJson } from "./wasi.js";

export type { FinalizerProtocolMetadata } from "./protocol.generated.js";
export {
  compileRoutingFilesWasm,
  invokeFinalizerJson,
  verifyBcryptPassword,
  type WasmRoutingCompilation,
} from "./wasi.js";

const REQUEST_FORMAT = "spacefast.finalizer.prepare.request.v1";

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- WASM decoding follows the caller-selected protocol output type.
export function invokeFinalizeOperation<T>(operation: string, input: unknown): T {
  return unwrapPrepareResponse<T>(
    invokeFinalizerJson("sf_prepare_finalize", {
      format: REQUEST_FORMAT,
      operation,
      input,
    }),
  );
}

let cachedMetadata: FinalizerProtocolMetadata | null = null;

export function finalizerProtocolMetadataRust(): FinalizerProtocolMetadata {
  cachedMetadata ??= invokeFinalizeOperation<FinalizerProtocolMetadata>("metadata", {});
  return cachedMetadata;
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function normalizeConfigOverlayRust<T>(config: unknown): T {
  return invokeFinalizeOperation<T>("normalize_overlay", { config });
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function compileSpaceThemeRust<T>(theme: unknown): T {
  return invokeFinalizeOperation<T>("compile_space_theme", { theme: theme ?? null });
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function validateThemeJsonRust<T>(source: string): T {
  return invokeFinalizeOperation<T>("validate_theme_json", { source });
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function mergeGateThemeRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("merge_gate_theme", input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function resolveEffectiveConfigRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("resolve_effective", input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function buildRuntimePayloadRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("build_runtime_payload", input);
}

export function mergePreviewLoaderCspRust(headers: string, origins: unknown): string {
  return invokeFinalizeOperation<string>("merge_preview_loader_csp", { headers, origins });
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function lowerRuntimeConventionsRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("lower_runtime_conventions", input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function applyRoutingPlanPolicyRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("apply_routing_plan_policy", input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function transformHtmlRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("transform_html", input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function compilePageRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("compile_page", input);
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function parseJsoncRust<T>(source: string): T {
  return invokeFinalizeOperation<T>("parse_jsonc", { source });
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function resolveDependencyDigestRust<T>(input: unknown): T {
  return invokeFinalizeOperation<T>("resolve_dependency_digest", input);
}
