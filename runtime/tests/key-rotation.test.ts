// A platform key rotation as an installed engine sees it. The box was given the
// original key at install (provider persistent data); the API then publishes a
// next key, signs with it, and finally drops the original. The engine follows
// the published JWKS without any write to the box.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, mkdirSync, mkdtempSync, rmSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  errorCode,
  managementToken,
  runtimeHttpPath,
  runtimeJwks,
  selfSignedCertificate,
  signEd25519Jwt,
  startRuntime,
  type Runtime,
} from "./harness";

const NEXT_KID = "spacefast-runtime-rotated";
const next = generateKeyPairSync("ed25519");
const nextJwk = {
  ...next.publicKey.export({ format: "jwk" }),
  kid: NEXT_KID,
  alg: "EdDSA",
  use: "sig",
};

// What the stand-in API publishes, as each stage of the rotation sets it.
const published = { keys: [nextJwk] };
let jwksRequests = 0;
// When set, the stand-in API holds its answer until released, and says when
// a read has arrived, so a test can overlap requests with one read in flight.
let jwksHold: { arrived: PromiseWithResolvers<void>; release: Promise<void> } | null = null;
// When set, the stand-in API is down.
let jwksDown = false;
let rt: Runtime;
let api: ReturnType<typeof Bun.serve>;
let certificates: string;

beforeAll(async () => {
  certificates = mkdtempSync(path.join(os.tmpdir(), "sf-rotation-"));
  const { certificate, key } = selfSignedCertificate(certificates, "api");
  api = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    tls: { cert: Bun.file(certificate), key: Bun.file(key) },
    async fetch(request) {
      if (new URL(request.url).pathname !== "/.well-known/spacefast-runtime-jwks.json") {
        return new Response("not found", { status: 404 });
      }
      jwksRequests += 1;
      if (jwksDown) return new Response("unavailable", { status: 503 });
      if (jwksHold) {
        jwksHold.arrived.resolve();
        await jwksHold.release;
      }
      return Response.json(published);
    },
  });
  rt = await startRuntime({
    atomicData: { SPACEFAST_API_BASE_URL: `https://127.0.0.1:${api.port}` },
    phpIni: { "curl.cainfo": certificate },
    // Several PHP workers, as on a box, so two requests can overlap.
    env: { PHP_CLI_SERVER_WORKERS: "4" },
  });
});

afterAll(() => {
  rt?.stop();
  api?.stop(true);
  rmSync(certificates, { recursive: true, force: true });
});

/** Poll until `condition` holds, failing after `timeoutMs`. */
async function waitFor(condition: () => boolean, timeoutMs = 10_000) {
  const deadline = Date.now() + timeoutMs;
  while (!condition()) {
    if (Date.now() > deadline) throw new Error("condition did not hold in time");
    await Bun.sleep(10);
  }
}

/**
 * The published set as the box last read it, a minute or more ago, so a test
 * does not depend on what an earlier one left behind.
 */
async function seedPublished(keys: readonly object[]) {
  const cachePath = path.join(rt.storageRoot, "runtime/platform-jwks.json");
  mkdirSync(path.dirname(cachePath), { recursive: true });
  const now = Math.floor(Date.now() / 1000);
  await Bun.write(
    cachePath,
    JSON.stringify({ fetched_at: now, attempted_at: now - 120, jwks: { keys } }),
  );
  return cachePath;
}

function readState(token: string) {
  return fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/api.php/state")}`, {
    headers: { authorization: `Bearer ${token}` },
  });
}

function nextKeyToken() {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(next.privateKey, NEXT_KID, {
    aud: "stattic-runtime-management",
    runtime_instance_id: "rti_test",
    operation_id: "op_read_state",
    action: "read_state",
    jti: `jti_${randomUUID()}`,
    exp: now + 300,
    nbf: now - 30,
  });
}

