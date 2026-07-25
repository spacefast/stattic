import { diagnostic } from "./diagnostics.js";
import type { CompiledHeaderOperation, CompiledHeaderRule, RoutingDiagnostic } from "./types.js";
import {
  compilePattern,
  escapeRegex,
  normalizeHostname,
  parseAbsoluteUrl,
  WHITESPACE_PATTERN,
} from "./utils.js";

const HEADER_LINE_LIMIT = 2_000;

// Platform-managed response headers user rules can never set or remove. Mirrors
// PLATFORM_MANAGED_RESPONSE_HEADERS in @spacefast/common static-runtime-policy (parity
// asserted by apps/control-plane php-policy-parity.test.ts); the PHP runtime enforces
// the same map at apply time. The sendfile/accel family is security-critical: under
// nginx+php-fpm those headers turn responses into server-side file reads.
const UNSUPPORTED_RESPONSE_HEADERS = new Set([
  "accept-ranges",
  "age",
  "allow",
  "alt-svc",
  "connection",
  "cookie",
  "content-encoding",
  "content-length",
  "content-range",
  "date",
  "host",
  "location",
  "server",
  "set-cookie",
  "strict-transport-security",
  "trailer",
  "transfer-encoding",
  "upgrade",
  "vary",
  "x-accel-buffering",
  "x-accel-redirect",
  "x-lighttpd-send-file",
  "x-sendfile",
]);

const CDN_CACHE_HEADERS = new Set([
  "cdn-cache-control",
  "cloudflare-cdn-cache-control",
  "netlify-cdn-cache-control",
  "surrogate-control",
]);

const BASIC_AUTH_CREDENTIAL_PATTERN = /^[^:\s]+:[^\s]+$/;
const CARRIAGE_RETURN_PATTERN = /\r$/;
const HEADER_NAME_PATTERN = /^[A-Za-z0-9-]+$/;
const LINE_SPLIT_PATTERN = /\r?\n/;

function canonicalHeaderName(input: string) {
  return input
    .trim()
    .toLowerCase()
    .split("-")
    .map((part) => (part ? `${part[0]?.toUpperCase() ?? ""}${part.slice(1)}` : part))
    .join("-");
}

function compileHeaderMatcher(
  rawPath: string,
  line: number,
  sourceLine: string,
  diagnostics: RoutingDiagnostic[],
) {
  const absolute = parseAbsoluteUrl(rawPath);
  if (absolute) {
    if (absolute.port) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_port_unsupported",
        "Header URL matchers cannot include ports.",
        sourceLine,
      );
      return null;
    }

    const host = normalizeHostname(absolute.host);
    const hostCompiled = compilePattern(host, ".", {
      allowWildcardPrefixInSegment: true,
    });
    const pathCompiled = compilePattern(absolute.path, "/", {
      allowWildcardPrefixInSegment: true,
    });
    if (hostCompiled.error || pathCompiled.error) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_pattern_invalid",
        hostCompiled.error ?? pathCompiled.error ?? "This header matcher is not valid.",
        sourceLine,
      );
      return null;
    }

    return {
      path: absolute.path,
      host,
      regex: pathCompiled.regex ?? `^${escapeRegex(absolute.path)}$`,
      hostRegex: hostCompiled.regex ?? `^${escapeRegex(host)}$`,
    };
  }

  if (!rawPath.startsWith("/")) {
    diagnostic(
      diagnostics,
      "_headers",
      line,
      "error",
      "header_path_invalid",
      "The header matcher must start with / or use an absolute URL.",
      sourceLine,
    );
    return null;
  }

  const compiled = compilePattern(rawPath, "/", {
    allowWildcardPrefixInSegment: false,
  });
  if (compiled.error) {
    diagnostic(
      diagnostics,
      "_headers",
      line,
      "error",
      "header_pattern_invalid",
      compiled.error,
      sourceLine,
    );
    return null;
  }

  return {
    path: rawPath,
    host: null,
    regex: compiled.regex ?? `^${escapeRegex(rawPath)}$`,
    hostRegex: null,
  };
}

