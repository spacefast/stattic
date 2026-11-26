// Sign in with Spacefast and the Space bar, end to end in a real browser: the
// real engine, the real control plane in process and the dashboard's hosted
// build (sign-in-flow-fixture.ts). It runs in a loopback-only network
// namespace (confined.sh), so nothing here reaches a machine but this one.
//
// One person's journey through one browser profile, in order. It is one test
// with named checkpoints, because each step starts where the one before left
// the person: the first checkpoint that fails is the one reported, and nothing
// after it runs on a half-finished state.
//
// Owners: this file owns the browser journey. The control plane's decisions
// behind each step keep their owners in apps/control-plane/src/access/
// share-oauth-proxy.test.ts (the holds a sign-out leaves, on both sign-ins);
// the engine's in runtime/tests/access-chain.test.ts.
import { afterAll, beforeAll, expect, setDefaultTimeout, test } from "bun:test";

import {
  chromium,
  expect as playwrightExpect,
  type Browser,
  type BrowserContext,
  type Frame,
  type Page,
  type Response as PlaywrightResponse,
} from "@playwright/test";
import { z } from "zod";

import { browserTestOptions } from "../../scripts/browser-test-options.cjs";
import { requireLoopbackOnly } from "./confinement.ts";

setDefaultTimeout(600_000);
const expectUi = playwrightExpect.configure({ timeout: 30_000 });

const spaceSchema = z.object({ spaceId: z.string(), host: z.string() });
const personSchema = z.object({
  userId: z.string(),
  email: z.string(),
  name: z.string(),
  cookie: z.string(),
  token: z.string(),
});
type Person = z.infer<typeof personSchema>;
// No fetch or browser starts outside the launcher's loopback-only namespace.
await requireLoopbackOnly();
const fixture = z
  .object({
    listenerPort: z.number(),
    controlUrl: z.string(),
    apiOrigin: z.string(),
    dashboardOrigin: z.string(),
    accessOrigin: z.string(),
    docs: spaceSchema.extend({ title: z.string() }),
    notes: spaceSchema.extend({ title: z.string() }),
    open: spaceSchema,
    robin: personSchema,
    sam: personSchema,
  })
  .parse(JSON.parse(process.env.SPACEFAST_BROWSER_FIXTURE_JSON ?? "null"));

const SESSION_COOKIE = "__Host-better-auth.session_token";
const docsUrl = (path = "/docs/") => `https://${fixture.docs.host}${path}`;
const notesUrl = (path = "/docs/") => `https://${fixture.notes.host}${path}`;
const openUrl = (path = "/") => `https://${fixture.open.host}${path}`;

let browser: Browser;
/** Every request a browser profile made, by URL. */
const requests: string[] = [];
/** Every docs-Space join Cast saw: its topic's resource and room, the ticket, and whether the platform verified it. */
const castJoins: { resource: string; room: string; token: string; verified: boolean }[] = [];

type ControlBody = {
  state?: "enabled" | "kill_switch";
  spaceId?: string;
  email?: string;
  role?: "viewer" | "commenter";
};

/** Asks the fixture to do what an operator or a Space's manager does outside this browser. */
async function control(path: "/switch" | "/invite", body: ControlBody) {
  // Not a ticket check: those go through verifyTicket.
  const response = await fetch(`${fixture.controlUrl}${path}`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!response.ok) throw new Error(`${path}: ${response.status} ${await response.text()}`);
}

/** Which account a Space's session principal names, read by the control plane. */
async function accountOf(spaceId: string, principal: string) {
  const url = new URL("/account", fixture.controlUrl);
  url.searchParams.set("space", spaceId);
  url.searchParams.set("principal", principal);
  return z.object({ userId: z.string().nullable() }).parse(await (await fetch(url)).json()).userId;
}

/**
 * Whether the platform accepts this ticket for this room on the Space: its
 * signature, issuer and audience, checked by the control plane's own verifier
 * in the fixture. Cast's own acceptance of a ticket is the Cast suite's
 * (apps/cast/test), not this proof's.
 */
async function verifyTicket(token: string, room: string, spaceId: string) {
  const response = await fetch(`${fixture.controlUrl}/verify-ticket`, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ token, room, spaceId }),
  });
  return z.object({ ok: z.boolean(), userId: z.string().optional() }).parse(await response.json());
}