test("an installed engine follows the published JWKS through a whole rotation", async () => {
  // Before: the install key verifies with no network read.
  expect((await readState(managementToken("read_state"))).status).toBe(200);
  expect(jwksRequests).toBe(0);

  // Stage 1 and 2: the API publishes the next key and signs with it. The box
  // has never been told; the unknown kid makes it read the set once.
  published.keys = [...runtimeJwks().keys, nextJwk];
  expect((await readState(nextKeyToken())).status).toBe(200);
  expect(jwksRequests).toBe(1);
  expect((await readState(nextKeyToken())).status).toBe(200);
  expect((await readState(managementToken("read_state"))).status).toBe(200);
  expect(jwksRequests).toBe(1);
});

test("a key the published set drops stops verifying after the box rereads it", async () => {
  published.keys = [nextJwk];
  // The box last read stage 2 (install + next key) over an hour ago, so this
  // request rereads the set a rotation's last stage publishes.
  const cachePath = await seedPublished([...runtimeJwks().keys, nextJwk]);
  const seeded = await Bun.file(cachePath).json();
  await Bun.write(cachePath, JSON.stringify({ ...seeded, fetched_at: 0 }));

  expect((await readState(nextKeyToken())).status).toBe(200);
  const retired = await readState(managementToken("read_state"));
  expect(retired.status).toBe(401);
  expect(await errorCode(retired)).toBe("runtime_jwks_key_missing");
});

test("requests that meet a new kid while the box is reading the set all verify, with one read", async () => {
  const third = generateKeyPairSync("ed25519");
  const thirdKid = "spacefast-runtime-third";
  published.keys = [
    nextJwk,
    { ...third.publicKey.export({ format: "jwk" }), kid: thirdKid, alg: "EdDSA", use: "sig" },
  ];
  // The rotation clock is past its floor, as it is once the box last read
  // the set over a minute ago.
  const cachePath = await seedPublished([nextJwk]);
  const thirdKeyToken = () => {
    const now = Math.floor(Date.now() / 1000);
    return signEd25519Jwt(third.privateKey, thirdKid, {
      aud: "stattic-runtime-management",
      runtime_instance_id: "rti_test",
      operation_id: "op_read_state",
      action: "read_state",
      jti: `jti_${randomUUID()}`,
      exp: now + 300,
      nbf: now - 30,
    });
  };
  const readsBefore = jwksRequests;
  const release = Promise.withResolvers<void>();
  jwksHold = { arrived: Promise.withResolvers<void>(), release: release.promise };
  try {
    const waitingMarker = `${cachePath}.waiting`;
    rmSync(waitingMarker, { force: true });
    const first = readState(thirdKeyToken());
    // The box is now waiting on the API for the first request.
    await jwksHold.arrived.promise;
    const second = readState(thirdKeyToken());
    // Release the read only once the second request is waiting on it, so the
    // two always overlap.
    await waitFor(() => existsSync(waitingMarker));
    release.resolve();
    const statuses = (await Promise.all([first, second])).map((response) => response.status);
    expect(statuses).toEqual([200, 200]);
    expect(jwksRequests - readsBefore).toBe(1);
  } finally {
    release.resolve();
    jwksHold = null;
  }
});

test("a key the box can't learn because the API is down answers retryable, first try and retry", async () => {
  const fourth = generateKeyPairSync("ed25519");
  const fourthKid = "spacefast-runtime-fourth";
  await seedPublished([nextJwk]);
  const fourthKeyToken = () => {
    const now = Math.floor(Date.now() / 1000);
    return signEd25519Jwt(fourth.privateKey, fourthKid, {
      aud: "stattic-runtime-management",
      runtime_instance_id: "rti_test",
      operation_id: "op_read_state",
      action: "read_state",
      jti: `jti_${randomUUID()}`,
      exp: now + 300,
      nbf: now - 30,
    });
  };
  jwksDown = true;
  try {
    // The read fails, then the retry lands inside the attempt floor: both say
    // the keys couldn't be fetched, which the client retries, not that the key
    // is gone.
    for (const attempt of ["first", "retry"]) {
      const response = await readState(fourthKeyToken());
      expect(response.status, attempt).toBe(500);
      expect(await errorCode(response), attempt).toBe("runtime_jwks_fetch_failed");
    }
  } finally {
    jwksDown = false;
  }
});
