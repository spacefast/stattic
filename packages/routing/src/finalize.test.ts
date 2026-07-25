import { describe, expect, test } from "bun:test";

import {
  applyRoutingPlanPolicyRust,
  lowerRuntimeConventionsRust,
  mergePreviewLoaderCspRust,
} from "./finalize.js";

describe("Rust finalizer convention transforms", () => {
  test("merges preview CSP in the shared WASM finalizer", () => {
    const output = mergePreviewLoaderCspRust(
      "/*\r\n  Content-Security-Policy: default-src 'none'\r\n  X-Test: 1\r\n",
      {
        apiOrigin: "https://api.example.test",
        castOrigins: ["https://cast.example.test"],
      },
    );

    expect(output).toContain(
      "connect-src https://api.example.test https://cast.example.test wss://cast.example.test",
    );
    expect(output).toEndWith("  X-Test: 1\n");
  });

  test("keeps the first duplicate CSP directive authoritative in WASM", () => {
    const output = mergePreviewLoaderCspRust(
      "/*\n  Content-Security-Policy: default-src 'self'; script-src 'none'; script-src *",
      {
        apiOrigin: "https://api.example.test",
        castOrigins: ["https://cast.example.test"],
      },
    );

    expect(output).toContain("script-src https://api.example.test https://cast.example.test");
    expect(output).not.toContain("script-src *");
  });

  test("keeps host-qualified exact paths in the request-time header bucket", () => {
    const output = lowerRuntimeConventionsRust<{
      headers_exact: Record<string, unknown[]>;
      headers_pattern: Array<{ host: string | null; path: string }>;
    }>({
      redirects: [],
      headers: [
        {
          path: "/docs",
          host: "docs.example.test",
          regex: "^/docs$",
          hostRegex: "^docs\\.example\\.test$",
          operations: [{ kind: "set", name: "X-Test", value: "1" }],
          headers: { "X-Test": "1" },
        },
      ],
    });

    expect(output.headers_exact).toEqual({});
    expect(output.headers_pattern).toHaveLength(1);
    expect(output.headers_pattern[0]).toMatchObject({
      host: "docs.example.test",
      path: "/docs",
    });
  });

  test("marks only the proxy when rules share source and destination", () => {
    const output = applyRoutingPlanPolicyRust<{
      redirects: Array<{ action: string; planGated?: string }>;
      diagnostics: Array<{ code: string }>;
    }>({
      redirects: [
        { source: "/same", destination: "https://api.example.test", action: "proxy" },
        { source: "/same", destination: "https://api.example.test", action: "redirect" },
      ],
      headers: [],
      policy: { externalProxy: false, routingRules: 10 },
    });

    expect(output.redirects[0]).toMatchObject({
      action: "proxy",
      planGated: "external_proxy",
    });
    expect(output.redirects[1]).toEqual({
      source: "/same",
      destination: "https://api.example.test",
      action: "redirect",
    });
    expect(output.diagnostics.map((diagnostic) => diagnostic.code)).toEqual([
      "free_external_proxy_disabled",
    ]);
  });
});
