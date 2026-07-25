import { afterAll, beforeAll, expect, test } from "bun:test";

import {
  errorCode,
  managementToken,
  runtimeHttpPath,
  startRuntime,
  type Runtime,
} from "./harness.ts";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime({ autoPrependInit: true });
}, 30000);

afterAll(() => rt?.stop());

test("auto-prepended init dispatches runtime health alias before private-path guard", async () => {
  const response = await fetch(`${rt.baseUrl}/__spacefast/health.php`);
  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toBe("application/json; charset=utf-8");
  expect(await response.json()).toMatchObject({
    ok: true,
    runtime: "stattic-php",
    site_state: "configured",
  });
});

test("auto-prepended init dispatches management API alias before private-path guard", async () => {
  const response = await fetch(`${rt.baseUrl}${runtimeHttpPath("/__spacefast/api.php/state")}`, {
    headers: { authorization: `Bearer ${managementToken("read_state")}` },
  });
  expect(response.status).toBe(200);
  const body = (await response.json()) as { ok?: boolean; runtime?: string; spaces?: unknown };
  expect(body.ok).toBe(true);
  expect(body.runtime).toBe("stattic-php");
  expect(Array.isArray(body.spaces)).toBe(true);
});

test("auto-prepended init dispatches upload API alias before private-path guard", async () => {
  const response = await fetch(
    `${rt.baseUrl}${runtimeHttpPath("/__spacefast/upload.php/upl_prepend/files/index.html")}`,
    { method: "PUT", body: "x" },
  );
  expect(response.status).toBe(401);
  expect(await errorCode(response)).toBe("runtime_upload_bearer_required");
});

test("auto-prepended init dispatches access callback alias before private-path guard", async () => {
  const response = await fetch(`${rt.baseUrl}/__spacefast/access-callback.php`, {
    redirect: "manual",
  });
  expect(response.status).toBe(503);
  expect(await response.text()).not.toBe("Forbidden.\n");
});
