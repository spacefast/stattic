import { afterAll, beforeAll, expect, setDefaultTimeout, test } from "bun:test";

import { chromium, type Browser } from "@playwright/test";

import { browserTestOptions } from "../../scripts/browser-test-options.cjs";

setDefaultTimeout(120_000);

const PASSWORD = "correct horse battery staple";
const VERIFIED_EMAIL_PASSWORD = "verified email horse battery staple";
const CROSS_SITE_HOST = "cross-site-attacker.test";

type Fixture = {
  host: string;
  pageUrl: string;
  defaultPageUrl: string;
  // The share link as a recipient receives it: the customer page with its
  // lane-prefixed durable token in `?__=` on the Space's own host.
  linkPageUrl: string;
  requestLifecycleEmail: string;
  requestStateUrl: string;
  exchangeEffectsUrl: string;
  exchangeControlUrl: string;
  accessStateCodes: string[];
};

const EXPECTED_REACHABLE_STATES = {
  "invalid-password": "That password didn't work.",
  "request-pending": "Request sent. You'll get an email when someone approves it.",
  "request-failed": "That request didn't go through. Try again in a moment.",
  "email-sent": "Check your email. Use the confirmation link we sent to finish opening this page.",
  "email-failed": "Couldn't send that confirmation. Enter the password and try again.",
  "session-expired": "Your access expired. Sign in again to continue.",
  "rate-limited": "Too many tries. Give it a minute.",
  "exchange-unavailable": "Couldn't reach Spacefast to check that. Try again in a moment.",
} as const;

// These states originate on central account/link flows, which this browser
// fixture cannot serve faithfully: those flows require a signed dashboard
// session or single-use emailed credentials and redirect to production HTTPS.
// Keep every state named so removing one from production still breaks this
// contract; their central production transitions are covered by
// access-broker-routes.test.ts.
const UNREACHABLE_IN_THIS_FIXTURE = {
  checked: "requires a signed-out central account probe",
  "no-grant": "requires a signed-in central account with no matching Grant",
  "link-expired": "requires an expired central Link credential",
  "link-revoked": "requires a revoked central Link credential",
  "link-invalid": "requires a centrally rejected live Link credential",
  "open-used": "requires a consumed single-use central Open credential",
} as const;

const fixture = (() => {
  const raw = process.env.SPACEFAST_BROWSER_FIXTURE_JSON;
  if (!raw) throw new Error("control-plane access fixture is required");
  return JSON.parse(raw) as Fixture;
})();

let browser: Browser;

beforeAll(async () => {
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  browser = await chromium.launch({
    ...(executablePath ? { executablePath } : { channel: "chromium" }),
    ...browserTestOptions,
    args: [
      `--host-resolver-rules=MAP ${fixture.host} 127.0.0.1, MAP ${CROSS_SITE_HOST} 127.0.0.1`,
      `--unsafely-treat-insecure-origin-as-secure=${new URL(fixture.pageUrl).origin}`,
      "--no-proxy-server",
    ],
  });
});

afterAll(async () => {
  await browser?.close();
});

async function passwordExchangeCount() {
  const response = await fetch(fixture.exchangeEffectsUrl);
  if (!response.ok) throw new Error(`exchange effects probe failed: ${response.status}`);
  const body: unknown = await response.json();
  if (
    typeof body !== "object" ||
    body === null ||
    !("password" in body) ||
    typeof body.password !== "number"
  ) {
    throw new Error("exchange effects probe returned an invalid payload");
  }
  return body.password;
}

