export type RoutingFile = "_redirects" | "_headers";

export type RoutingDiagnostic = {
  file: RoutingFile;
  line: number;
  severity: "warning" | "error";
  code: string;
  message: string;
  source: string;
  /** Rust classified this error as valid before server-side variable substitution. */
  deferredUntilSubstitution?: true;
};

export type CompiledRedirectCondition =
  | { kind: "country"; values: string[] }
  | { kind: "language"; values: string[] }
  | { kind: "cookie"; values: string[] }
  | { kind: "agent"; values: string[] };

export type CompiledRedirectRule = {
  source: string;
  destination: string;
  action: "redirect" | "rewrite" | "proxy" | "notFound";
  status: number;
  match: "exact" | "splat" | "pattern";
  regex: string | null;
  host: string | null;
  hostRegex: string | null;
  force: boolean;
  query: Record<string, string> | null;
  conditions: CompiledRedirectCondition[];
  /** Explicit proxy response cache mode. Absent/default rules never enter shared caches. */
  cache: "shared" | null;
  // Plan-policy annotation (spec "Routing And Headers"): the compiled artifact
  // is plan-agnostic — `planGated` names the entitlement key the runtime must
  // check at SERVE time (serving-state sync, no republish needed to
  // enable/disable). `disabled` is a back-compat field for artifacts compiled
  // before serve-time gating existed; new artifacts never set it. The runtime
  // dual-reads both (runtime/redirects.php _stattic_redirect_proxy_action).
  disabled?: true;
  disabledReason?: string;
  planGated?: "external_proxy";
};

export type CompiledHeaderOperation =
  | { kind: "set"; name: string; value: string }
  | { kind: "remove"; name: string; value: null }
  | { kind: "basicAuth"; name: string; value: string };

export type CompiledHeaderRule = {
  path: string;
  host: string | null;
  regex: string | null;
  hostRegex: string | null;
  operations: CompiledHeaderOperation[];
  headers: Record<string, string>;
};

export type BasicAuthPlan = {
  rule: {
    id: string;
    match: {
      pathPattern: string;
      host?: string | undefined;
      hostPattern?: string | undefined;
      hostTemplate?: string | undefined;
    };
    effect: "challenge";
    auth: {
      requiredGrants: string[];
      acquire: Array<{
        type: "password";
        ref: string;
        transport: "basic";
        username?: string | undefined;
      }>;
    };
    reasonCode: "headers_basic_auth";
  };
  secretValues: Record<string, string>;
};

export type RoutingCompilation = {
  redirects: CompiledRedirectRule[];
  headers: CompiledHeaderRule[];
  diagnostics: RoutingDiagnostic[];
  stats: {
    redirectRuleCount: number;
    headerRuleCount: number;
    staticRedirectCount: number;
    dynamicRedirectCount: number;
    basicAuthRuleCount: number;
    proxyRuleCount: number;
  };
  // Produced only by the Rust (WASM) compiler surface; the pure-TS compiler
  // never sets these.
  basicAuth?: BasicAuthPlan[];
  sanitizedHeaders?: string | null;
};

export type RoutingCompileOptions = {
  assignedHostnames?: string[];
  // Honored only by the Rust (WASM) compiler surface.
  basicAuthAllowed?: boolean;
  basicAuthSaltContext?: string;
};