/** The room key a Cast topic (`room:<resource>:<escaped room key>`) carries. */
function roomOfTopic(topic: string) {
  const escaped = decodeURIComponent(topic.split(":").slice(2).join(":"));
  return escaped.replace(
    /~([0-5])/g,
    (_, n: string) => ["~", "/", ":", "\\", "%", "\0"][Number(n)] ?? "",
  );
}

/**
 * Cast at the wire. A join is answered only once the platform verified its
 * ticket for the room it asks for; an invalid ticket gets an error reply, as
 * Cast would give it. Every other message gets an empty success.
 */
async function serveCast(target: BrowserContext) {
  await target.routeWebSocket(
    (url) => url.hostname === "cast.spacefast.com",
    (socket) => {
      socket.onMessage(async (raw) => {
        const [joinRef, ref, topic, event, payload] = z
          .tuple([z.string().nullable(), z.string().nullable(), z.string(), z.string()])
          .rest(z.unknown())
          .parse(JSON.parse(raw.toString()));
        const join = event === "phx_join";
        // Checkpoint 3's joins, on the docs Space, are answered only with a
        // ticket the platform verifies. Other Spaces' joins (the public
        // Space's anonymous visitor) are answered as before: no claim is made
        // about them here.
        const room = roomOfTopic(topic);
        if (join && room.startsWith(`space:${fixture.docs.spaceId}:`)) {
          const ticket = z.object({ token: z.string().optional() }).safeParse(payload);
          const token = ticket.success ? ticket.data.token : undefined;
          const verified = token
            ? (await verifyTicket(token, room, fixture.docs.spaceId)).ok
            : false;
          if (token) castJoins.push({ resource: topic.split(":")[1] ?? "", room, token, verified });
          if (!verified) {
            socket.send(
              JSON.stringify([
                joinRef,
                ref,
                topic,
                "phx_reply",
                { status: "error", response: { reason: "unauthorized" } },
              ]),
            );
            return;
          }
        }
        const response = join
          ? {
              room: { id: "room_sign_in_flow" },
              actor: { id: "actor_flow", kind: "user", anonymous_id: null, profile: {} },
              session: { id: "session_flow", external_id: null },
              capabilities: {
                comments_create: true,
                drawing_create: false,
                telemetry_read: false,
              },
              unread_count: 0,
              mention_unread_count: 0,
              threads: [],
            }
          : { ok: true };
        socket.send(JSON.stringify([joinRef, ref, topic, "phx_reply", { status: "ok", response }]));
      });
    },
  );
}

/** A dashboard session for this account, as signing in to Spacefast leaves one. */
async function signedInToSpacefast(target: BrowserContext, who: Person) {
  await target.addCookies([
    {
      name: SESSION_COOKIE,
      value: who.cookie,
      domain: "api.spacefast.com",
      path: "/",
      secure: true,
      httpOnly: true,
      sameSite: "Lax",
    },
  ]);
}

async function newContext() {
  const created = await browser.newContext({ ignoreHTTPSErrors: true });
  created.on("request", (request) => requests.push(request.url()));
  await serveCast(created);
  return created;
}

/** Who this Space's page session is, asked by the page itself on its own origin. */
async function principalOn(page: Page) {
  const body: unknown = await page.evaluate(async () => {
    const response = await fetch("/__spacefast/access/session", { cache: "no-store" });
    return response.json();
  });
  const session = z
    .object({
      data: z.discriminatedUnion("state", [
        z.object({ state: z.literal("anonymous") }),
        z.object({ state: z.literal("authenticated"), principal: z.string() }),
      ]),
    })
    .parse(body).data;
  return session.state === "authenticated" ? session.principal : null;
}

/** The page is signed in to this Space as exactly this person. */
async function signedInAs(page: Page, spaceId: string, who: Person) {
  const principal = await principalOn(page);
  expect(principal).not.toBeNull();
  expect(await accountOf(spaceId, principal ?? "")).toBe(who.userId);
  return principal ?? "";
}

/** Who the dashboard is signed in as: its own page, asking its own API. */
async function dashboardAccount(context: BrowserContext) {
  const page = await context.newPage();
  await page.goto(`${fixture.dashboardOrigin}/shared`);
  await expectUi(page.getByRole("heading", { name: "Shared with You" })).toBeVisible();
  const body: unknown = await page.evaluate(async (api) => {
    const response = await fetch(`${api}/v1/me`, { credentials: "include" });
    return response.json();
  }, fixture.apiOrigin);
  await page.close();
  return z.object({ data: z.object({ user: z.object({ email: z.string() }) }) }).parse(body).data
    .user.email;
}