test("custom access page CSP permits its same-origin script and forms", async () => {
  const passwordContext = await browser.newContext();
  const passwordPage = await passwordContext.newPage();
  const clientScriptResponse = passwordPage.waitForResponse(
    (response) => new URL(response.url()).pathname === "/__spacefast/access/client.js",
  );
  const rendered = await passwordPage.goto(fixture.pageUrl);
  expect(rendered?.status()).toBe(403);
  expect(await passwordPage.getByRole("heading", { name: "Private studio" }).count()).toBe(1);
  expect((await clientScriptResponse).status()).toBe(200);

  const popupPromise = passwordContext.waitForEvent("page");
  await passwordPage.getByRole("link", { name: "Continue with Spacefast" }).click();
  const popup = await popupPromise;
  await popup.goto(fixture.pageUrl);
  await popup.locator('input[name="password"]').fill(PASSWORD);
  const passwordResponsePromise = popup.waitForResponse(
    (response) => new URL(response.url()).pathname === "/__spacefast/access/password",
  );
  await popup.getByRole("button", { name: "Open" }).click();
  const passwordResponse = await passwordResponsePromise;
  expect(passwordResponse.status()).toBe(303);
  const passwordHeaders = await passwordResponse.request().allHeaders();
  expect(passwordHeaders.origin).toBe("null");
  expect(passwordHeaders["sec-fetch-site"]).toBe("same-origin");
  await popup.getByText("custom home").waitFor();

  await popup.close();
  expect((await passwordPage.reload()).status()).toBe(200);
  await passwordPage.getByText("custom home").waitFor();
  await passwordContext.close();

  const verifiedEmailContext = await browser.newContext();
  const verifiedEmailPage = await verifiedEmailContext.newPage();
  expect((await verifiedEmailPage.goto(fixture.pageUrl))?.status()).toBe(403);
  await verifiedEmailPage.locator('input[name="password"]').fill(VERIFIED_EMAIL_PASSWORD);
  const verifiedPasswordResponsePromise = verifiedEmailPage.waitForResponse(
    (response) => new URL(response.url()).pathname === "/__spacefast/access/password",
  );
  await verifiedEmailPage.getByRole("button", { name: "Open" }).click();
  const verifiedPasswordResponse = await verifiedPasswordResponsePromise;
  expect(verifiedPasswordResponse.status()).toBe(403);
  const verifiedPasswordHeaders = await verifiedPasswordResponse.request().allHeaders();
  expect(verifiedPasswordHeaders.origin).toBe("null");
  expect(verifiedPasswordHeaders["sec-fetch-site"]).toBe("same-origin");
  await verifiedEmailPage
    .locator('.sf-access-verify-email input[name="email"]')
    .fill("visitor@example.com");
  const emailResponsePromise = verifiedEmailPage.waitForResponse(
    (response) => new URL(response.url()).pathname === "/__spacefast/access/email",
  );
  await verifiedEmailPage.getByRole("button", { name: "Send confirmation link" }).click();
  const emailResponse = await emailResponsePromise;
  expect(emailResponse.status()).toBe(303);
  const emailHeaders = await emailResponse.request().allHeaders();
  expect(emailHeaders.origin).toBe("null");
  expect(emailHeaders["sec-fetch-site"]).toBe("same-origin");
  await verifiedEmailPage.getByText("Check your email.").waitFor();
  expect(verifiedEmailPage.url()).toMatch(/[?&]sf_access=email-sent(?:&|$)/);
  await verifiedEmailContext.close();

  const inviteContext = await browser.newContext();
  const invitePage = await inviteContext.newPage();
  expect((await invitePage.goto(fixture.pageUrl))?.status()).toBe(403);
  await invitePage.getByText("Need access? Request an invite").click();
  const inviteForm = invitePage.locator(".sf-access-request > form");
  expect(await inviteForm.locator(":scope > .sf-input").count()).toBe(2);
  await invitePage.locator('input[name="email"]').fill("visitor@example.com");
  const inviteResponsePromise = invitePage.waitForResponse(
    (response) => new URL(response.url()).pathname === "/__spacefast/access/request",
  );
  await invitePage.getByRole("button", { name: "Request an invite" }).click();
  const inviteResponse = await inviteResponsePromise;
  expect(inviteResponse.status()).toBe(303);
  const inviteHeaders = await inviteResponse.request().allHeaders();
  expect(inviteHeaders.origin).toBe("null");
  expect(inviteHeaders["sec-fetch-site"]).toBe("same-origin");
  await invitePage.getByText("Request sent.").waitFor();
  await inviteContext.close();
});

