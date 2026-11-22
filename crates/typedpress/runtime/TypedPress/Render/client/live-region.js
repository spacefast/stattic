// `<Live>` regions in the browser (docs/decisions/2026-11-14-live-regions.md). Each region carries
// its first answer (`data-tp-live-seed`: the URL it was asked for, its token and the kinds it
// read). One typed client per page subscribes to each through the subscription endpoint, with those
// kinds as the read's dependencies, so a notice or a write naming one asks again at once. A changed
// answer goes through the Interactivity router's own navigation with the page's HTML handed to it,
// so client components and form inputs in the page keep their state: the router diffs, it doesn't
// rebuild. An unchanged answer touches nothing.
import { createClient, pageRead } from "@typedpress/client";
import { getConfig } from "@wordpress/interactivity";

const client = createClient({ nonce: getConfig("tp/live").nonce || undefined });

const html = new Map(); // region id → the HTML now on the page

// Shared with client/router.js's form submit (the same object, whichever module runs first).
const forms = (globalThis[Symbol.for("tp/forms")] ??= { pending: 0, idle: Promise.resolve(), ticket: 0, last: 0, renders: 0, living: false, live: Promise.resolve(), navigations: 0 });

async function apply(id, region, again) {
  // One swap at a time, and none while a form's post is in flight: both hand the router a whole
  // page for this URL, and the later one would undo the other. If a submit's page rendered while
  // this answer waited, either may be the newer region, so the answer isn't applied: the region
  // asks again (`again`), and that answer, from after the submit, is current. Dropping it outright
  // would leave the region stale, since the server answers "not modified" to the old token.
  for (;;) {
    if (forms.pending > 0) {
      const seen = forms.renders;
      await forms.idle;
      if (forms.renders !== seen) {
        again();
        return;
      }
      continue;
    }
    if (forms.living) {
      await forms.live;
      continue;
    }
    break;
  }
  // The router applies a whole document: today's page with this region's new HTML in it, without
  // a form's pending markers (client/router.js), which belong to no page the server rendered.
  const doc = document.documentElement.cloneNode(true);
  const target = doc.querySelector(`[data-tp-live="${CSS.escape(id)}"]`);
  if (!target) return;
  target.innerHTML = region;
  for (const el of doc.querySelectorAll("[data-tp-pending]")) {
    el.removeAttribute("data-tp-pending");
    if (el instanceof HTMLFormElement) el.removeAttribute("aria-busy");
    else el.removeAttribute("disabled");
  }
  // A form's error message (client/router.js) is the browser's, not the server's: left out of the
  // copy, so the swap doesn't make it part of the page.
  doc.querySelectorAll("[data-tp-form-error]").forEach((box) => box.remove());
  forms.living = true;
  const run = (async () => {
    try {
      const { actions } = await import("@wordpress/interactivity-router");
      await actions.navigate(window.location.href, { html: "<!DOCTYPE html>" + doc.outerHTML, force: true, replace: true, loadingAnimation: false, screenReaderAnnouncement: false });
    } finally {
      forms.living = false;
    }
  })();
  forms.live = run.catch(() => {});
  await run;
}

function watch(element) {
  const id = element.dataset.tpLive;
  let seed;
  try {
    seed = JSON.parse(element.dataset.tpLiveSeed ?? "null");
  } catch {
    return;
  }
  if (!seed) return;
  html.set(id, element.innerHTML);
  let kinds = seed.kinds;
  let stop = () => {};
  const subscribe = (deps, initial) => {
    stop();
    stop = pageRead(client, seed.url, id, deps).subscribe((answer) => {
      // A region whose render now reads other kinds subscribes again with them as its dependencies.
      // The answer's token isn't the subscriber's to see, so the new subscription asks once more;
      // that answer is unchanged and swaps nothing.
      const next = answer.kinds ?? [];
      if (next.join("\n") !== kinds.join("\n")) {
        kinds = next;
        queueMicrotask(() => subscribe(kinds, undefined));
      }
      // The browser's own serialisation of both, so only a real change swaps anything.
      const parsed = document.createElement("div");
      parsed.innerHTML = answer.html;
      if (parsed.innerHTML === html.get(id)) return;
      html.set(id, parsed.innerHTML);
      apply(id, answer.html, () => {
        // What the submit's page put in the region, then a fresh ask.
        html.set(id, element.isConnected ? element.innerHTML : document.querySelector(`[data-tp-live="${CSS.escape(id)}"]`)?.innerHTML ?? "");
        subscribe(kinds, undefined);
      });
    }, undefined, initial);
  };
  // The server-rendered region is the first answer: delivered now, and its token sent with the first
  // request, which the server answers "not modified" unless something changed since the render.
  subscribe(kinds, seed.token ? { token: seed.token, data: { html: element.innerHTML, kinds } } : undefined);
}

document.querySelectorAll("[data-tp-live][data-tp-live-seed]").forEach(watch);
