import { fileURLToPath } from "node:url";

import { eq } from "drizzle-orm";

import { MAIN_TENANT_ID } from "@spacefast/common/contracts/ids";
const runtimeBin = process.env.SPACEFAST_RUNTIME_BIN;
await import("../../apps/control-plane/src/test-env.ts");
if (runtimeBin === undefined) {
  delete process.env.SPACEFAST_RUNTIME_BIN;
} else {
  process.env.SPACEFAST_RUNTIME_BIN = runtimeBin;
}

const [
  { deliveryExchangeContractRoutes },
  { createApp },
  { createAccessShareLink, logoutAllSpaceSessions, setAccessRequestClockForTests },
  { createPasswordCredential },
  { env },
  { db },
  { authMembers },
  { activityEvents, domains, operations },
  { clearAllFixedWindowCountersForTests },
  { createId },
  { ensureSpaceDefaultHostname },
  { runtimeRouteConfigForSpace },
  { runtimeRouteIntentForSpace },
  { cleanupSpaceWithKey, seedSpaceWithKey },
  { compilePages, compileVersionConfig, runtimeServingConfigPayload },
  { deploy, putRoute, startRuntime },
] = await Promise.all([
  import("../../apps/control-plane/src/api-contracts/delivery/exchange.ts"),
  import("../../apps/control-plane/src/app.ts"),
  import("../../apps/control-plane/src/access/canonical.ts"),
  import("../../apps/control-plane/src/access/credentials.ts"),
  import("../../apps/control-plane/src/config/env.ts"),
  import("../../apps/control-plane/src/db/client.ts"),
  import("../../apps/control-plane/src/db/schema/auth.ts"),
  import("../../apps/control-plane/src/db/schema/core.ts"),
  import("../../apps/control-plane/src/http/rate-limit-counter.ts"),
  import("../../apps/control-plane/src/lib/ids.ts"),
  import("../../apps/control-plane/src/runtime/hostname-identity.ts"),
  import("../../apps/control-plane/src/runtime/route-config.ts"),
  import("../../apps/control-plane/src/runtime/runtime-api-routes.ts"),
  import("../../apps/control-plane/src/test/seed.ts"),
  import("../../apps/control-plane/src/versions/finalize-config.ts"),
  import("../tests/harness.ts"),
]);

const PASSWORD = "correct horse battery staple";
const VERIFIED_EMAIL_PASSWORD = "verified email horse battery staple";
const accessRulesPath = fileURLToPath(
  new URL("../engine/runtime/access-rules.php", import.meta.url),
);
const accessStatesProcess = Bun.spawnSync([
  "php",
  "-r",
  `require ${JSON.stringify(accessRulesPath)}; echo json_encode(STATTIC_ACCESS_STATUS_CODES, JSON_THROW_ON_ERROR);`,
]);
if (!accessStatesProcess.success) {
  throw new Error(
    `production access-state enumeration failed: ${accessStatesProcess.stderr.toString()}`,
  );
}
const accessStateCodes: unknown = JSON.parse(accessStatesProcess.stdout.toString());
if (!Array.isArray(accessStateCodes) || accessStateCodes.length === 0) {
  throw new Error("production access-state enumeration returned no states");
}

const PAGE_SOURCE =
  '<!doctype html><html><head><meta name="referrer" content="no-referrer">' +
  "<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; form-action 'self'\"></head>" +
  "<body><h1>Private studio</h1>" +
  "<sf-access-status></sf-access-status><sf-access-lanes></sf-access-lanes>" +
  "</body></html>";

const seed = await seedSpaceWithKey("origin-null-browser", { providerPlacement: "unplaced" });
let stopExchange: (() => void) | undefined;
let runtime: Awaited<ReturnType<typeof startRuntime>> | undefined;
let assignedDomainId: string | undefined;
let cleaned = false;

async function cleanup() {
  if (cleaned) return;
  cleaned = true;
  stopExchange?.();
  runtime?.stop();
  if (assignedDomainId) {
    await db.delete(domains).where(eq(domains.id, assignedDomainId));
  }
  await db.delete(operations).where(eq(operations.resourceId, seed.spaceId));
  await db.delete(activityEvents).where(eq(activityEvents.principalId, seed.teamId));
  await cleanupSpaceWithKey(seed);
}

