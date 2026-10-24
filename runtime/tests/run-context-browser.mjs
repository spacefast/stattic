/** Real native Gutenberg + real WordPress. One Space root, existing authority. */
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import path from "node:path";

import { chromium, expect as playwrightExpect } from "@playwright/test";

// Real WordPress in a single local wasm worker: a save and its read take seconds.
const expect = playwrightExpect.configure({ timeout: 30000 });
const origin = "http://127.0.0.1:9419";
const pages = `${origin}/wp-json/wp/v2/pages`;
const output = path.resolve(".cache/context-acceptance");
const browser = await chromium.launch({ headless: true });
// These are signed cookies/nonces from the disposable native WordPress user,
// not a substituted authentication API. They are never written to tracked files.
const native = JSON.parse(await readFile(path.join(output, "preview-editor-session.json"), "utf8"));
const editor = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  permissions: ["clipboard-read", "clipboard-write"],
  extraHTTPHeaders: { "x-wp-nonce": native.nonce },
});
await editor.addCookies([{ name: native.cookie, value: native.value, url: origin }]);
await editor.route("**/*", (route) => {
  const url = new URL(route.request().url());
  if (/^\/(?:wp-admin|wp-login\.php|zero-admin)(?:\/|$)/.test(url.pathname))
    return route.abort("blockedbyclient");
  return url.origin === origin || ["data:", "blob:"].includes(url.protocol)
    ? route.continue()
    : route.abort("blockedbyclient");
});
const page = await editor.newPage();
page.setDefaultTimeout(60000);
// The editor saves through Gutenberg itself (the native page endpoint), which
// also persists the shared real-time document. Agents save the same page.
// The header's save state; the Save button's spinner is a status too.
const saveState = (target) => target.locator('header [role="status"][aria-live="polite"]');
// The document is the Space's `context` page; people and agents save it through
// core's pages API behind one revision fence.
const read = async () =>
  (await (await editor.request.get(`${pages}?slug=context&context=edit`)).json())[0];
const editorSave = (target, status) =>
  target.waitForResponse(
    (response) =>
      response.url().startsWith(`${pages}/`) &&
      ["POST", "PUT"].includes(response.request().method()) &&
      (status === undefined || response.status() === status),
  );
const errors = [];
page.on("pageerror", (error) => errors.push(error.message));
// Each editor's identity in the live room, as Gutenberg's sync announces it.
const liveClients = new Map();
const trackLiveClient = (target) =>
  target.on("request", (request) => {
    if (!request.url().includes("/wp-sync/v1/updates")) return;
    const room = JSON.parse(request.postData() ?? "{}").rooms?.find((entry) =>
      entry.room.startsWith("postType/page:"),
    );
    if (room) liveClients.set(target, room.client_id);
  });
