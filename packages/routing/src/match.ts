import type {
  CompiledHeaderRule,
  CompiledRedirectCondition,
  CompiledRedirectRule,
  RoutingCompilation,
} from "./types.js";
import {
  escapeRegex,
  normalizeHostname,
  PYTHON_NAMED_CAPTURE_PATTERN,
  stripTrailingSlash,
  WHITESPACE_PATTERN,
} from "./utils.js";

export type RoutingRequest = {
  url: URL;
  headers: Headers;
};

export type RedirectMatch =
  | { action: "redirect"; rule: CompiledRedirectRule; target: string; status: number }
  | { action: "rewrite" | "notFound"; rule: CompiledRedirectRule; path: string; status: number }
  | { action: "proxy"; rule: CompiledRedirectRule; target: string };

// THE python→JS named-group regex bridge for compiled-rule patterns. Exported
// so consumers matching compiled rules outside this evaluator (e.g. the CLI's
// fingerprint pass probing user Cache-Control coverage) share one translation
// instead of growing their own.
export function jsRegex(pattern: string) {
  return new RegExp(pattern.replace(PYTHON_NAMED_CAPTURE_PATTERN, "(?<$1>"));
}

function hostMatches(rule: { hostRegex: string | null }, host: string) {
  return !rule.hostRegex || jsRegex(rule.hostRegex).test(normalizeHostname(host));
}

function pathCaptures(rule: { regex: string | null }, path: string) {
  if (!rule.regex) {
    return {};
  }
  const match = jsRegex(rule.regex).exec(path);
  return match ? { ...match.groups } : null;
}

function queryMatches(
  rule: CompiledRedirectRule,
  request: RoutingRequest,
  captures: Record<string, string>,
) {
  if (!rule.query) {
    return true;
  }
  const params = request.url.searchParams;
  if (new Set(params.keys()).size !== Object.keys(rule.query).length) {
    return false;
  }
  for (const [name, capture] of Object.entries(rule.query)) {
    if (!params.has(name)) {
      return false;
    }
    captures[capture] = params.getAll(name).join(",");
  }
  return true;
}

function cookies(request: RoutingRequest) {
  return new Set(
    (request.headers.get("cookie") ?? "")
      .split(";")
      .map((entry) => entry.split("=", 1)[0]?.trim().toLowerCase())
      .filter((name): name is string => Boolean(name)),
  );
}

function acceptedLanguage(request: RoutingRequest) {
  return (
    request.headers.get("x-spacefast-language") ??
    request.headers.get("accept-language")?.split(",", 1)[0]?.split(";", 1)[0] ??
    ""
  )
    .trim()
    .toLowerCase();
}

// Must stay in lockstep with _stattic_is_agent_request in
// runtime/engine/runtime/redirects.php — this matcher validates and simulates,
// that function serves. curl and wget count as agents: on this platform a
// shell fetch is an agent, not a person. The shared expectation table lives in
// packages/routing/fixtures/agent-detection.json (replayed against both twins,
// needle list parity-checked): widen there first, then both implementations.
function isAgentRequest(request: RoutingRequest) {
  const accept = request.headers.get("accept")?.toLowerCase() ?? "";
  if (
    (accept.includes("text/plain") || accept.includes("text/markdown")) &&
    !accept.includes("text/html")
  ) {
    return true;
  }
  const userAgent = request.headers.get("user-agent")?.toLowerCase() ?? "";
  return [
    "anthropic",
    "chatgpt",
    "claude",
    "codex",
    "curl",
    "cursor",
    "gptbot",
    "llm",
    "openai",
    "perplexity",
    "wget",
  ].some((needle) => userAgent.includes(needle));
}

function conditionMatches(condition: CompiledRedirectCondition, request: RoutingRequest) {
  if (condition.kind === "country") {
    const country = request.headers.get("x-spacefast-country")?.toLowerCase() ?? "";
    return country !== "" && condition.values.includes(country);
  }
  if (condition.kind === "language") {
    const language = acceptedLanguage(request);
    return language !== "" && condition.values.includes(language);
  }
  if (condition.kind === "cookie") {
    const names = cookies(request);
    return condition.values.some((name) => names.has(name.toLowerCase()));
  }
  return isAgentRequest(request);
}

