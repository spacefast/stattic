import { expect, test } from "bun:test";
import { randomBytes, randomUUID } from "node:crypto";
import path from "node:path";

import { eq } from "drizzle-orm";
import { Window } from "happy-dom";
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
    authTeams,
  } = await import("../../apps/control-plane/src/db/schema/auth.js");
  const {
    channels,
    runtimeBoxes,
    spaceHostnames,
    spaces,
    spacefastTagSets,
    spacefastTagRevisions,
    spacefastTagReleases,
  } = await import("../../apps/control-plane/src/db/schema/core.js");
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
  const { setFeatureFlagEnv } = await import("../../apps/control-plane/src/test/feature-flags.js");
  const { tagGraphFromTemplateSelection } =
    await import("../../packages/common/src/contracts/tags.js");
  const { runOperationNow } = await import("../../apps/control-plane/src/operations/claimant.js");
  const { platformWpCloudContext } =
    await import("../../apps/control-plane/src/wp-cloud/tenant-client.js");
  const { simulatedWpCloudContext } =
    await import("../../apps/control-plane/src/wp-cloud/simulated-driver.js");
  // Exact promotion reads provider edge state; serving still uses the real PHP runtime.
  const providerClient = platformWpCloudContext().client;
  const providerFetch = providerClient.getConfig().fetch;
  providerClient.setConfig({
    fetch: simulatedWpCloudContext("poppy-publish").client.getConfig().fetch,
  });
  cleanup.defer(() => {
    providerClient.setConfig({ fetch: providerFetch });
  });
  const restoreTags = setFeatureFlagEnv("SPACEFAST_FLAG_TAGS", "enabled");
  cleanup.defer(restoreTags);
  const app = createApp();
  const agent = await createPoppyTestAgent(
    app,
    "teams:read spaces:write spaces:publish offline_access",
  );
  const originalFetch = globalThis.fetch;
  const teamId = await seedTeam("poppy-publish");
  const user = await seedSessionUser("poppy-publish", { activeTeamId: teamId });
  let spaceId: string | undefined;
  let runtime: Awaited<ReturnType<typeof startRuntime>> | undefined;
  try {
    await db
      .insert(authMembers)
      .values({ id: createId("mbr"), teamId, userId: user.userId, role: "owner" });
    const [team] = await db
      .update(authTeams)
      .set({ referralChipEnabled: true })
      .where(eq(authTeams.id, teamId))
      .returning({ slug: authTeams.slug });
    if (!team) throw new Error("Created team is missing");
    const referralUrl = `https://spacefast.com/?sf_ref=${team.slug}`;
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
      visitorScheme: "https",
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
    const hostname = await db.query.spaceHostnames.findFirst({
      where: eq(spaceHostnames.spaceId, spaceId),
    });
    if (!hostname) throw new Error("Created Space has no hostname");
    const servingOrigin = runtime.baseUrl;
    // Bind the fixture's public address to real PHP, preserving its virtual host.
    // Readiness must observe the actual response, not a simulated success.
    globalThis.fetch = Object.assign(
      (input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) => {
        const url = new URL(input instanceof Request ? input.url : input);
        if (url.hostname !== hostname.hostname) return originalFetch(input, init);
        const headers = new Headers(init?.headers);
        headers.set("host", hostname.hostname);
        return originalFetch(new URL(`${url.pathname}${url.search}`, servingOrigin), {
          ...init,
          headers,
        });
      },
      { preconnect: originalFetch.preconnect },
    );
    const tagSetId = createId("tgs");
    const tagRevisionId = createId("tgv");
    const graphFor = (value: string) =>
      tagGraphFromTemplateSelection({
        templateId: "custom-html",
        id: "baked.demo",
        delivery: "static",
        placement: "head",
        config: {
          html: `<meta name="baked-demo" content="${value}"><script>window.__baked = "${value}";</script>`,
        },
      });
    const space = await db.query.spaces.findFirst({ where: eq(spaces.id, spaceId) });
    if (!space) throw new Error("Created Space is missing");
    await db.insert(spacefastTagSets).values({
      id: tagSetId,
      tenantId: space.tenantId,
      teamId,
      scopeType: "space",
      scopeId: spaceId,
      name: "Baked demo",
    });
    await db.insert(spacefastTagRevisions).values({
      id: tagRevisionId,
      tagSetId,
      revisionNumber: 1,
      status: "published",
      title: "Baked demo",
      createdByActorJson: {},
      validationJson: { valid: true, diagnostics: [], requiredApprovals: [], graphRevision: 1 },
      graphJson: graphFor("saved"),
      graphRevision: 1,
      effectiveInputDigest: "effective:saved",
      staticDigest: "static:saved",
      dynamicDigest: "dynamic:saved",
      sdkRevision: "sdk:saved",
    });
    await db.insert(spacefastTagReleases).values({
      id: createId("tgr"),
      tagSetId,
      environment: "production",
      revisionId: tagRevisionId,
      sdkRevision: "sdk:saved",
      staticDigest: "static:saved",
      releasedByActorJson: {},
    });
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
          referral: z.object({ url: z.string(), message: z.string() }),
        }),
      })
      .parse(await published.json());
    expect(receipt.data.referral.url).toBe(referralUrl);
    expect(receipt.data.referral.message).toContain(referralUrl);
    const channel = await db.query.channels.findFirst({ where: eq(channels.spaceId, spaceId) });
    expect(channel?.versionId).toBe(receipt.data.version.id);
    const served = await fetch(runtime.baseUrl, { headers: { host: hostname.hostname } });
    expect(served.status, await served.clone().text()).toBe(200);
    const servedHtml = await served.text();
    expect(servedHtml).toContain(marker);
    expect(servedHtml).toContain('<meta name="baked-demo" content="saved">');
    const view = new Window({
      url: `https://${hostname.hostname}/`,
      settings: {
        enableJavaScriptEvaluation: true,
        suppressInsecureJavaScriptEnvironmentWarning: true,
        disableJavaScriptFileLoading: true,
      },
    });
    try {
      view.document.write(servedHtml);
      const sdk = view.document.querySelector('script[src*="/__spacefast/sdk.js"]');
      expect(sdk).not.toBeNull();
      const source = sdk?.getAttribute("src");
      if (!source) throw new Error("Published page has no SDK source");
      const sdkResponse = await fetch(new URL(source, runtime.baseUrl), {
        headers: { host: hostname.hostname },
      });
      expect(sdkResponse.status).toBe(200);
      const shadows: ShadowRoot[] = [];
      const attachShadow = view.HTMLElement.prototype.attachShadow;
      view.HTMLElement.prototype.attachShadow = function (options) {
        const shadow = attachShadow.call(this, options);
        shadows.push(shadow);
        return shadow;
      };
      view.eval(await sdkResponse.text());
      await view.happyDOM.whenAsyncComplete();
      expect(view.document.querySelectorAll("#sf-referral")).toHaveLength(1);
      expect(shadows.map((shadow) => shadow.querySelector("a")?.href)).toContain(referralUrl);
    } finally {
      await view.happyDOM.close();
    }
    // Roll back original source bytes after both the live content and tag settings move.
    const newer = new FormData();
    newer.set("spaceId", spaceId);
    newer.append(
      "files",
      new File([html.replaceAll(marker, "newer-content")], "index.html", { type: "text/html" }),
    );
    const humanHeaders = {
      cookie: await sessionCookie(user.sessionToken),
      origin: "https://my.sf.localhost",
    };
    const second = await app.request(publishUrl, {
      method: "POST",
      headers: humanHeaders,
      body: newer,
    });
    expect(second.status, await second.clone().text()).toBe(201);
    await db
      .update(spacefastTagRevisions)
      .set({ graphJson: graphFor("current"), graphRevision: 2 })
      .where(eq(spacefastTagRevisions.id, tagRevisionId));
    const rollbackUrl = `${API}/v1/spaces/${spaceId}/versions/${receipt.data.version.id}/promote?async=1`;
    const restore = async (body: { refreshRewrites?: boolean }) => {
      const response = await app.request(rollbackUrl, {
        method: "POST",
        headers: { ...humanHeaders, "content-type": "application/json" },
        body: JSON.stringify(body),
      });
      expect(response.status, await response.clone().text()).toBe(200);
      const result = z
        .object({
          data: z.object({ id: z.string() }),
          operation: z.object({
            id: z.string(),
            kind: z.string(),
            resources: z.array(z.object({ type: z.string(), id: z.string() })),
          }),
        })
        .parse(await response.json());
      const outcome = await runOperationNow({ operationId: result.operation.id });
      const pending = await db.query.operations.findFirst({
        where: (row, { eq }) => eq(row.id, result.operation.id),
      });
      expect(outcome, JSON.stringify(pending)).toBe("succeeded");
      const live = await db.query.channels.findFirst({ where: eq(channels.spaceId, spaceId) });
      expect(live?.versionId).toBe(result.data.id);
      const responseHtml = await (
        await fetch(runtime.baseUrl, { headers: { host: hostname.hostname } })
      ).text();
      expect(responseHtml).toContain(marker);
      expect(responseHtml).not.toContain("newer-content");
      expect(responseHtml.match(/data-spacefast-sdk="v1"/g)).toHaveLength(1);
      return { id: result.data.id, operation: result.operation, html: responseHtml };
    };
    const refreshed = await restore({ refreshRewrites: true });
    expect(refreshed.id).not.toBe(receipt.data.version.id);
    expect(refreshed.html.match(/name="baked-demo"/g)).toHaveLength(1);
    expect(refreshed.html).toContain('name="baked-demo" content="current"');
    expect(refreshed.html).not.toContain('content="saved"');
    // Exact restoration uses the existing pointer-promotion job, without recompiling the saved version.
    const exact = await restore({});
    expect(exact.id).toBe(receipt.data.version.id);
    expect(exact.operation.kind).toBe("version_promote_live");
    expect(exact.operation.resources).toContainEqual({
      type: "version",
      id: receipt.data.version.id,
    });
    expect(exact.html).toContain('<meta name="baked-demo" content="saved">');
    expect(exact.html).not.toContain('name="baked-demo" content="current"');
    expect(exact.html).toBe(servedHtml);
  } finally {
    globalThis.fetch = originalFetch;
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
      const tagSet = await db.query.spacefastTagSets.findFirst({
        where: eq(spacefastTagSets.scopeId, spaceId),
      });
      if (tagSet) {
        await db.delete(spacefastTagReleases).where(eq(spacefastTagReleases.tagSetId, tagSet.id));
        await db.delete(spacefastTagRevisions).where(eq(spacefastTagRevisions.tagSetId, tagSet.id));
        await db.delete(spacefastTagSets).where(eq(spacefastTagSets.id, tagSet.id));
      }
      await db.delete(runtimeBoxes).where(eq(runtimeBoxes.spaceId, spaceId));
      await db.delete(spaceHostnames).where(eq(spaceHostnames.spaceId, spaceId));
      await db.delete(spaces).where(eq(spaces.id, spaceId));
    }
    await cleanupSessionUser(user);
    await cleanupTeam(teamId);
  }
}, 60_000);
