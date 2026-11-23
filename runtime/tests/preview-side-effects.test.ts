// A preview never charges a live card, delivers a webhook or mails a customer,
// through WordPress's own HTTP and mail APIs, while the live version's calls
// all go out. Which version is live comes from the route pointer alone.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { WORDPRESS_IMAGE } from "./zero-wordpress.ts";

const DRIVER = path.resolve(import.meta.dir, "fixtures/preview-side-effects.php");
const SPACE = "spc_preview_effects";

let work: string;
let core: string;
let storage: string;
let content: string;

const resultSchema = z.object({
  preview: z.boolean(),
  stripeLive: z.union([z.string(), z.number()]),
  stripeTest: z.union([z.string(), z.number()]),
  stripeBasicLive: z.union([z.string(), z.number()]),
  paypalLive: z.union([z.string(), z.number()]),
  paypalSandbox: z.union([z.string(), z.number()]),
  braintreeLive: z.union([z.string(), z.number()]),
  braintreeSandbox: z.union([z.string(), z.number()]),
  adyenLive: z.union([z.string(), z.number()]),
  adyenTest: z.union([z.string(), z.number()]),
  webhook: z.union([z.string(), z.number()]),
  fetch: z.union([z.string(), z.number()]),
  wpMail: z.boolean(),
  appMail: z.union([z.boolean(), z.string()]),
  gateways: z.array(z.string()),
  wooWebhookDelivers: z.boolean(),
  outbound: z.array(z.string()),
  mailed: z.array(z.string()),
  held: z.array(z.string()),
});

function run(version: string, variables: Record<string, string> = {}) {
  const proc = Bun.spawnSync({
    cmd: [
      "php",
      DRIVER,
      JSON.stringify({ core, content, storage, space: SPACE, version, variables }),
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  const stdout = proc.stdout.toString();
  if (proc.exitCode !== 0) throw new Error(`${proc.stderr.toString()}\n${stdout}`);
  return resultSchema.parse(JSON.parse(stdout.trim().split("\n").pop() ?? "null"));
}

beforeAll(() => {
  work = mkdtempSync(path.join(os.tmpdir(), "sf-preview-effects-"));
  core = path.join(work, "core");
  // Engine paths must sit under a `.stattic/storage` private root.
  storage = path.join(work, ".stattic", "storage");
  mkdirSync(core, { recursive: true });
  const extract = Bun.spawnSync({
    cmd: [
      "docker",
      "run",
      "--rm",
      "--entrypoint",
      "tar",
      WORDPRESS_IMAGE,
      "-C",
      "/usr/src/wordpress",
      "-cf",
      "-",
      "wp-includes",
      "wp-settings.php",
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (extract.exitCode !== 0) throw new Error(extract.stderr.toString());
  const untar = Bun.spawnSync({ cmd: ["tar", "-xf", "-", "-C", core], stdin: extract.stdout });
  if (untar.exitCode !== 0) throw new Error(untar.stderr.toString());
  content = path.join(work, "wp-content");
  mkdirSync(content, { recursive: true });
  // A database drop-in that answers every query empty: these APIs need no rows.
  writeFileSync(
    path.join(content, "db.php"),
    `<?php
require_once ABSPATH . WPINC . '/class-wpdb.php';
$GLOBALS['wpdb'] = new class extends wpdb {
    public function __construct() { $this->ready = true; $this->charset = 'utf8mb4'; }
    public function db_connect($allow_bail = true) { return true; }
    public function check_connection($allow_bail = true) { return true; }
    public function query($query) { $this->last_result = []; return 0; }
    public function db_version() { return '8.4'; }
    public function db_server_info() { return '8.4'; }
    public function has_cap($cap) { return true; }
};
`,
  );
  const routes = path.join(storage, "spaces", SPACE, "routes");
  mkdirSync(routes, { recursive: true });
  writeFileSync(
    path.join(routes, "production.json"),
    JSON.stringify({ space_id: SPACE, route_name: "production", version_id: "ver_live" }),
  );
}, 120_000);

afterAll(() => {
  if (work) rmSync(work, { recursive: true, force: true });
});

// On live, the engine's SpacePress transport hands the message to the Space's
// mail broker; this fixture has no route config, so that send attempt is
// refused with this message. On a preview the transport never gets there.
const LIVE_APP_MAIL_ATTEMPT = "Space mail routing is unavailable.";

test("a preview refuses live payments and holds webhooks and mail", () => {
  expect(run("ver_preview")).toEqual({
    preview: true,
    stripeLive: "spacefast_preview_live_payment",
    stripeTest: 200,
    stripeBasicLive: "spacefast_preview_live_payment",
    paypalLive: "spacefast_preview_live_payment",
    paypalSandbox: 200,
    braintreeLive: "spacefast_preview_live_payment",
    braintreeSandbox: 200,
    adyenLive: "spacefast_preview_live_payment",
    adyenTest: 200,
    webhook: 202,
    fetch: 200,
    wpMail: true,
    appMail: true,
    gateways: ["stripe_test", "cod"],
    wooWebhookDelivers: false,
    // Only test-mode payment calls and the ordinary read left the process.
    outbound: [
      "api.stripe.com",
      "api-m.sandbox.paypal.com",
      "payments.sandbox.braintree-api.com",
      "checkout-test.adyen.com",
      "api.example.test",
    ],
    mailed: [],
    held: [
      "payment_refused",
      "payment_refused",
      "payment_refused",
      "payment_refused",
      "payment_refused",
      "webhook_held",
      "mail_held",
      "mail_held",
    ],
  });
});

test("a preview that opts in delivers webhooks and mail, but still never takes live payments", () => {
  const result = run("ver_preview", {
    SPACEPRESS_PREVIEW_ALLOW_MAIL: "true",
    SPACEPRESS_PREVIEW_ALLOW_WEBHOOKS: "1",
  });
  expect(result).toMatchObject({
    stripeLive: "spacefast_preview_live_payment",
    stripeBasicLive: "spacefast_preview_live_payment",
    paypalLive: "spacefast_preview_live_payment",
    braintreeLive: "spacefast_preview_live_payment",
    adyenLive: "spacefast_preview_live_payment",
    webhook: 200,
    wooWebhookDelivers: true,
    appMail: LIVE_APP_MAIL_ATTEMPT,
    mailed: ["wp:customer@example.test"],
    held: [
      "payment_refused",
      "payment_refused",
      "payment_refused",
      "payment_refused",
      "payment_refused",
    ],
  });
});

test("the live version's payments, webhooks and mail all go out", () => {
  expect(run("ver_live")).toEqual({
    preview: false,
    stripeLive: 200,
    stripeTest: 200,
    stripeBasicLive: 200,
    paypalLive: 200,
    paypalSandbox: 200,
    braintreeLive: 200,
    braintreeSandbox: 200,
    adyenLive: 200,
    adyenTest: 200,
    webhook: 200,
    fetch: 200,
    wpMail: true,
    appMail: LIVE_APP_MAIL_ATTEMPT,
    gateways: ["stripe", "stripe_test", "ppcp", "cod"],
    wooWebhookDelivers: true,
    outbound: [
      "api.stripe.com",
      "api.stripe.com",
      "api.stripe.com",
      "api-m.paypal.com",
      "api-m.sandbox.paypal.com",
      "payments.braintree-api.com",
      "payments.sandbox.braintree-api.com",
      "example-merchant-checkout-live.adyenpayments.com",
      "checkout-test.adyen.com",
      "hooks.example.test",
      "api.example.test",
    ],
    mailed: ["wp:customer@example.test"],
    held: [],
  });
});