test("production transitions render every locally reachable state with independent copy", async () => {
  expect(fixture.accessStateCodes.toSorted()).toEqual(
    [
      ...Object.keys(EXPECTED_REACHABLE_STATES),
      ...Object.keys(UNREACHABLE_IN_THIS_FIXTURE),
    ].toSorted(),
  );

  async function expectStatus(
    page: Awaited<ReturnType<Browser["newPage"]>>,
    code: keyof typeof EXPECTED_REACHABLE_STATES,
  ) {
    if (code !== "session-expired") {
      expect(page.url()).toMatch(new RegExp(`[?&]sf_access=${code}(?:&|$)`));
    }
    expect(
      await page.getByRole(code === "invalid-password" ? "alert" : "status").allTextContents(),
    ).toContain(EXPECTED_REACHABLE_STATES[code]);
  }

  const context = await browser.newContext();
  try {
    const page = await context.newPage();

    await page.goto(fixture.defaultPageUrl);
    await page.locator('input[name="password"]').fill("definitely wrong");
    await page.getByRole("button", { name: "Open" }).click();
    await expectStatus(page, "invalid-password");

    await page.goto(fixture.defaultPageUrl);
    await page.getByText("Need access? Request an invite").click();
    await page.locator('input[name="email"]').fill("visitor@example.com");
    await page.getByRole("button", { name: "Request an invite" }).click();
    await expectStatus(page, "request-pending");

    await context.clearCookies();
    await page.goto(fixture.defaultPageUrl);
    const requestFailedNavigation = page.waitForURL(/sf_access=request-failed/);
    await page.locator(".sf-access-request form").evaluate((form) => {
      const email = form.querySelector('input[name="email"]');
      if (!(email instanceof HTMLInputElement) || !(form instanceof HTMLFormElement)) {
        throw new Error("request form is unavailable");
      }
      email.value = "not-an-email";
      form.submit();
    });
    await requestFailedNavigation;
    await expectStatus(page, "request-failed");

    await context.clearCookies();
    await page.goto(fixture.defaultPageUrl);
    await page.locator('input[name="password"]').fill(VERIFIED_EMAIL_PASSWORD);
    await page.getByRole("button", { name: "Open" }).click();
    const emailFailedNavigation = page.waitForURL(/sf_access=email-failed/);
    await page.locator("form.sf-access-verify-email").evaluate((form) => {
      const email = form.querySelector('input[name="email"]');
      if (!(email instanceof HTMLInputElement) || !(form instanceof HTMLFormElement)) {
        throw new Error("verified email form is unavailable");
      }
      email.value = "not-an-email";
      form.submit();
    });
    await emailFailedNavigation;
    await expectStatus(page, "email-failed");

    await context.clearCookies();
    await page.goto(fixture.defaultPageUrl);
    const sessionResponsePromise = page.waitForResponse(
      (response) => new URL(response.url()).pathname === "/__spacefast/access/password",
    );
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.getByRole("button", { name: "Open" }).click();
    expect((await sessionResponsePromise).status()).toBe(303);
    expect(await context.cookies(fixture.defaultPageUrl)).toHaveLength(1);
    const expired = await fetch(`${fixture.exchangeControlUrl}/expire-sessions`, {
      method: "POST",
    });
    expect(expired.status).toBe(204);
    await page.goto(fixture.defaultPageUrl);
    await expectStatus(page, "session-expired");

    await context.clearCookies();
    let rateLimited = false;
    for (let attempt = 0; attempt < 12; attempt += 1) {
      await page.goto(fixture.defaultPageUrl);
      await page.locator('input[name="password"]').fill("still wrong");
      await page.getByRole("button", { name: "Open" }).click();
      if (new URL(page.url()).searchParams.get("sf_access") === "rate-limited") {
        rateLimited = true;
        break;
      }
    }
    expect(rateLimited).toBe(true);
    await expectStatus(page, "rate-limited");

    await fetch(`${fixture.exchangeControlUrl}/exchange-unavailable`, { method: "POST" });
    try {
      await page.goto(fixture.defaultPageUrl);
      await page.locator('input[name="password"]').fill(PASSWORD);
      await page.getByRole("button", { name: "Open" }).click();
      await expectStatus(page, "exchange-unavailable");
    } finally {
      await fetch(`${fixture.exchangeControlUrl}/exchange-available`, { method: "POST" });
    }
  } finally {
    await context.close();
  }
});

