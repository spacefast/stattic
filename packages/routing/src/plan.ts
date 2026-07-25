import type { CompiledHeaderRule, CompiledRedirectRule, RoutingDiagnostic } from "./types.js";

// Plan compilation over the compiled routing artifact (internal-docs/platform.md
// "Routing And Headers", plans phase): the compiler always parses the FULL rule
// set (so diagnostics and upgrade paths stay precise), then this pass annotates
// it against plan policy. Everything here is soft — warnings and gating
// annotations, never finalize failures. The artifact itself stays plan-agnostic
// (proxy.md: "the rule stays in your config and activates the moment you
// upgrade — no redeploy needed") — `planGated` names the entitlement the
// runtime checks against live serving-state at request time; this pass never
// bakes a plan verdict into the compiled rule. Compiler SCALE limits
// (2,100/100/2,000) stay error-severity in diagnostics.ts; PLAN limits live here.

export type RoutingPlanPolicy = {
  /** Plan allows external 200 proxy rules (Free/anonymous: false). */
  externalProxy: boolean;
  /** Pooled routing-rule allowance (rewrites+redirects+headers+auth blocks). */
  routingRules: number | "unlimited";
};

export type RoutingPlanResult = {
  redirects: CompiledRedirectRule[];
  /** ONE pooled total across redirect rules and header/auth blocks. */
  pooledRuleCount: number;
  diagnostics: RoutingDiagnostic[];
};

export function applyRoutingPlanPolicy(
  input: {
    redirects: CompiledRedirectRule[];
    headers: CompiledHeaderRule[];
  },
  policy: RoutingPlanPolicy,
): RoutingPlanResult {
  const diagnostics: RoutingDiagnostic[] = [];

  // The compiled artifact stays plan-agnostic: every "proxy" rule is stamped
  // `planGated: "external_proxy"` regardless of the CURRENT plan, so the
  // runtime — not this compile step — decides at serve time whether the
  // team's live entitlement allows it (serving-state sync, no republish on
  // upgrade/downgrade). The diagnostic still only fires when the compiling
  // team isn't entitled today, since it's advisory feedback for THIS publish.
  const redirects = input.redirects.map((rule) => {
    if (rule.action !== "proxy") {
      return rule;
    }
    if (!policy.externalProxy) {
      diagnostics.push({
        file: "_redirects",
        line: 0,
        severity: "warning",
        code: "free_external_proxy_disabled",
        message: `External proxying requires a paid plan; "${rule.source} ${rule.destination} 200" publishes but serves the plan-restriction page until upgraded.`,
        source: `${rule.source} ${rule.destination}`,
      });
    }
    return {
      ...rule,
      planGated: "external_proxy" as const,
    };
  });

  // Pooled plan-rule accounting (spec: one total bucket across redirects,
  // rewrites, proxies, header rules, and auth blocks).
  const pooledRuleCount = input.redirects.length + input.headers.length;
  const limit = policy.routingRules;
  if (limit !== "unlimited" && pooledRuleCount > limit) {
    // Soft phase (spec: routing-rule plan limits are warning-based initially;
    // over-limit rules never fail finalize).
    diagnostics.push({
      file: "_redirects",
      line: 0,
      severity: "warning",
      code: "routing_rules_over_plan",
      message: `This version compiles ${pooledRuleCount} routing rules; the plan includes ${limit}. Over-limit rules still serve during the soft-limit phase.`,
      source: `${pooledRuleCount} rules / plan limit ${limit}`,
    });
  }

  return { redirects, pooledRuleCount, diagnostics };
}
