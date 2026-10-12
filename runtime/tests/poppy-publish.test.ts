import { expect, test } from "bun:test";
import { randomBytes, randomUUID } from "node:crypto";
import path from "node:path";

import { eq } from "drizzle-orm";
import { z } from "zod";

import { startRuntime } from "./harness.ts";

const API = "https://api.sf.localhost";
const credentialsSchema = z.object({ access_token: z.string(), session_id: z.string() });

test("a personal agent creates a Space, publishes through DPoP, and reads the real PHP runtime", async () => {
  await using cleanup = new AsyncDisposableStack();
  if (process.env.SPACEFAST_TEST_DATABASE_URL === undefined) {
    const { provisionTestDatabases, dropTestDatabases } =
      await import("../../apps/control-plane/src/test/test-database-provisioning.js");
    const { databaseUrlWithName, testWorkerDatabaseName } =
      await import("../../apps/control-plane/src/test/test-database-names.js");
    const adminUrl =
      process.env.SPACEFAST_TEST_DATABASE_ADMIN_URL ??
      "postgres://stattic:stattic@127.0.0.1:25432/stattic";
    const runId = randomBytes(6).toString("hex");
    await provisionTestDatabases({
      adminUrl,
      migrationsFolder: path.resolve(import.meta.dir, "../../apps/control-plane/drizzle"),
      runId,
      workerCount: 1,
    });
    process.env.SPACEFAST_TEST_DATABASE_URL = databaseUrlWithName(
      adminUrl,
      testWorkerDatabaseName(runId, 1),
    );
    process.env.SPACEFAST_TEST_REDIS_URL =
      process.env.SPACEFAST_TEST_REDIS_ADMIN_URL ?? "redis://127.0.0.1:26379";
    cleanup.defer(() => dropTestDatabases({ adminUrl, runId, workerCount: 1 }));
  }
  const runtimeBinary = process.env.SPACEFAST_RUNTIME_BIN;
  await import("../../apps/control-plane/src/test-env.js");
  if (runtimeBinary !== undefined) process.env.SPACEFAST_RUNTIME_BIN = runtimeBinary;
  const { createApp } = await import("../../apps/control-plane/src/app.js");
  const { db } = await import("../../apps/control-plane/src/db/client.js");
  const {
    authMembers,
    authOAuthClients,
    authSessions,
    authGrants,
    authOAuthConsents,
    authOAuthClientResources,
  } = await import("../../apps/control-plane/src/db/schema/auth.js");
  const { channels, runtimeBoxes, spaceHostnames, spaces } =
    await import("../../apps/control-plane/src/db/schema/core.js");
  const { createId } = await import("../../apps/control-plane/src/lib/ids.js");
  const { runtimeJwks } = await import("../../apps/control-plane/src/runtime/auth.js");
  const { createPoppyTestAgent } = await import("../../apps/control-plane/src/test/poppy-agent.js");
  const {
    seedTeam,
    seedSessionUser,
    sessionCookie,
    seedBoxForSpace,
    cleanupSessionUser,
    cleanupTeam,
  } = await import("../../apps/control-plane/src/test/seed.js");
  const app = createApp();
  const agent = await createPoppyTestAgent(
    app,
    "teams:read spaces:write spaces:publish offline_access",
  );
  const teamId = await seedTeam("poppy-publish");
  const user = await seedSessionUser("poppy-publish", { activeTeamId: teamId });
  let spaceId: string | undefined;
  let runtime: Awaited<ReturnType<typeof startRuntime>> | undefined;
  try {
    await db
      .insert(authMembers)
      .values({ id: createId("mbr"), teamId, userId: user.userId, role: "owner" });
    const started = credentialsSchema.parse(
      await (
        await agent.token({
          grant_type: "urn:ietf:params:oauth:grant-type:jwt-bearer",
          assertion: await agent.assertion(`${API}/v1/auth/oauth2/token`, "publish-user"),
        })
      ).json(),
    );
    const signedIn = credentialsSchema.parse(
      await (await agent.signIn(await sessionCookie(user.sessionToken), started.session_id)).json(),
    );
    const createUrl = `${API}/v1/spaces`;
    const createBody = JSON.stringify({
      teamId,
      title: "Poppy publishing demo",
      slug: `poppy-${randomUUID().slice(0, 8)}`,
      access: "public",
    });
    const createHeaders = {
      authorization: `DPoP ${signedIn.access_token}`,
      dpop: await agent.proof(createUrl, "POST", signedIn.access_token),
      "content-type": "application/json",
      "idempotency-key": randomUUID(),
    };
    const created = await app.request(createUrl, {
      method: "POST",
      headers: createHeaders,
      body: createBody,
    });
    expect(created.status, await created.clone().text()).toBe(201);
    spaceId = z
      .object({ data: z.object({ space: z.object({ id: z.string() }) }) })
      .parse(await created.json()).data.space.id;
    const replay = await app.request(createUrl, {
      method: "POST",
      headers: createHeaders,
      body: createBody,
    });
    expect(replay.status).toBe(401);
    expect(replay.headers.get("www-authenticate")).toBe('DPoP error="invalid_dpop_proof"');
    const receiptRead = await app.request(createUrl, {
      method: "POST",
      headers: {
        ...createHeaders,
        dpop: await agent.proof(createUrl, "POST", signedIn.access_token),
      },
      body: createBody,
    });
    expect(receiptRead.status, await receiptRead.clone().text()).toBe(201);
    expect(receiptRead.headers.get("idempotency-replayed")).toBe("true");
    expect(
      z
        .object({ data: z.object({ space: z.object({ id: z.string() }) }) })
        .parse(await receiptRead.json()).data.space.id,
    ).toBe(spaceId);
    const box = await seedBoxForSpace(spaceId);
    runtime = await startRuntime({
      atomicData: {
        SPACEFAST_RUNTIME_INSTANCE_ID: box.boxId,
        SPACEFAST_RUNTIME_JWKS_B64: Buffer.from(JSON.stringify(runtimeJwks())).toString("base64"),
      },
    });
    // Supply real local serving capacity; provider provisioning belongs to the live lane.
    await db
      .update(runtimeBoxes)
      .set({ boxHostname: runtime.baseUrl })
      .where(eq(runtimeBoxes.id, box.boxId));
    const marker = `poppy-published-${randomUUID()}`;
    const html = `<!doctype html><html><head><title>Poppy publishing demo</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f5f8;color:#17213a;font:18px system-ui}main{max-width:700px;padding:64px;background:white;border-radius:20px;box-shadow:0 12px 50px #17213a12}small{color:#6752d5;font-weight:700}h1{font-size:48px;line-height:1.1;letter-spacing:-2px}p{line-height:1.7;color:#586277}code{font-size:12px;overflow-wrap:anywhere}</style></head><body><main><small>SPACEFAST × POPPY</small><h1>Published by a personal agent.</h1><p>Discovery → Sign in → Create a Space → Publish → Serve</p><p>This page was uploaded through the Spacefast API with a DPoP session token, finalized by the runtime worker, and served by the real PHP runtime.</p><code>${marker}</code></main></body></html>`;
    const form = new FormData();
    form.set("spaceId", spaceId);
    form.set("files", new File([html], "index.html", { type: "text/html" }));
    const publishUrl = `${API}/v1/publish`;
    const published = await app.request(publishUrl, {
      method: "POST",
      headers: {
        authorization: `DPoP ${signedIn.access_token}`,
        dpop: await agent.proof(publishUrl, "POST", signedIn.access_token),
      },
      body: form,
    });
    expect(published.status, await published.clone().text()).toBe(201);
    const receipt = z
      .object({
        data: z.object({
          version: z.object({ id: z.string(), status: z.literal("ready") }),
          activation: z.object({ outcome: z.literal("activated") }),
        }),
      })
      .parse(await published.json());
    const channel = await db.query.channels.findFirst({ where: eq(channels.spaceId, spaceId) });
    expect(channel?.versionId).toBe(receipt.data.version.id);
    const hostname = await db.query.spaceHostnames.findFirst({
      where: eq(spaceHostnames.spaceId, spaceId),
    });
    if (!hostname) throw new Error("Published Space has no hostname");
    const served = await fetch(runtime.baseUrl, { headers: { host: hostname.hostname } });
    expect(served.status, await served.clone().text()).toBe(200);
    expect(await served.text()).toContain(marker);
  } finally {
    runtime?.stop();
    agent.restore();
    await db.delete(authSessions).where(eq(authSessions.clientId, agent.clientId));
    await db.delete(authGrants).where(eq(authGrants.clientId, agent.clientId));
    await db.delete(authOAuthConsents).where(eq(authOAuthConsents.clientId, agent.clientId));
    await db
      .delete(authOAuthClientResources)
      .where(eq(authOAuthClientResources.clientId, agent.clientId));
    await db.delete(authOAuthClients).where(eq(authOAuthClients.clientId, agent.clientId));
    if (spaceId) {
      await db.delete(runtimeBoxes).where(eq(runtimeBoxes.spaceId, spaceId));
      await db.delete(spaceHostnames).where(eq(spaceHostnames.spaceId, spaceId));
      await db.delete(spaces).where(eq(spaces.id, spaceId));
    }
    await cleanupSessionUser(user);
    await cleanupTeam(teamId);
  }
}, 60_000);
