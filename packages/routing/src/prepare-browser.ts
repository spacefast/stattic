import { invokeBrowserFinalizerJson } from "./browser.js";
import {
  prepareRequest,
  unwrapPrepareResponse,
  type AnalyzeFinalizeInput,
  type AnalyzeFinalizeOutput,
  type PrepareFinalizeInput,
  type PrepareFinalizeOutput,
} from "./prepare-protocol.js";

export type * from "./prepare-protocol.js";

async function invokePrepare<T>(
  operation: "analyze" | "prepare",
  input: AnalyzeFinalizeInput | PrepareFinalizeInput,
): Promise<T> {
  return unwrapPrepareResponse<T>(
    await invokeBrowserFinalizerJson(prepareRequest(operation, input)),
  );
}

export function analyzeFinalize(input: AnalyzeFinalizeInput): Promise<AnalyzeFinalizeOutput> {
  return invokePrepare("analyze", input);
}

export function prepareFinalize(input: PrepareFinalizeInput): Promise<PrepareFinalizeOutput> {
  return invokePrepare("prepare", input);
}
