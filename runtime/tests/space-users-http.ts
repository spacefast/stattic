import assert from "node:assert/strict";

import { z } from "zod";

import {
  spaceUserAbilityRequest,
  type SpaceUserAbilityName,
} from "../../apps/control-plane/src/space-users/ability-request.js";
import { contentRestResponseSchema } from "../../packages/common/src/contracts/content.js";
import {
  spaceUserAccountSchema,
  spaceUserSessionsSchema,
  spaceUsersListSchema,
} from "../../packages/common/src/contracts/space-users.js";
import { verifyUser } from "../../packages/zero/src/users.js";

export const usersHttpFixtureSchema = z
  .array(
    z.object({
      id: z.number().int().positive(),
      subject: z.string().regex(/^usr_[a-f0-9]{64}$/),
      cookie: z.string().startsWith("sfi_session="),
      csrf: z.string().min(1),
    }),
  )
  .length(4);

export const usersProtectedPhp = `<?php
$auth = sf_auth();
if (!$auth['isAuthenticated']) sf_json(['error' => 'sign_in_required'], 401);
sf_json(['userId' => $auth['userId'], 'wordpressLoaded' => class_exists('Spacefast\\Identity\\Plugin', false)]);
`;

/** The same installed HTTP boundary runs locally and on the retained provider site. */
export async function acceptSpaceUsersHttp(input: {
  origin: string;
  protectedPath: string;
  search: string;
  accounts: z.infer<typeof usersHttpFixtureSchema>;
  ownerRequest: (request: ReturnType<typeof spaceUserAbilityRequest>) => Promise<Response>;
}) {
  const [alice, bob, carol, dave] = input.accounts;
  if (!alice || !bob || !carol || !dave)
    throw new Error("Four isolated account fixtures are required.");
  const call = (path: string, cookie: string, options: RequestInit = {}) =>
    fetch(input.origin + path, {
      redirect: "error",
      signal: AbortSignal.timeout(15_000),
      ...options,
      headers: { Origin: input.origin, Cookie: cookie, ...options.headers },
    });
  const protectedUser = async (cookie: string, expected: string | null) => {
    const response = await call(input.protectedPath, cookie);
    assert.equal(response.status, expected === null ? 401 : 200);
    if (expected !== null) {
      const result = z
        .object({ userId: z.string(), wordpressLoaded: z.literal(false) })
        .parse(await response.json());
      assert.equal(result.userId, expected);
    }
    assert.match(response.headers.get("cache-control") ?? "", /no-store/);
    const user = await verifyUser(
      new Request(input.origin + input.protectedPath, { headers: { Cookie: cookie } }),
      { origin: input.origin },
    );
    assert.equal(user?.id ?? null, expected);
  };
  const owner = async (
    name: SpaceUserAbilityName,
    args: Record<string, string | number | boolean>,
    status = 200,
  ) => {
    const response = await input.ownerRequest(spaceUserAbilityRequest(name, args));
    assert.equal(response.status, 200);
    const result = contentRestResponseSchema.parse(await response.json());
    assert.equal(result.status, status);
    return result.body;
  };
  await protectedUser(alice.cookie, alice.subject);
  await protectedUser(bob.cookie, bob.subject);
  const forged = await call(input.protectedPath, alice.cookie, {
    method: "POST",
    headers: { Origin: "https://other.example.test" },
  });
  assert.equal(forged.status, 403);
  const directory = spaceUsersListSchema.parse(
    await owner("account-list", { page: 1, perPage: 20, search: input.search }),
  );
  assert.deepEqual(
    directory.users.map((user) => user.id).sort(),
    input.accounts.map((user) => user.id).sort(),
  );
  const account = spaceUserAccountSchema.parse(await owner("account-get", { id: alice.id }));
  assert.equal(account.subject, alice.subject);
  const sessions = spaceUserSessionsSchema.parse(await owner("sessions-list", { id: alice.id }));
  assert.equal(sessions.sessions.filter((session) => session.revokedAt === null).length, 1);
  assert.deepEqual(await owner("sessions-revoke", { id: alice.id }), { revoked: true });
  await protectedUser(alice.cookie, null);
  assert.equal(
    spaceUserAccountSchema.parse(await owner("suspend", { id: bob.id, suspended: true })).status,
    "suspended",
  );
  await protectedUser(bob.cookie, null);
  assert.equal(
    spaceUserAccountSchema.parse(await owner("suspend", { id: bob.id, suspended: false })).status,
    "active",
  );
  await protectedUser(bob.cookie, null);
  const deletion = await call("/__zero/auth/api/account/delete", carol.cookie, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Identity-CSRF": carol.csrf },
    body: JSON.stringify({ confirmation: "DELETE" }),
  });
  assert.equal(deletion.status, 200);
  assert.equal(
    spaceUserAccountSchema.parse(await owner("account-get", { id: carol.id })).status,
    "deletion_requested",
  );
  await owner("suspend", { id: carol.id, suspended: false }, 409);
  assert.deepEqual(await owner("deletion-complete", { id: carol.id }), { deleted: true });
  assert.deepEqual(await owner("deletion-complete", { id: carol.id }), { deleted: true });
  await protectedUser(carol.cookie, null);
  const signout = await call("/__zero/auth/sign-out", dave.cookie, { method: "POST" });
  assert.equal(signout.status, 200);
  assert.match(signout.headers.get("set-cookie") ?? "", /sfi_session=/);
  await protectedUser(dave.cookie, null);
}
