import type {
  CompiledHeaderRule,
  CompiledRedirectRule,
  RoutingDiagnostic,
  RoutingFile,
} from "./types.js";
import { isStaticRedirect } from "./utils.js";

const HEADER_RULE_LIMIT = 100;
const REDIRECT_TOTAL_LIMIT = 2_100;
const REDIRECT_STATIC_LIMIT = 2_000;
const REDIRECT_DYNAMIC_LIMIT = 100;

export function diagnostic(
  diagnostics: RoutingDiagnostic[],
  file: RoutingFile,
  line: number,
  severity: "warning" | "error",
  code: string,
  message: string,
  source: string,
) {
  diagnostics.push({ file, line, severity, code, message, source });
}

export function addLimitDiagnostics(
  redirects: CompiledRedirectRule[],
  headers: CompiledHeaderRule[],
  diagnostics: RoutingDiagnostic[],
) {
  const staticRedirects = redirects.filter(isStaticRedirect).length;
  const dynamicRedirects = redirects.length - staticRedirects;

  if (redirects.length > REDIRECT_TOTAL_LIMIT) {
    diagnostic(
      diagnostics,
      "_redirects",
      0,
      "error",
      "redirect_limit_exceeded",
      `Use ${REDIRECT_TOTAL_LIMIT} or fewer redirect rules.`,
      "",
    );
  }
  if (staticRedirects > REDIRECT_STATIC_LIMIT) {
    diagnostic(
      diagnostics,
      "_redirects",
      0,
      "error",
      "redirect_static_limit_exceeded",
      `Use ${REDIRECT_STATIC_LIMIT} or fewer static redirect rules.`,
      "",
    );
  }
  if (dynamicRedirects > REDIRECT_DYNAMIC_LIMIT) {
    diagnostic(
      diagnostics,
      "_redirects",
      0,
      "error",
      "redirect_dynamic_limit_exceeded",
      `Use ${REDIRECT_DYNAMIC_LIMIT} or fewer dynamic redirect rules.`,
      "",
    );
  }
  if (headers.length > HEADER_RULE_LIMIT) {
    diagnostic(
      diagnostics,
      "_headers",
      0,
      "error",
      "header_limit_exceeded",
      `Use ${HEADER_RULE_LIMIT} or fewer header rules.`,
      "",
    );
  }
}
