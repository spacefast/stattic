import { addLimitDiagnostics } from "./diagnostics.js";
import { compileHeaders } from "./headers.js";
import { compileRedirects } from "./redirects.js";
import type { RoutingCompilation, RoutingCompileOptions, RoutingDiagnostic } from "./types.js";
import { isStaticRedirect } from "./utils.js";

export function compileRoutingFiles(input: {
  redirects?: string | undefined;
  headers?: string | undefined;
  options?: RoutingCompileOptions | undefined;
}): RoutingCompilation {
  const diagnostics: RoutingDiagnostic[] = [];
  const options = input.options ?? {};

  const redirects = compileRedirects(input.redirects ?? "", options, diagnostics);
  const headers = compileHeaders(input.headers ?? "", diagnostics);
  addLimitDiagnostics(redirects, headers, diagnostics);

  const staticRedirectCount = redirects.filter(isStaticRedirect).length;
  return {
    redirects,
    headers,
    diagnostics,
    stats: {
      redirectRuleCount: redirects.length,
      headerRuleCount: headers.length,
      staticRedirectCount,
      dynamicRedirectCount: redirects.length - staticRedirectCount,
      basicAuthRuleCount: headers.filter((rule) =>
        rule.operations.some((operation) => operation.kind === "basicAuth"),
      ).length,
      proxyRuleCount: redirects.filter((rule) => rule.action === "proxy").length,
    },
  };
}
