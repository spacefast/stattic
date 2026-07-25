// Shared-fixture guard for the agent-detection twins, PHP side. Replays every
// case from packages/routing/fixtures/agent-detection.json against
// _stattic_is_agent_request through the real serving path (php -S + an
// agent-conditional forced rewrite): agents get the markdown twin, browsers
// get the HTML twin. packages/routing/src/agent-detection.test.ts replays the
// same table against the TS matcher and carries the source-parity checks;
// widening detection in redirects.php without updating the shared table goes
// red here, on the runtime lane, even when packages/routing is untouched.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import path from "node:path";

import { deploy, get, startRuntime, type Runtime } from "./harness.ts";

type AgentDetectionCase = {
  note: string;
  accept: string;
  userAgent: string;
  isAgent: boolean;
};

type AgentDetectionTable = {
  acceptTokens: string[];
  needles: string[];
  cases: AgentDetectionCase[];
};

// The one intentional reach outside runtime/: the fixture table must have a
// single home so the TS and PHP suites can never drift apart on expectations.
const FIXTURE_PATH = path.resolve(
  import.meta.dir,
  "../../packages/routing/fixtures/agent-detection.json",
);
const table: AgentDetectionTable = JSON.parse(readFileSync(FIXTURE_PATH, "utf8"));

const SITE = "agent-detect.test";
const HTML = "<h1>probe html</h1>\n";
const MARKDOWN = "# probe markdown\n";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
  await deploy(rt, {
    spaceId: "spc_agent_detect",
    versionId: "ver_agent_detect_1",
    metadata: { mode: "website", title: "Agent detection" },
    files: {
      "index.html": "<h1>home</h1>\n",
      "probe/index.html": HTML,
      "probe.md": MARKDOWN,
    },
    serving: {
      redirects_exact: {
        "/probe": [
          {
            destination: "/probe.md",
            status: 200,
            action: "rewrite",
            force: true,
            conditions: [{ kind: "agent", values: ["true"] }],
            order: 1,
          },
        ],
      },
      redirects_pattern: [],
    },
    activate: {
      route_name: "production",
      config: { mode: "website", site_title: "Agent detection" },
      production_hostnames: [SITE],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
});

afterAll(() => rt?.stop());

async function servedBody(accept: string, userAgent: string): Promise<string> {
  // Always send both headers explicitly, including empty values: Bun's fetch
  // would otherwise substitute its own defaults (accept: */*, user-agent:
  // Bun/x.y) and the table's empty-header cases would never reach PHP's
  // missing-header paths. Bun preserves explicitly supplied empty values.
  const headers: Record<string, string> = { accept, "user-agent": userAgent };
  const response = await get(rt, SITE, "/probe", { headers });
  expect(response.status).toBe(200);
  return response.text();
}

for (const testCase of table.cases) {
  test(`${testCase.note} -> ${testCase.isAgent ? "markdown" : "html"}`, async () => {
    expect(await servedBody(testCase.accept, testCase.userAgent)).toBe(
      testCase.isAgent ? MARKDOWN : HTML,
    );
  });
}

for (const needle of table.needles) {
  test(`needle "${needle}" is honored inside a larger user agent`, async () => {
    expect(await servedBody("*/*", `Mozilla/5.0 (compatible; ${needle}-probe/1.0)`)).toBe(MARKDOWN);
  });
}

// Runtime-lane copy of the needle/accept-token parity guard: a runtime-only PR
// never runs the packages/routing suite, so the check that redirects.php
// enumerates exactly the shared table's constants must also live here.
const phpSource = readFileSync(
  path.resolve(import.meta.dir, "../engine/runtime/redirects.php"),
  "utf8",
);

const byCodePoint = (left: string, right: string) => (left < right ? -1 : left > right ? 1 : 0);

test("PHP needle list matches the shared table", () => {
  const start = phpSource.indexOf("function _stattic_is_agent_request");
  expect(start).toBeGreaterThanOrEqual(0);
  const body = phpSource.slice(start, phpSource.indexOf("\n}", start));
  const listStart = body.indexOf("foreach ([");
  const listEnd = body.indexOf("] as $needle", listStart);
  expect(listStart).toBeGreaterThanOrEqual(0);
  expect(listEnd).toBeGreaterThan(listStart);
  const needles = (body.slice(listStart, listEnd).match(/'([a-z0-9]+)'/g) ?? []).map((literal) =>
    literal.slice(1, -1),
  );
  expect(needles.toSorted(byCodePoint)).toEqual(table.needles);
  expect([...new Set(body.match(/text\/[a-z-]+/g) ?? [])].toSorted(byCodePoint)).toEqual(
    table.acceptTokens,
  );
});