function conditionsMatch(rule: CompiledRedirectRule, request: RoutingRequest) {
  return rule.conditions.every((condition) => conditionMatches(condition, request));
}

function expand(template: string, captures: Record<string, string>) {
  return template.replace(/:([A-Za-z0-9_]+)/g, (_match, name: string) => captures[name] ?? "");
}

function targetPath(target: string, fallback: string, status: number) {
  try {
    const parsed = new URL(target, "http://stattic.local");
    return { path: `${parsed.pathname}${parsed.search}`, status };
  } catch {
    return { path: fallback, status };
  }
}

function appendIncomingQuery(target: string, query: string) {
  if (!query || target.includes("?")) {
    return target;
  }
  const [base, fragment] = target.split("#", 2);
  return `${base}?${query}${fragment === undefined ? "" : `#${fragment}`}`;
}

export function matchRedirect(input: {
  compilation: RoutingCompilation;
  request: RoutingRequest;
  hasStaticFile?: (path: string) => boolean;
}): RedirectMatch | null {
  const host = input.request.url.host;
  const path = stripTrailingSlash(input.request.url.pathname);

  for (const rule of input.compilation.redirects) {
    if (!hostMatches(rule, host)) {
      continue;
    }
    let captures: Record<string, string> | null;
    if (rule.regex) {
      captures = pathCaptures(rule, path);
    } else {
      captures = rule.source === path ? {} : null;
    }
    if (
      !captures ||
      !queryMatches(rule, input.request, captures) ||
      !conditionsMatch(rule, input.request)
    ) {
      continue;
    }

    const target = expand(rule.destination, captures);
    if (rule.action === "proxy") {
      return { action: "proxy", rule, target };
    }
    if (rule.action === "rewrite" || rule.action === "notFound") {
      if (!rule.force && input.hasStaticFile?.(input.request.url.pathname)) {
        continue;
      }
      return {
        action: rule.action,
        rule,
        ...targetPath(target, input.request.url.pathname, rule.action === "notFound" ? 404 : 200),
      };
    }
    return {
      action: "redirect",
      rule,
      target: appendIncomingQuery(target, input.request.url.search.slice(1)),
      status: rule.status,
    };
  }

  return null;
}

function isExactHeaderRule(rule: CompiledHeaderRule) {
  return rule.regex === `^${escapeRegex(rule.path)}$`;
}

function matchedHeaderCaptures(
  rule: CompiledHeaderRule,
  host: string,
  path: string,
): Record<string, string> | null {
  if (!hostMatches(rule, host)) return null;
  if (!isExactHeaderRule(rule)) {
    return pathCaptures(rule, path);
  }
  return rule.path === path ? {} : null;
}

export function headersForRequest(compilation: RoutingCompilation, request: RoutingRequest) {
  const host = request.url.host;
  const path = request.url.pathname;
  const applied = new Map<string, { name: string; values: string[] }>();

  for (const rule of compilation.headers) {
    const captures = matchedHeaderCaptures(rule, host, path);
    if (!captures) {
      continue;
    }
    for (const operation of rule.operations) {
      const key = operation.name.toLowerCase();
      if (operation.kind === "remove") {
        applied.delete(key);
      } else if (operation.kind === "set") {
        const value = expand(operation.value, captures);
        const current = applied.get(key);
        if (current) {
          current.values.push(value);
        } else {
          applied.set(key, { name: operation.name, values: [value] });
        }
      }
    }
  }

  return Object.fromEntries(
    Array.from(applied.values()).map((header) => [
      header.name,
      header.values.length === 1 ? (header.values[0] ?? "") : header.values,
    ]),
  );
}

export function basicAuthCredentials(compilation: RoutingCompilation, request: RoutingRequest) {
  const host = request.url.host;
  const path = request.url.pathname;
  const credentials: string[] = [];

  for (const rule of compilation.headers) {
    const captures = matchedHeaderCaptures(rule, host, path);
    if (!captures) {
      continue;
    }
    for (const operation of rule.operations) {
      if (operation.kind === "basicAuth") {
        credentials.push(...operation.value.split(WHITESPACE_PATTERN).filter(Boolean));
      }
    }
  }

  return credentials;
}
