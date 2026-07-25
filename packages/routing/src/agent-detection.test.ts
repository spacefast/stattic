// Shared-fixture guard for the agent-detection twins. The case table in
// packages/routing/fixtures/agent-detection.json is the single source of truth
// for what counts as an agent request; this suite replays it against
// isAgentRequest (via the public matchRedirect API) while
// runtime/tests/agent-detection.test.ts replays the same table against
// _stattic_is_agent_request over real HTTP. Widening detection on either side
// without updating the shared table turns the parity checks below red.
import { describe, expect, test } from "bun:test";
import { readFileSync } from "node:fs";

import { compileRoutingFiles } from "./compile.js";
import { matchRedirect } from "./match.js";

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

const FIXTURE_URL = new URL("../fixtures/agent-detection.json", import.meta.url);
const table: AgentDetectionTable = JSON.parse(readFileSync(FIXTURE_URL, "utf8"));

const compilation = compileRoutingFiles({ redirects: "/probe /probe.md 200! Agent=true" });

const byCodePoint = (left: string, right: string) => (left < right ? -1 : left > right ? 1 : 0);

function detectsAgent(accept: string, userAgent: string): boolean {
  // Empty values are set explicitly, mirroring the runtime replay (which must
  // pin them so Bun's fetch defaults don't stand in for absent headers).
  const headers = new Headers({ accept, "user-agent": userAgent });
  const match = matchRedirect({
    compilation,
    request: { url: new URL("https://example.com/probe"), headers },
    hasStaticFile: () => true,
  });
  return match !== null;
}

describe("agent detection shared fixture table", () => {
  test("table shape is sound", () => {
    expect(table.cases.length).toBeGreaterThan(0);
    expect(table.needles).toEqual([...table.needles].toSorted(byCodePoint));
    expect(table.acceptTokens).toEqual([...table.acceptTokens].toSorted(byCodePoint));
  });

  for (const testCase of table.cases) {
    test(`${testCase.note} -> ${testCase.isAgent ? "agent" : "not agent"}`, () => {
      expect(detectsAgent(testCase.accept, testCase.userAgent)).toBe(testCase.isAgent);
    });
  }

  for (const needle of table.needles) {
    test(`needle "${needle}" is honored inside a larger user agent`, () => {
      expect(detectsAgent("*/*", `Mozilla/5.0 (compatible; ${needle}-probe/1.0)`)).toBe(true);
    });
  }
});

// Implementation-parity drift guard (the one legitimate source-reader class:
// twin implementations must enumerate identical constants). Behavioral cases
// above can only catch a widening that flips a listed case; these catch any
// needle or accept token added to one implementation but not the shared table.
// Scope is deliberate: literal lists inside the two functions. A widening
// routed through an external constant or a non-literal branch evades the
// extraction — this guard is a tripwire for the observed drift class (list
// items, #417), not a proof system; the behavioral matrix is the main net.
function functionBody(source: string, marker: string): string {
  const start = source.indexOf(marker);
  expect(start).toBeGreaterThanOrEqual(0);
  const body = source.slice(start);
  const end = body.indexOf("\n}");
  expect(end).toBeGreaterThan(0);
  return body.slice(0, end);
}

function quotedList(body: string, open: string, close: string): string[] {
  const start = body.indexOf(open);
  const end = body.indexOf(close, start);
  expect(start).toBeGreaterThanOrEqual(0);
  expect(end).toBeGreaterThan(start);
  const literals = body.slice(start, end).match(/["']([a-z0-9]+)["']/g) ?? [];
  return literals.map((literal) => literal.slice(1, -1)).toSorted(byCodePoint);
}

function acceptTokens(body: string): string[] {
  return [...new Set(body.match(/text\/[a-z-]+/g) ?? [])].toSorted(byCodePoint);
}

describe("agent detection twin parity", () => {
  const tsSource = readFileSync(new URL("./match.ts", import.meta.url), "utf8");
  const phpSource = readFileSync(
    new URL("../../../runtime/engine/runtime/redirects.php", import.meta.url),
    "utf8",
  );

  const tsBody = functionBody(tsSource, "function isAgentRequest");
  const phpBody = functionBody(phpSource, "function _stattic_is_agent_request");

  test("TS needle list matches the shared table", () => {
    expect(quotedList(tsBody, "return [", "].some")).toEqual(table.needles);
  });

  test("PHP needle list matches the shared table", () => {
    expect(quotedList(phpBody, "foreach ([", "] as $needle")).toEqual(table.needles);
  });

  test("TS accept tokens match the shared table", () => {
    expect(acceptTokens(tsBody)).toEqual(table.acceptTokens);
  });

  test("PHP accept tokens match the shared table", () => {
    expect(acceptTokens(phpBody)).toEqual(table.acceptTokens);
  });
});