function normalizeHeaderOperation(
  operation: CompiledHeaderOperation,
  line: number,
  sourceLine: string,
  diagnostics: RoutingDiagnostic[],
) {
  const name = operation.name.trim();
  const lowerName = name.toLowerCase();

  if (lowerName === "basic-auth") {
    if (operation.kind !== "set" || !operation.value?.trim()) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_basic_auth_invalid",
        "Basic-Auth needs one or more username:password credentials.",
        sourceLine,
      );
      return null;
    }

    const credentials = operation.value.trim().split(WHITESPACE_PATTERN);
    if (credentials.some((credential) => !BASIC_AUTH_CREDENTIAL_PATTERN.test(credential))) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_basic_auth_invalid",
        "Basic-Auth credentials must use username:password.",
        sourceLine,
      );
      return null;
    }

    diagnostic(
      diagnostics,
      "_headers",
      line,
      "warning",
      "header_basic_auth_never_emitted",
      "Basic-Auth protects matching paths and is not sent as a response header.",
      sourceLine,
    );
    return { kind: "basicAuth" as const, name: "Basic-Auth", value: operation.value.trim() };
  }

  if (!HEADER_NAME_PATTERN.test(name)) {
    diagnostic(
      diagnostics,
      "_headers",
      line,
      "error",
      "header_name_invalid",
      "This header name is not valid.",
      sourceLine,
    );
    return null;
  }

  if (UNSUPPORTED_RESPONSE_HEADERS.has(lowerName)) {
    diagnostic(
      diagnostics,
      "_headers",
      line,
      "error",
      "header_name_unsupported",
      `The "${canonicalHeaderName(name)}" header is managed by the platform and cannot be changed.`,
      sourceLine,
    );
    return null;
  }

  if (CDN_CACHE_HEADERS.has(lowerName)) {
    diagnostic(
      diagnostics,
      "_headers",
      line,
      "error",
      "header_cdn_cache_unsupported",
      `The "${canonicalHeaderName(name)}" header does not have Spacefast semantics.`,
      sourceLine,
    );
    return null;
  }

  if (lowerName === "cache-control") {
    diagnostic(
      diagnostics,
      "_headers",
      line,
      "warning",
      "header_cache_control_platform_managed",
      "Cache-Control applies to browser responses; platform edge caching is managed by Spacefast.",
      sourceLine,
    );
  }

  return { ...operation, name: canonicalHeaderName(name) };
}

export function compileHeaders(content: string, diagnostics: RoutingDiagnostic[]) {
  const rules: CompiledHeaderRule[] = [];
  let currentPath: { path: string; line: number; source: string } | null = null;
  let operations: Array<CompiledHeaderOperation & { line: number; source: string }> = [];

  const flush = () => {
    if (!currentPath) {
      operations = [];
      return;
    }

    const matcher = compileHeaderMatcher(
      currentPath.path,
      currentPath.line,
      currentPath.source,
      diagnostics,
    );
    const normalizedOperations = operations.flatMap((operation) => {
      const normalized = normalizeHeaderOperation(
        operation,
        operation.line,
        operation.source,
        diagnostics,
      );
      return normalized ? [normalized] : [];
    });

    if (matcher && normalizedOperations.length > 0) {
      const headers: Record<string, string> = {};
      for (const operation of normalizedOperations) {
        if (operation.kind === "set" && operation.value !== null) {
          headers[operation.name] = operation.value;
        }
      }
      rules.push({ ...matcher, operations: normalizedOperations, headers });
    }

    currentPath = null;
    operations = [];
  };

  for (const [index, rawLine] of content.split(LINE_SPLIT_PATTERN).entries()) {
    const line = index + 1;
    const sourceLine = rawLine.replace(CARRIAGE_RETURN_PATTERN, "");
    const trimmed = sourceLine.trim();

    if (sourceLine.length > HEADER_LINE_LIMIT) {
      if (!sourceLine.startsWith(" ") && !sourceLine.startsWith("\t")) {
        flush();
      }
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_line_too_long",
        "This header line is too long.",
        trimmed,
      );
      continue;
    }

    if (!trimmed || trimmed.startsWith("#")) {
      continue;
    }

    if (!sourceLine.startsWith(" ") && !sourceLine.startsWith("\t")) {
      flush();
      currentPath = { path: trimmed, line, source: trimmed };
      continue;
    }

    if (!currentPath) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_operation_without_matcher",
        "Add a path or URL before this header.",
        trimmed,
      );
      continue;
    }

    if (trimmed.startsWith("!")) {
      const name = trimmed.slice(1).trim();
      if (!name) {
        diagnostic(
          diagnostics,
          "_headers",
          line,
          "error",
          "header_remove_invalid",
          "Choose a header to remove.",
          trimmed,
        );
        continue;
      }
      operations.push({ kind: "remove", name, value: null, line, source: trimmed });
      continue;
    }

    const separatorIndex = trimmed.indexOf(":");
    if (separatorIndex === -1) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_operation_invalid",
        "Header operations must use Name: value.",
        trimmed,
      );
      continue;
    }

    const name = trimmed.slice(0, separatorIndex).trim();
    const value = trimmed.slice(separatorIndex + 1).trim();
    if (!name) {
      diagnostic(
        diagnostics,
        "_headers",
        line,
        "error",
        "header_name_missing",
        "Add a header name.",
        trimmed,
      );
      continue;
    }
    operations.push({ kind: "set", name, value, line, source: trimmed });
  }

  flush();
  return rules;
}
