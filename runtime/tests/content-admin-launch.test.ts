// The editor door on a real runtime, with the launch signed by the control
// plane's own signer. WordPress is stood in for at wp-load.php: the door's
// contract is the session it mints and where it sends the browser, and the
// WordPress auth cookies come from the same helper the real kernel exposes.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, sign } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, renameSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import { z } from "zod";

import {
  deploy,
  get,
  managementToken,
  publicAccessConfig,
  RUNTIME_INSTANCE_ID,
  runtimeHttpPath,
  runtimeJwks as harnessJwks,
  type Runtime,
  signEd25519Jwt,
  startRuntime,
} from "./harness.ts";

const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-content-admin-launch";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-content-admin-launch-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-content-admin-launch-token";
process.env.WP_CLOUD_CLIENT_NAME = "runtime-content-admin-launch";

const [{ runtimeContentPrincipalAssertion, signRuntimeContentAdminLaunch }, { runtimeJwks }] =
  await Promise.all([
    import("../../apps/control-plane/src/runtime/runtime-api-client.ts"),
    import("../../apps/control-plane/src/runtime/auth.ts"),
  ]);

const SPACEPRESS_HOST = "sp-admin-launch.test";
const SPACEPRESS_SPACE = "spc_spadminlaunch";
const MANAGED_HOST = "sp-admin-managed.test";
const MANAGED_SPACE = "spc_spadminmanaged";
const DASHBOARD = "https://my.spacefast.test";
const INSTANCE = RUNTIME_INSTANCE_ID;

let runtime: Runtime;

