import { describe, expect, test } from "bun:test";

import { compileRoutingFiles } from "./compile.js";
import { basicAuthCredentials, headersForRequest, matchRedirect } from "./match.js";

function request(path: string, headers: Record<string, string> = {}) {
  return {
    url: new URL(path, "https://example.com"),
    headers: new Headers(headers),
  };
}

describe("routing matcher", () => {
  test("matches redirects and preserves incoming query strings", () => {
    const compilation = compileRoutingFiles({ redirects: "/old /new 301" });

    expect(matchRedirect({ compilation, request: request("/old?from=test") })).toMatchObject({
      action: "redirect",
      status: 301,
      target: "/new?from=test",
    });
  });

  test("expands splats and query captures in rewrites", () => {
    const compilation = compileRoutingFiles({
      redirects: "/blog/* id=:id id2=:id2 /posts/:splat/:id2/:id?from=:literal 200",
    });

    expect(
      matchRedirect({ compilation, request: request("/blog/a/b?id=123&id2=456") }),
    ).toMatchObject({
      action: "rewrite",
      path: "/posts/a/b/456/123?from=",
      status: 200,
    });
  });

  test("matches repeated query keys as one declared query name", () => {
    const compilation = compileRoutingFiles({
      redirects: "/search id=:id /result/:id 200",
    });

    expect(matchRedirect({ compilation, request: request("/search?id=1&id=2") })).toMatchObject({
      action: "rewrite",
      path: "/result/1,2",
    });
  });

  test("does not rewrite over existing static files without force", () => {
    const compilation = compileRoutingFiles({ redirects: "/docs /index.html 200" });

    expect(
      matchRedirect({
        compilation,
        request: request("/docs"),
        hasStaticFile: () => true,
      }),
    ).toBeNull();
  });

  test("agent rewrites can force over static files before host redirects", () => {
    const compilation = compileRoutingFiles({
      redirects: [
        "/ai /ai.txt 200! Agent=true",
        "https://example.com/* https://www.example.com/:splat 301",
      ].join("\n"),
      options: { assignedHostnames: ["example.com", "www.example.com"] },
    });

    expect(
      matchRedirect({
        compilation,
        request: request("https://example.com/ai", { "user-agent": "ChatGPT-User" }),
        hasStaticFile: () => true,
      }),
    ).toMatchObject({
      action: "rewrite",
      path: "/ai.txt",
      status: 200,
    });

    expect(
      matchRedirect({
        compilation,
        request: request("https://example.com/ai"),
        hasStaticFile: () => true,
      }),
    ).toMatchObject({
      action: "redirect",
      target: "https://www.example.com/ai",
      status: 301,
    });
  });

  test("agent rewrites match markdown accept negotiation", () => {
    const compilation = compileRoutingFiles({
      redirects: "/ai /ai.txt 200! Agent=true",
    });

    expect(
      matchRedirect({
        compilation,
        request: request("/ai", { accept: "text/markdown" }),
        hasStaticFile: () => true,
      }),
    ).toMatchObject({
      action: "rewrite",
      path: "/ai.txt",
      status: 200,
    });

    expect(
      matchRedirect({
        compilation,
        request: request("/ai", { accept: "text/html, text/markdown" }),
        hasStaticFile: () => true,
      }),
    ).toBeNull();
  });

  test("agent rewrites match shell fetch user agents", () => {
    const compilation = compileRoutingFiles({
      redirects: "/ai /ai.txt 200! Agent=true",
    });
    const rewrite = { action: "rewrite", path: "/ai.txt", status: 200 };

    expect(
      matchRedirect({
        compilation,
        request: request("/ai", { accept: "*/*", "user-agent": "curl/8.5.0" }),
        hasStaticFile: () => true,
      }),
    ).toMatchObject(rewrite);
    expect(
      matchRedirect({
        compilation,
        request: request("/ai", { accept: "*/*", "user-agent": "Wget/1.21.4 (linux-gnu)" }),
        hasStaticFile: () => true,
      }),
    ).toMatchObject(rewrite);
    expect(
      matchRedirect({
        compilation,
        request: request("/ai", {
          accept: "text/html,application/xhtml+xml",
          "user-agent":
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Safari/537.36",
        }),
        hasStaticFile: () => true,
      }),
    ).toBeNull();
  });

  test("applies header set, remove, expansion, and basic auth rules", () => {
    const compilation = compileRoutingFiles({
      headers:
        "/*\n  x-one: first\n  x-one: second\n  ! x-one\n  x-page: :splat\n  Basic-Auth: user:pass",
    });
    const matched = request("/docs/start");

    expect(headersForRequest(compilation, matched)).toEqual({ "X-Page": "docs/start" });
    expect(basicAuthCredentials(compilation, matched)).toEqual(["user:pass"]);
  });

  test("preserves repeated response header values", () => {
    const compilation = compileRoutingFiles({
      headers: "/*\n  x-item: one\n  x-item: two",
    });

    expect(headersForRequest(compilation, request("/docs"))).toEqual({
      "X-Item": ["one", "two"],
    });
  });

  test("matches local condition shims", () => {
    const compilation = compileRoutingFiles({
      redirects: "/country /nl 302 Country=nl\n/language /nl 302 Language=nl",
    });

    expect(
      matchRedirect({
        compilation,
        request: request("/country", { "x-spacefast-country": "nl" }),
      }),
    ).toMatchObject({ target: "/nl" });
    expect(
      matchRedirect({
        compilation,
        request: request("/language", { "accept-language": "nl-NL,nl;q=0.9" }),
      }),
    ).toBeNull();
    expect(
      matchRedirect({
        compilation,
        request: request("/language", { "accept-language": "nl" }),
      }),
    ).toMatchObject({ target: "/nl" });
  });
});