/** The bar frame inside the page, once client.js mounted it. */
async function barFrame(page: Page): Promise<Frame> {
  const isBar = (frame: Frame) => frame.url().startsWith(`${fixture.accessOrigin}/bar/`);
  await expectUi.poll(() => page.frames().some(isBar)).toBe(true);
  const frame = page.frames().find(isBar);
  if (!frame) throw new Error("bar frame missing");
  return frame;
}

const barRequests = () =>
  requests.filter(
    (url) =>
      url.startsWith(`${fixture.accessOrigin}/bar/`) ||
      /\/__spacefast\/chunks\/bar-/.test(url) ||
      /\/sdk\/v1\/bar\.js(?:\?|$)/.test(url),
  );

/** Signs in on the interactive Space authorize page, as a person would. */
async function continueOnDoor(page: Page, button: RegExp) {
  await page.waitForURL(
    (url) => url.origin === fixture.apiOrigin && url.pathname === "/oauth/authorize",
  );
  const door = page.waitForResponse(
    (response) =>
      response.url() === `${fixture.apiOrigin}/oauth/authorize` &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: button }).first().click();
  expect((await door).status()).toBe(303);
}

/** Opens the account menu after the Bar exchanges its popup's authorization code. */
async function openAccountMenu(page: Page) {
  const frame = await barFrame(page);
  // The sign-in window can open and close before Playwright reports it as a page.
  const [exchange] = await Promise.all([
    page.waitForResponse(
      (response) =>
        response.url() === `${fixture.apiOrigin}/v1/auth/oauth2/token` &&
        response.request().method() === "POST",
    ),
    frame.getByRole("button", { name: "Account and options" }).click(),
  ]);
  expect(exchange.status()).toBe(200);
  const menu = frame.getByRole("region", { name: "Account and options", exact: true });
  await expectUi(menu).toBeVisible();
  await expectUi.poll(() => page.context().pages().length).toBe(1);
  return menu;
}

/**
 * The gate's silent sign-in on this Space, refused with `login_required`, as
 * one chain: the browser's `prompt=none` request to the API's authorize
 * endpoint for this Space, carrying the platform session cookie, answered
 * with a redirect to the Space's own callback that carries the same `state`,
 * `error=login_required` and no code. Armed before the navigation that makes
 * the gate ask, so the request cannot be missed. A gate that never asked, a
 * callback this browser did not get from that authorize, or an authorize that
 * never saw the platform session (which would also answer `login_required`)
 * fails here.
 */
function silentSignInRefused(page: Page, host: string) {
  return page
    .waitForRequest(
      (request) => {
        const url = new URL(request.url());
        return (
          url.host === host &&
          url.pathname === "/__spacefast/callback" &&
          url.searchParams.get("error") === "login_required"
        );
      },
      { timeout: 30_000 },
    )
    .then(async (callback) => {
      const back = new URL(callback.url());
      const authorize = callback.redirectedFrom();
      if (!authorize) throw new Error("the login_required callback did not come from a redirect");
      const asked = new URL(authorize.url());
      expect(asked.origin).toBe(fixture.apiOrigin);
      expect(asked.pathname).toBe("/v1/auth/oauth2/authorize");
      expect(asked.searchParams.get("prompt")).toBe("none");
      expect(asked.searchParams.get("redirect_uri")).toBe(`https://${host}/__spacefast/callback`);
      expect(back.searchParams.get("state")).toBe(asked.searchParams.get("state"));
      expect(back.searchParams.has("code")).toBe(false);
      // The platform session went with it: the refusal is the hold's, not an
      // anonymous browser's.
      expect(await authorize.headerValue("cookie")).toContain(`${SESSION_COOKIE}=`);
      return callback;
    });
}

/**
 * One named step of the journey. A failure inside it carries the step's name,
 * so the report says which checkpoint broke.
 */
async function checkpoint(name: string, run: () => Promise<void>) {
  try {
    await run();
  } catch (error) {
    if (error instanceof Error) error.message = `[checkpoint ${name}] ${error.message}`;
    throw error;
  }
}