beforeAll(async () => {
  runtime = await startRuntime({
    atomicData: {
      // Not a URL: the box has no address to read the published keys from.
      SPACEFAST_API_BASE_URL: "not a url",
      // The box trusts the control plane's signing key, as a real box does,
      // beside the harness key its deploys are signed with.
      SPACEFAST_RUNTIME_JWKS_B64: Buffer.from(
        JSON.stringify({ keys: [...harnessJwks().keys, ...runtimeJwks().keys] }),
      ).toString("base64"),
    },
  });
  for (const [spaceId, host, generation] of [
    [SPACEPRESS_SPACE, SPACEPRESS_HOST, "spacepress"],
    [MANAGED_SPACE, MANAGED_HOST, "none"],
  ] as const) {
    await deploy(runtime, {
      spaceId,
      versionId: `ver_${spaceId.slice(4)}`,
      files: { "index.html": "<h1>home</h1>\n" },
      activate: {
        route_name: "production",
        config: publicAccessConfig({ mode: "website", zero_generation: generation }),
        production_hostnames: [host],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });
  }
  // WordPress, as far as the editor door can tell: a row to sign in, the
  // stock auth cookie pair for it, and a dashboard page that reports who the
  // editor session made the request.
  writeFileSync(
    path.join(runtime.root, "wp-load.php"),
    [
      "<?php",
      "define('SECURE_AUTH_COOKIE', 'wordpress_sec_test');",
      "define('LOGGED_IN_COOKIE', 'wordpress_logged_in_test');",
      "function spacefast_content_principal_establish_user(): int { return 42; }",
      "function spacefast_content_admin_auth_cookies(int $userId, int $ttl): array {",
      "  return [",
      "    ['name' => SECURE_AUTH_COOKIE, 'value' => 'sec-' . $userId],",
      "    ['name' => LOGGED_IN_COOKIE, 'value' => 'in-' . $userId],",
      "  ];",
      "}",
      "",
    ].join("\n"),
  );
  mkdirSync(path.join(runtime.root, "wp-admin"), { recursive: true });
  writeFileSync(
    path.join(runtime.root, "wp-admin/index.php"),
    "<?php header('Content-Type: application/json'); echo json_encode(['admin' => 'index', 'space' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null, 'role' => $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] ?? null, 'sessionUser' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null]);",
  );
});

afterAll(() => runtime?.stop());

async function launch(
  host: string,
  spaceId: string,
  overrides: {
    accessGeneration?: number;
    now?: number;
    access?: { surface: "wordpress"; path?: string };
    displayName?: string;
  } = {},
) {
  const signed = await signRuntimeContentAdminLaunch(
    {
      baseUrl: `http://${host}`,
      runtimeInstanceId: INSTANCE,
      operationId: "op_sp_admin",
      spaceId,
      accessGeneration: overrides.accessGeneration ?? 3,
      sessionVersion: 1,
      frameOrigin: DASHBOARD,
      publicOrigin: `https://${host}`,
      access: overrides.access ?? { surface: "wordpress" },
      principalActor: {
        kind: "user",
        id: "usr_editor",
        issuer: "https://api.spacefast.test/v1/auth",
        subject: "usr_editor",
        profile: { displayName: overrides.displayName ?? "Editor" },
      },
      grantCapabilities: ["page.view", "content.publish"],
    },
    overrides.now,
  );
  return signed.path;
}

function setCookies(response: Response): Map<string, string> {
  return new Map(
    response.headers.getSetCookie().map((cookie) => {
      const [pair = ""] = cookie.split(";", 1);
      const at = pair.indexOf("=");
      return [pair.slice(0, at), cookie];
    }),
  );
}

test("a signed launch opens the editor once, with its WordPress session, on the box it was redeemed at", async () => {
  const doorPath = await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE);
  expect(doorPath).toStartWith("/__spacefast/content-admin.php?launch=");

  const opened = await get(runtime, SPACEPRESS_HOST, doorPath);
  expect(opened.status, await opened.clone().text()).toBe(303);
  // The landing is a page the editor session opens on this same host.
  const landing = opened.headers.get("location") ?? "";
  expect(landing).toBe("/wp-admin/index.php?classic=1");
  expect(opened.headers.get("content-security-policy")).toBe(`frame-ancestors 'self' ${DASHBOARD}`);
  const cookies = setCookies(opened);
  // The platform's editor session, plus WordPress's own pair, which
  // wp-admin's auth_redirect() and the REST nonce read directly.
  expect([...cookies.keys()].toSorted()).toEqual([
    "spacefast_content_admin",
    "wordpress_logged_in_test",
    "wordpress_sec_test",
  ]);
  expect(cookies.get("wordpress_sec_test")).toContain("wordpress_sec_test=sec-42");

  // Follow it with the cookies the browser now holds: the editor session
  // takes the request into WordPress as the signed-in person.
  const cookieHeader = [...cookies.values()].map((cookie) => cookie.split(";", 1)[0]).join("; ");
  const followed = await get(runtime, SPACEPRESS_HOST, landing, {
    headers: { cookie: cookieHeader },
  });
  expect(followed.status, await followed.clone().text()).toBe(200);
  expect(await followed.json()).toEqual({
    admin: "index",
    space: SPACEPRESS_SPACE,
    role: "editor",
    sessionUser: 42,
  });

  // The frame spent it: the same launch opens nothing a second time.
  const replayed = await get(runtime, SPACEPRESS_HOST, doorPath);
  expect(replayed.status).toBe(401);
  expect((await replayed.json()).code).toBe("content_admin_ticket_invalid");

  // A new tab asks for a fresh launch, and that one opens.
  const fresh = await get(
    runtime,
    SPACEPRESS_HOST,
    await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE),
  );
  expect(fresh.status).toBe(303);
});

test("every Space lands on the classic dashboard, and an explicit path wins", async () => {
  const classic = await get(runtime, MANAGED_HOST, await launch(MANAGED_HOST, MANAGED_SPACE));
  expect(classic.status, await classic.clone().text()).toBe(303);
  expect(classic.headers.get("location")).toBe("/wp-admin/index.php?classic=1");

  const explicit = await get(
    runtime,
    SPACEPRESS_HOST,
    await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE, {
      access: { surface: "wordpress", path: "/wp-admin/edit.php?post_type=page" },
    }),
  );
  expect(explicit.status).toBe(303);
  expect(explicit.headers.get("location")).toBe("/wp-admin/edit.php?post_type=page&classic=1");
});

