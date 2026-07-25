import { describe, expect, test } from "bun:test";

import { applyRoutingPlanPolicy } from "./plan.js";
import type { CompiledHeaderRule, CompiledRedirectRule } from "./types.js";

function redirect(overrides: Partial<CompiledRedirectRule>): CompiledRedirectRule {
  return {
    source: "/a",
    destination: "/b",
    action: "redirect",
    status: 301,
    match: "exact",
    regex: null,
    host: null,
    hostRegex: null,
    force: false,
    query: null,
    conditions: [],
    cache: null,
    ...overrides,
  };
}

function header(): CompiledHeaderRule {
  return { path: "/x", host: null, regex: null, hostRegex: null, operations: [], headers: {} };
}

describe("applyRoutingPlanPolicy", () => {
  test("stamps proxy rules planGated with a warning when the plan disallows them, never baking disabled", () => {
    const proxy = redirect({ action: "proxy", destination: "https://api.example.com/" });
    const result = applyRoutingPlanPolicy(
      { redirects: [redirect({}), proxy], headers: [] },
      { externalProxy: false, routingRules: "unlimited" },
    );
    expect(result.redirects[0]?.planGated).toBeUndefined();
    expect(result.redirects[0]?.disabled).toBeUndefined();
    expect(result.redirects[1]).toMatchObject({ planGated: "external_proxy" });
    expect(result.redirects[1]?.disabled).toBeUndefined();
    expect(result.diagnostics).toHaveLength(1);
    expect(result.diagnostics[0]).toMatchObject({
      severity: "warning",
      code: "free_external_proxy_disabled",
    });
  });

  test("stamps proxy rules planGated (no warning) when the plan allows external proxying", () => {
    const result = applyRoutingPlanPolicy(
      { redirects: [redirect({ action: "proxy", cache: "shared" })], headers: [] },
      { externalProxy: true, routingRules: "unlimited" },
    );
    expect(result.redirects[0]).toMatchObject({ planGated: "external_proxy", cache: "shared" });
    expect(result.redirects[0]?.disabled).toBeUndefined();
    expect(result.diagnostics).toEqual([]);
  });

  test("never stamps planGated on non-proxy actions regardless of plan", () => {
    const result = applyRoutingPlanPolicy(
      {
        redirects: [redirect({ action: "redirect" }), redirect({ action: "rewrite" })],
        headers: [],
      },
      { externalProxy: false, routingRules: "unlimited" },
    );
    expect(result.redirects.every((rule) => rule.planGated === undefined)).toBe(true);
    expect(result.diagnostics).toEqual([]);
  });

  test("pools redirects and header blocks into one count with a soft over-plan warning", () => {
    const result = applyRoutingPlanPolicy(
      {
        redirects: Array.from({ length: 8 }, () => redirect({})),
        headers: Array.from({ length: 4 }, header),
      },
      { externalProxy: true, routingRules: 10 },
    );
    expect(result.pooledRuleCount).toBe(12);
    expect(result.diagnostics).toHaveLength(1);
    expect(result.diagnostics[0]).toMatchObject({
      severity: "warning",
      code: "routing_rules_over_plan",
    });
  });

  test("emits nothing at or under the pooled plan limit", () => {
    const result = applyRoutingPlanPolicy(
      { redirects: Array.from({ length: 6 }, () => redirect({})), headers: [header()] },
      { externalProxy: true, routingRules: 10 },
    );
    expect(result.pooledRuleCount).toBe(7);
    expect(result.diagnostics).toEqual([]);
  });
});