beforeAll(async () => {
  const executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  browser = await chromium.launch({
    ...(executablePath ? { executablePath } : { channel: "chromium" }),
    ...browserTestOptions,
    args: [
      `--host-resolver-rules=MAP * 127.0.0.1:${fixture.listenerPort}, EXCLUDE localhost`,
      "--no-proxy-server",
    ],
  });
});

afterAll(async () => {
  await browser?.close();
});

test("one person signs in, uses the bar, switches and signs out, and the switch turns it all off", async () => {
  const context = await newContext();
  // 0. The switch goes on for both teams, and the route sync each write
  //    staged for each Space succeeds. That the new route reached a Space is
  //    shown where the journey first needs it: the docs gate in step 1, the
  //    notes bar in step 4.
  await checkpoint("0 the switch on, its route syncs succeeded", async () => {
    await control("/switch", { state: "enabled" });
  });

  // 1. A signed-out visitor, and an anonymous visitor on a public Space, load
  //    no bar frame/module and make no session or bar request; the public Space
  //    draws only the zero-network anonymous mark.
  await checkpoint("1 signed-out and public visitors load no bar", async () => {
    const page = await context.newPage();
    await page.goto(docsUrl());
    const signIn = page.getByRole("link", { name: "Continue with Spacefast", exact: true });
    await expectUi(signIn).toBeVisible();
    expect(new URL((await signIn.getAttribute("href")) ?? "", docsUrl()).pathname).toBe(
      "/__spacefast/sign-in",
    );
    const beforeOpen = requests.length;
    await page.goto(openUrl());
    await expectUi(page.getByRole("heading", { name: "flow-open home" })).toBeVisible();
    await page.waitForLoadState("networkidle");
    expect(barRequests()).toEqual([]);
    expect(
      requests.slice(beforeOpen).filter((url) => new URL(url).pathname === "/__spacefast/session"),
    ).toEqual([]);
    expect(
      page.frames().filter((frame) => frame.url().startsWith(`${fixture.accessOrigin}/bar/`)),
    ).toEqual([]);
    await expectUi(page.locator('spacefast-bar[data-sf-bar="anonymous_mark"]')).toHaveCount(1);
    await page.close();
  });

  // 2. A manager invites Robin. He finds the Space under Shared with You,
  //    accepts it there, opens it through the door and lands on the page as himself.
  let robinOnDocs = "";
  await checkpoint("2 invite, Shared with You, door, page", async () => {
    await control("/invite", { spaceId: fixture.docs.spaceId, email: fixture.robin.email });
    await signedInToSpacefast(context, fixture.robin);
    const page = await context.newPage();
    await page.goto(`${fixture.dashboardOrigin}/shared`);
    await expectUi(page.getByRole("heading", { name: "Shared with You" })).toBeVisible();
    const docsRow = page.getByRole("listitem").filter({ hasText: fixture.docs.title });
    await docsRow.getByRole("button", { name: "Accept" }).click();
    await docsRow.getByRole("link", { name: "Open" }).click();
    await continueOnDoor(page, /^Continue as Robin$/);
    // An invite opens the whole Space, so it lands on its front page.
    await page.waitForURL(docsUrl("/"));
    await expectUi(page.getByRole("heading", { name: "flow-docs home" })).toBeVisible();
    robinOnDocs = await signedInAs(page, fixture.docs.spaceId, fixture.robin);
    await page.close();
  });

  // 3. The bar appears for him, and its Comments button joins today's
  //    Comments as his account.
  await checkpoint("3 the bar's Comments as Robin, with a verified ticket", async () => {
    const page = await context.newPage();
    await page.goto(docsUrl());
    const frame = await barFrame(page);
    const comments = frame.getByRole("button", { name: "Comments", exact: true });
    await expectUi(comments).toBeVisible();
    // Who this page is signed in as right before the click.
    const current = await signedInAs(page, fixture.docs.spaceId, fixture.robin);
    // Comments load only when asked for: nothing before the click.
    expect(await page.locator("spacefast-collab").count()).toBe(0);
    // The Space bar's own live-notice watcher may also ask the same ticket
    // endpoint on load; collect every ticket response and match the one
    // Comments joins the page's Cast room with.
    const tickets = new Set<string>();
    const onTicket = (response: PlaywrightResponse) => {
      if (
        new URL(response.url()).pathname !== "/__spacefast/comments/ticket" ||
        response.request().method() !== "POST" ||
        !response.ok()
      ) {
        return;
      }
      void response
        .json()
        .then((body) => {
          const parsed = z.object({ data: z.object({ token: z.string() }) }).safeParse(body);
          if (parsed.success) tickets.add(parsed.data.data.token);
        })
        .catch(() => {});
    };
    page.on("response", onTicket);
    const expectedResource = `resource_${fixture.docs.spaceId}`;
    const expectedRoom = `space:${fixture.docs.spaceId}:path:${encodeURIComponent("/docs")}`;
    const matchingJoin = () =>
      castJoins.find(
        (join) =>
          tickets.has(join.token) &&
          join.verified &&
          join.resource === expectedResource &&
          join.room === expectedRoom,
      );
    let ticket: string;
    try {
      await comments.click();
      await expectUi(comments).toHaveAttribute("aria-pressed", "true");
      // Comments joined Cast with a ticket minted from `/__spacefast/comments/ticket`,
      // which the platform verifies (signature, issuer, audience) for the room it joined.
      //   The join names the docs Space's own Cast resource and exactly the
      //   room of the page it was opened on: `/docs/`, whose room key carries
      //   the page path without its trailing slash.
      await expectUi
        .poll(() => matchingJoin() !== undefined)
        .toBe(true)
        .catch((error: Error) => {
          error.message += ` (joins seen: ${JSON.stringify(castJoins.map(({ resource, room, verified }) => ({ resource, room, verified })))})`;
          throw error;
        });
      const joined = matchingJoin();
      if (!joined) throw new Error("expected a verified Cast join for the comments room");
      ticket = joined.token;
    } finally {
      page.off("response", onTicket);
    }
    const claims = z
      .object({ sub: z.string(), actorKind: z.enum(["user", "guest"]) })
      .parse(JSON.parse(Buffer.from(ticket.split(".")[1] ?? "", "base64url").toString()));
    // The ticket speaks for the account this page is signed in as, as a user.
    // Its subject is that account's Space subject, or the Person entry the
    // account accepted; either way it must name the same account as the
    // page's session did a moment ago.
    expect(claims.actorKind).toBe("user");
    expect(await accountOf(fixture.docs.spaceId, claims.sub)).toBe(
      await accountOf(fixture.docs.spaceId, current),
    );
    // Today's Comments overlay, with the bar as its only way in: no orb. The
    // button opens the comments list, as the rail's Sidebar button would.
    // That a comment can then be started is bar-comments.test.ts's.
    await expectUi(page.locator("spacefast-collab .sf-pins-host")).toHaveCount(1);
    expect(await page.locator("spacefast-collab .sf-orb").count()).toBe(0);
    await expectUi(
      page.locator("spacefast-collab .sf-sidebar .sf-tab[aria-selected='true']"),
    ).toHaveText("Comments");
    await page.close();
  });

  // 4. On the Space where he can only view, the bar's menu says so.
  let robinOnNotes = "";
  await checkpoint("4 viewer's menu", async () => {
    await control("/invite", {
      spaceId: fixture.notes.spaceId,
      email: fixture.robin.email,
      role: "viewer",
    });
    const page = await context.newPage();
    await page.goto(`${fixture.dashboardOrigin}/shared`);
    const notesRow = page.getByRole("listitem").filter({ hasText: fixture.notes.title });
    await notesRow.getByRole("button", { name: "Accept" }).click();
    await notesRow.getByRole("link", { name: "Open" }).click();
    await continueOnDoor(page, /^Continue as Robin$/);
    await page.waitForURL(notesUrl("/"));
    robinOnNotes = await signedInAs(page, fixture.notes.spaceId, fixture.robin);
    const menu = await openAccountMenu(page);
    await expectUi(menu.getByText(`Signed in as ${fixture.robin.name}`)).toBeVisible();
    await expectUi(menu.getByText("You can view this Space")).toBeVisible();
    await expectUi(menu.getByRole("menuitem", { name: "Switch Account" })).toBeVisible();
    await page.close();
  });

  // 5. Switch Account signs the docs Space in as Sam. The dashboard keeps
  //    Robin, and so does the notes Space.
  await checkpoint("5 Switch Account", async () => {
    // Both accounts signed in to Spacefast in this browser.
    await context.addCookies(
      [fixture.robin, fixture.sam].map((who) => ({
        name: `${SESSION_COOKIE}_multi-${who.token.toLowerCase()}`,
        value: who.cookie,
        domain: "api.spacefast.com",
        path: "/",
        secure: true,
        httpOnly: true,
        sameSite: "Lax" as const,
      })),
    );
    const page = await context.newPage();
    await page.goto(docsUrl());
    const frame = await barFrame(page);
    await frame.getByRole("button", { name: "Account and options" }).click();
    await frame.getByRole("menuitem", { name: "Switch Account" }).click();
    // `/oauth/authorize` asks which account this Space signs in as.
    await page.waitForURL(
      (url) => url.origin === fixture.apiOrigin && url.pathname === "/oauth/authorize",
    );
    await expectUi(page.getByRole("heading", { name: "Choose an account" })).toBeVisible();
    await continueOnDoor(page, /Sam Second/);
    // Sam's first sign-in on this Space: the door asks once.
    await continueOnDoor(page, /^Continue as Sam$/);
    await page.waitForURL((url) => url.host === fixture.docs.host);
    const samOnDocs = await signedInAs(page, fixture.docs.spaceId, fixture.sam);
    expect(samOnDocs).not.toBe(robinOnDocs);
    expect(await dashboardAccount(context)).toBe(fixture.robin.email);
    await page.goto(notesUrl());
    expect(await principalOn(page)).toBe(robinOnNotes);
    await page.close();
  });

  // 6. Sign Out of This Space ends only the docs Space's session, and the
  //    gate does not sign it back in.
  await checkpoint("6 Sign Out of This Space", async () => {
    const page = await context.newPage();
    await page.goto(docsUrl());
    // Sam manages this Space: the bar holds a token and names him.
    const menu = await openAccountMenu(page);
    await expectUi(menu.getByText("Signed in as Sam Second")).toBeVisible();
    // The sign-out lands back on the page, whose gate asks silently, and is
    // refused: the hold this sign-out left.
    const refusedAfterSignOut = silentSignInRefused(page, fixture.docs.host);
    await menu.getByRole("menuitem", { name: "Sign Out of This Space" }).click();
    await refusedAfterSignOut;
    await page.waitForURL((url) => url.host === fixture.docs.host && url.pathname === "/docs/");
    expect(await principalOn(page)).toBeNull();
    expect(await page.locator("spacefast-bar").count()).toBe(0);
    // A fresh visit. The engine remembers its last silent ask for hours and
    // would not ask again, so the Space's own cookies go first: the gate then
    // asks, and only the control plane's hold can refuse it.
    await context.clearCookies({ domain: fixture.docs.host });
    const refusedOnRevisit = silentSignInRefused(page, fixture.docs.host);
    await page.goto(docsUrl("/"));
    await refusedOnRevisit;
    expect(await principalOn(page)).toBeNull();
    expect(await dashboardAccount(context)).toBe(fixture.robin.email);
    await page.goto(notesUrl());
    expect(await principalOn(page)).toBe(robinOnNotes);
    await page.close();
  });

  // 7. A page's own `access.signOut()` (the page's button, not the bar's
  //    menu) stays signed out until
  //    the person signs in again.
  await checkpoint("7 a page's own access.signOut()", async () => {
    const page = await context.newPage();
    await page.goto(notesUrl());
    expect(await principalOn(page)).toBe(robinOnNotes);
    const refusedAfterSignOut = silentSignInRefused(page, fixture.notes.host);
    await page.getByRole("button", { name: "Sign out", exact: true }).click();
    await refusedAfterSignOut;
    await page.waitForURL((url) => url.host === fixture.notes.host && url.pathname === "/docs/");
    expect(await principalOn(page)).toBeNull();
    // A fresh visit, without the Space's own cookies (as in step 6): the
    // gate's silent sign-in asks, and is refused.
    await context.clearCookies({ domain: fixture.notes.host });
    const refusedOnRevisit = silentSignInRefused(page, fixture.notes.host);
    await page.goto(notesUrl("/"));
    await refusedOnRevisit;
    expect(await principalOn(page)).toBeNull();
    // A click on the Space's sign-in, through the door, signs in again. The
    // browser holds two accounts, so the door asks which; Robin picks himself.
    await page.getByRole("link", { name: "Continue with Spacefast", exact: true }).click();
    await continueOnDoor(page, /Robin Guest/);
    await page.waitForURL((url) => url.host === fixture.notes.host);
    await signedInAs(page, fixture.notes.spaceId, fixture.robin);
    await page.close();
  });
  await context.close();

  // 8. After the kill switch, the same Space serves the older sign-in, which
  //    signs a person in, and no bar.
  await checkpoint("8 kill switch serves the older sign-in", async () => {
    await control("/switch", { state: "kill_switch" });
    const before = barRequests().length;
    // A fresh profile holding only Robin's dashboard session: no Space cookie.
    const fresh = await newContext();
    await signedInToSpacefast(fresh, fixture.robin);
    // Every request this profile makes, by origin, method and path, recorded
    // from before the first navigation: a request that fails or is canceled
    // before any answer is still here.
    const sent: { origin: string; method: string; pathname: string }[] = [];
    fresh.on("request", (request) => {
      const url = new URL(request.url());
      sent.push({ origin: url.origin, method: request.method(), pathname: url.pathname });
    });
    // The engine's handoff that set the Space session, and the redirect chain
    // that led to it.
    const handoff = new Promise<{ chain: { url: URL; status: number }[] }>((resolve) => {
      fresh.on("response", (response) => {
        const url = new URL(response.url());
        if (
          url.host !== fixture.docs.host ||
          !(url.pathname === "/__sf/redeem" || url.pathname.startsWith("/__spacefast/in/")) ||
          response.status() !== 303
        )
          return;
        void response.allHeaders().then(async (headers) => {
          if (!/__Host-spacefast_session=/.test(headers["set-cookie"] ?? "")) return;
          const chain: { url: URL; status: number }[] = [];
          for (let step = response.request().redirectedFrom(); step; step = step.redirectedFrom()) {
            // oxlint-disable-next-line no-await-in-loop -- walk the chain back, one hop at a time.
            const answer = await step.response();
            chain.unshift({ url: new URL(step.url()), status: answer?.status() ?? 0 });
          }
          resolve({ chain });
        });
      });
    });

    const page = await fresh.newPage();
    await page.goto(docsUrl());
    // The older gate either admits a known account silently, or shows its
    // account sign-in for a click. Wait for one of the two before choosing.
    const signIn = page.getByRole("link", { name: "Continue with Spacefast", exact: true });
    const docsHeading = page.getByRole("heading", { name: "flow-docs docs" });
    await expectUi(signIn.or(docsHeading)).toBeVisible();
    if (await signIn.isVisible()) {
      await expectUi(signIn).toBeEnabled();
      await signIn.click();
    }
    await expectUi(docsHeading).toBeVisible();
    await signedInAs(page, fixture.docs.spaceId, fixture.robin);
    // It was the older sign-in: the API's account acquire answered 303, and
    // that redirect is the one that led to the engine's handoff that set the
    // Space session.
    const { chain } = await Promise.race([
      handoff,
      new Promise<never>((_, reject) =>
        setTimeout(() => reject(new Error("no engine handoff set the Space session")), 30_000),
      ),
    ]);
    const acquire = chain.findLast(
      ({ url }) =>
        url.origin === fixture.apiOrigin && url.pathname.startsWith("/v1/access/acquire/"),
    );
    expect(acquire?.status).toBe(303);
    expect(chain.at(-1)).toBe(acquire);
    // And no request of the new sign-in was even sent.
    expect(
      sent.filter(
        ({ origin, method, pathname }) =>
          (origin === fixture.apiOrigin && pathname === "/v1/auth/oauth2/authorize") ||
          (origin === fixture.apiOrigin && method === "POST" && pathname === "/oauth/authorize") ||
          (origin === `https://${fixture.docs.host}` &&
            (pathname === "/__spacefast/sign-in" || pathname === "/__spacefast/callback")) ||
          (origin === fixture.apiOrigin && pathname === "/oauth/authorize") ||
          (origin === fixture.apiOrigin && pathname === "/v1/door/page") ||
          (origin === fixture.apiOrigin && method === "POST" && pathname === "/v1/door") ||
          (origin === fixture.apiOrigin && pathname === "/v1/space-sign-in/page") ||
          (origin === fixture.apiOrigin && method === "POST" && pathname === "/v1/space-sign-in") ||
          (origin === fixture.dashboardOrigin && pathname === "/sign-in"),
      ),
    ).toEqual([]);
    expect(await page.locator("spacefast-bar").count()).toBe(0);
    expect(barRequests().slice(before)).toEqual([]);
    await fresh.close();
  });
});