test("a launch is refused on another host, after it expires, or behind a newer access generation", async () => {
  const refused = async (response: Response) => {
    expect(response.status, await response.clone().text()).toBe(401);
    expect((await response.json()).code).toBe("content_admin_ticket_invalid");
    expect(response.headers.getSetCookie()).toEqual([]);
  };
  // Signed for one Space's host, presented at another's.
  await refused(await get(runtime, MANAGED_HOST, await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE)));
  // Signed two minutes ago: past its 60 s.
  await refused(
    await get(
      runtime,
      SPACEPRESS_HOST,
      await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE, {
        now: Math.floor(Date.now() / 1000) - 120,
      }),
    ),
  );
  // A forged signature.
  const genuine = await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE);
  await refused(await get(runtime, SPACEPRESS_HOST, `${genuine.slice(0, -4)}AAAA`));
  // Signed by the platform key, but naming another purpose or issuer, or none.
  const [header = "", payload = ""] = decodeURIComponent(genuine.split("launch=")[1] ?? "").split(
    ".",
  );
  const kid = z
    .object({ kid: z.string() })
    .parse(JSON.parse(Buffer.from(header, "base64url").toString())).kid;
  const claims = z
    .record(z.string(), z.unknown())
    .parse(JSON.parse(Buffer.from(payload, "base64url").toString()));
  for (const changed of [
    { ...claims, purpose: "frame-session" },
    { ...claims, iss: "someone-else" },
    { ...claims, purpose: undefined },
    { ...claims, iss: undefined },
  ]) {
    const token = signEd25519Jwt(platformKey.privateKey, kid, changed);
    await refused(
      await get(runtime, SPACEPRESS_HOST, `/__spacefast/content-admin.php?launch=${token}`),
    );
  }
  // The Space's access changed (generation 4) after this launch was signed.
  const newer = await get(
    runtime,
    SPACEPRESS_HOST,
    await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE, { accessGeneration: 4 }),
  );
  expect(newer.status).toBe(303);
  await refused(
    await get(
      runtime,
      SPACEPRESS_HOST,
      await launch(SPACEPRESS_HOST, SPACEPRESS_SPACE, { accessGeneration: 3 }),
    ),
  );
});

test("a replay store the box can't write answers retryable, and burns nothing", async () => {
  const doorPath = await launch(MANAGED_HOST, MANAGED_SPACE);
  // A file where the replay store's directory belongs: every claim fails to
  // write, as on a box whose storage refuses writes.
  const replayStore = path.join(runtime.storageRoot, "runtime/jti");
  const parked = `${replayStore}.parked`;
  renameSync(replayStore, parked);
  writeFileSync(replayStore, "");
  try {
    const outage = await get(runtime, MANAGED_HOST, doorPath);
    expect(outage.status, await outage.clone().text()).toBe(503);
    expect((await outage.json()).code).toBe("runtime_replay_guard_unavailable");
  } finally {
    rmSync(replayStore);
    renameSync(parked, replayStore);
  }
  // The same launch opens once the store is writable again.
  expect((await get(runtime, MANAGED_HOST, doorPath)).status).toBe(303);
});

test("a launch signed with a key the box can't fetch answers retryable, not a spent link", async () => {
  // The platform rotated to a key this box has not learned, and the box has
  // no usable address to read the published set from.
  const rotated = generateKeyPairSync("ed25519");
  const signed = new URL(
    await launch(MANAGED_HOST, MANAGED_SPACE),
    "https://box.invalid",
  ).searchParams.get("launch");
  // Re-sign the same claims, byte for byte, under the key the box lacks.
  const [header = "", payload = ""] = (signed ?? "").split(".");
  const unlearnedHeader = Buffer.from(
    JSON.stringify({
      ...z
        .object({ alg: z.string() })
        .passthrough()
        .parse(JSON.parse(Buffer.from(header, "base64url").toString())),
      kid: "spacefast-runtime-unlearned",
    }),
  ).toString("base64url");
  const signature = sign(null, Buffer.from(`${unlearnedHeader}.${payload}`), rotated.privateKey);
  const token = `${unlearnedHeader}.${payload}.${signature.toString("base64url")}`;
  // The click and its retry (inside the box's one-a-minute read floor) give
  // the same retryable answer, naming why, and neither spends the launch.
  for (const attempt of ["first", "retry"]) {
    const response = await get(
      runtime,
      MANAGED_HOST,
      `/__spacefast/content-admin.php?launch=${encodeURIComponent(token)}`,
    );
    expect(response.status, `${attempt}: ${await response.clone().text()}`).toBe(500);
    expect((await response.json()).code, attempt).toBe("runtime_api_base_url_missing");
  }
});