trackLiveClient(page);
try {
  await page.goto(origin);
  const title = page.getByRole("textbox", { name: "Title", exact: true });
  const body = page.locator('.sf-context-blocks [contenteditable="true"]').first();
  await body.waitFor();
  const original = await read();
  assert.match(original.spacefast_revision, /^sha256:/);
  const marker = `Human edit ${Date.now()} `;
  await body.click();
  await body.press(process.platform === "darwin" ? "Meta+ArrowUp" : "Control+Home");
  await body.pressSequentially(marker);
  await expect(saveState(page)).toHaveText("Unsaved changes");
  // The user chooses Save; editing alone has not mutated the WordPress record.
  assert.equal((await read()).spacefast_revision, original.spacefast_revision);
  const saved = editorSave(page, 200);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await saved;
  await expect(saveState(page)).toHaveText("Saved");
  const human = await read();
  assert.match(human.content.raw, new RegExp(marker.trim()));
  // Gutenberg re-serializes on save; every original block must survive it.
  const blockNames = (markup) =>
    [...markup.matchAll(/<!-- wp:([a-z/-]+)/g)].map((match) => match[1]);
  assert.deepEqual(blockNames(human.content.raw), blockNames(original.content.raw));
  await expect(saveState(page)).toHaveText("Saved");

  // Clicking the open page below the last block continues writing there,
  // exactly once, without a second prompt under the new empty paragraph.
  const blocks = page.locator(".sf-context-blocks .is-root-container > [data-type]");
  const blockCount = await blocks.count();
  await page
    .locator(".sf-context-blocks")
    .evaluate((element) => element.scrollIntoView({ block: "end" }));
  const canvas = await page.locator(".sf-context-blocks").boundingBox();
  await page.mouse.click(canvas.x + 200, canvas.y + canvas.height - 60);
  await expect(blocks).toHaveCount(blockCount + 1);
  await expect(page.locator(".block-editor-default-block-appender")).toHaveCount(0);
  await page.mouse.click(canvas.x + 200, canvas.y + canvas.height - 30);
  await expect(blocks).toHaveCount(blockCount + 1);
  await page.keyboard.type("Continued below");
  await expect(blocks.last()).toHaveText("Continued below");
  await page.keyboard.press(process.platform === "darwin" ? "Meta+a" : "Control+a");
  await page.keyboard.press("Backspace");
  await page.keyboard.press("Backspace");
  await expect(blocks).toHaveCount(blockCount);
  await page.screenshot({ path: path.join(output, "context-editor-light.png"), fullPage: true });

  // Live co-editing through the Gutenberg plugin's sync: a second editor sees
  // typing and title changes as they happen, without saving or reloading.
  const peerContext = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    extraHTTPHeaders: { "x-wp-nonce": native.nonce },
  });
  await peerContext.addCookies([{ name: native.cookie, value: native.value, url: origin }]);
  const peer = await peerContext.newPage();
  peer.setDefaultTimeout(60000);
  peer.on("pageerror", (error) => errors.push(`peer: ${error.message}`));
  trackLiveClient(peer);
  await peer.goto(origin);
  const peerTitle = peer.getByRole("textbox", { name: "Title", exact: true });
  await peer.locator('.sf-context-blocks [contenteditable="true"]').first().waitFor();
  const live = `Typed live ${Date.now()}`;
  await body.click();
  await body.press("End");
  await body.pressSequentially(` ${live}`);
  await expect(peer.locator(".sf-context-blocks")).toContainText(live);
  await peerTitle.fill("Co-edited title");
  await expect(title).toHaveValue("Co-edited title");
  await peer.screenshot({ path: path.join(output, "context-coedit-peer.png") });
  // One person saves the shared draft; the other sees it saved without acting.
  await expect(saveState(peer)).toHaveText("Unsaved changes");
  const shared = editorSave(page, 200);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await shared;
  await expect(saveState(page)).toHaveText("Saved");
  await expect(saveState(peer)).toHaveText("Saved", { timeout: 60000 });
  await peerContext.close();

  const coEdited = await read();
  assert.equal(coEdited.title.raw, "Co-edited title");
  assert.match(coEdited.content.raw, new RegExp(live));
  // An outside write cannot merge into a live session, so agents wait for it.
  const duringLive = await editor.request.post(`${pages}/${coEdited.id}`, {
    data: { spacefast_revision: coEdited.spacefast_revision, title: "Agent revision" },
  });
  assert.equal(duringLive.status(), 409);
  assert.equal((await duringLive.json()).code, "content_context_live_editing");
  // The closed peer and a tab that went quiet (asleep, offline) drop out of the
  // live session; the plugin would also forget them after its awareness timeout.
  await page.route("**/wp-json/wp-sync/**", (route) => route.abort());
  for (const clientId of liveClients.values()) {
    const left = await editor.request.post(`${origin}/wp-json/wp-sync/v1/updates`, {
      data: {
        rooms: [
          {
            room: `postType/page:${coEdited.id}`,
            client_id: clientId,
            after: 0,
            awareness: null,
            updates: [],
          },
        ],
      },
    });
    assert.equal(left.status(), 200);
  }
  // ...so agents may write again, and concurrent writes still serialize.
  const race = await Promise.all(
    ["Agent revision A", "Agent revision B"].map(async (nextTitle) =>
      (
        await editor.request.post(`${pages}/${coEdited.id}`, {
          data: { spacefast_revision: coEdited.spacefast_revision, title: nextTitle },
        })
      ).status(),
    ),
  );
  assert.deepEqual(
    race.toSorted((a, b) => a - b),
    [200, 409],
  );
  const agentTitle = (await read()).title.raw;
  // Back in the quiet tab, saving over the agent's change is refused, not lost.
  await title.fill("Keep my local title");
  const conflict = editorSave(page, 409);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await conflict;
  await expect(title).toHaveValue("Keep my local title");
  await page.unroute("**/wp-json/wp-sync/**");
  await page.getByRole("button", { name: "Load current context", exact: true }).click();
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Load current context", exact: true })
    .click();
  // A fresh session on the saved document: the stale copy is not merged in.
  await body.waitFor();
  await expect(title).toHaveValue(agentTitle);
  await expect(saveState(page)).toHaveText("Saved");

  await page.reload();
  await body.waitFor();
  // A save the server cannot complete keeps the edit and offers a retry. The
  // failure is injected at the network; every other request is real.
  await page.route(`${pages}/*`, (route) =>
    route.request().method() === "POST"
      ? route.fulfill({
          status: 503,
          contentType: "application/json",
          body: JSON.stringify({ code: "content_sync_busy", message: "Retry the save." }),
        })
      : route.fallback(),
  );
  await title.fill("Unsaved title");
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(saveState(page)).toHaveText("Save failed");
  await expect(title).toHaveValue("Unsaved title");
  await page.unroute(`${pages}/*`);
  await title.fill("Recovered context");
  const recovered = editorSave(page, 200);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await recovered;
  await page.getByRole("button", { name: "Document", exact: true }).click();
  await page.getByRole("menuitem", { name: "History", exact: true }).click();
  const historyDialog = page.getByRole("dialog", { name: "History" });
  await historyDialog.waitFor();
  // History lists each saved version once, with the saved one marked current.
  await expect(historyDialog.getByRole("listitem").first().locator("p").first()).toHaveText(
    "Recovered context",
  );
  await expect(historyDialog.getByText("Current", { exact: true })).toHaveCount(1);
  await historyDialog
    .getByRole("listitem")
    .filter({ hasText: human.title.raw })
    .filter({ hasText: marker.trim() })
    .first()
    .getByRole("button", { name: /^Restore/ })
    .click();
  await expect(historyDialog).toHaveCount(0);
  await expect(page.getByRole("status").filter({ hasText: "Save to keep it" })).toHaveCount(1);
  await body.waitFor();
  await expect(title).toHaveValue(human.title.raw);
  const restored = editorSave(page, 200);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await restored;
  await expect(body).toContainText(marker.trim());

  // The kit Menu by keyboard: focus moves in, arrows walk, Esc returns focus.
  const documentMenu = page.getByRole("button", { name: "Document", exact: true });
  await documentMenu.focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("menuitem", { name: "Copy link" })).toBeFocused();
  await page.keyboard.press("ArrowDown");
  await expect(page.getByRole("menuitem", { name: "History" })).toBeFocused();
  await page.keyboard.press("End");
  await expect(page.getByRole("menuitem", { name: "Download HTML" })).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("menu")).toHaveCount(0);
  await expect(documentMenu).toBeFocused();
  await documentMenu.click();
  await page.getByRole("menuitem", { name: "Copy link", exact: true }).click();
  await expect(page.getByRole("menu")).toHaveCount(0);
  assert.equal(await page.evaluate(() => navigator.clipboard.readText()), `${origin}/`);
  // Confirmations are transient: they do not push the document around.
  const linkCopied = page.getByRole("status").filter({ hasText: "Link copied" });
  await expect(linkCopied).toHaveCount(1);
  await expect(linkCopied).toHaveCount(0, { timeout: 6000 });
  for (const [item, extension] of [
    ["Download Markdown", ".md"],
    ["Download HTML", ".html"],
  ]) {
    await page.getByRole("button", { name: "Document", exact: true }).click();
    const file = page.waitForEvent("download");
    await page.getByRole("menuitem", { name: item, exact: true }).click();
    assert.ok(
      (await file).suggestedFilename().endsWith(extension),
      `${item} must download a ${extension} file.`,
    );
  }
  await page.emulateMedia({ colorScheme: "dark" });
  await page.screenshot({ path: path.join(output, "context-editor-dark.png"), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({
    path: path.join(output, "context-editor-mobile-dark.png"),
    fullPage: true,
  });
  const geometry = await page.evaluate(() => ({
    width: innerWidth,
    content: document.documentElement.scrollWidth,
  }));
  assert.ok(
    geometry.content <= geometry.width,
    "The mobile editor must not overflow the viewport.",
  );

  const reader = await browser.newContext({ colorScheme: "dark" });
  const readPage = await reader.newPage();
  await readPage.goto(origin);
  await expect(saveState(readPage)).toHaveText("Read only");
  await expect(readPage.locator(".sf-context-prose")).toContainText(marker.trim());
  const denied = await reader.request.post(`${pages}/${human.id}`, {
    data: { spacefast_revision: human.spacefast_revision, markdown: "Denied" },
  });
  assert.equal(denied.status(), 401);
  await readPage.screenshot({ path: path.join(output, "context-reader-dark.png"), fullPage: true });
  await reader.close();
  assert.deepEqual(errors, []);
  console.log(
    "Context browser proof passed: native Gutenberg, live co-editing between two editors, explicit Save, stale/concurrent fencing, failed-save recovery, history restore, one shared URL, dark/mobile layout, and read-only Space access.",
  );
} catch (error) {
  await page.screenshot({ path: path.join(output, "context-failure.png"), fullPage: true });
  await writeFile(path.join(output, "context-failure.html"), await page.content());
  console.error("Browser errors:", errors);
  throw error;
} finally {
  await browser.close();
}
