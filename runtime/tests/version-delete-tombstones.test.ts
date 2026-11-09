// A deleted version keeps its files on the box, so only its tombstone stops its
// immutable hostname from serving. This drives the real control-plane delete and
// takedown restore against the real PHP runtime and Postgres: deleting a second
// version must not bring the first back, and neither may a takedown restore.
import { expect, test } from "bun:test";
import { randomBytes } from "node:crypto";
import { existsSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  RUNTIME_ATOMIC_DATA,
  RUNTIME_INSTANCE_ID,
  api,
  deploy,
  get,
  startRuntime,
  versionRoot,
} from "./harness.ts";

const CURRENT_BOX_ID = "rti_current";

test("deleted versions stay tombstoned across later deletes and a takedown restore", async () => {
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
  const { eq } = await import("drizzle-orm");
  const { db } = await import("../../apps/control-plane/src/db/client.js");
  const { channels, runtimeBoxes, spaces, versions } =
    await import("../../apps/control-plane/src/db/schema/core.js");
  const { runtimeJwks } = await import("../../apps/control-plane/src/runtime/auth.js");
  const { ensureSpaceDefaultHostname } =
    await import("../../apps/control-plane/src/runtime/hostname-identity.js");
  const { runtimeRouteConfigForSpace } =
    await import("../../apps/control-plane/src/runtime/route-config.js");
  const { runtimeRouteIntentForSpace } =
    await import("../../apps/control-plane/src/runtime/runtime-api-routes.js");
  const { versionImmutableHostname } =
    await import("../../apps/control-plane/src/runtime/hostnames.js");
  const { deleteVersion } = await import("../../apps/control-plane/src/versions/lifecycle.js");
  const { applySpaceTakedown, restoreSpaceTakedown } =
    await import("../../apps/control-plane/src/spaces/takedown.js");
  const { createId } = await import("../../apps/control-plane/src/lib/ids.js");
  const { MAIN_TENANT_ID } = await import("@spacefast/common/contracts/ids");
  const {
    seedSpaceWithKey,
    cleanupSpaceWithKey,
    seedSessionUser,
    sessionCookie,
    cleanupSessionUser,
  } = await import("../../apps/control-plane/src/test/seed.js");
  const { createApp } = await import("../../apps/control-plane/src/app.js");
  const { mintSessionReverification } =
    await import("../../apps/control-plane/src/auth/reverification.js");
  const { claimOperationById } = await import("../../apps/control-plane/src/operations/claim.js");
  const { executeLeasedOperation } =
    await import("../../apps/control-plane/src/operations/claimant.js");
  const { operations } = await import("../../apps/control-plane/src/db/schema/core.js");

  const seed = await seedSpaceWithKey("version-delete-tombstones", {
    providerPlacement: "unplaced",
  });
  cleanup.defer(() => cleanupSpaceWithKey(seed));
  // The runtime trusts both the harness key (which seeds versions) and the key
  // this control plane signs management calls with.
  const harnessKeys = z
    .object({ keys: z.array(z.record(z.string(), z.unknown())) })
    .parse(
      JSON.parse(Buffer.from(RUNTIME_ATOMIC_DATA.SPACEFAST_RUNTIME_JWKS_B64, "base64").toString()),
    );
  const jwks = { keys: [...harnessKeys.keys, ...runtimeJwks().keys] };
  const runtime = await startRuntime({
    visitorScheme: "https",
    atomicData: {
      SPACEFAST_RUNTIME_JWKS_B64: Buffer.from(JSON.stringify(jwks)).toString("base64"),
    },
  });
  cleanup.defer(() => runtime.stop());
  // Real local serving capacity: the control plane manages this PHP runtime,
  // whose instance id the harness signs for.
  await db.insert(runtimeBoxes).values({
    id: RUNTIME_INSTANCE_ID,
    tenantId: MAIN_TENANT_ID,
    boxHostname: runtime.baseUrl,
    providerRef: `provider-${seed.spaceId}`,
    status: "assigned",
    spaceId: seed.spaceId,
    engineRevision: "seed-revision",
  });
  await db.update(spaces).set({ boxId: RUNTIME_INSTANCE_ID }).where(eq(spaces.id, seed.spaceId));
  cleanup.defer(async () => {
    await db.update(spaces).set({ boxId: null }).where(eq(spaces.id, seed.spaceId));
    await db.delete(runtimeBoxes).where(eq(runtimeBoxes.id, RUNTIME_INSTANCE_ID));
  });

  const identity = await ensureSpaceDefaultHostname(seed.spaceId);
  const label = {
    label: identity.label,
    scopeLabel: identity.scopeLabel,
    hostname: identity.hostname,
  };
  const space = await db.query.spaces.findFirst({ where: eq(spaces.id, seed.spaceId) });
  if (!space) throw new Error("seeded Space is missing");
  const [first, second, live] = [createId("ver"), createId("ver"), createId("ver")] as const;
  const ids = [first, second, live];
  await db.insert(versions).values(
    ids.map((id, index) => ({
      id,
      spaceId: seed.spaceId,
      number: index + 1,
      status: "ready" as const,
      readyAt: new Date().toISOString(),
      skippedPathsJson: [],
    })),
  );
  const host = (id: string) => versionImmutableHostname({ id, number: ids.indexOf(id) + 1 }, label);
  // Publish each version's bytes and the live route with every immutable host.
  for (const id of ids) {
    // eslint-disable-next-line no-await-in-loop -- one finalize at a time per Space
    await deploy(runtime, {
      spaceId: seed.spaceId,
      versionId: id,
      files: { "index.html": `<h1>${id}</h1>\n` },
      activate: {
        route_name: "production",
        config: await runtimeRouteConfigForSpace(space),
        ...(await runtimeRouteIntentForSpace({
          space,
          defaultHostname: identity.hostname,
          hostnameLabel: identity.label,
          hostnameScope: identity.scopeLabel,
          versionId: id,
        })),
      },
    });
  }
  await db.update(channels).set({ versionId: live }).where(eq(channels.spaceId, seed.spaceId));
  // The Space is private, so a routed version answers the access gate (403).
  // A tombstoned host answers the removed-page 404 before any access check.
  const routed = async (id: string) => {
    const response = await get(runtime, host(id), "/");
    await response.arrayBuffer();
    return response.status !== 404;
  };
  for (const id of ids) expect(await routed(id)).toBe(true);

  const actor = { type: "user" as const, id: createId("usr") };
  await deleteVersion({
    spaceId: seed.spaceId,
    versionId: first,
    actor,
    operationId: createId("op"),
  });
  expect(await routed(first)).toBe(false);
  await deleteVersion({
    spaceId: seed.spaceId,
    versionId: second,
    actor,
    operationId: createId("op"),
  });
  // The second delete must not un-tombstone the first: its files are kept.
  expect(await routed(first)).toBe(false);
  expect(await routed(second)).toBe(false);
  expect(await routed(live)).toBe(true);

  // Deleted versions leave the route intent, so a takedown restore (which
  // removes tombstones for every intent hostname) cannot bring them back.
  const takedown = {
    space,
    operationId: createId("op"),
    reason: "site_suspended" as const,
    actor,
    details: null,
  };
  expect(await applySpaceTakedown(takedown)).toBe(true);
  const disabled = await db.query.spaces.findFirst({ where: eq(spaces.id, seed.spaceId) });
  if (!disabled) throw new Error("Space vanished during takedown");
  await restoreSpaceTakedown({ space: disabled, operationId: createId("op") });
  expect(await routed(live)).toBe(true);
  expect(await routed(first)).toBe(false);
  expect(await routed(second)).toBe(false);

  // A control-plane pod older than #5170 that claims a version delete sends a
  // replace tombstone naming only its own hostname before its hold refuses.
  // That un-tombstones every version deleted before it.
  const replaced = await api(
    runtime,
    "PUT",
    `/__spacefast/api.php/spaces/${seed.spaceId}/tombstones`,
    "update_tombstones",
    { space_id: seed.spaceId },
    { mode: "replace", hostnames: [host(second)], reason: "version_deleted" },
  );
  expect(replaced.status).toBe(200);
  await replaced.arrayBuffer();
  expect(await routed(first)).toBe(true);

  // The one-shot repair puts it back with no further delete. A person runs
  // it from Superadmin: the preview is the dry run, queueing applies it, and a
  // second run changes nothing.
  const app = createApp();
  const admin = await seedSessionUser("retombstone-repair", {
    wpcomUserId: "wpcom_superadmin_test",
  });
  cleanup.defer(() => cleanupSessionUser(admin));
  await mintSessionReverification(admin.sessionToken);
  const headers = {
    cookie: await sessionCookie(admin.sessionToken),
    "content-type": "application/json",
  };
  const body = JSON.stringify({
    reason: "Put deleted versions' tombstones back after the rollout",
    task: { action: "retombstoneDeletedVersions" },
  });
  const preview = await app.request("http://localhost/superadmin/operations/repairs/preview", {
    method: "POST",
    headers,
    body,
  });
  expect(preview.status, await preview.clone().text()).toBe(200);
  const plan = z
    .object({
      data: z.object({
        targets: z.array(z.string()),
        details: z.object({ spaces: z.number(), hostnames: z.number() }).passthrough(),
      }),
    })
    .parse(await preview.json()).data;
  expect(plan.targets).toContain(seed.spaceId);
  expect(await routed(first)).toBe(true);
  const run = async () => {
    const queued = await app.request("http://localhost/superadmin/operations/repairs", {
      method: "POST",
      headers,
      body,
    });
    expect(queued.status, await queued.clone().text()).toBe(202);
    const { operationId } = z
      .object({ data: z.object({ operationId: z.string() }) })
      .parse(await queued.json()).data;
    cleanup.defer(() => db.delete(operations).where(eq(operations.id, operationId)));
    const leased = await claimOperationById(operationId);
    if (!leased) throw new Error("repair was not claimable");
    expect((await executeLeasedOperation({ ...leased, signal: undefined })).status).toBe(
      "succeeded",
    );
    const row = await db.query.operations.findFirst({ where: eq(operations.id, operationId) });
    return z
      .object({
        results: z.array(
          z.object({ spaceId: z.string(), added: z.number(), alreadyTombstoned: z.number() }),
        ),
      })
      .parse(row?.receiptJson)
      .results.find((result) => result.spaceId === seed.spaceId);
  };
  expect(await run()).toMatchObject({ added: 1, alreadyTombstoned: 1 });
  expect(await routed(first)).toBe(false);
  expect(await routed(second)).toBe(false);
  expect(await routed(live)).toBe(true);
  expect(await run()).toMatchObject({ added: 0, alreadyTombstoned: 2 });

  // An operator retiring a failed copy on the Space's previous box keeps the
  // copy's files there and only tombstones its hostname on that box. The
  // harness signs only for its own instance id, so the box above becomes the
  // previous box and the Space moves to a second runtime.
  const current = await startRuntime({
    visitorScheme: "https",
    atomicData: {
      SPACEFAST_RUNTIME_INSTANCE_ID: CURRENT_BOX_ID,
      SPACEFAST_RUNTIME_JWKS_B64: Buffer.from(JSON.stringify(jwks)).toString("base64"),
    },
  });
  cleanup.defer(() => current.stop());
  await db.insert(runtimeBoxes).values({
    id: CURRENT_BOX_ID,
    tenantId: MAIN_TENANT_ID,
    boxHostname: current.baseUrl,
    providerRef: `provider-current-${seed.spaceId}`,
    status: "assigned",
    engineRevision: "seed-revision",
  });
  cleanup.defer(() => db.delete(runtimeBoxes).where(eq(runtimeBoxes.id, CURRENT_BOX_ID)));
  await db.update(spaces).set({ boxId: null }).where(eq(spaces.id, seed.spaceId));
  await db
    .update(runtimeBoxes)
    .set({ spaceId: null, status: "ready" })
    .where(eq(runtimeBoxes.id, RUNTIME_INSTANCE_ID));
  await db
    .update(runtimeBoxes)
    .set({ spaceId: seed.spaceId })
    .where(eq(runtimeBoxes.id, CURRENT_BOX_ID));
  await db.update(spaces).set({ boxId: CURRENT_BOX_ID }).where(eq(spaces.id, seed.spaceId));
  cleanup.defer(async () => {
    await db.update(spaces).set({ boxId: null }).where(eq(spaces.id, seed.spaceId));
    await db.update(runtimeBoxes).set({ spaceId: null }).where(eq(runtimeBoxes.id, CURRENT_BOX_ID));
  });

  // The copy a failed move left behind on the previous box, routed at its
  // immutable hostname there.
  const failedCopy = createId("ver");
  await db.insert(versions).values({
    id: failedCopy,
    spaceId: seed.spaceId,
    number: 4,
    status: "failed",
    skippedPathsJson: [],
  });
  const failedHost = versionImmutableHostname({ id: failedCopy, number: 4 }, label);
  await deploy(runtime, {
    spaceId: seed.spaceId,
    versionId: failedCopy,
    files: { "index.html": `<h1>${failedCopy}</h1>\n` },
    activate: {
      route_name: "production",
      config: await runtimeRouteConfigForSpace(space),
      production_hostnames: [],
      version_hostnames: [{ hostname: failedHost, version_id: failedCopy }],
    },
  });
  const failedCopyRouted = async () => {
    const response = await get(runtime, failedHost, "/");
    await response.arrayBuffer();
    return response.status !== 404;
  };
  expect(await failedCopyRouted()).toBe(true);
  const retired = await app.request("http://localhost/superadmin/operations/repairs", {
    method: "POST",
    headers,
    body: JSON.stringify({
      reason: "Retire the copy a failed move left on the previous box",
      task: { action: "retireFailedCopy", boxId: RUNTIME_INSTANCE_ID, versionId: failedCopy },
    }),
  });
  expect(retired.status, await retired.clone().text()).toBe(202);
  const retiredId = z
    .object({ data: z.object({ operationId: z.string() }) })
    .parse(await retired.json()).data.operationId;
  cleanup.defer(() => db.delete(operations).where(eq(operations.id, retiredId)));
  const leasedRetire = await claimOperationById(retiredId);
  if (!leasedRetire) throw new Error("retire repair was not claimable");
  expect((await executeLeasedOperation({ ...leasedRetire, signal: undefined })).status).toBe(
    "succeeded",
  );
  // The copy's files stay on the previous box, and it no longer routes there.
  expect(existsSync(versionRoot(runtime, seed.spaceId, failedCopy))).toBe(true);
  expect(await failedCopyRouted()).toBe(false);
  const retiredRow = await db.query.versions.findFirst({ where: eq(versions.id, failedCopy) });
  expect(retiredRow?.deletedAt).toBeTruthy();
  expect(retiredRow?.deletionReason).toBe("failed_copy_retired");
  expect(retiredRow?.deletedByActorId).toBe(admin.userId);
}, 400_000);
