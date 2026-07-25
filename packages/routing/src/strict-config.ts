import { invokeFinalizerJson } from "./wasi.js";

type StrictConfigIssue = {
  code: string;
  severity: "error";
  path: string;
  line: number;
  column: number;
  message: string;
  suggestion?: string;
};

export type StrictConfigOutput = {
  success: boolean;
  config: unknown;
  effective: unknown;
  issues: StrictConfigIssue[];
  locations: Record<string, { line: number; column: number }>;
};

export function compileStrictConfigV1(input: {
  source: string;
  artifactPaths?: readonly string[];
  templateSources?: readonly { path: string; source: string }[];
}): StrictConfigOutput {
  const response = invokeFinalizerJson<{
    format: string;
    ok: boolean;
    output?: StrictConfigOutput;
    error?: { code: string; message: string };
  }>("sf_prepare_finalize", {
    format: "spacefast.finalizer.prepare.request.v1",
    operation: "compile_strict_v1",
    input: {
      format: "spacefast.config.strict-v1.input.v1",
      source: input.source,
      ...(input.artifactPaths === undefined ? {} : { artifactPaths: input.artifactPaths }),
      templateSources: input.templateSources ?? [],
    },
  });
  if (!response.ok || response.output === undefined) {
    throw new Error(
      `stattic_runtime_core_strict_config_failed:${response.error?.code ?? "unknown"}:${response.error?.message ?? "Rust strict config compiler failed."}`,
    );
  }
  return response.output;
}
