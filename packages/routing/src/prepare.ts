import {
  prepareRequest,
  unwrapPrepareResponse,
  type AnalyzeFinalizeInput,
  type AnalyzeFinalizeOutput,
  type PrepareFinalizeInput,
  type PrepareFinalizeOutput,
} from "./prepare-protocol.js";
import { invokeFinalizerJson } from "./wasi.js";

export type * from "./prepare-protocol.js";

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
function invokePrepare<T>(operation: "analyze" | "prepare", input: unknown): T {
  return unwrapPrepareResponse<T>(
    invokeFinalizerJson(
      "sf_prepare_finalize",
      prepareRequest(operation, input as AnalyzeFinalizeInput),
    ),
  );
}

export function analyzeFinalize(input: AnalyzeFinalizeInput): AnalyzeFinalizeOutput {
  return invokePrepare("analyze", input);
}

export function prepareFinalize(input: PrepareFinalizeInput): PrepareFinalizeOutput {
  return invokePrepare("prepare", input);
}
