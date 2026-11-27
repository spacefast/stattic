import { afterAll, beforeAll, beforeEach, expect, test } from "bun:test";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { existsSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";

import {
  createDeclaredSession,
  deploy,
  errorCode,
  get,
  holdAdmissionSlots,
  putBlob,
  putRoute,
  redeemHandoff,
  RUNTIME_INSTANCE_ID,
  RUNTIME_UPLOAD_API_BASE,
  type Runtime,
  runtimeHttpPath,
  selfSignedCertificate,
  sha256,
  signEd25519Jwt,
  signToken,
  startRuntime,
  visitorIssuer,
} from "./harness.ts";

let rt: Runtime;

const SPACE = "spc_adm_slots";
const VERSION = "ver_adm_slots_1";
const HOST = "admission-slots.test";
const DISPATCH_LIMIT = 2;
const EXCHANGE_CREDENTIAL = "runtime-admission-exchange-credential-0123456789";

const visitorKeyPair = generateKeyPairSync("ed25519");
const visitorJwk = visitorKeyPair.publicKey.export({ format: "jwk" });
const visitor = visitorIssuer(visitorKeyPair.publicKey);
let visitorCookie = "";

function accessProjection() {
  return {
    projection_generation: 1,
    visitor_issuer: "spacefast-api",
    visitor_jwks: {
      keys: [
        {
          kty: "OKP",
          crv: "Ed25519",
          kid: visitor.kid,
          alg: "EdDSA",
          use: "sig",
          x: visitorJwk.x ?? "",
        },
      ],
    },
    session_version: 0,
    authorization: {
      generation: 1,
      sessionVersion: 0,
      fence: "none",
      acquireUrl: "https://access.spacefast.test/acquire/admission",
      accessPage: {
        displayName: null,
        accountUrl: null,
        connections: [],
        exchange: {
          passwordUrl: "https://access.spacefast.test/acquire/admission/password",
          tokenUrl: "https://access.spacefast.test/acquire/admission/token",
          requestUrl: "https://access.spacefast.test/acquire/admission/request",
          credential: EXCHANGE_CREDENTIAL,
        },
      },
      spaceClaimed: true,
      grants: [
        {
          id: "grt_admission_public",
          generation: 1,
          audience: { kind: "public" },
          resources: { include: ["/**"], exclude: ["/private/**"] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "managed", reference: "test:admission-public" },
        },
        {
          id: "grt_admission_member",
          generation: 1,
          audience: {
            kind: "external",
            issuer: "spacefast-membership",
            subject: "mem_admission",
          },
          resources: { include: ["/**"], exclude: [] },
          capabilities: ["page.view"],
          constraints: {},
          target: { kind: "live" },
          source: { kind: "system", reference: "test:admission-member" },
        },
      ],
    },
  };
}

function visitorToken(host: string, spaceId: string, authorities: string[]): string {
  const now = Math.floor(Date.now() / 1000);
  return signEd25519Jwt(visitorKeyPair.privateKey, visitor.kid, {
    sub: authorities[0],
    purpose: "handoff",
    grants: authorities,
    authorities,
    iss: "spacefast-api",
    aud: spaceId,
    host,
    sv: 0,
    generation: 1,
    spaceId,
    sid: createHash("sha256").update(randomUUID()).digest("hex"),
    iat: now,
    nbf: now,
    exp: now + 300,
    jti: randomUUID(),
  });
}

beforeAll(async () => {
  rt = await startRuntime({
    env: {
      PHP_CLI_SERVER_WORKERS: "16",
    },
  });
  await deploy(rt, {
    spaceId: SPACE,
    versionId: VERSION,
    metadata: { mode: "website", title: "admission slots" },
    files: {
      "index.html": "<h1>home</h1>\n",
      "private/index.html": "<h1>private</h1>\n",
      "assets/large.bin": "x".repeat(262144),
    },
    functions: {
      artifact: {
        appName: "admission-slots",
        entry: "worker.js",
        mainModule: "index.js",
        compatibilityDate: "2026-07-01",
        compatibilityFlags: [],
        routes: [{ method: "GET", path: "/api/ping", subtree: false }],
      },
      host: {
        hostname: "functions.invalid",
        bundleUrl: "https://bundle.invalid/bundle.json",
      },
      grantedCapabilities: [],
    },
    activate: {
      route_name: "production",
      config: { mode: "website" },
      production_hostnames: [HOST],
      noindex_production_hostnames: [],
      version_hostnames: [],
    },
  });
  await putRoute(rt, SPACE, "production", {
    version_id: VERSION,
    config: {
      admission: { concurrency: DISPATCH_LIMIT, php_workers: 10 },
      ...accessProjection(),
    },
  });
  const callback = await redeemHandoff(
    rt,
    HOST,
    visitorToken(HOST, SPACE, ["member:mem_admission"]),
  );
  expect(callback.status).toBe(303);
  visitorCookie = (callback.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
});

afterAll(() => {
  rt?.stop();
});

beforeEach(() => {
  rmSync(path.join(rt.storageRoot, "runtime/admission"), { recursive: true, force: true });
});

test("protected pages and large static files do not use legacy admission counters", async () => {
  const responses = await Promise.all([
    get(rt, HOST, "/private/", { headers: { cookie: visitorCookie } }),
    get(rt, HOST, "/private/", { headers: { cookie: visitorCookie } }),
    get(rt, HOST, "/private/", { headers: { cookie: visitorCookie } }),
    get(rt, HOST, "/assets/large.bin"),
    get(rt, HOST, "/assets/large.bin"),
  ]);
  expect(responses.map((response) => response.status)).toEqual([200, 200, 200, 200, 200]);
  expect(existsSync(path.join(rt.storageRoot, "runtime/admission", `${SPACE}.json`))).toBe(false);
  expect(
    existsSync(path.join(rt.storageRoot, "runtime/admission", `${SPACE}.json.generation`)),
  ).toBe(false);
});

test("Worker dispatch flock slots shed with 429 when full and release immediately after SIGKILL", async () => {
  const slotDir = path.join(rt.storageRoot, "runtime/admission/blocking", SPACE);

  // At limit - 1, dispatch still admits (reaches functions_edge_unconfigured in this test runtime).
  const partial = await holdAdmissionSlots(slotDir, DISPATCH_LIMIT - 1);
  try {
    const admitted = await get(rt, HOST, "/api/ping", {
      headers: { accept: "application/json" },
    });
    expect(admitted.status).toBe(503);
    expect(await errorCode(admitted)).toBe("functions_edge_unconfigured");
  } finally {
    await partial.release();
  }

  // At full capacity, dispatch sheds immediately with 429 functions_dispatch_busy.
  const full = await holdAdmissionSlots(slotDir, DISPATCH_LIMIT);
  try {
    const busy = await get(rt, HOST, "/api/ping", {
      headers: { accept: "application/json" },
    });
    expect(busy.status).toBe(429);
    expect(busy.headers.get("retry-after")).toBe("2");
    expect(await errorCode(busy)).toBe("functions_dispatch_busy");
  } finally {
    // Hard kill simulates an OOM/SIGKILL PHP-FPM worker crash: kernel releases
    // the flocks immediately with no stale rotation window.
    await full.release("SIGKILL");
  }

  const recovered = await get(rt, HOST, "/api/ping", {
    headers: { accept: "application/json" },
  });
  expect(recovered.status).toBe(503);
  expect(await errorCode(recovered)).toBe("functions_edge_unconfigured");
});

test("lock storage fault answers 503 admission_storage_unavailable rather than 429", async () => {
  const admissionRoot = path.join(rt.storageRoot, "runtime/admission");
  mkdirSync(admissionRoot, { recursive: true });
  // Place a regular file where the blocking slot directory must live so mkdir fails.
  writeFileSync(path.join(admissionRoot, "blocking"), "not-a-directory");

  const fault = await get(rt, HOST, "/api/ping", {
    headers: { accept: "application/json" },
  });
  expect(fault.status).toBe(503);
  expect(fault.headers.get("retry-after")).toBe("2");
  expect(await errorCode(fault)).toBe("admission_storage_unavailable");
});

test("un-synced overlays stay conservative and forward cutover subtracts legacy holders", async () => {
  const slotDir = path.join(rt.storageRoot, "runtime/admission/blocking", SPACE);
  const legacyPrefix = path.join(rt.storageRoot, "runtime/admission", SPACE);
  const legacyGen = "b".repeat(32);

  try {
    // 1) Pre-upgrade overlay without php_workers and saved concurrency=10 caps at 1
    //    (safe even on the smallest supported 2-worker box).
    await putRoute(rt, SPACE, "production", {
      version_id: VERSION,
      config: { admission: { concurrency: 10 }, ...accessProjection() },
    });
    const oneHeld = await holdAdmissionSlots(slotDir, 1);
    try {
      const capped = await get(rt, HOST, "/api/ping", {
        headers: { accept: "application/json" },
      });
      expect(capped.status).toBe(429);
      expect(await errorCode(capped)).toBe("functions_dispatch_busy");
    } finally {
      await oneHeld.release();
    }

    // 2) Forward cutover on a 10-worker box with concurrency=10 (ceiling 7):
    //    3 active legacy dispatches leave 4 flock slots (3 + 4 = 7).
    await putRoute(rt, SPACE, "production", {
      version_id: VERSION,
      config: { admission: { concurrency: 10, php_workers: 10 }, ...accessProjection() },
    });
    mkdirSync(path.dirname(legacyPrefix), { recursive: true });
    const now = Math.floor(Date.now() / 1000);
    writeFileSync(
      `${legacyPrefix}.functions-dispatch.json.generation`,
      JSON.stringify({ generation: legacyGen }),
    );
    writeFileSync(
      `${legacyPrefix}.functions-dispatch.json.${legacyGen}`,
      JSON.stringify({ count: 3, started_at: now, updated_at: now }),
    );
    const threeNew = await holdAdmissionSlots(slotDir, 3);
    try {
      // 3 legacy + 3 flock = 6 < 7 -> still admits.
      const admitted = await get(rt, HOST, "/api/ping", {
        headers: { accept: "application/json" },
      });
      expect(admitted.status).toBe(503);
      expect(await errorCode(admitted)).toBe("functions_edge_unconfigured");
    } finally {
      await threeNew.release();
    }
    const fourNew = await holdAdmissionSlots(slotDir, 4);
    try {
      // 3 legacy + 4 flock = 7 -> sheds immediately.
      const shed = await get(rt, HOST, "/api/ping", {
        headers: { accept: "application/json" },
      });
      expect(shed.status).toBe(429);
      expect(await errorCode(shed)).toBe("functions_dispatch_busy");
    } finally {
      await fourNew.release();
    }

    // 3) Forward cutover on a 4-worker box (ceiling 2): 2 legacy holders shed
    //    new requests immediately (2 + 0 = 2).
    await putRoute(rt, SPACE, "production", {
      version_id: VERSION,
      config: { admission: { concurrency: 10, php_workers: 4 }, ...accessProjection() },
    });
    writeFileSync(
      `${legacyPrefix}.functions-dispatch.json.${legacyGen}`,
      JSON.stringify({ count: 2, started_at: now, updated_at: now }),
    );
    const shedSmall = await get(rt, HOST, "/api/ping", {
      headers: { accept: "application/json" },
    });
    expect(shedSmall.status).toBe(429);
    expect(await errorCode(shedSmall)).toBe("functions_dispatch_busy");
    rmSync(`${legacyPrefix}.functions-dispatch.json.generation`, { force: true });
    rmSync(`${legacyPrefix}.functions-dispatch.json.${legacyGen}`, { force: true });

    // 4) Shrinking a box's limit accounts for holders still on higher-index
    //    slot files (slot-2, slot-3) until they drain.
    const fourHeld = await holdAdmissionSlots(slotDir, 4);
    try {
      // With 4 slots held (slot-0..slot-3) and limit shrunk to 2, new requests
      // shed even if slots 0..1 were free; here all 4 are held, then after
      // releasing slots 0..3 normal admission resumes.
      const shedDuringShrink = await get(rt, HOST, "/api/ping", {
        headers: { accept: "application/json" },
      });
      expect(shedDuringShrink.status).toBe(429);
      expect(await errorCode(shedDuringShrink)).toBe("functions_dispatch_busy");
    } finally {
      await fourHeld.release();
    }
  } finally {
    await putRoute(rt, SPACE, "production", {
      version_id: VERSION,
      config: {
        admission: { concurrency: DISPATCH_LIMIT, php_workers: 10 },
        ...accessProjection(),
      },
    });
  }
});

test("mixed blocking traffic on a 4-worker box keeps callback reserve free", async () => {
  const smallSpace = "spc_small_box";
  const smallVersion = "ver_small_box_1";
  const smallHost = "small-box.test";
  const { certificate, key } = selfSignedCertificate(rt.root, "admission-small-box");

  // Hold one incoming Worker dispatch open on a real TLS edge until released.
  let edgeReceivedCount = 0;
  let edgeAssetsNonce: string | null = null;
  let notifyEdgeArrived!: () => void;
  const edgeArrived = new Promise<void>((resolve) => {
    notifyEdgeArrived = resolve;
  });
  let releaseEdge!: () => void;
  const edgeHold = new Promise<void>((resolve) => {
    releaseEdge = resolve;
  });
  const edge = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    idleTimeout: 30,
    tls: { cert: Bun.file(certificate), key: Bun.file(key) },
    async fetch(request) {
      edgeReceivedCount += 1;
      edgeAssetsNonce = request.headers.get("sf-fx-assets-nonce");
      notifyEdgeArrived();
      await edgeHold;
      return new Response("worker-ok", { headers: { "content-type": "text/plain" } });
    },
  });

  // Hold one incoming synchronous connector call open until released.
  let connectorReceivedCount = 0;
  let notifyConnectorArrived!: () => void;
  const connectorArrived = new Promise<void>((resolve) => {
    notifyConnectorArrived = resolve;
  });
  let releaseConnector!: () => void;
  const connectorHold = new Promise<void>((resolve) => {
    releaseConnector = resolve;
  });
  const apiServer = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    idleTimeout: 30,
    async fetch() {
      connectorReceivedCount += 1;
      notifyConnectorArrived();
      await connectorHold;
      return Response.json({
        state: { role: "inbox", code: "ok", message: "Connected." },
        result: { ok: true, data: { messages: [] } },
      });
    },
  });

  const smallRt = await startRuntime({
    atomicData: {
      SPACEFAST_API_BASE_URL: apiServer.url.toString(),
      SPACEFAST_FUNCTIONS_DISPATCH_TOKEN: "fx-small-box-secret",
    },
    env: {
      PHP_CLI_SERVER_WORKERS: "4",
    },
    phpIni: {
      "curl.cainfo": certificate,
    },
  });

  let inflightDispatch: Promise<Response> | null = null;
  let inflightConnector: Promise<Response> | null = null;
  try {
    await deploy(smallRt, {
      spaceId: smallSpace,
      versionId: smallVersion,
      metadata: { mode: "website", title: "small box" },
      files: { "index.html": "<h1>small box home</h1>\n" },
      functions: {
        artifact: {
          appName: "small-box",
          entry: "worker.js",
          mainModule: "index.js",
          compatibilityDate: "2026-07-01",
          compatibilityFlags: [],
          routes: [{ method: "GET", path: "/api/ping", subtree: false }],
        },
        host: {
          hostname: `127.0.0.1:${edge.port}`,
          bundleUrl: "https://bundle.invalid/bundle.json",
        },
        grantedCapabilities: ["storage.read"],
      },
      finalize: {
        connectors_token: "ver_small_box_1.connectors-token",
      },
      activate: {
        route_name: "production",
        config: {
          mode: "website",
          // Even with a saved concurrency=10 override, php_workers=4 bounds the
          // shared blocking pool to 2 slots so 2 PHP workers remain free.
          admission: { concurrency: 10, php_workers: 4 },
          ...accessProjection(),
        },
        production_hostnames: [smallHost],
        noindex_production_hostnames: [],
        version_hostnames: [],
      },
    });

    const uploadFiles = { "blob.txt": "payload" };
    const uploadSession = await createDeclaredSession(
      smallRt,
      smallSpace,
      "ver_small_box_upload",
      uploadFiles,
    );

    // Occupy both allowed blocking slots with mixed traffic: 1 Worker dispatch
    // + 1 synchronous connector call (holding 2 of the 4 PHP workers open).
    // Await each arrival sequentially so php -S routes each request to its own
    // worker instead of serializing near-simultaneous connects onto one worker.
    const waitArrival = (label: string, arrived: Promise<void>, response: Promise<Response>) => {
      let settled = false;
      return Promise.race([
        arrived.then(() => {
          settled = true;
        }),
        response.then(async (res) => {
          if (settled) {
            return;
          }
          throw new Error(
            `${label} returned early with ${res.status}: ${await res.clone().text()}`,
          );
        }),
      ]);
    };
    inflightDispatch = get(smallRt, smallHost, "/api/ping", {
      headers: { connection: "close", accept: "application/json" },
    });
    await waitArrival("dispatch", edgeArrived, inflightDispatch);
    inflightConnector = get(smallRt, smallHost, "/__spacefast/connectors/inbox/messages.list", {
      headers: { connection: "close", accept: "application/json" },
    });
    await waitArrival("connector", connectorArrived, inflightConnector);

    // While both blocking slots stay held:
    // 1) A second Worker dispatch sheds immediately without reaching the edge.
    const shedDispatch = await get(smallRt, smallHost, "/api/ping", {
      headers: { connection: "close", accept: "application/json" },
    });
    expect(shedDispatch.status).toBe(429);
    expect(await errorCode(shedDispatch)).toBe("functions_dispatch_busy");
    expect(edgeReceivedCount).toBe(1);

    // 2) An upload sheds on the shared blocking pool instead of consuming a
    //    reserved callback worker.
    const shedUpload = await fetch(
      `${smallRt.baseUrl}${runtimeHttpPath(`${RUNTIME_UPLOAD_API_BASE}/spaces/${smallSpace}/blobs/${sha256("payload")}`)}`,
      {
        method: "PUT",
        headers: {
          connection: "close",
          authorization: `Bearer ${uploadSession.token}`,
          accept: "application/json",
        },
        body: "payload",
      },
    );
    expect(shedUpload.status).toBe(429);
    expect(await errorCode(shedUpload)).toBe("ingest_admission_exceeded");

    // 3) Another connector call sheds immediately without reaching the API.
    const shedConnector = await get(
      smallRt,
      smallHost,
      "/__spacefast/connectors/inbox/messages.list",
      { headers: { connection: "close", accept: "application/json" } },
    );
    expect(shedConnector.status).toBe(429);
    expect(connectorReceivedCount).toBe(1);

    // 4) A real Worker relay callback completes while both blocking requests
    //    are still in flight, proving the 2 reserved PHP workers stay free.
    const relayToken = signToken({
      aud: "spacefast-functions-relay",
      runtime_instance_id: RUNTIME_INSTANCE_ID,
      space_id: smallSpace,
      version_id: smallVersion,
      ownership_revision: null,
      capabilities: ["storage.read"],
    });
    const callback = await fetch(`${smallRt.baseUrl}/__spacefast/functions/relay`, {
      method: "POST",
      headers: {
        connection: "close",
        host: smallHost,
        "content-type": "application/json",
        authorization: `Bearer ${relayToken}`,
        "sf-fx-broker": "assets",
      },
      body: JSON.stringify({
        method: "GET",
        path: "/index.html",
        host: smallHost,
        headers: {},
        nonce: edgeAssetsNonce,
      }),
    });
    expect(callback.status).toBe(200);
    expect(await callback.text()).toBe("<h1>small box home</h1>\n");

    // Release both held requests and verify normal admission resumes.
    releaseEdge();
    releaseConnector();
    const [dispatchDone, connectorDone] = await Promise.all([inflightDispatch, inflightConnector]);
    expect(dispatchDone.status).toBe(200);
    expect(await dispatchDone.text()).toBe("worker-ok");
    expect(connectorDone.status).toBe(200);

    const uploadAfterRelease = await putBlob(
      smallRt,
      smallSpace,
      uploadSession.token,
      sha256("payload"),
      "payload",
    );
    expect(uploadAfterRelease.status).toBe(200);
  } finally {
    releaseEdge();
    releaseConnector();
    await Promise.allSettled([inflightDispatch, inflightConnector].filter(Boolean));
    smallRt.stop();
    apiServer.stop(true);
    edge.stop(true);
  }
}, 20_000);