test("the default access page keeps the invite form branded, aligned, and responsive", async () => {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  try {
    const page = await context.newPage();
    expect((await page.goto(fixture.defaultPageUrl))?.status()).toBe(403);
    await page.evaluate(() => document.fonts.ready);
    expect(await page.locator(".sf-page.access").count()).toBe(1);
    // The access wall is deliberately zero-webfont: the platform body face is
    // the SF Pro system stack, so the headline renders in it rather than the
    // UA default, and nothing is downloaded to get there.
    expect(
      await page.locator("h1").evaluate((element) => getComputedStyle(element).fontFamily),
    ).toContain("-apple-system");

    const requestDetails = page.locator(".sf-access-request");
    await requestDetails.evaluate((details) => {
      if (details instanceof HTMLDetailsElement) details.open = true;
    });
    const email = requestDetails.locator('input[name="email"]');
    const message = requestDetails.locator('textarea[name="message"]');
    const button = requestDetails.locator('button[type="submit"]');
    const [emailBox, messageBox, buttonBox] = await Promise.all([
      email.boundingBox(),
      message.boundingBox(),
      button.boundingBox(),
    ]);
    if (!emailBox || !messageBox || !buttonBox) throw new Error("invite controls are hidden");
    expect(emailBox.y + emailBox.height).toBeLessThan(messageBox.y);
    expect(messageBox.y + messageBox.height).toBeLessThan(buttonBox.y);
    expect(Math.abs(emailBox.x - messageBox.x)).toBeLessThan(1);
    expect(Math.abs(messageBox.x - buttonBox.x)).toBeLessThan(1);
    expect(Math.abs(emailBox.width - buttonBox.width)).toBeLessThan(1);
    expect(await email.evaluate((element) => getComputedStyle(element).fontFamily)).toContain(
      "-apple-system",
    );

    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(fixture.defaultPageUrl);
    const contentBox = await page.locator(".sf-access-lanes").boundingBox();
    if (!contentBox) throw new Error("access lanes are hidden on mobile");
    expect(contentBox.x).toBeGreaterThanOrEqual(20);
    expect(contentBox.x + contentBox.width).toBeLessThanOrEqual(355);
  } finally {
    await context.close();
  }
});

test("denied and expired requests can be replaced through the production request flow", async () => {
  const context = await browser.newContext();
  const page = await context.newPage();
  const submitRequest = async () => {
    await page.goto(fixture.pageUrl);
    await page.locator("details.sf-access-request summary").click();
    await page.locator('input[name="email"]').fill(fixture.requestLifecycleEmail);
    await page.getByRole("button", { name: "Request an invite" }).click();
    await page.getByText("Request sent.").waitFor();
  };
  const moveRequestTo = async (action: "deny" | "expire") => {
    const response = await fetch(fixture.requestStateUrl, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ action }),
    });
    expect(response.status).toBe(204);
  };
  const verifyRequest = async () => {
    const response = await fetch(fixture.requestStateUrl, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ action: "verification-url" }),
    });
    expect(response.status, await response.clone().text()).toBe(200);
    const payload: unknown = await response.json();
    if (
      typeof payload !== "object" ||
      payload === null ||
      !("url" in payload) ||
      typeof payload.url !== "string"
    ) {
      throw new Error("request verification probe returned an invalid payload");
    }
    await page.goto(payload.url);
    await page.locator('input[name="displayName"]').fill("Lifecycle Visitor");
    await page.getByRole("button", { name: "Verify email and send request" }).click();
    await page.getByText("Your access request was sent.").waitFor();
  };
  const requestStatuses = async () => {
    const response = await fetch(fixture.requestStateUrl);
    expect(response.status).toBe(200);
    const payload: unknown = await response.json();
    if (
      !Array.isArray(payload) ||
      !payload.every(
        (entry) =>
          typeof entry === "object" &&
          entry !== null &&
          "status" in entry &&
          typeof entry.status === "string",
      )
    ) {
      throw new Error("request status probe returned an invalid payload");
    }
    return payload;
  };

  await submitRequest();
  await verifyRequest();
  await moveRequestTo("deny");
  await submitRequest();
  expect((await requestStatuses()).map(({ status }) => status)).toEqual([
    "verification_pending",
    "denied",
  ]);

  await moveRequestTo("expire");
  await submitRequest();
  expect((await requestStatuses()).map(({ status }) => status)).toEqual([
    "verification_pending",
    "expired",
    "denied",
  ]);
  await context.close();
});

