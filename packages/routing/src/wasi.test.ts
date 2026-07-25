import { describe, expect, test } from "bun:test";

import { compileRoutingFilesWasm, verifyBcryptPassword } from "./wasi.js";

describe("compileRoutingFilesWasm", () => {
  test("parses redirects with query matches, forced status, and conditions", () => {
    const result = compileRoutingFilesWasm({
      redirects: "/store id=:id /blog/:id 301! Country=us Cookie=BetaUser Agent=true",
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.redirects).toMatchObject([
      {
        source: "/store",
        destination: "/blog/:id",
        action: "redirect",
        status: 301,
        force: true,
        query: { id: "id" },
        conditions: [
          { kind: "country", values: ["us"] },
          { kind: "cookie", values: ["BetaUser"] },
          { kind: "agent", values: ["true"] },
        ],
      },
    ]);
  });

  test("rejects invalid agent condition values", () => {
    const result = compileRoutingFilesWasm({
      redirects: "/ai /ai.txt 200 Agent=maybe",
    });

    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_agent_condition_invalid",
      }),
    );
  });

  test("rejects role conditions", () => {
    const result = compileRoutingFilesWasm({
      redirects: "/admin/* /login 302 Role=admin",
    });

    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_role_unsupported",
      }),
    );
  });

  test("accepts external 200 proxy targets in redirects", () => {
    const result = compileRoutingFilesWasm({
      redirects: "/api/* https://api.example.com/:splat 200",
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.stats.proxyRuleCount).toBe(1);
    expect(result.redirects[0]).toMatchObject({
      source: "/api/*",
      destination: "https://api.example.com/:splat",
      action: "proxy",
      status: 200,
    });
  });

  test("rejects invalid custom 404 destinations", () => {
    const result = compileRoutingFilesWasm({
      redirects: "/gone missing 404\n/external https://example.com/404.html 404",
    });

    expect(result.redirects).toHaveLength(0);
    expect(
      result.diagnostics.filter(
        (diagnostic) => diagnostic.code === "redirect_rewrite_destination_invalid",
      ),
    ).toHaveLength(2);
  });

  test("classifies complete server-substituted redirect lines in Rust", () => {
    const valid = compileRoutingFilesWasm({
      redirects: "/api/* id=:id {{ vars.API_HOST }}/:id 200! Country=NL Agent=true",
    });
    expect(valid.diagnostics).toContainEqual(
      expect.objectContaining({ deferredUntilSubstitution: true }),
    );

    for (const redirects of [
      "/api/* {{vars.API_HOST}}bad 200",
      "/api/* id=id {{vars.API_HOST}}/:id 200",
      "/api/* {{vars.API_HOST}}/:splat 200 Role=admin",
      "/api/* {{vars.API_HOST}} 200 # comment",
    ]) {
      expect(
        compileRoutingFilesWasm({ redirects }).diagnostics.some(
          (diagnostic) => diagnostic.deferredUntilSubstitution === true,
        ),
      ).toBe(false);
    }
  });

  test("rejects redirect loops for host pattern sources", () => {
    const result = compileRoutingFilesWasm({
      redirects: "https://*.example.com/foo https://www.example.com/foo 301",
    });

    expect(result.redirects).toHaveLength(0);
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_loop_invalid",
      }),
    );
  });

  test("rejects absolute source redirect loops", () => {
    const result = compileRoutingFilesWasm({
      redirects: "https://www.example.com/foo /foo 301",
      options: { assignedHostnames: ["www.example.com"] },
    });

    expect(result.redirects).toHaveLength(0);
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_loop_invalid",
      }),
    );
  });

  test("allows same-path redirects across different hosts", () => {
    const result = compileRoutingFilesWasm({
      redirects: "https://old.example.com/foo https://new.example.com/foo 301",
      options: { assignedHostnames: ["old.example.com"] },
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.redirects[0]).toMatchObject({
      source: "/foo",
      host: "old.example.com",
      destination: "https://new.example.com/foo",
      status: 301,
    });
  });

  test("allows relative-source redirects to external same paths", () => {
    const result = compileRoutingFilesWasm({
      redirects: "/foo https://example.com/foo 301",
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.redirects[0]).toMatchObject({
      source: "/foo",
      host: null,
      destination: "https://example.com/foo",
      status: 301,
    });
  });

  test("rejects absolute sources for unassigned hostnames", () => {
    const result = compileRoutingFilesWasm({
      redirects: "https://other.example.com/foo /bar 301",
      options: { assignedHostnames: ["www.example.com"] },
    });

    expect(result.redirects).toHaveLength(0);
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_hostname_unassigned",
      }),
    );
  });

  test("normalizes trailing slash redirect sources and rejects slash-only redirects", () => {
    const normalized = compileRoutingFilesWasm({
      redirects: "/blog/:slug/ /posts/:slug 301",
    });
    expect(normalized.diagnostics).toEqual([]);
    expect(normalized.redirects[0]).toMatchObject({
      source: "/blog/:slug",
      regex: "^/blog/(?P<slug>[^/]+)/?$",
    });

    const loop = compileRoutingFilesWasm({
      redirects: "/docs /docs/ 301",
    });
    expect(loop.redirects).toHaveLength(0);
    expect(loop.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_loop_invalid",
      }),
    );
  });

  test("parses header blocks and warns for Cache-Control", () => {
    const result = compileRoutingFilesWasm({
      headers: "/*\n  cache-control: max-age=60\n  x-frame-options: DENY",
    });

    expect(result.headers).toHaveLength(1);
    expect(result.headers[0]?.operations).toMatchObject([
      { kind: "set", name: "Cache-Control", value: "max-age=60" },
      { kind: "set", name: "X-Frame-Options", value: "DENY" },
    ]);
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "warning",
        code: "header_cache_control_platform_managed",
      }),
    );
  });

  test("returns one sanitized Basic-Auth lowering plan from Rust", () => {
    const result = compileRoutingFilesWasm({
      headers:
        "https://:tenant.example.com/private/*\n  Basic-Auth: admin:secret editor:other\n  X-Robots-Tag: noindex",
    });
    expect(result.headers[0]?.operations).toMatchObject([
      { kind: "set", name: "X-Robots-Tag", value: "noindex" },
    ]);
    expect(result.basicAuth).toEqual([
      {
        rule: {
          id: "headers-basic-auth-1",
          match: { pathPattern: "/private/**", hostTemplate: ":tenant.example.com" },
          effect: "challenge",
          auth: {
            requiredGrants: ["pw:headers-basic-auth-1"],
            acquire: [
              {
                type: "password",
                ref: "secret:headers-basic-auth-1-1",
                transport: "basic",
                username: "admin",
              },
              {
                type: "password",
                ref: "secret:headers-basic-auth-1-2",
                transport: "basic",
                username: "editor",
              },
            ],
          },
          reasonCode: "headers_basic_auth",
        },
        secretValues: {
          "headers-basic-auth-1-1": expect.stringMatching(/^\$2b\$10\$/),
          "headers-basic-auth-1-2": expect.stringMatching(/^\$2b\$10\$/),
        },
      },
    ]);
    expect(result.sanitizedHeaders).toContain("X-Robots-Tag: noindex");
    expect(result.sanitizedHeaders).not.toContain("secret");
    expect(JSON.stringify(result.basicAuth)).not.toContain('"hashInputs"');
    expect(JSON.stringify(result.basicAuth)).not.toContain("admin:secret");
    expect(
      verifyBcryptPassword(
        "secret",
        result.basicAuth[0]?.secretValues["headers-basic-auth-1-1"] ?? "",
      ),
    ).toBe(true);
    expect(
      verifyBcryptPassword(
        "other",
        result.basicAuth[0]?.secretValues["headers-basic-auth-1-2"] ?? "",
      ),
    ).toBe(true);
  });

  test("uses deterministic, version-scoped bcrypt salts", () => {
    const compile = (context: string) =>
      compileRoutingFilesWasm({
        headers: "/*\n  Basic-Auth: admin:correct-horse",
        options: { basicAuthSaltContext: context },
      }).basicAuth[0]?.secretValues["headers-basic-auth-1"];

    const versionOne = compile("spc_1/ver_1");
    expect(versionOne).toBeDefined();
    expect(compile("spc_1/ver_1")).toBe(versionOne);
    expect(compile("spc_1/ver_2")).not.toBe(versionOne);
  });

  test("redacts Basic-Auth diagnostics and applies entitlement policy in Rust", () => {
    const allowed = compileRoutingFilesWasm({
      headers: "/*\n  Basic-Auth: admin:secret\n  Basic-Auth: malformed",
    });
    const basicAuthDiagnostics = allowed.diagnostics.filter((diagnostic) =>
      diagnostic.code.startsWith("header_basic_auth_"),
    );
    expect(basicAuthDiagnostics.length).toBeGreaterThan(0);
    expect(basicAuthDiagnostics.every((diagnostic) => diagnostic.source === "[redacted]")).toBe(
      true,
    );

    const denied = compileRoutingFilesWasm({
      headers: "/*\n  Basic-Auth: admin:secret",
      options: { basicAuthAllowed: false },
    });
    expect(denied.basicAuth).toEqual([]);
    expect(denied.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "warning",
        code: "free_headers_basic_auth_disabled",
      }),
    );
  });

  test("rejects Set-Cookie and the sendfile/accel header family as platform-managed", () => {
    const result = compileRoutingFilesWasm({
      headers: [
        "/*",
        "  Set-Cookie: session=abc",
        "  X-Accel-Redirect: /protected/secret",
        "  X-Sendfile: /etc/passwd",
        "  X-LIGHTTPD-send-file: /etc/passwd",
        "  X-Accel-Buffering: no",
        "  !set-cookie",
        "  !x-sendfile",
      ].join("\n"),
    });

    // Every operation (set and remove alike) must be dropped with an error diagnostic;
    // no rule may survive carrying any of these names.
    expect(result.headers).toHaveLength(0);
    const unsupported = result.diagnostics.filter(
      (diagnostic) => diagnostic.code === "header_name_unsupported",
    );
    expect(unsupported).toHaveLength(7);
    for (const diagnostic of unsupported) {
      expect(diagnostic.severity).toBe("error");
    }
  });

  test("does not leak header state after oversized lines", () => {
    const result = compileRoutingFilesWasm({
      headers: `/old\n  x-frame-options: DENY\n  ${"x".repeat(2_001)}\n  x-content-type-options: nosniff\n${"x".repeat(2_001)}\n  x-robots-tag: noindex\n/new\n  referrer-policy: no-referrer`,
    });

    expect(result.headers).toHaveLength(2);
    expect(result.headers[0]).toMatchObject({
      path: "/old",
      operations: [
        { kind: "set", name: "X-Frame-Options", value: "DENY" },
        { kind: "set", name: "X-Content-Type-Options", value: "nosniff" },
      ],
    });
    expect(result.headers[1]).toMatchObject({
      path: "/new",
      operations: [{ kind: "set", name: "Referrer-Policy", value: "no-referrer" }],
    });
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "header_line_too_long",
      }),
    );
  });

  test("rejects header suffix wildcards", () => {
    const result = compileRoutingFilesWasm({
      headers: "/*.jpg\n  ! X-Frame-Options",
    });

    expect(result.headers).toHaveLength(0);
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "header_pattern_invalid",
      }),
    );
  });

  test("preserves absolute URL host patterns", () => {
    const result = compileRoutingFilesWasm({
      redirects: "https://*.Example.COM/foo /bar 301",
      headers: "https://:Space.Pages.DEV/*\n  x-frame-options: DENY",
      options: { assignedHostnames: ["www.example.com"] },
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.redirects[0]).toMatchObject({
      host: "*.example.com",
      hostRegex: "^(?P<splat>.*)\\.example\\.com$",
    });
    expect(result.headers[0]).toMatchObject({
      host: ":space.pages.dev",
      hostRegex: "^(?P<space>[^.]+)\\.pages\\.dev$",
    });
  });
});
