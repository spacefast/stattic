import { expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import path from "node:path";

import { z } from "zod";

import type { SpaceGrant } from "@spacefast/common/contracts/grants";
import { RUNTIME_AUTHORIZATION_GRANT_LIMIT } from "@spacefast/common/contracts/runtime-api";

import {
  authorityGrantGeneration,
  authorityGrantGenerationMatches,
  type TeamGrantScope,
} from "../../apps/control-plane/src/access/authority-generation.ts";
import { projectRuntimeMembershipGrants } from "../../apps/control-plane/src/runtime/access-projection.ts";
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
function engineGeneration(
  team: TeamGrantScope,
  authority: string,
  format: "members" | "team",
): string | null {
  const runtimeGrants = controlPlaneGrants().map(({ grant, generation }) => ({
    ...grant,
    generation,
  }));
  const projection = {
    generation: 1,
    sessionVersion: 0,
    fence: "none",
    acquireUrl: "https://access.spacefast.test/acquire/parity",
    accessPage: null,
    spaceClaimed: true,
    teamId: TEAM_ID,
    membershipEpoch: team.membershipEpoch,
    grants:
      format === "members"
        ? projectRuntimeMembershipGrants(runtimeGrants, team.teamId, team.memberIds)
        : runtimeGrants,
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

test("installed member and team projections validate only live identities and current grants", () => {
  const memberId = MEMBER_AUTHORITY.slice("member:".length);
  const team: TeamGrantScope = { teamId: TEAM_ID, membershipEpoch: 4, memberIds: [memberId] };
  const active = controlPlaneGrants();
  const legacy = engineGeneration(team, MEMBER_AUTHORITY, "members");
  const epochDigest = engineGeneration(team, MEMBER_AUTHORITY, "team");
  expect(legacy).toMatch(/^[a-f0-9]{64}$/);
  expect(epochDigest).toMatch(/^[a-f0-9]{64}$/);
  if (!legacy || !epochDigest) throw new Error("member generation missing");
  expect(authorityGrantGeneration(active, team, MEMBER_AUTHORITY)).toBe(legacy);
  expect(authorityGrantGenerationMatches(active, team, MEMBER_AUTHORITY, legacy)).toBe(true);
  expect(authorityGrantGenerationMatches(active, team, MEMBER_AUTHORITY, epochDigest)).toBe(true);

  const reduced = { ...team, membershipEpoch: team.membershipEpoch + 1 };
  expect(authorityGrantGenerationMatches(active, reduced, MEMBER_AUTHORITY, epochDigest)).toBe(
    false,
  );
  const removed = { ...reduced, memberIds: [] };
  expect(authorityGrantGenerationMatches(active, removed, MEMBER_AUTHORITY, legacy)).toBe(false);
  expect(authorityGrantGenerationMatches(active, removed, MEMBER_AUTHORITY, epochDigest)).toBe(
    false,
  );
  const restored = { ...removed, memberIds: ["mbr_restored"] };
  expect(authorityGrantGenerationMatches(active, restored, MEMBER_AUTHORITY, legacy)).toBe(false);
  expect(authorityGrantGenerationMatches(active, restored, MEMBER_AUTHORITY, epochDigest)).toBe(
    false,
  );

  const edited = active.map((grant) => ({ ...grant, generation: grant.generation + 1 }));
  expect(authorityGrantGenerationMatches(edited, team, MEMBER_AUTHORITY, legacy)).toBe(false);
  expect(authorityGrantGenerationMatches(edited, team, MEMBER_AUTHORITY, epochDigest)).toBe(false);
  expect(
    authorityGrantGenerationMatches(
      active,
      { ...team, teamId: "team_transferred" },
      MEMBER_AUTHORITY,
      legacy,
    ),
  ).toBe(false);
  expect(
    authorityGrantGenerationMatches(
      active,
      { ...team, teamId: "team_transferred" },
      MEMBER_AUTHORITY,
      epochDigest,
    ),
  ).toBe(false);

  const runtimeGrants = active.map(({ grant, generation }) => ({ ...grant, generation }));
  const memberIds = Array.from(
    { length: Math.floor((RUNTIME_AUTHORIZATION_GRANT_LIMIT - 1) / 2) },
    (_, index) => `mbr_large_${index}`,
  );
  const underLimit = projectRuntimeMembershipGrants(runtimeGrants, TEAM_ID, memberIds);
  expect(underLimit.length).toBe(memberIds.length * 2 + 1);
  expect(underLimit.some((grant) => grant.audience.kind === "team")).toBe(false);
  const largeTeam = { ...team, memberIds: [...memberIds, memberId, "mbr_extra"] };
  expect(projectRuntimeMembershipGrants(runtimeGrants, TEAM_ID, largeTeam.memberIds)).toEqual(
    runtimeGrants,
  );
  const largeDigest = engineGeneration(largeTeam, MEMBER_AUTHORITY, "members");
  expect(largeDigest).toBe(epochDigest);
  if (!largeDigest) throw new Error("large-team generation missing");
  const reducedLargeTeam = {
    ...largeTeam,
    membershipEpoch: largeTeam.membershipEpoch + 1,
    memberIds: largeTeam.memberIds.filter((id) => id !== memberId),
  };
  expect(engineGeneration(reducedLargeTeam, MEMBER_AUTHORITY, "members")).not.toBe(largeDigest);
  expect(authorityGrantGenerationMatches(active, largeTeam, MEMBER_AUTHORITY, largeDigest)).toBe(
    true,
  );
  expect(
    authorityGrantGenerationMatches(active, reducedLargeTeam, MEMBER_AUTHORITY, largeDigest),
  ).toBe(false);
});