test("a launch the box can't lock its Space for stays redeemable", async () => {
  const doorPath = await launch(MANAGED_HOST, MANAGED_SPACE);
  // A file where the Space's write lock belongs: the lock can't be taken.
  const lockPath = path.join(runtime.storageRoot, "runtime/locks/spaces", `${MANAGED_SPACE}.lock`);
  mkdirSync(path.dirname(lockPath), { recursive: true });
  const parked = `${lockPath}.parked`;
  let hadLock = false;
  try {
    renameSync(lockPath, parked);
    hadLock = true;
  } catch {
    // No lock file yet.
  }
  mkdirSync(lockPath);
  try {
    const outage = await get(runtime, MANAGED_HOST, doorPath);
    expect(outage.status, await outage.clone().text()).toBe(503);
    expect((await outage.json()).code).toBe("runtime_write_lock_unavailable");
  } finally {
    rmSync(lockPath, { recursive: true, force: true });
    if (hadLock) renameSync(parked, lockPath);
  }
  // The same launch opens once the lock is back: the outage spent nothing.
  expect((await get(runtime, MANAGED_HOST, doorPath)).status).toBe(303);
});

test("a spent launch is refused before it writes, and an unreadable access record is retryable", async () => {
  const recordPath = path.join(
    runtime.storageRoot,
    "spaces",
    MANAGED_SPACE,
    "content-admin-authorization.json",
  );
  const doorPath = await launch(MANAGED_HOST, MANAGED_SPACE);
  expect((await get(runtime, MANAGED_HOST, doorPath)).status).toBe(303);
  // The record is gone after the launch was spent; a replay must not bring
  // it back, and must not need the Space lock to be refused.
  rmSync(recordPath);
  const replayed = await get(runtime, MANAGED_HOST, doorPath);
  expect(replayed.status).toBe(401);
  expect(existsSync(recordPath)).toBe(false);

  // A record that exists but can't be read is never overwritten: the same
  // launch answers retryable and stays redeemable.
  const fresh = await launch(MANAGED_HOST, MANAGED_SPACE);
  mkdirSync(recordPath);
  try {
    const unreadable = await get(runtime, MANAGED_HOST, fresh);
    expect(unreadable.status, await unreadable.clone().text()).toBe(503);
    expect((await unreadable.json()).code).toBe("content_admin_authorization_unavailable");
  } finally {
    rmSync(recordPath, { recursive: true, force: true });
  }
  expect((await get(runtime, MANAGED_HOST, fresh)).status).toBe(303);
});

test("an access record that names another Space is never overwritten", async () => {
  const recordPath = path.join(
    runtime.storageRoot,
    "spaces",
    MANAGED_SPACE,
    "content-admin-authorization.json",
  );
  const doorPath = await launch(MANAGED_HOST, MANAGED_SPACE, { accessGeneration: 9 });
  const foreign = JSON.stringify({ space_id: SPACEPRESS_SPACE, access_generation: 1 });
  writeFileSync(recordPath, foreign);
  try {
    const refused = await get(runtime, MANAGED_HOST, doorPath);
    expect(refused.status, await refused.clone().text()).toBe(503);
    expect((await refused.json()).code).toBe("content_admin_authorization_unavailable");
    expect(readFileSync(recordPath, "utf8")).toBe(foreign);
  } finally {
    rmSync(recordPath, { force: true });
  }
});

