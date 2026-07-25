import { diagnostic } from "./diagnostics.js";
import type {
  CompiledRedirectCondition,
  CompiledRedirectRule,
  RoutingCompileOptions,
  RoutingDiagnostic,
} from "./types.js";
import {
  ABSOLUTE_URL_PATTERN,
  compilePattern,
  escapeRegex,
  hasHostPattern,
  normalizeHostname,
  patternMatchesValue,
  parseAbsoluteUrl,
  stripTrailingSlash,
  WHITESPACE_PATTERN,
} from "./utils.js";

const REDIRECT_STATUSES = new Set([200, 301, 302, 303, 307, 308, 404]);
const REDIRECT_DECLARATION_LIMIT = 1_000;

const COUNTRY_CODE_PATTERN = /^[a-z]{2}$/i;
const AGENT_CONDITION_VALUES = new Set(["1", "true", "yes"]);
const LINE_SPLIT_PATTERN = /\r?\n/;
const QUERY_TOKEN_PATTERN = /^[A-Za-z][A-Za-z0-9_-]*=:[A-Za-z][A-Za-z0-9_]*$/;
const STATUS_START_PATTERN = /^[0-9]/;
const STATUS_TOKEN_PATTERN = /^(\d{3})(!)?$/;

function isQueryToken(token: string) {
  return QUERY_TOKEN_PATTERN.test(token);
}

function parseQueryTokens(tokens: string[]) {
  const query: Record<string, string> = {};
  for (const token of tokens) {
    const [name, capture] = token.split("=:");
    if (!name || !capture) {
      return null;
    }
    query[name] = capture;
  }
  return query;
}

function parseConditions(
  tokens: string[],
  line: number,
  source: string,
  diagnostics: RoutingDiagnostic[],
) {
  const conditions: CompiledRedirectCondition[] = [];

  for (const token of tokens) {
    const [rawName, rawValue] = token.split("=");
    const name = rawName?.toLowerCase();
    const values = rawValue?.split(",").filter(Boolean) ?? [];
    if (!name || values.length === 0 || token.includes(" ")) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_condition_invalid",
        "This condition is not valid. Check the name and values.",
        source,
      );
      continue;
    }

    if (name === "country") {
      if (values.some((value) => !COUNTRY_CODE_PATTERN.test(value))) {
        diagnostic(
          diagnostics,
          "_redirects",
          line,
          "error",
          "redirect_country_invalid",
          "Country values must use two-letter country codes.",
          source,
        );
        continue;
      }
      conditions.push({ kind: "country", values: values.map((value) => value.toLowerCase()) });
      continue;
    }

    if (name === "language") {
      conditions.push({ kind: "language", values: values.map((value) => value.toLowerCase()) });
      continue;
    }

    if (name === "cookie") {
      conditions.push({ kind: "cookie", values });
      continue;
    }

    if (name === "agent") {
      const normalizedValues = values.map((value) => value.toLowerCase());
      if (normalizedValues.some((value) => !AGENT_CONDITION_VALUES.has(value))) {
        diagnostic(
          diagnostics,
          "_redirects",
          line,
          "error",
          "redirect_agent_condition_invalid",
          'Agent conditions must use "true", "yes", or "1".',
          source,
        );
        continue;
      }
      conditions.push({ kind: "agent", values: normalizedValues });
      continue;
    }

    if (name === "role") {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_role_unsupported",
        "Role-based routing is not supported. Remove this condition before publishing.",
        source,
      );
      continue;
    }

    diagnostic(
      diagnostics,
      "_redirects",
      line,
      "error",
      "redirect_condition_unsupported",
      `The "${rawName}" condition is not supported.`,
      source,
    );
  }

  return conditions;
}

function normalizeSource(
  rawSource: string,
  options: RoutingCompileOptions,
  line: number,
  sourceLine: string,
  diagnostics: RoutingDiagnostic[],
) {
  const absolute = parseAbsoluteUrl(rawSource);
  if (absolute) {
    const host = normalizeHostname(absolute.host);
    const hostCompiled = compilePattern(host, ".");
    if (hostCompiled.error) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_host_invalid",
        hostCompiled.error,
        sourceLine,
      );
      return null;
    }

    const assignedHostnames = new Set(options.assignedHostnames?.map(normalizeHostname) ?? []);
    if (assignedHostnames.size > 0 && !hasHostPattern(host) && !assignedHostnames.has(host)) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_hostname_unassigned",
        `The hostname "${host}" is not assigned to this space.`,
        sourceLine,
      );
      return null;
    }

    return {
      path: stripTrailingSlash(absolute.path),
      host,
      hostRegex: hostCompiled.regex ?? `^${escapeRegex(host)}$`,
    };
  }

  if (!rawSource.startsWith("/")) {
    diagnostic(
      diagnostics,
      "_redirects",
      line,
      "error",
      "redirect_source_invalid",
      "The source must start with / or use an absolute URL.",
      sourceLine,
    );
    return null;
  }

  return { path: stripTrailingSlash(rawSource), host: null, hostRegex: null };
}

function redirectLoop(source: { path: string; host: string | null }, destination: string) {
  const parsedDestination = parseAbsoluteUrl(destination);
  if (parsedDestination?.host !== undefined) {
    const destinationHost = normalizeHostname(parsedDestination.host);
    if (source.host === null) {
      return false;
    }
    if (hasHostPattern(source.host)) {
      if (!patternMatchesValue(normalizeHostname(source.host), ".", destinationHost)) {
        return false;
      }
    } else if (normalizeHostname(source.host) !== destinationHost) {
      return false;
    }
  }

  const destinationPath = parsedDestination?.path ?? destination.split("?", 1)[0] ?? destination;
  return stripTrailingSlash(source.path) === stripTrailingSlash(destinationPath);
}

