// The Sign in with Spacefast fixture: the real control plane in this process,
// the real engine under the runtime harness (one PHP runtime per Space, as one
// box serves one Space), and the dashboard's own hosted build. Nothing on the
// sign-in path is a stand-in. The browser reaches every origin by its
// production name through one TLS listener here (`--host-resolver-rules` in the
// spec), which hands each request to its owner:
//
//   api.spacefast.com, access.spacefast.com → the control plane (`app.handle`)
//   my.spacefast.com                         → apps/my/dist/client
//   <space>.space.fast                       → that Space's runtime
//
// Cast is the one service not run. The spec answers its websocket at the wire,
// as packages/collab-sdk's browser proofs do, and the control plane's session
// drops go to a receiver here that accepts every drop, as Cast does.
//
// The switch is flipped the way the operator route flips it (`mutateOverrides`
// + `setFeatureOverride`), and the route syncs that queues are run by the
// control plane's own operation worker in this process.
//
// Nothing leaves this machine: the whole run is in a loopback-only network
// namespace (confined.sh). The PHP engines reach the production names through
// a local forward proxy that maps them to this fixture's listener.
//
// All data is self-seeded in the run's own database, which run.ts provisions
// and drops after this process exits.
import { randomBytes } from "node:crypto";
import { existsSync, mkdtempSync, readFileSync, rmSync, statSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { requireLauncherDatabase } from "./launcher-database.ts";

/**
 * The fixture. Importing this module loads only Node built-ins and the local
 * check, and runs nothing. sign-in-flow-fixture.ts calls this after its own check, and
 * this checks again first; every package it needs is loaded only after that.
 */
export async function main(): Promise<void> {
  await requireLauncherDatabase();
  // Packages load only now, past the check: importing this module resolves none.
  const { and, eq, sql } = await import("drizzle-orm");
  const { z } = await import("zod");
  const { MAIN_TENANT_ID } = await import("@spacefast/common/contracts/ids");
  const { startNameProxy } = await import("./name-proxy.ts");

  // Loaded by sign-in-flow-fixture.ts once it has checked the namespace and the
  // run's database. No network here but loopback (confined.sh). run.ts has
  // checked that the engine's pinned WordPress toolkit and the hosted dashboard
  // build are on disk.
  const runtimeBin = process.env.SPACEFAST_RUNTIME_BIN;
  await import("../../apps/control-plane/src/test-env.ts");
  if (runtimeBin === undefined) delete process.env.SPACEFAST_RUNTIME_BIN;
  else process.env.SPACEFAST_RUNTIME_BIN = runtimeBin;

  const API_ORIGIN = "https://api.spacefast.com";
  // The platform hostnames the fixture serves.
  const SERVED_PLATFORM_HOSTS = new Set([
    "api.spacefast.com",
    "access.spacefast.com",
    "my.spacefast.com",
  ]);
  const DASHBOARD_ORIGIN = "https://my.spacefast.com";
  const ACCESS_ORIGIN = "https://access.spacefast.com";

  // Production's names, so cookies, Origin and Fetch Metadata behave as they do
  // for a person: the API and the dashboard share a site; a Space is another.
  // `share.sign_in` stays off by default; the spec turns it on per team.
  delete process.env.SPACEFAST_FLAG_SHARE_SIGN_IN;
  Object.assign(process.env, {
    SPACEFAST_ENV_DOMAIN: "spacefast.com",
    SPACEFAST_API_URL: API_ORIGIN,
    SPACEFAST_BROWSER_HANDOFF_URL: API_ORIGIN,
    SPACEFAST_AGENT_HANDOFF_URL: API_ORIGIN,
    SPACEFAST_DASHBOARD_URL: DASHBOARD_ORIGIN,
    SPACEFAST_WWW_URL: "https://spacefast.com",
    SPACEFAST_MCP_URL: "https://mcp.spacefast.com",
    SPACEFAST_CAST_API_URL: "https://cast.spacefast.com",
    SPACEFAST_CAST_WS_URL: "wss://cast.spacefast.com/_ws/socket",
    SPACEFAST_EMAIL_TRANSPORT: "log",
    SPACEFAST_RUN_OPERATION_WORKER: "true",
  });
  // No provider stands behind these boxes. Provider calls the control plane's
  // background jobs make (aliases, edge rules, crons, site metadata) go to a
  // local endpoint that refuses each one; the fixture never names the real
  // provider host, so nothing it does is an attempt to reach it.
  const refusingProvider = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch() {
      return new Response("no provider in this fixture", { status: 503 });
    },
  });
  process.env.WP_CLOUD_API_BASE = `http://127.0.0.1:${refusingProvider.port}/api/v1.0/`;

  const [
    { createApp },
    { db },
    { authUsers },
    { spaceGrants },
    { channels, operations, runtimeBoxes, runtimeReleases, spaceCollaboration, spaces, versions },
    { env },
    { createId },
    { encryptOpaqueValue },
    { buildRuntimeComponentInventory },
    { runtimeJwks },
    { runtimeRouteConfigForSpace },
    { runtimeRouteIntentForSpace },
    { ensureSpaceDefaultHostname },
    seed,
    { mutateOverrides },
    { setFeatureOverride, teamFeatureScope },
    { startOperationClaimant, stopOperationClaimant, waitForOperation },
    { spaceSubjectUserId },
    { setRuntimeConnectAddressDependenciesForTests },
    { verifyCollaborationBearer },
    { parseCommentRoomKey },
    { buildCdnModules },
    harness,
  ] = await Promise.all([
    import("../../apps/control-plane/src/app.ts"),
    import("../../apps/control-plane/src/db/client.ts"),
    import("../../apps/control-plane/src/db/schema/auth.ts"),
    import("../../apps/control-plane/src/db/schema/policy.ts"),
    import("../../apps/control-plane/src/db/schema/core.ts"),
    import("../../apps/control-plane/src/config/env.ts"),
    import("../../apps/control-plane/src/lib/ids.ts"),
    import("../../apps/control-plane/src/lib/crypto.ts"),
    import("../../apps/control-plane/src/runtime/component-lock.ts"),
    import("../../apps/control-plane/src/runtime/auth.ts"),
    import("../../apps/control-plane/src/runtime/route-config.ts"),
    import("../../apps/control-plane/src/runtime/runtime-api-routes.ts"),
    import("../../apps/control-plane/src/runtime/hostname-identity.ts"),
    import("../../apps/control-plane/src/test/seed.ts"),
    import("../../apps/control-plane/src/superadmin/features.ts"),
    import("../../apps/control-plane/src/features/overrides.ts"),
    import("../../apps/control-plane/src/operations/claimant.ts"),
    import("../../apps/control-plane/src/access/space-subject.ts"),
    import("../../apps/control-plane/src/runtime/runtime-connect-address.ts"),
    import("../../apps/control-plane/src/comments/mint.ts"),
    import("../../apps/control-plane/src/comments/rooms.ts"),
    import("../../packages/space-client/scripts/browser.mjs"),
    import("../tests/harness.ts"),
  ]);

  const DASHBOARD_ROOT = fileURLToPath(new URL("../../apps/my/dist/client/", import.meta.url));
  if (!existsSync(path.join(DASHBOARD_ROOT, "_shell.html"))) {
    throw new Error("the hosted dashboard build is missing: run `bun run build:hosted` in apps/my");
  }
  // The build names its API at build time; this proof serves the production names.
  const dashboardBundles = [...new Bun.Glob("assets/*.js").scanSync({ cwd: DASHBOARD_ROOT })];
  if (
    !dashboardBundles.some((file) =>
      readFileSync(path.join(DASHBOARD_ROOT, file), "utf8").includes(API_ORIGIN),
    )
  ) {
    throw new Error(
      `the dashboard build does not call ${API_ORIGIN}; rebuild it without VITE_API_URL`,
    );
  }

  const castDrops = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    async fetch(request) {
      const body = z.object({ receipt_id: z.string() }).safeParse(await request.json());
      if (!body.success) return new Response(null, { status: 404 });
      return Response.json({
        receipt: { id: body.data.receipt_id, attempted_sessions: 1, accepted: true },
      });
    },
  });
  Object.assign(env, { SPACEFAST_CAST_INTERNAL_API_URL: `http://127.0.0.1:${castDrops.port}` });

  type Runtime = Awaited<ReturnType<typeof harness.startRuntime>>;
  const runtimes: Runtime[] = [];
  let listener: ReturnType<typeof Bun.serve> | undefined;
  let tlsDirectory: string | undefined;
  let castSdkDirectory: string | undefined;
  let stopProxy: (() => void) | undefined;
  let controlPlane: ReturnType<typeof Bun.serve> | undefined;
  let control: ReturnType<typeof Bun.serve> | undefined;
  // One cleanup, shared: a second signal (the launcher's stop, then the spec's
  // exit) awaits the same teardown instead of returning before it is done.
  let cleaning: Promise<void> | undefined;
  /**
   * Stops everything this fixture started. Every action is attempted; any that
   * fails makes cleanup throw, and the fixture exit non-zero. The database is
   * the run's own (run.ts provisions it and drops it after this process exits),
   * so no seeded row needs deleting here.
   */
  function cleanup(): Promise<void> {
    cleaning ??= teardown();
    return cleaning;
  }
  async function teardown() {
    const actions: [string, () => Promise<void> | void][] = [
      [
        "operation claimant",
        async () => {
          await stopOperationClaimant({ drainDeadlineMs: 2_000 });
        },
      ],
      ["engine proxy", () => stopProxy?.()],
      ["TLS listener", () => listener?.stop(true)],
      // The certificate's private key never outlives the run.
      [
        "TLS key",
        () => {
          if (tlsDirectory) rmSync(tlsDirectory, { recursive: true, force: true });
        },
      ],
      [
        "Cast SDK assets",
        () => {
          if (castSdkDirectory) rmSync(castSdkDirectory, { recursive: true, force: true });
        },
      ],
      ["control plane", () => controlPlane?.stop(true)],
      ["fixture control", () => control?.stop(true)],
      ["Cast drop receiver", () => castDrops.stop(true)],
      ["refusing provider", () => refusingProvider.stop(true)],
      ...runtimes.map((rt, index): [string, () => void] => [
        `engine ${index + 1}`,
        () => rt.stop(),
      ]),
    ];
    const failed: string[] = [];
    for (const [name, action] of actions) {
      try {
        // oxlint-disable-next-line no-await-in-loop -- teardown runs in order.
        await action();
      } catch (error) {
        failed.push(`${name}: ${error instanceof Error ? error.message : String(error)}`);
      }
    }
    if (failed.length > 0) throw new Error(`cleanup failed: ${failed.join("; ")}`);
  }
  for (const signal of ["SIGINT", "SIGTERM"] as const) {
    process.on(signal, () => {
      // A cleanup that fails fails the run: the launcher reads this exit code.
      cleanup().then(
        () => process.exit(0),
        (error: Error) => {
          console.error("fixture cleanup failed:", error.message);
          process.exit(1);
        },
      );
    });
  }

  /** The keys an engine verifies management calls with: the harness's, which deploys, and this control plane's. */
  function trustedJwksB64() {
    const harnessKeys = z
      .object({ keys: z.array(z.record(z.string(), z.unknown())) })
      .parse(
        JSON.parse(
          Buffer.from(harness.RUNTIME_ATOMIC_DATA.SPACEFAST_RUNTIME_JWKS_B64, "base64").toString(),
        ),
      );
    return Buffer.from(
      JSON.stringify({ keys: [...harnessKeys.keys, ...runtimeJwks().keys] }),
    ).toString("base64");
  }

  try {
    // A release whose engine declares what the flow needs, recorded from the
    // same inventory a real build records: runtime.siws.v1 (sign-in),
    // runtime.space-bar.v1 (the bar), runtime.space-subjects.v1 (per-Space ids),
    // runtime.space-identity.v1 and runtime.platform-jwks.v1 (the Space's own
    // credential and key fetch).
    const revision = `sign-in-flow-${randomBytes(6).toString("hex")}`;
    await db.insert(runtimeReleases).values({
      revision,
      promotedAt: new Date().toISOString(),
      zipUrl: `https://example.test/${revision}.zip`,
      md5: "c".repeat(32),
      nativeSha256: "c".repeat(64),
      componentInventoryJson: buildRuntimeComponentInventory({
        revision,
        nativeSha256: "c".repeat(64),
      }),
    });

    const app = createApp();
    await app.modules;
    controlPlane = Bun.serve({
      hostname: "127.0.0.1",
      port: 0,
      fetch: (request) => app.handle(request),
    });
    const controlPlaneOrigin = `http://127.0.0.1:${controlPlane.port}`;
    // A route sync reaches each box by its management name, which the fetch
    // wrapper below sends to that box's PHP server. Without a provider there are
    // no provider addresses to look up first.
    setRuntimeConnectAddressDependenciesForTests({
      loadProviderTarget: async () => null,
    });
    await startOperationClaimant({ maintenance: false, idlePollMs: 200 });

    // Each Space's hostname, served by its runtime. The browser reaches them
    // through the listener below; the control plane's own public probes (a route
    // sync proves the hostname serves before it reports done) go the same way,
    // where production would go through the provider's edge.
    const routes = new Map<string, Runtime>();
    // A Space's version hosts (`<label>--v<n>.<apex>`) are served by its box too.
    const runtimeFor = (host: string) =>
      routes.get(host) ?? routes.get(host.replace(/--v\d+(?=\.)/, ""));
    const realFetch = globalThis.fetch;
    globalThis.fetch = Object.assign(
      async (input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) => {
        const request =
          input instanceof Request ? new Request(input, init) : new Request(input, init);
        const url = new URL(request.url);
        const rt = url.protocol === "https:" ? runtimeFor(url.hostname) : undefined;
        if (!rt) {
          if (SERVED_PLATFORM_HOSTS.has(url.hostname))
            return app.handle(new Request(request, { redirect: "manual" }));
          return realFetch(input, init);
        }
        const headers = new Headers(request.headers);
        headers.set("host", url.hostname);
        headers.set("connection", "close");
        return realFetch(`${rt.baseUrl}${url.pathname}${url.search}`, {
          method: request.method,
          headers,
          body:
            request.method === "GET" || request.method === "HEAD"
              ? null
              : await request.arrayBuffer(),
          redirect: "manual",
          signal: request.signal,
        });
      },
      { preconnect: realFetch.preconnect },
    );

    // Build the Cast CDN modules (`sdk/v1/bar.js`, `sdk/v1/client.js`, and their
    // shared chunks) into a run-owned temp directory, matching Cast's own build.
    castSdkDirectory = mkdtempSync(path.join(tmpdir(), "sign-in-flow-cast-sdk-"));
    await buildCdnModules({ outdir: castSdkDirectory });

    // The TLS listener every browser and engine request for a production name
    // arrives at. Its port is known now, so the engines' proxy can name it.
    tlsDirectory = mkdtempSync(path.join(tmpdir(), "sign-in-flow-tls-"));
    const tls = harness.selfSignedCertificate(tlsDirectory, "fixture");
    // Bound before the first engine starts; set once the listener is up.
    let boundListenerPort: number | undefined;
    const proxy = await startNameProxy((host) =>
      SERVED_PLATFORM_HOSTS.has(host) || runtimeFor(host) ? boundListenerPort : undefined,
    );
    stopProxy = proxy.stop;

    const types = new Map([
      [".html", "text/html; charset=utf-8"],
      [".js", "text/javascript; charset=utf-8"],
      [".css", "text/css; charset=utf-8"],
      [".json", "application/json"],
      [".svg", "image/svg+xml"],
      [".png", "image/png"],
      [".ico", "image/x-icon"],
      [".woff2", "font/woff2"],
      [".webmanifest", "application/manifest+json"],
    ]);
    function dashboardFile(pathname: string): string {
      const relative = decodeURIComponent(pathname).replace(/^\/+/, "");
      if (!relative.split("/").includes("..")) {
        for (const candidate of [
          ...(relative === "" ? [] : [path.join(DASHBOARD_ROOT, relative)]),
          path.join(DASHBOARD_ROOT, `${relative}.html`),
          path.join(DASHBOARD_ROOT, relative, "index.html"),
        ]) {
          if (existsSync(candidate) && statSync(candidate).isFile()) return candidate;
        }
      }
      // The hosted build's SPA fallback (apps/my/sf.jsonc).
      return path.join(DASHBOARD_ROOT, "_shell.html");
    }
    listener = Bun.serve({
      hostname: "127.0.0.1",
      port: 0,
      tls: { cert: readFileSync(tls.certificate), key: readFileSync(tls.key) },
      async fetch(request) {
        const url = new URL(request.url);
        const host = (request.headers.get("host") ?? url.host).split(":")[0] ?? "";
        if (host === "api.spacefast.com" || host === "access.spacefast.com") {
          return app.handle(
            new Request(`https://${host}${url.pathname}${url.search}`, {
              method: request.method,
              headers: request.headers,
              body: request.method === "GET" || request.method === "HEAD" ? null : request.body,
              redirect: "manual",
            }),
          );
        }
        if (host === "my.spacefast.com") {
          const file = dashboardFile(url.pathname);
          return new Response(Bun.file(file), {
            headers: {
              "content-type": types.get(path.extname(file)) ?? "application/octet-stream",
            },
          });
        }
        if (
          host === "cast.spacefast.com" &&
          url.pathname.startsWith("/sdk/v1/") &&
          castSdkDirectory
        ) {
          const file = url.pathname.slice("/sdk/v1/".length);
          if (file && !file.includes("/") && !file.includes("..")) {
            const candidate = path.join(castSdkDirectory, file);
            if (existsSync(candidate) && statSync(candidate).isFile()) {
              return new Response(Bun.file(candidate), {
                headers: {
                  "content-type":
                    types.get(path.extname(candidate)) ?? "application/javascript; charset=utf-8",
                  "access-control-allow-origin": "*",
                  "cross-origin-resource-policy": "cross-origin",
                },
              });
            }
          }
          return new Response("not found", { status: 404 });
        }
        if (runtimeFor(host)) {
          return globalThis.fetch(`https://${host}${url.pathname}${url.search}`, {
            method: request.method,
            headers: request.headers,
            body:
              request.method === "GET" || request.method === "HEAD"
                ? null
                : await request.arrayBuffer(),
            redirect: "manual",
          });
        }
        return new Response("not served by this fixture", { status: 421 });
      },
    });
    boundListenerPort = listener.port;

    /** One private (or public) Space on its own box, published through the real route projection. */
    async function publishSpace(label: string, options: { public?: boolean } = {}) {
      const owner = await seed.seedSpaceWithKey(label, { providerPlacement: "unplaced" });
      const boxId = createId("box");
      const rt = await harness.startRuntime({
        visitorScheme: "https",
        atomicData: {
          SPACEFAST_API_BASE_URL: API_ORIGIN,
          SPACEFAST_BROWSER_API_URL: API_ORIGIN,
          SPACEFAST_DASHBOARD_ORIGIN: DASHBOARD_ORIGIN,
          SPACEFAST_RUNTIME_INSTANCE_ID: boxId,
          SPACEFAST_RUNTIME_JWKS_B64: trustedJwksB64(),
        },
        env: {
          // Real `__Host-` cookies over the fixture's TLS listener.
          SPACEFAST_INSECURE_COOKIES: "",
          PHP_CLI_SERVER_WORKERS: "6",
          // Test mode only: the engine dials this process for api.spacefast.com.
          SPACEFAST_ACCESS_EXCHANGE_TEST_CONNECT_ORIGIN: controlPlaneOrigin,
          // Every other outbound request (the Space token, the key set) goes
          // through the name proxy, which maps the served names to this fixture.
          ...proxy.env,
        },
        // libcurl trusts the fixture's certificate for the production names.
        phpIni: { "curl.cainfo": tls.certificate, "openssl.cafile": tls.certificate },
      });
      runtimes.push(rt);
      const boxHost = `${boxId.toLowerCase().replace("_", "-")}.box.test`;
      routes.set(boxHost, rt);
      harness.setRuntimeInstanceId(boxId);
      await db.insert(runtimeBoxes).values({
        id: boxId,
        tenantId: MAIN_TENANT_ID,
        // A box's management host is https in production, and the engine binds
        // the content principal to that origin, so the control plane reaches
        // this one by an https name the fetch shim above maps to the runtime.
        boxHostname: boxHost,
        providerRef: `provider-${owner.spaceId}`,
        status: "assigned",
        spaceId: owner.spaceId,
        engineRevision: revision,
      });
      await db.update(spaces).set({ boxId }).where(eq(spaces.id, owner.spaceId));
      const versionId = createId("ver");
      await db.insert(versions).values({
        id: versionId,
        spaceId: owner.spaceId,
        number: 1,
        status: "ready",
        readyAt: new Date().toISOString(),
        skippedPathsJson: [],
      });
      await db
        .insert(channels)
        .values({ id: createId("chn"), spaceId: owner.spaceId, name: "live", versionId });
      // Comments on, so the bar's Comments button has today's Comments to open.
      await db.insert(spaceCollaboration).values({
        id: createId("collab"),
        tenantId: MAIN_TENANT_ID,
        teamId: owner.teamId,
        spaceId: owner.spaceId,
        castResourceId: `cast_${owner.spaceId}`,
        castResourceKey: `resource_${owner.spaceId}`,
        castResourceManagementKeyCiphertext: encryptOpaqueValue("management-secret"),
        commentsFeatureState: "on",
        settingsJson: { surface: "everywhere" },
      });
      if (options.public) {
        await seed.seedSpaceGrant({
          spaceId: owner.spaceId,
          audience: { kind: "public" },
          capabilities: ["page.view", "comments.read", "comments.write"],
        });
      }
      const identity = await ensureSpaceDefaultHostname(owner.spaceId);
      routes.set(identity.hostname, rt);
      const space = await db.query.spaces.findFirst({ where: eq(spaces.id, owner.spaceId) });
      if (!space) throw new Error(`seeded Space ${owner.spaceId} vanished`);
      // A page that signs out through the Space client, as an author's page would.
      const page = (title: string, extra = "") =>
        `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title></head><body><h1>${title}</h1>${extra}</body></html>`;
      await harness.deploy(rt, {
        spaceId: owner.spaceId,
        versionId,
        files: {
          "index.html": page(`${label} home`),
          "docs/index.html": page(
            `${label} docs`,
            '<button id="page-sign-out" type="button">Sign out</button><script type="module">import { space } from "/__spacefast/client.js";document.getElementById("page-sign-out").addEventListener("click",()=>space.access.signOut());</script>',
          ),
        },
        activate: {
          route_name: "production",
          config: await runtimeRouteConfigForSpace(space),
          ...(await runtimeRouteIntentForSpace({
            space,
            defaultHostname: identity.hostname,
            hostnameLabel: identity.label,
            hostnameScope: identity.scopeLabel,
            versionId,
          })),
        },
      });
      return { ...owner, host: identity.hostname, runtime: rt };
    }

    const docs = await publishSpace("flow-docs");
    const notes = await publishSpace("flow-notes");
    const open = await publishSpace("flow-open", { public: true });

    async function person(label: string, name: string) {
      const user = await seed.seedSessionUser(label);
      await db
        .update(authUsers)
        .set({ emailVerified: true, name })
        .where(eq(authUsers.id, user.userId));
      const row = await db.query.authUsers.findFirst({ where: eq(authUsers.id, user.userId) });
      if (!row) throw new Error("seeded user vanished");
      const signed = (await seed.sessionCookie(user.sessionToken)).split("=").slice(1).join("=");
      return {
        userId: user.userId,
        email: row.email,
        name,
        token: user.sessionToken,
        cookie: signed,
      };
    }
    // Robin is invited in the browser. Sam can already view both private Spaces
    // and is the account "Switch Account" picks.
    const robin = await person("flow-robin", "Robin Guest");
    const sam = await person("flow-sam", "Sam Second");
    // Sam manages the docs Space, so the bar holds a token for him there and
    // offers "Sign Out of This Space"; on the notes Space he only comments.
    await seed.seedSpacePerson({
      spaceId: docs.spaceId,
      email: sam.email,
      displayName: sam.name,
      capabilities: [
        "page.view",
        "comments.read",
        "comments.write",
        "content.publish",
        "access.manage",
      ],
    });
    await seed.seedSpacePerson({
      spaceId: notes.spaceId,
      email: sam.email,
      displayName: sam.name,
      capabilities: ["page.view", "comments.read", "comments.write"],
    });

    // What the spec may ask the fixture to do: what an operator or the Space's
    // owner does outside the browser. Each answer is from real state.
    const ownerHeaders = new Map([
      [docs.spaceId, docs.authHeaders],
      [notes.spaceId, notes.authHeaders],
    ]);
    const teams = new Map([
      [docs.spaceId, docs.teamId],
      [notes.spaceId, notes.teamId],
      [open.spaceId, open.teamId],
    ]);
    /**
     * Waits for exactly these operations to succeed; one that fails throws.
     * `staged` maps each Space to the operation the action staged for it,
     * which may be one already in flight that the action joined. Every Space
     * the action covers must have one.
     */
    async function awaitStaged(staged: ReadonlyMap<string, string>, spaceIds: readonly string[]) {
      const missing = spaceIds.filter((spaceId) => !staged.has(spaceId));
      if (missing.length > 0) throw new Error(`no route sync was staged for ${missing.join(", ")}`);
      await Promise.all(
        spaceIds.map((spaceId) => waitForOperation(staged.get(spaceId) ?? "", 60_000)),
      );
    }

    /** The access change an invite of `email` to the Space staged: its own operation, by its payload. */
    async function inviteTransition(spaceId: string, email: string) {
      const rows = await db
        .select({ id: operations.id })
        .from(operations)
        .where(
          and(
            eq(operations.resourceId, spaceId),
            eq(operations.eventType, "access.transition"),
            sql`${operations.payloadJson}->'mutation'->>'kind' = 'person_invite'`,
            sql`${operations.payloadJson}->'mutation'->>'normalizedEmail' = ${email.trim().toLowerCase()}`,
          ),
        );
      if (rows.length !== 1)
        throw new Error(
          `expected one invite transition for ${email} on ${spaceId}, found ${rows.length}`,
        );
      return new Map([[spaceId, rows[0]?.id ?? ""]]);
    }
    control = Bun.serve({
      hostname: "127.0.0.1",
      port: 0,
      async fetch(request) {
        const { pathname } = new URL(request.url);
        const body = z
          .object({
            spaceId: z.string().optional(),
            state: z.string().optional(),
            email: z.string().optional(),
            role: z.enum(["viewer", "commenter"]).optional(),
          })
          .parse(
            request.method === "POST" && pathname !== "/verify-ticket"
              ? await request.clone().json()
              : {},
          );
        if (pathname === "/switch") {
          // Exactly what `PUT /superadmin/features/overrides` runs. Each write
          // returns the serving-state apply it staged for each of its Spaces.
          const teamIds = [...new Set(teams.values())];
          const state = z.enum(["enabled", "kill_switch"]).parse(body.state);
          const staged = new Map<string, string>();
          for (const teamId of teamIds) {
            const scope = teamFeatureScope(teamId);
            const applies = await mutateOverrides(
              { keys: ["share.sign_in"], scope },
              async (tx) => {
                await setFeatureOverride({ key: "share.sign_in", scope, state }, tx);
              },
            );
            for (const [spaceId, operationId] of applies) staged.set(spaceId, operationId);
          }
          await awaitStaged(staged, [...teams.keys()]);
          return new Response(null, { status: 204 });
        }
        if (pathname === "/account") {
          // Which account a Space's session principal names, read the way the
          // control plane reads it: an account id, or a per-Space id looked up.
          const spaceId = z.string().parse(new URL(request.url).searchParams.get("space"));
          const principal = z.string().parse(new URL(request.url).searchParams.get("principal"));
          const subject = principal.replace(/^account:/, "");
          if (subject.startsWith("person:")) {
            // A Comments ticket names an invited person by their Person entry:
            // the account that accepted it.
            const [row] = await db
              .select({ userId: spaceGrants.acceptedBy })
              .from(spaceGrants)
              .where(
                and(
                  eq(spaceGrants.spaceId, spaceId),
                  sql`${spaceGrants.audience}->>'personId' = ${subject.slice("person:".length)}`,
                ),
              )
              .limit(1);
            return Response.json({ userId: row?.userId ?? null });
          }
          // A Space that has not moved to per-Space ids is told the account id.
          const userId = subject.startsWith("usr_")
            ? subject
            : await spaceSubjectUserId(spaceId, subject);
          return Response.json({ userId });
        }
        if (pathname === "/verify-ticket") {
          // What Cast checks before it answers a join: the ticket's signature,
          // issuer and audience with the platform's keys (Cast's own verifier is
          // the Cast suite's), bound to the room it names on this Space.
          const ticket = z
            .object({ token: z.string(), room: z.string(), spaceId: z.string() })
            .parse(await request.json());
          const room = parseCommentRoomKey(ticket.room, ticket.spaceId);
          const verified = room ? await verifyCollaborationBearer(ticket.token, ticket.room) : null;
          return Response.json(
            verified?.ok
              ? { ok: true, userId: verified.userId, role: verified.role }
              : { ok: false },
          );
        }
        if (pathname === "/invite") {
          // The manager invites by email through `createSpaceAccess`.
          const spaceId = z.string().parse(body.spaceId);
          const email = z.string().parse(body.email);
          const response = await app.handle(
            new Request(`${API_ORIGIN}/v1/spaces/${spaceId}/access`, {
              method: "POST",
              headers: { ...ownerHeaders.get(spaceId), "content-type": "application/json" },
              body: JSON.stringify({
                audience: "person",
                email,
                role: body.role ?? "commenter",
              }),
            }),
          );
          const text = await response.text();
          if (response.ok) await awaitStaged(await inviteTransition(spaceId, email), [spaceId]);
          return new Response(text, { status: response.status });
        }
        return new Response(null, { status: 404 });
      },
    });

    process.stdout.write(
      `${JSON.stringify({
        listenerPort: listener.port,
        controlUrl: `http://127.0.0.1:${control.port}`,
        apiOrigin: API_ORIGIN,
        dashboardOrigin: DASHBOARD_ORIGIN,
        accessOrigin: ACCESS_ORIGIN,
        docs: { spaceId: docs.spaceId, host: docs.host, title: "flow-docs space" },
        notes: { spaceId: notes.spaceId, host: notes.host, title: "flow-notes space" },
        open: { spaceId: open.spaceId, host: open.host },
        robin,
        sam,
      })}\n`,
    );
    await new Promise<void>(() => {});
  } catch (error) {
    await cleanup();
    throw error;
  }
}