test("the ticket path and the access update answer a corrupt access record as retryable", async () => {
  const recordPath = path.join(
    runtime.storageRoot,
    "spaces",
    MANAGED_SPACE,
    "content-admin-authorization.json",
  );
  const now = Math.floor(Date.now() / 1000);
  const principal = runtimeContentPrincipalAssertion(
    {
      baseUrl: `http://${MANAGED_HOST}`,
      runtimeInstanceId: INSTANCE,
      operationId: "op_content",
      spaceId: MANAGED_SPACE,
      accessGeneration: 11,
      sessionVersion: 1,
      principalActor: {
        kind: "user",
        id: "usr_editor",
        issuer: "https://api.spacefast.test/v1/auth",
        subject: "usr_editor",
      },
      grantCapabilities: ["page.view", "content.publish"],
    },
    now,
  );
  const content = (operation: "authorization.apply" | "admin.launch") =>
    fetch(`${runtime.baseUrl}${runtimeHttpPath("/__spacefast/content.php")}`, {
      method: "POST",
      headers: {
        Connection: "close",
        Host: MANAGED_HOST,
        "content-type": "application/json",
        authorization: `Bearer ${managementToken(`content.${operation}`, { space_id: MANAGED_SPACE })}`,
      },
      body: JSON.stringify({
        operation,
        authorization: { spaceId: MANAGED_SPACE, accessGeneration: 11 },
        principal,
        frameOrigin: DASHBOARD,
        publicOrigin: `https://${MANAGED_HOST}`,
        access: { surface: "wordpress" },
      }),
    });
  writeFileSync(recordPath, "{}");
  try {
    for (const operation of ["authorization.apply", "admin.launch"] as const) {
      const refused = await content(operation);
      expect(refused.status, `${operation}: ${await refused.clone().text()}`).toBe(503);
      expect((await refused.json()).code, operation).toBe(
        "content_admin_authorization_unavailable",
      );
      expect(readFileSync(recordPath, "utf8"), operation).toBe("{}");
    }
  } finally {
    rmSync(recordPath, { force: true });
  }
  // Both answer once the record is readable again.
  expect((await content("authorization.apply")).status).toBe(200);
  const launched = await content("admin.launch");
  expect(launched.status, await launched.clone().text()).toBe(200);
});

test("a person whose name is longer than the box takes still opens the editor", async () => {
  // 86 characters of three bytes each: within the contract's 255 characters,
  // past the box's 255 bytes.
  const doorPath = await launch(MANAGED_HOST, MANAGED_SPACE, {
    // Above any generation an earlier test applied, so this one stands alone.
    accessGeneration: 20,
    displayName: "€".repeat(86),
  });
  const opened = await get(runtime, MANAGED_HOST, doorPath);
  expect(opened.status, await opened.clone().text()).toBe(303);
});

test("a spent launch whose replay marker can't be read answers retryable and writes nothing", async () => {
  const recordPath = path.join(
    runtime.storageRoot,
    "spaces",
    MANAGED_SPACE,
    "content-admin-authorization.json",
  );
  const doorPath = await launch(MANAGED_HOST, MANAGED_SPACE, { accessGeneration: 30 });
  expect((await get(runtime, MANAGED_HOST, doorPath)).status).toBe(303);
  const jti = z
    .object({ jti: z.string() })
    .parse(
      JSON.parse(
        Buffer.from(
          decodeURIComponent(doorPath.split("launch=")[1] ?? "").split(".")[1] ?? "",
          "base64url",
        ).toString(),
      ),
    ).jti;
  // The record is gone, and the spent launch's marker can't be read.
  rmSync(recordPath);
  const marker = path.join(
    runtime.storageRoot,
    "runtime/jti",
    `${createHash("sha256").update(`spacefast-content-admin-launch:${jti}`).digest("hex")}.json`,
  );
  expect(existsSync(marker)).toBe(true);
  // Unreadable, or readable with no expiry: neither is "unspent".
  for (const damaged of ["{not json", "{}"]) {
    writeFileSync(marker, damaged);
    const replayed = await get(runtime, MANAGED_HOST, doorPath);
    expect(replayed.status, `${damaged}: ${await replayed.clone().text()}`).toBe(503);
    expect((await replayed.json()).code, damaged).toBe("runtime_replay_guard_unavailable");
    expect(existsSync(recordPath), damaged).toBe(false);
  }
});
