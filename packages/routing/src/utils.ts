import type { CompiledRedirectRule } from "./types.js";

export const ABSOLUTE_URL_PATTERN = /^https?:\/\//i;
const ABSOLUTE_URL_PARSE_PATTERN = /^(https?):\/\/([^/?#]+)([^?#]*)(?:\?[^#]*)?(?:#.*)?$/i;
const PLACEHOLDER_NAME_PATTERN = /^[A-Za-z][A-Za-z0-9_]*/;
export const PYTHON_NAMED_CAPTURE_PATTERN = /\(\?P<([A-Za-z][A-Za-z0-9_]*)>/g;
const REGEX_ESCAPE_PATTERN = /[|\\{}()[\]^$+?.]/g;
const TRAILING_DOT_PATTERN = /\.$/;
const TRAILING_SLASH_PATTERN = /\/+$/;
export const WHITESPACE_PATTERN = /\s+/;

export function escapeRegex(input: string) {
  return input.replace(REGEX_ESCAPE_PATTERN, "\\$&");
}

export function compilePattern(
  pattern: string,
  delimiter: "/" | ".",
  options: { allowWildcardPrefixInSegment?: boolean; optionalTrailingSlash?: boolean } = {},
) {
  let seenSplat = false;
  const seenNames = new Set<string>();
  let output = "^";
  let dynamic = false;

  const segments = pattern.split(delimiter);
  for (const segment of segments) {
    if (
      segment.includes("*") &&
      segment !== "*" &&
      !segment.endsWith("*") &&
      !(options.allowWildcardPrefixInSegment && segment.startsWith("*"))
    ) {
      return {
        error: "Wildcards must appear at the end of a path segment.",
        regex: null,
        match: "pattern" as const,
      };
    }
    if (segment.includes("*") && segment.includes(":")) {
      return {
        error: "Wildcards and placeholders cannot be used in the same segment.",
        regex: null,
        match: "pattern" as const,
      };
    }
  }

  for (let index = 0; index < pattern.length; ) {
    const char = pattern[index];
    if (char === "*") {
      if (seenSplat) {
        return { error: "Only one wildcard is supported.", regex: null, match: "pattern" as const };
      }
      seenSplat = true;
      dynamic = true;
      output += "(?P<splat>.*)";
      index += 1;
      continue;
    }

    if (char === ":") {
      const name = pattern.slice(index + 1).match(PLACEHOLDER_NAME_PATTERN)?.[0] ?? null;
      if (!name) {
        return {
          error: "Placeholders must start with a letter.",
          regex: null,
          match: "pattern" as const,
        };
      }
      if (seenNames.has(name)) {
        return {
          error: `Placeholder ":${name}" can only be captured once.`,
          regex: null,
          match: "pattern" as const,
        };
      }
      seenNames.add(name);
      dynamic = true;
      output += delimiter === "/" ? `(?P<${name}>[^/]+)` : `(?P<${name}>[^.]+)`;
      index += name.length + 1;
      continue;
    }

    output += escapeRegex(char as string);
    index += 1;
  }

  if (!dynamic) {
    return { error: null, regex: null, match: "exact" as const };
  }

  return {
    error: null,
    regex: `${output}${options.optionalTrailingSlash ? "/?" : ""}$`,
    match: seenSplat && seenNames.size === 0 ? ("splat" as const) : ("pattern" as const),
  };
}

export function normalizeHostname(input: string) {
  return input.trim().replace(TRAILING_DOT_PATTERN, "").toLowerCase();
}

export function hasHostPattern(host: string) {
  return host.includes("*") || host.includes(":");
}

export function parseAbsoluteUrl(value: string) {
  const match = value.match(ABSOLUTE_URL_PARSE_PATTERN);
  if (!match) {
    return null;
  }

  const scheme = match[1]?.toLowerCase();
  const authority = match[2];
  if (!scheme || !authority || authority.includes("@")) {
    return null;
  }

  const portMatch = authority.match(/:(\d+)$/);
  const host = portMatch ? authority.slice(0, -portMatch[0].length) : authority;
  if (!host) {
    return null;
  }

  return {
    scheme,
    host: host.trim().replace(TRAILING_DOT_PATTERN, ""),
    path: match[3] || "/",
    port: portMatch?.[1] ?? "",
  };
}

export function stripTrailingSlash(value: string) {
  return value === "/" ? "/" : value.replace(TRAILING_SLASH_PATTERN, "");
}

export function patternMatchesValue(pattern: string, delimiter: "/" | ".", value: string) {
  const compiled = compilePattern(pattern, delimiter);
  if (!compiled.regex) {
    return pattern === value;
  }
  const javascriptRegex = compiled.regex.replace(PYTHON_NAMED_CAPTURE_PATTERN, "(?<$1>");
  return new RegExp(javascriptRegex).test(value);
}

export function isStaticRedirect(rule: CompiledRedirectRule) {
  return rule.match === "exact" && rule.host === null;
}