test("a share link opens its clean page in one navigation, with no gate in between", async () => {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    const gated = await page.goto(fixture.defaultPageUrl);
    expect(gated?.status()).toBe(403);

    const opened = await page.goto(fixture.linkPageUrl);
    if (!opened) throw new Error("share link navigation returned no response");
    expect(opened.status()).toBe(200);
    // The token is redeemed on the page request itself. Ordinary clean-URL
    // canonicalization may add the directory slash, but keeps the token and
    // never inserts an access interstitial or a second navigation.
    const finalUrl = new URL(fixture.pageUrl);
    finalUrl.search = new URL(fixture.linkPageUrl).search;
    expect(page.url()).toBe(finalUrl.toString());
    let firstRequest = opened.request();
    for (;;) {
      const redirectedFrom = firstRequest.redirectedFrom();
      if (!redirectedFrom) break;
      firstRequest = redirectedFrom;
    }
    expect(firstRequest.url()).toBe(fixture.linkPageUrl);
    const entryResponse = await firstRequest.response();
    if (!entryResponse) throw new Error("share link entry returned no response");
    expect((await entryResponse.allHeaders())["cache-control"]).toBe("private, no-store");
    await page.getByText("custom home").waitFor();
    expect((await opened.allHeaders())["cache-control"]).toBe("private, no-store");

    // The session cookie carries every later navigation, including pages the
    // durable Link never named.
    // (The dev/test lane serves plain HTTP, so the host-only cookie carries its
    // non-__Host- name here; production keeps __Host-spacefast_session.)
    expect(
      (await context.cookies(fixture.defaultPageUrl)).some((cookie) =>
        cookie.name.startsWith("spacefast_session"),
      ),
    ).toBe(true);
    const followed = await page.goto(fixture.pageUrl);
    expect(followed?.status()).toBe(200);
  } finally {
    await context.close();
  }
});

test("a cross-site document cannot drive the password exchange", async () => {
  const target = new URL("/__spacefast/access/password", fixture.pageUrl).toString();
  const attacker = Bun.serve({
    hostname: "127.0.0.1",
    port: 0,
    fetch: () =>
      new Response(
        '<!doctype html><html><body><form method="post" action="' +
          target +
          '"><input name="password" value="' +
          PASSWORD +
          '"><input name="return" value="/"><button>Break in</button></form></body></html>',
        { headers: { "content-type": "text/html; charset=utf-8" } },
      ),
  });
  const attackerOrigin = `http://${CROSS_SITE_HOST}:${attacker.port}`;
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    expect((await page.goto(`${attackerOrigin}/`))?.status()).toBe(200);
    const exchangesBefore = await passwordExchangeCount();
    const responsePromise = page.waitForResponse(
      (response) => new URL(response.url()).pathname === "/__spacefast/access/password",
    );
    await page.getByRole("button", { name: "Break in" }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(403);
    const headers = await response.request().allHeaders();
    expect(headers.origin).toBe(attackerOrigin);
    expect(headers["sec-fetch-site"]).toBe("cross-site");
    expect(
      (await context.cookies(fixture.pageUrl)).find((cookie) =>
        cookie.name.startsWith("spacefast_session"),
      ),
    ).toBeUndefined();
    expect(await passwordExchangeCount()).toBe(exchangesBefore);
  } finally {
    await context.close();
    attacker.stop(true);
  }
});
