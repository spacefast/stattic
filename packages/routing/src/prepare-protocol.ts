import type { SpaceConfigFile } from "./protocol.generated.js";

const REQUEST_FORMAT = "spacefast.finalizer.prepare.request.v1";
const RESPONSE_FORMAT = "spacefast.finalizer.prepare.response.v1";
export const ANALYZE_INPUT_FORMAT = "spacefast.finalizer.analyze.input.v1";
export const PREPARE_INPUT_FORMAT = "spacefast.finalizer.prepare.input.v1";

export type PrepareDiagnostic = {
  severity: "info" | "warning" | "error";
  code: string;
  message: string;
  path?: string;
  details?: Record<string, unknown>;
};

export type ConventionFiles = { redirects?: string; headers?: string };
export type AnalyzeFinalizeInput = {
  configCandidates?: Record<string, string>;
  conventionFiles?: ConventionFiles;
  templateSources?: Record<string, string>;
};
export type VariableRequirement = { name: string; system: boolean };
export type AnalyzeFinalizeOutput = {
  selectedConfigPath?: string;
  config?: SpaceConfigFile;
  templatePaths: string[];
  variableRequirements: VariableRequirement[];
  diagnostics: PrepareDiagnostic[];
};
export type ScopedVariable = {
  value?: string;
  secret?: boolean;
  channelValues?: Record<string, string>;
};
export type VariableScope = { kind: string; values?: Record<string, ScopedVariable> };
export type PrepareFinalizeInput = AnalyzeFinalizeInput & {
  variableScopes?: VariableScope[];
  systemVariables?: Record<string, string>;
  channels?: Array<{ name: string; routeName: string }>;
};
export type PrepareFinalizeOutput = AnalyzeFinalizeOutput & {
  conventionFiles?: ConventionFiles;
  runtimeConventionFiles?: ConventionFiles;
  templateFiles: Record<string, string>;
  templateVariants: Record<string, Record<string, string>>;
  substitutedPaths: string[];
  dependencies: Record<string, string>;
  systemDependencies: string[];
};

type PrepareResponse<T> = {
  format: string;
  ok: boolean;
  output?: T;
  error?: { code: string; message: string };
};

export function prepareRequest(
  operation: "analyze" | "prepare",
  input: AnalyzeFinalizeInput | PrepareFinalizeInput,
): unknown {
  const common = {
    configCandidates: input.configCandidates ?? {},
    conventionFiles: input.conventionFiles ?? {},
    templateSources: input.templateSources ?? {},
  };
  return {
    format: REQUEST_FORMAT,
    operation,
    input:
      operation === "analyze"
        ? { format: ANALYZE_INPUT_FORMAT, ...common }
        : {
            format: PREPARE_INPUT_FORMAT,
            ...common,
            variableScopes: (input as PrepareFinalizeInput).variableScopes ?? [],
            systemVariables: (input as PrepareFinalizeInput).systemVariables ?? {},
            channels: (input as PrepareFinalizeInput).channels ?? [],
          },
  };
}

// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- protocol operation determines the decoded output type.
export function unwrapPrepareResponse<T>(response: unknown): T {
  const prepared = response as PrepareResponse<T>;
  if (prepared.format !== RESPONSE_FORMAT) {
    throw new Error(`stattic_runtime_core_prepare_response_invalid:${prepared.format}`);
  }
  if (!prepared.ok || prepared.output === undefined) {
    const code = prepared.error?.code ?? "finalizer_prepare_failed";
    const message = prepared.error?.message ?? "Rust finalizer prepare failed.";
    throw new Error(`stattic_runtime_core_prepare_failed:${code}:${message}`);
  }
  return prepared.output;
}
