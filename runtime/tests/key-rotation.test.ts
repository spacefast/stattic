// A platform key rotation as an installed engine sees it. The box was given the
// original key at install (provider persistent data); the API then publishes a
// next key, signs with it, and finally drops the original. The engine follows
// the published JWKS without any write to the box.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { generateKeyPairSync, randomUUID } from "node:crypto";
import { mkdtempSync, rmSync } from "node:fs";
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
    fetch(request) {
      if (new URL(request.url).pathname !== "/.well-known/spacefast-runtime-jwks.json") {
        return new Response("not found", { status: 404 });
      }
      jwksRequests += 1;
      return Response.json(published);
    },
  });
  rt = await startRuntime({
    atomicData: { SPACEFAST_API_BASE_URL: `https://127.0.0.1:${api.port}` },
    phpIni: { "curl.cainfo": certificate },
  });
});

afterAll(() => {
  rt?.stop();
  api?.stop(true);
  rmSync(certificates, { recursive: true, force: true });
});

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
  // Force the reread a rotation's last stage would get within the hour.
  const cachePath = path.join(rt.storageRoot, "runtime/platform-jwks.json");
  const cached = await Bun.file(cachePath).json();
  await Bun.write(cachePath, JSON.stringify({ ...cached, fetched_at: 0, attempted_at: 0 }));

  expect((await readState(nextKeyToken())).status).toBe(200);
  const retired = await readState(managementToken("read_state"));
  expect(retired.status).toBe(401);
  expect(await errorCode(retired)).toBe("runtime_jwks_key_missing");
});