function redirectAction(status: number, destination: string): CompiledRedirectRule["action"] {
  if (status === 404) {
    return "notFound";
  }
  if (status === 200 && ABSOLUTE_URL_PATTERN.test(destination)) {
    return "proxy";
  }
  if (status === 200) {
    return "rewrite";
  }
  return "redirect";
}

export function compileRedirects(
  content: string,
  options: RoutingCompileOptions,
  diagnostics: RoutingDiagnostic[],
) {
  const rules: CompiledRedirectRule[] = [];

  for (const [index, rawLine] of content.split(LINE_SPLIT_PATTERN).entries()) {
    const line = index + 1;
    const sourceLine = rawLine.trim();
    if (!sourceLine || sourceLine.startsWith("#")) {
      continue;
    }

    if (sourceLine.length > REDIRECT_DECLARATION_LIMIT) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_line_too_long",
        "This redirect rule is too long.",
        sourceLine,
      );
      continue;
    }

    const tokens = sourceLine.split(WHITESPACE_PATTERN);
    const rawSource = tokens[0];
    if (!rawSource) {
      continue;
    }

    const queryTokens: string[] = [];
    let destinationIndex = 1;
    while (destinationIndex < tokens.length && isQueryToken(tokens[destinationIndex] ?? "")) {
      queryTokens.push(tokens[destinationIndex] ?? "");
      destinationIndex += 1;
    }

    const destination = tokens[destinationIndex];
    if (!destination) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_destination_missing",
        "Add a destination for this redirect rule.",
        sourceLine,
      );
      continue;
    }

    const statusToken = tokens[destinationIndex + 1];
    const statusMatch = statusToken?.match(STATUS_TOKEN_PATTERN) ?? null;
    const status = statusMatch ? Number(statusMatch[1]) : 302;
    const force = statusMatch?.[2] === "!";
    const directiveTokens = tokens.slice(destinationIndex + (statusMatch ? 2 : 1));

    if (statusToken && !statusMatch && STATUS_START_PATTERN.test(statusToken)) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_status_invalid",
        "Use a supported status code, optionally followed by !.",
        sourceLine,
      );
      continue;
    }

    if (!REDIRECT_STATUSES.has(status)) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_status_unsupported",
        `Status ${status} is not supported.`,
        sourceLine,
      );
      continue;
    }

    const normalizedSource = normalizeSource(rawSource, options, line, sourceLine, diagnostics);
    if (!normalizedSource) {
      continue;
    }

    if (redirectLoop(normalizedSource, destination)) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_loop_invalid",
        "This rule points back to the same path and could create a loop.",
        sourceLine,
      );
      continue;
    }

    const pathCompiled = compilePattern(normalizedSource.path, "/", {
      optionalTrailingSlash: true,
    });
    if (pathCompiled.error) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_pattern_invalid",
        pathCompiled.error,
        sourceLine,
      );
      continue;
    }

    const query = queryTokens.length > 0 ? parseQueryTokens(queryTokens) : null;
    if (queryTokens.length > 0 && !query) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_query_match_invalid",
        "Query matches must look like name=:capture.",
        sourceLine,
      );
      continue;
    }

    if (
      (status === 200 && !destination.startsWith("/") && !ABSOLUTE_URL_PATTERN.test(destination)) ||
      (status === 404 && !destination.startsWith("/"))
    ) {
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "error",
        "redirect_rewrite_destination_invalid",
        "A rewrite destination must be a path or an absolute URL. A custom 404 destination must be a path.",
        sourceLine,
      );
      continue;
    }

    const action = redirectAction(status, destination);
    const conditionTokens: string[] = [];
    let cache: CompiledRedirectRule["cache"] = null;
    let invalidCacheDirective = false;
    for (const token of directiveTokens) {
      const separator = token.indexOf("=");
      const name = separator === -1 ? token : token.slice(0, separator);
      if (name.toLowerCase() !== "cache") {
        conditionTokens.push(token);
        continue;
      }

      const value = separator === -1 ? "" : token.slice(separator + 1);
      if (value !== "shared") {
        invalidCacheDirective = true;
        diagnostic(
          diagnostics,
          "_redirects",
          line,
          "error",
          "redirect_cache_directive_invalid",
          'Proxy cache directives must use "cache=shared".',
          sourceLine,
        );
        continue;
      }
      cache = "shared";
    }

    if (invalidCacheDirective) {
      cache = null;
    }
    if (cache === "shared" && action !== "proxy") {
      cache = null;
      diagnostic(
        diagnostics,
        "_redirects",
        line,
        "warning",
        "redirect_cache_not_proxy",
        'The "cache=shared" directive only applies to absolute-URL 200 proxy rules.',
        sourceLine,
      );
    }

    const conditions = parseConditions(conditionTokens, line, sourceLine, diagnostics);
    rules.push({
      source: normalizedSource.path,
      destination,
      action,
      status,
      match: pathCompiled.match,
      regex: pathCompiled.regex,
      host: normalizedSource.host,
      hostRegex: normalizedSource.hostRegex,
      force,
      query,
      conditions,
      cache,
    });
  }

  return rules;
}
