import { describe, expect, test } from "bun:test";

import { compileRoutingFiles } from "./compile.js";

describe("compileRoutingFiles", () => {
  test("parses redirects with query matches, forced status, and conditions", () => {
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
      redirects: "/api/* https://api.example.com/:splat 200",
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.stats.proxyRuleCount).toBe(1);
    expect(result.redirects[0]).toMatchObject({
      source: "/api/*",
      destination: "https://api.example.com/:splat",
      action: "proxy",
      status: 200,
      cache: null,
    });
  });

  test("compiles cache=shared as proxy policy instead of a match condition", () => {
    const result = compileRoutingFiles({
      redirects: "/api/* https://api.example.com/:splat 200 cache=shared Country=us",
    });

    expect(result.diagnostics).toEqual([]);
    expect(result.redirects[0]).toMatchObject({
      action: "proxy",
      cache: "shared",
      conditions: [{ kind: "country", values: ["us"] }],
    });
  });

  test("fails closed for unsupported or contradictory proxy cache directives", () => {
    const unsupported = compileRoutingFiles({
      redirects: "/api https://api.example.com/data 200 cache=public",
    });
    expect(unsupported.redirects[0]).toMatchObject({ action: "proxy", cache: null });
    expect(unsupported.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "error",
        code: "redirect_cache_directive_invalid",
      }),
    );

    const contradictory = compileRoutingFiles({
      redirects: "/api https://api.example.com/data 200 cache=shared cache=private",
    });
    expect(contradictory.redirects[0]).toMatchObject({ action: "proxy", cache: null });
    expect(contradictory.diagnostics).toContainEqual(
      expect.objectContaining({ code: "redirect_cache_directive_invalid" }),
    );
  });

  test("warns and ignores cache=shared on non-proxy rules", () => {
    const result = compileRoutingFiles({ redirects: "/old /new 301 cache=shared" });

    expect(result.redirects[0]).toMatchObject({ action: "redirect", cache: null });
    expect(result.diagnostics).toContainEqual(
      expect.objectContaining({
        severity: "warning",
        code: "redirect_cache_not_proxy",
      }),
    );
  });

  test("rejects invalid custom 404 destinations", () => {
    const result = compileRoutingFiles({
      redirects: "/gone missing 404\n/external https://example.com/404.html 404",
    });

    expect(result.redirects).toHaveLength(0);
    expect(
      result.diagnostics.filter(
        (diagnostic) => diagnostic.code === "redirect_rewrite_destination_invalid",
      ),
    ).toHaveLength(2);
  });

  test("rejects redirect loops for host pattern sources", () => {
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const normalized = compileRoutingFiles({
      redirects: "/blog/:slug/ /posts/:slug 301",
    });
    expect(normalized.diagnostics).toEqual([]);
    expect(normalized.redirects[0]).toMatchObject({
      source: "/blog/:slug",
      regex: "^/blog/(?P<slug>[^/]+)/?$",
    });

    const loop = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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

  test("rejects Set-Cookie and the sendfile/accel header family as platform-managed", () => {
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
    const result = compileRoutingFiles({
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
