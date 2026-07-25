import { describe, expect, test } from "bun:test";

import { analyzeFinalize, prepareFinalize } from "./prepare.js";

describe("Rust finalizer preparation", () => {
  test("selects the canonical config and parses JSONC", () => {
    const result = analyzeFinalize({
      configCandidates: {
        "sf.jsonc": '{"templates":["legacy.js"]}',
        "spacefast.jsonc": '{// comment\n"templates":["app.js",],}',
      },
      templateSources: { "app.js": "{{ vars.NAME }}" },
    });
    expect(result.selectedConfigPath).toBe("spacefast.jsonc");
    expect(result.templatePaths).toEqual(["app.js"]);
    expect(result.variableRequirements).toEqual([{ name: "NAME", system: false }]);
  });

  test("keeps Basic-Auth plaintext out of the native runtime boundary", () => {
    const result = prepareFinalize({
      conventionFiles: {
        redirects: "/old /{{ vars.SLUG }} 301",
        headers: "/*\n  Basic-Auth: admin:{{ vars.PASSWORD }}\n  X-Site: docs",
      },
      variableScopes: [
        {
          kind: "space",
          values: { SLUG: { value: "new" }, PASSWORD: { value: "correct-horse" } },
        },
      ],
    });
    expect(result.conventionFiles?.headers).toContain("admin:correct-horse");
    expect(result.runtimeConventionFiles).toEqual({
      redirects: "/old /new 301",
      headers: "/*\n  X-Site: docs\n",
    });
    expect(JSON.stringify(result.runtimeConventionFiles)).not.toContain("correct-horse");
    expect(JSON.stringify(result.runtimeConventionFiles).toLowerCase()).not.toContain("basic-auth");
  });

  test("strips malformed and rejected Basic-Auth source lines from runtime bytes", () => {
    const result = prepareFinalize({
      conventionFiles: {
        headers: [
          "/*",
          "  Basic-Auth malformed:malformed-secret",
          "  ! Basic-Auth: dropped:dropped-secret",
          "  X-Safe: yes",
        ].join("\n"),
      },
    });
    const runtime = JSON.stringify(result.runtimeConventionFiles);
    expect(runtime).toContain("X-Safe");
    expect(runtime).not.toContain("malformed-secret");
    expect(runtime).not.toContain("dropped-secret");
    expect(runtime.toLowerCase()).not.toContain("basic-auth");
  });
});
