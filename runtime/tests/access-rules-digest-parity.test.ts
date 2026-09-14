// Generated↔source drift guard: the control plane's authority generation
// digest against the engine's.
//
// Both sides derive one hash per live Grant and then a hash over the sorted
// set. A team-shaped Grant folds the membership epoch into its source string
// (`<grantId>:<generation>:<membershipEpoch>`) so one epoch bump retires every
// member session; everything else digests `<grantId>:<generation>`. If the two
// implementations ever disagree, the control plane mints a generation the
// engine refuses and every session on a team-owned Space fails closed — which
// is exactly the failure this file exists to catch before deploy.
//
// This runs the real PHP and the real TypeScript. Nothing here reads source.
import { expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import path from "node:path";

import { z } from "zod";

import type { SpaceGrant } from "@spacefast/common/contracts/grants";

import {
  authorityGrantGeneration,
  type TeamGrantScope,
} from "../../apps/control-plane/src/access/authority-generation.ts";
import { PHP_BINARY } from "./harness.ts";

type ParityGrant = {
  id: `grt_${string}`;
  generation: number;
  audience: SpaceGrant["grant"]["audience"];
};

const TEAM_ID = "team_parity";
const MEMBER_AUTHORITY = "member:mbr_parity";

function grants(): ParityGrant[] {
  return [
    {
      id: "grt_parity_team_live",
      generation: 2,
      audience: { kind: "team", teamId: TEAM_ID },
    },
    {
      id: "grt_parity_team_versions",
      generation: 7,
      audience: { kind: "team", teamId: TEAM_ID },
    },
    // A member-issued external Grant shares the same authority reference and
    // must NOT pick up the epoch: it is not a team Grant.
    {
      id: "grt_parity_member_external",
      generation: 3,
      audience: {
        kind: "external",
        issuer: "spacefast-membership",
        subject: MEMBER_AUTHORITY.slice("member:".length),
      },
    },
  ];
}

/** The control plane's own input shape. */
function controlPlaneGrants(): SpaceGrant[] {
  return grants().map((grant) => ({
    grant: {
      id: grant.id,
      name: grant.id,
      audience: grant.audience,
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "system", reference: grant.id },
    },
    generation: grant.generation,
    status: "active",
    sourceExplanation: { editable: false, label: "Space default", detail: "Ownership policy" },
    createdAt: "2026-09-13T00:00:00.000Z",
    updatedAt: "2026-09-13T00:00:00.000Z",
  }));
}

/** The engine answers with a sha-256 digest, or null when nothing admits. */
const engineGenerationSchema = z.union([z.string().regex(/^[a-f0-9]{64}$/), z.null()]);

/** The serving engine's own input shape, decided by the engine's own code. */
function engineGeneration(membershipEpoch: number, authority: string): string | null {
  const projection = {
    generation: 1,
    sessionVersion: 0,
    fence: "none",
    acquireUrl: "https://access.spacefast.test/acquire/parity",
    accessPage: null,
    spaceClaimed: true,
    teamId: TEAM_ID,
    membershipEpoch,
    grants: grants().map((grant) => ({
      id: grant.id,
      generation: grant.generation,
      audience: grant.audience,
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
      source: { kind: "system", reference: grant.id },
    })),
  };
  const accessRulesPath = path.resolve(import.meta.dir, "../engine/runtime/access-rules.php");
  const probe = spawnSync(
    PHP_BINARY,
    [
      "-r",
      [
        "require $argv[1];",
        "$projection = json_decode(getenv('SF_PARITY_PROJECTION'), true);",
        // The engine decides with the projection it compiled at route PUT,
        // never the raw one, so compile it here the same way.
        "$compiled = _stattic_compile_authorization_projection($projection);",
        "if ($compiled === null) { fwrite(STDERR, 'projection did not compile'); exit(1); }",
        "$generation = _stattic_authority_generation($compiled, getenv('SF_PARITY_AUTHORITY'));",
        "echo json_encode($generation);",
      ].join(" "),
      accessRulesPath,
    ],
    {
      encoding: "utf8",
      env: {
        ...process.env,
        SF_PARITY_PROJECTION: JSON.stringify(projection),
        SF_PARITY_AUTHORITY: authority,
      },
    },
  );
  expect(probe.stderr).toBe("");
  expect(probe.status).toBe(0);
  return engineGenerationSchema.parse(JSON.parse(probe.stdout));
}

test("the control plane and the engine derive the same member authority generation", () => {
  for (const membershipEpoch of [0, 1, 42]) {
    const team: TeamGrantScope = { teamId: TEAM_ID, membershipEpoch };
    const controlPlane = authorityGrantGeneration(
      controlPlaneGrants(),
      team,
      MEMBER_AUTHORITY,
      true,
    );
    expect(controlPlane).toBeTruthy();
    expect(controlPlane).toBe(engineGeneration(membershipEpoch, MEMBER_AUTHORITY));
  }
});

test("moving only the membership epoch moves the generation on both sides", () => {
  const before = authorityGrantGeneration(
    controlPlaneGrants(),
    { teamId: TEAM_ID, membershipEpoch: 0 },
    MEMBER_AUTHORITY,
    true,
  );
  const after = authorityGrantGeneration(
    controlPlaneGrants(),
    { teamId: TEAM_ID, membershipEpoch: 1 },
    MEMBER_AUTHORITY,
    true,
  );
  expect(before).not.toBe(after);
  expect(engineGeneration(0, MEMBER_AUTHORITY)).not.toBe(engineGeneration(1, MEMBER_AUTHORITY));
});