for (const signal of ["SIGINT", "SIGTERM"] as const) {
  process.on(signal, () => {
    void cleanup().finally(() => process.exit(signal === "SIGINT" ? 130 : 0));
  });
}

try {
  // The scaffold's owner membership would produce an identity-bound management
  // Grant and trigger silent account acquisition before the access page. This
  // visitor fixture has no active member, so remove that principal and let the
  // production projection omit its Team Grant.
  await db.delete(authMembers).where(eq(authMembers.userId, seed.userId));

  // The seeded default .fast hostname is HSTS-preloaded and cannot reach this
  // plain-HTTP PHP server, so give the browser runtime a .test hostname.
  // Provider lifecycle behavior is covered only by the real wp.cloud sandbox
  // lane.
  const defaultHostname = await ensureSpaceDefaultHostname(seed.spaceId);
  const hostname = `${defaultHostname.label}.access-page.test`;
  assignedDomainId = createId("dom");
  await db.insert(domains).values({
    id: assignedDomainId,
    tenantId: MAIN_TENANT_ID,
    teamId: seed.teamId,
    spaceId: seed.spaceId,
    hostname,
    apexHostname: hostname,
    source: "external",
    state: "active",
    lastPositiveSignal: "dns",
    dnsInstructionsJson: [],
  });

  // Production credential creation owns the password proof and its canonical
  // Grant. No fixture code invents a credential id, authority reference, Grant
  // projection, exchange credential, or handoff token.
  await createPasswordCredential({
    spaceId: seed.spaceId,
    credential: {
      name: "Private studio password",
      password: PASSWORD,
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: {},
      target: { kind: "live" },
    },
  });
  await createPasswordCredential({
    spaceId: seed.spaceId,
    credential: {
      name: "Private studio verified-email password",
      password: VERIFIED_EMAIL_PASSWORD,
      resources: { include: ["/**"], exclude: [] },
      capabilities: ["page.view"],
      constraints: { requireVerifiedEmail: true },
      target: { kind: "live" },
    },
  });

  const exchangeEffects = { password: 0, request: 0 };
  let exchangeUnavailable = false;
  const requestLifecycleEmail = `request-lifecycle-${seed.spaceId}@example.test`;
  let controlPlane: ReturnType<typeof createApp> | undefined;
  const exchange = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch: async (request) => {
      const { pathname } = new URL(request.url);
      if (request.method === "GET" && pathname === "/__fixture/exchange-effects") {
        return Response.json(exchangeEffects);
      }
      if (request.method === "POST" && pathname === "/__fixture/exchange-unavailable") {
        exchangeUnavailable = true;
        return new Response(null, { status: 204 });
      }
      if (request.method === "POST" && pathname === "/__fixture/exchange-available") {
        exchangeUnavailable = false;
        return new Response(null, { status: 204 });
      }
      if (request.method === "POST" && pathname === "/__fixture/expire-sessions") {
        return (async () => {
          if (!runtime) return Response.json({ error: "runtime unavailable" }, { status: 503 });
          await logoutAllSpaceSessions(seed.spaceId);
          const refreshedSpace = await db.query.spaces.findFirst({
            where: (spaces, { eq: equals }) => equals(spaces.id, seed.spaceId),
          });
          if (!refreshedSpace) {
            return Response.json({ error: "space unavailable" }, { status: 503 });
          }
          await putRoute(runtime, seed.spaceId, "production", {
            version_id: versionId,
            config: await runtimeRouteConfigForSpace(refreshedSpace),
          });
          return new Response(null, { status: 204 });
        })();
      }
      if (exchangeUnavailable) {
        return Response.json({ error: { code: "fixture_unavailable" } }, { status: 503 });
      }
      if (pathname === "/__fixture/request-state") {
        if (request.method === "GET") {
          return Response.json(
            await db.query.accessRequests.findMany({
              columns: { status: true },
              where: (requests, { and, eq: equals }) =>
                and(
                  equals(requests.spaceId, seed.spaceId),
                  equals(requests.normalizedEmail, requestLifecycleEmail),
                ),
              orderBy: (requests, { desc }) => [desc(requests.createdAt)],
            }),
          );
        }
        const body: unknown = await request.json();
        const action =
          typeof body === "object" && body !== null && "action" in body ? body.action : null;
        const current = await db.query.accessRequests.findFirst({
          columns: { id: true, status: true },
          where: (requests, { and, eq: equals, inArray }) =>
            and(
              equals(requests.spaceId, seed.spaceId),
              equals(requests.normalizedEmail, requestLifecycleEmail),
              inArray(requests.status, ["verification_pending", "pending"]),
            ),
          orderBy: (requests, { desc }) => [desc(requests.createdAt)],
        });
        if (!current || !controlPlane) {
          return new Response("request lifecycle fixture state missing", { status: 409 });
        }
        if (action === "verification-url") {
          const email = await db.query.sentEmails.findFirst({
            columns: { props: true },
            where: (emails, { and, eq: equals }) =>
              and(
                equals(emails.to, requestLifecycleEmail),
                equals(emails.subject, "Verify your Spacefast access request"),
              ),
            orderBy: (emails, { desc }) => [desc(emails.createdAt)],
          });
          const links = email?.props.links;
          const actionUrl = Array.isArray(links)
            ? links.find(
                (link): link is { label: string; url: string } =>
                  typeof link === "object" &&
                  link !== null &&
                  "label" in link &&
                  typeof link.label === "string" &&
                  "url" in link &&
                  typeof link.url === "string",
              )?.url
            : undefined;
          if (current.status !== "verification_pending" || typeof actionUrl !== "string") {
            return new Response("request verification fixture state missing", { status: 409 });
          }
          return Response.json({ url: actionUrl });
        }
        if (action === "deny") {
          const denied = await controlPlane.handle(
            new Request(
              `http://control-plane.test/v1/spaces/${seed.spaceId}/access-requests/${current.id}/deny`,
              { method: "POST", headers: seed.authHeaders },
            ),
          );
          if (denied.status !== 200) return denied;
          return new Response(null, { status: 204 });
        }
        if (action === "expire") {
          setAccessRequestClockForTests(() => Date.now() + 15 * 24 * 60 * 60 * 1000);
          try {
            const expired = await controlPlane.handle(
              new Request(`http://control-plane.test/v1/spaces/${seed.spaceId}/access-requests`, {
                headers: seed.authHeaders,
              }),
            );
            if (expired.status !== 200) return expired;
          } finally {
            setAccessRequestClockForTests();
          }
          return new Response(null, { status: 204 });
        }
        return new Response("unknown request lifecycle action", { status: 400 });
      }
      if (request.method === "POST" && pathname.endsWith("/password")) {
        exchangeEffects.password += 1;
      }
      if (request.method === "POST" && pathname.endsWith("/request")) {
        exchangeEffects.request += 1;
      }
      if (pathname.startsWith("/acquire/")) {
        return deliveryExchangeContractRoutes.fetch(request);
      }
      if (!controlPlane) return new Response("fixture starting", { status: 503 });
      return controlPlane.handle(request);
    },
  });
  stopExchange = () => exchange.stop(true);
  // Route server calls and browser handoffs through this fixture. Production
  // keeps these origins separate, so overriding one must not leave projected
  // exchange URLs on the environment's public handoff origin.
  const fixtureOrigin = `http://127.0.0.1:${exchange.port}`;
  Object.assign(env, {
    SPACEFAST_API_URL: fixtureOrigin,
    SPACEFAST_BROWSER_HANDOFF_URL: fixtureOrigin,
    SPACEFAST_AUTH_EMAIL_FROM: "Spacefast <accounts@example.test>",
    SPACEFAST_EMAIL_TRANSPORT: "log",
  });
  controlPlane = createApp();
  await controlPlane.modules;
  // Attempt budgets key on the visitor IP and inbox address, which repeat
  // across local runs against the shared Redis. Clear them so a rerun starts
  // with empty windows.
  await clearAllFixedWindowCountersForTests();

  const manifestPaths = new Set(["custom/index.html", "custom/_pages/access.html"]);
  const compiledConfig = compileVersionConfig({
    manifestPaths,
    spacefastConfigRaw: JSON.stringify({ index: false, listing: false, viewer: false }),
    overlay: null,
  });
  const pages = await compilePages({
    paths: manifestPaths,
    readFile: async (path) => (path === "custom/_pages/access.html" ? PAGE_SOURCE : null),
    theme: compiledConfig.serving.pageTheme,
    pagesTemplatesEntitled: true,
    hideSpacefastBrandingEntitled: false,
    listing: compiledConfig.serving.listing,
    viewer: compiledConfig.serving.viewer,
  });
  const compileErrors = pages.diagnostics.filter(({ severity }) => severity === "error");
  if (compileErrors.length > 0) {
    throw new Error(`access page compilation failed: ${JSON.stringify(compileErrors)}`);
  }

  // The share link a recipient is given: the destination URL with the durable
  // token on it. The browser spec walks that URL shape, rehosted on this
  // fixture's plain-HTTP origin.
  const link = await createAccessShareLink({
    spaceId: seed.spaceId,
    name: "Browser share link fixture",
    landingPath: "/custom/",
    resources: { include: ["/**"], exclude: [] },
    capabilities: ["page.view"],
    constraints: {},
    target: { kind: "live" },
    createdBy: seed.userId,
  });
  const linkUrl = new URL(link.url);
  if (!linkUrl.searchParams.get("__")) {
    throw new Error("share link fixture produced no branded URL token");
  }

  const space = await db.query.spaces.findFirst({
    where: (spaces, { eq: equals }) => equals(spaces.id, seed.spaceId),
  });
  if (!space) throw new Error("seeded space disappeared before route projection");

  // Project the whole runtime route through production boundaries. Neither
  // authorization nor hostname intent is fixture-shaped.
  const projectedRoute = await runtimeRouteConfigForSpace(space);
  const versionId = createId("ver");
  const routeIntent = await runtimeRouteIntentForSpace({
    space,
    defaultHostname: defaultHostname.hostname,
    hostnameLabel: defaultHostname.label,
    hostnameScope: defaultHostname.scopeLabel,
    versionId,
  });
  if (!routeIntent.production_hostnames.includes(hostname)) {
    throw new Error(`assigned hostname missing from production route projection: ${hostname}`);
  }
  const deployment = {
    spaceId: seed.spaceId,
    versionId,
    files: {
      "custom/index.html": "<!doctype html><html><body>custom home</body></html>",
    },
    pageArtifacts: pages.documents,
    serving: {
      config: runtimeServingConfigPayload(compiledConfig.serving, {
        pagePointers: pages.pointers,
      }),
    },
    activate: {
      route_name: "production",
      ...routeIntent,
      config: projectedRoute,
    },
  };

  runtime = await startRuntime();
  await deploy(runtime, deployment);
  const port = new URL(runtime.baseUrl).port;
  process.stdout.write(
    `${JSON.stringify({
      host: hostname,
      pageUrl: `http://${hostname}:${port}/custom/`,
      defaultPageUrl: `http://${hostname}:${port}/`,
      linkPageUrl: `http://${hostname}:${port}${linkUrl.pathname}${linkUrl.search}`,
      requestLifecycleEmail,
      requestStateUrl: `http://127.0.0.1:${exchange.port}/__fixture/request-state`,
      exchangeEffectsUrl: `http://127.0.0.1:${exchange.port}/__fixture/exchange-effects`,
      exchangeControlUrl: `http://127.0.0.1:${exchange.port}/__fixture`,
      accessStateCodes,
    })}\n`,
  );
  // Stay alive alongside the PHP child and exchange server. The SIGINT/SIGTERM
  // handlers above do the cleanup and exit.
  await new Promise<void>(() => {});
} catch (error) {
  await cleanup();
  throw error;
}
