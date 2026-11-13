// A connector role from publish to a page call, with nothing stood in between:
// the API publishes an `sf.jsonc` that declares a team role, the finalize
// worker freezes it and hands the runtime its credential, the real PHP runtime
// serves `/__spacefast/connectors/<role>/<tool>` to the Space's page and calls
// the in-process control plane, which runs the tool against a real OpenAPI
// upstream on loopback. connectors.test.ts owns the runtime's own answers
// against a stand-in control plane; this file owns the seam between them.
import { expect, test } from "bun:test";
import { randomBytes, randomUUID } from "node:crypto";
import path from "node:path";

import { and, eq } from "drizzle-orm";
import { z } from "zod";

import {
  connectorRoleReachFromDiagnostics,
  pendingConnectorRolesFromDiagnostics,
} from "../../packages/common/src/contracts/spaces.ts";
import { get, startRuntime } from "./harness.ts";

const API = "https://api.sf.localhost";

test("a published config role reads once for every viewer and writes only for a signed-in member", async () => {
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
  const { authMembers } = await import("../../apps/control-plane/src/db/schema/auth.js");
  const { connectorConnections, connectorRuns, spaceConnectorBindings } =
    await import("../../apps/control-plane/src/db/schema/connectors.js");
  const { channels, runtimeBoxes, runtimeReleases, spaceHostnames, spaces } =
    await import("../../apps/control-plane/src/db/schema/core.js");
  const { createId } = await import("../../apps/control-plane/src/lib/ids.js");
  const { runtimeJwks } = await import("../../apps/control-plane/src/runtime/auth.js");
  const { buildRuntimeComponentInventory } =
    await import("../../apps/control-plane/src/runtime/component-lock.js");
  const { mintAuthorityToken } = await import("../../apps/control-plane/src/access/authorize.js");
  const { createConnection, listToolsWithPolicy, registerConnector } =
    await import("../../apps/control-plane/src/connectors/connectors-service.js");
  const { openConnectorScopeForSystemTask } =
    await import("../../apps/control-plane/src/connectors/scope.js");
  const {
    seedSpaceWithKey,
    seedBoxForSpace,
    seedSpaceGrant,
    seedSessionUser,
    sessionCookie,
    cleanupSessionUser,
    cleanupSpaceWithKey,
  } = await import("../../apps/control-plane/src/test/seed.js");
  const { setFeatureFlagEnv } = await import("../../apps/control-plane/src/test/feature-flags.js");
  const { platformWpCloudContext } =
    await import("../../apps/control-plane/src/wp-cloud/tenant-client.js");
  const { simulatedWpCloudContext } =
    await import("../../apps/control-plane/src/wp-cloud/simulated-driver.js");
  cleanup.defer(setFeatureFlagEnv("SPACEFAST_FLAG_CONNECTORS", "enabled"));
  // Exact promotion reads provider edge state; serving still uses the real PHP runtime.
  const providerClient = platformWpCloudContext().client;
  const providerFetch = providerClient.getConfig().fetch;
  providerClient.setConfig({
    fetch: simulatedWpCloudContext("connectors-publish").client.getConfig().fetch,
  });
  cleanup.defer(() => providerClient.setConfig({ fetch: providerFetch }));

  // The tool's own API. Only the page's reads carry its query; publish's
  // health and catalog checks reach the host bare.
  const upstreamReads: string[] = [];
  let upstreamWrites = 0;
  const upstream = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch(request) {
      const url = new URL(request.url);
      if (request.method === "POST") {
        upstreamWrites++;
        return Response.json({ id: `deal_${upstreamWrites}` });
      }
      if (url.search) upstreamReads.push(url.search);
      return Response.json({ deals: [{ id: "deal_1", stage: "won" }] });
    },
  });
  cleanup.defer(() => upstream.stop(true));
  // Connector egress refuses loopback; the tool's public host resolves here.
  const http = await import("node:http");
  const https = (await import("node:https")).default;
  const originalRequest = https.request;
  Object.defineProperty(https, "request", {
    configurable: true,
    value: (
      options: import("node:https").RequestOptions,
      callback: (response: import("node:http").IncomingMessage) => void,
    ) =>
      options.host === "example.com"
        ? http.request(
            { ...options, host: "127.0.0.1", port: upstream.port, lookup: undefined },
            callback,
          )
        : originalRequest(options, callback),
  });
  cleanup.defer(() =>
    Object.defineProperty(https, "request", { configurable: true, value: originalRequest }),
  );

  // The control plane the runtime calls, on a real port.
  const app = createApp();
  const controlPlane = Bun.serve({ hostname: "127.0.0.1", port: 0, fetch: (r) => app.handle(r) });
  cleanup.defer(() => controlPlane.stop(true));

  const seed = await seedSpaceWithKey("connectors-publish", { providerPlacement: "unplaced" });
  cleanup.defer(() => cleanupSpaceWithKey(seed));
  // The person who publishes, binds and signs in: a team owner on the dashboard.
  const owner = await seedSessionUser("connectors-publish-owner", { activeTeamId: seed.teamId });
  cleanup.defer(() => cleanupSessionUser(owner));
  await db
    .insert(authMembers)
    .values({ id: createId("mbr"), teamId: seed.teamId, userId: owner.userId, role: "owner" });
  const ownerHeaders = {
    cookie: await sessionCookie(owner.sessionToken),
    origin: "https://my.sf.localhost",
  };
  // The Space is public: the role serves anyone who can open it.
  await seedSpaceGrant({
    spaceId: seed.spaceId,
    audience: { kind: "public" },
    capabilities: ["page.view"],
  });
  const space = await db.query.spaces.findFirst({ where: eq(spaces.id, seed.spaceId) });
  if (!space?.teamId) throw new Error("Seeded Space has no team");

  // A team connection to the tool, serving sites.
  await using setup = await openConnectorScopeForSystemTask({
    teamId: space.teamId,
    tenantId: space.tenantId,
    reason: "health_sweep",
  });
  await registerConnector(setup, {
    slug: "crm",
    source: {
      kind: "openapi",
      spec: {
        openapi: "3.0.3",
        info: { title: "CRM", version: "1" },
        servers: [{ url: "https://example.com" }],
        paths: {
          "/deals": {
            get: {
              operationId: "listDeals",
              parameters: [{ name: "stage", in: "query", schema: { type: "string" } }],
              responses: { "200": { description: "Deals" } },
            },
            post: { operationId: "createDeal", responses: { "200": { description: "Created" } } },
          },
        },
      },
    },
  });
  const connected = await createConnection(setup, { connector: "crm", owner: "team" });
  if (connected.status !== "connected") throw new Error("The CRM connection did not connect");
  await db
    .update(connectorConnections)
    .set({ servingGrantedAt: new Date().toISOString() })
    .where(eq(connectorConnections.id, connected.connection.id));
  // The page names a tool by its catalog name.
  const tools = await listToolsWithPolicy(setup, { connectionId: connected.connection.id });
  const listDeals = tools.find((entry) => entry.tool.includes("listDeals"))?.tool;
  const createDeal = tools.find((entry) => entry.tool.includes("createDeal"))?.tool;
  if (!listDeals || !createDeal) throw new Error(`CRM tools missing: ${JSON.stringify(tools)}`);

  // A box running this checkout's engine, which serves connector roles.
  const box = await seedBoxForSpace(seed.spaceId);
  const engineRevision = `connectors-publish-${randomUUID().replaceAll("-", "")}`;
  await db.insert(runtimeReleases).values({
    revision: engineRevision,
    zipUrl: `https://runtime.connectors.test/${engineRevision}.zip`,
    md5: "a".repeat(32),
    nativeSha256: "b".repeat(64),
    componentInventoryJson: buildRuntimeComponentInventory({
      revision: engineRevision,
      nativeSha256: "b".repeat(64),
    }),
    promotedAt: new Date().toISOString(),
  });
  cleanup.defer(() =>
    db.delete(runtimeReleases).where(eq(runtimeReleases.revision, engineRevision)),
  );
  const runtime = await startRuntime({
    visitorScheme: "https",
    atomicData: {
      SPACEFAST_RUNTIME_INSTANCE_ID: box.boxId,
      SPACEFAST_RUNTIME_JWKS_B64: Buffer.from(JSON.stringify(runtimeJwks())).toString("base64"),
      SPACEFAST_API_BASE_URL: `http://127.0.0.1:${controlPlane.port}`,
    },
  });
  cleanup.defer(() => runtime.stop());
  await db
    .update(runtimeBoxes)
    .set({ boxHostname: runtime.baseUrl, engineRevision })
    .where(eq(runtimeBoxes.id, box.boxId));
  const hostname = await db.query.spaceHostnames.findFirst({
    where: eq(spaceHostnames.spaceId, seed.spaceId),
  });
  if (!hostname) throw new Error("Seeded Space has no hostname");
  const host = hostname.hostname;
  // Publishing proves the live address serves the new version.
  const originalFetch = globalThis.fetch;
  globalThis.fetch = Object.assign(
    (input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) => {
      const url = new URL(input instanceof Request ? input.url : input);
      if (url.hostname !== host) return originalFetch(input, init);
      const headers = new Headers(init?.headers);
      headers.set("host", host);
      return originalFetch(new URL(`${url.pathname}${url.search}`, runtime.baseUrl), {
        ...init,
        headers,
      });
    },
    { preconnect: originalFetch.preconnect },
  );
  cleanup.defer(() => {
    globalThis.fetch = originalFetch;
  });

  // Publish: a page that calls the role through the Space client, and the role.
  const form = new FormData();
  form.set("spaceId", seed.spaceId);
  form.append(
    "files",
    new File(
      [
        '<!doctype html><title>Deals</title><script type="module" src="/__spacefast/client.js"></script>',
      ],
      "index.html",
      { type: "text/html" },
    ),
  );
  form.append(
    "files",
    new File(
      [JSON.stringify({ connectors: { crm: { connector: "crm", as: "team", tools: "*" } } })],
      "sf.jsonc",
      { type: "application/json" },
    ),
  );
  const published = await app.request(`${API}/v1/publish`, {
    method: "POST",
    headers: ownerHeaders,
    body: form,
  });
  expect(published.status, await published.clone().text()).toBe(201);
  // Finalize may outrun the request; the queued operation runs to the end,
  // as the worker would, and the version reads back like a polling client.
  const accepted = z
    .object({
      data: z.object({
        version: z.object({ id: z.string() }),
        operation: z.object({ id: z.string() }).optional(),
      }),
    })
    .parse(await published.json()).data;
  const versionId = accepted.version.id;
  if (accepted.operation) {
    const { runOperationNow } = await import("../../apps/control-plane/src/operations/claimant.js");
    expect(await runOperationNow({ operationId: accepted.operation.id })).toBe("succeeded");
  }
  const versionRead = await app.request(`${API}/v1/spaces/${seed.spaceId}/versions/${versionId}`, {
    headers: seed.authHeaders,
  });
  expect(versionRead.status, await versionRead.clone().text()).toBe(200);
  const version = z
    .object({
      data: z.object({
        status: z.literal("ready"),
        diagnostics: z.array(z.object({ code: z.string(), details: z.unknown().optional() })),
      }),
    })
    .parse(await versionRead.json()).data;
  const live = await db.query.channels.findFirst({
    where: and(eq(channels.spaceId, seed.spaceId), eq(channels.name, "live")),
  });
  expect(live?.versionId).toBe(versionId);
  // Publish bound the team's one eligible account, and says the role is off
  // on a Space anyone can open until a team admin lets it serve there.
  const binding = await db.query.spaceConnectorBindings.findFirst({
    where: eq(spaceConnectorBindings.spaceId, seed.spaceId),
  });
  expect(binding?.connectionId).toBe(connected.connection.id);
  // The receipt's role lists, read from the diagnostics exactly as the receipt does.
  expect(
    pendingConnectorRolesFromDiagnostics(version.diagnostics).map(({ role, state }) => [
      role,
      state,
    ]),
  ).toEqual([["crm", "needs_public_serving_ack"]]);
  expect(connectorRoleReachFromDiagnostics(version.diagnostics)).toEqual([]);

  /** A request from the Space's own page: same-origin fetch metadata, its cookie. */
  const page = (
    requestPath: string,
    init: { method?: "GET" | "POST"; body?: string; cookie?: string } = {},
  ) =>
    get(runtime, host, requestPath, {
      ...(init.method && { method: init.method }),
      ...(init.body !== undefined && { body: init.body }),
      headers: {
        "sec-fetch-site": "same-origin",
        // The fixture's cookies are insecure, so its pages' origin is http.
        ...(init.method === "POST" && {
          origin: `http://${host}`,
          "content-type": "application/json",
        }),
        ...(init.cookie && { cookie: init.cookie }),
      },
    });
  const read = `/__spacefast/connectors/crm/${listDeals}?stage=won`;
  const off = await page(read);
  expect(off.status, await off.clone().text()).toBe(403);
  expect(upstreamReads).toEqual([]);

  // A team owner lets the role serve the public Space, naming the account.
  const allowed = await app.request(
    `${API}/v1/spaces/${seed.spaceId}/connectors/crm/public-serving`,
    {
      method: "PUT",
      headers: { ...ownerHeaders, "content-type": "application/json" },
      body: JSON.stringify({ enabled: true, connectionId: connected.connection.id }),
    },
  );
  expect(allowed.status, await allowed.clone().text()).toBe(200);
  // The role view says who reads through it now.
  expect(
    z.object({ data: z.object({ reach: z.string() }) }).parse(await allowed.json()).data.reach,
  ).toBe(
    "Anyone who can open this Space can read through your team's crm account, with every tool it has, including ones added later, without signing in.",
  );

  // An anonymous viewer's read runs the tool once; the next viewer's read is
  // the same answer from the shared cache, with no second upstream call.
  const first = await page(read);
  expect(first.status, await first.clone().text()).toBe(200);
  const firstBody = z
    .object({ state: z.literal("ok"), data: z.unknown(), cachedAt: z.string().optional() })
    .parse(await first.json());
  expect(firstBody.data).toEqual({ deals: [{ id: "deal_1", stage: "won" }] });
  expect(firstBody.cachedAt).toBeUndefined();
  const second = await page(read);
  expect(second.status).toBe(200);
  const secondBody = z
    .object({ state: z.literal("ok"), data: z.unknown(), cachedAt: z.string() })
    .parse(await second.json());
  expect(secondBody.data).toEqual(firstBody.data);
  expect(upstreamReads).toEqual(["?stage=won"]);
  const runs = await db.query.connectorRuns.findMany({
    where: eq(connectorRuns.spaceId, seed.spaceId),
  });
  expect(runs.map((run) => [run.origin, run.status])).toEqual([["page", "succeeded"]]);

  // A write from the same page: refused for an anonymous viewer, and run
  // for a signed-in member of the team (the team policy holds writes for
  // approval by default, so it parks rather than running unasked).
  const write = `/__spacefast/connectors/crm/${createDeal}`;
  const anonymous = await page(write, { method: "POST", body: "{}" });
  expect(anonymous.status, await anonymous.clone().text()).toBe(403);
  expect(z.object({ code: z.string() }).parse(await anonymous.json()).code).toBe(
    "connector_write_sign_in_required",
  );
  const now = Math.floor(Date.now() / 1000);
  const { token } = await mintAuthorityToken({
    sub: `member:${owner.userId}`,
    principal: `account:${owner.userId}`,
    authorities: [`member:${owner.userId}`],
    spaceId: seed.spaceId,
    sessionId: randomBytes(32).toString("hex"),
    generation: space.accessGeneration,
    audience: host,
    jti: randomUUID(),
    authorityExpiresAt: now + 3600,
  });
  const redeemed = await get(runtime, host, "/__sf/redeem", {
    method: "POST",
    headers: {
      origin: `http://${host}`,
      "content-type": "application/x-www-form-urlencoded",
    },
    body: new URLSearchParams({ token, return: "/" }).toString(),
  });
  expect(redeemed.status, await redeemed.clone().text()).toBe(303);
  const member = (redeemed.headers.get("set-cookie") ?? "").split(";", 1)[0] ?? "";
  expect(member).toContain("sfv2_");
  const signedIn = await page(write, { method: "POST", body: "{}", cookie: member });
  expect(signedIn.status, await signedIn.clone().text()).toBe(200);
  expect(z.object({ state: z.string() }).parse(await signedIn.json()).state).toBe(
    "approval_pending",
  );
  const memberRun = await db.query.connectorRuns.findFirst({
    where: and(
      eq(connectorRuns.spaceId, seed.spaceId),
      eq(connectorRuns.status, "awaiting_approval"),
    ),
  });
  expect(memberRun?.visitorSubject).toBe(`account:${owner.userId}`);
  expect(upstreamWrites).toBe(0);
}, 180_000);
