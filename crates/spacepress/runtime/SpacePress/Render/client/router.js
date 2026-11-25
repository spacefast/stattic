// Region navigation for SpacePress's <Link> (docs/spec/rendering.md §4.3). A click on a same-site link
// fetches the next page and swaps the router region in place with @wordpress/interactivity-router;
// a modified click, another target or a download stays a normal navigation. Without JavaScript the
// links are ordinary links.
//
// The JavaScript submit for `<form action={name}>` (docs/decisions/2026-11-14-forms-without-javascript.md):
// the same form-encoded post, sent with `fetch`. `fetch` follows the action's 303 to the page with
// `?_flash=<id>`, so that one page request reads the read-once flash, and the router gets that HTML
// handed to it (`html`, as client/live-region.js does): it diffs the region, so client components
// and scroll stay, and nothing reloads. Without JavaScript the form posts as before.
//
// A form whose action succeeded starts over once its page is in, with the values the server
// rendered, as a page load would leave it; a form whose submit was refused keeps what was typed.
//
// Once a post may have reached the server, it is never sent again: a failure after that shows a
// message in the form. Only when the router module can't load (nothing was sent) does the browser
// post the form itself.
import { store, withSyncEvent, getElement, getConfig } from "@wordpress/interactivity";

const MARKER = "_flash";
const PENDING = "data-sp-pending";
const SUBMITS = 'button:not([type]), button[type="submit"], input[type="submit"], input[type="image"]';

// What submits and `<Live>` swaps share on this page (client/live-region.js reads it):
// - `pending`, `idle`: how many submits are in flight, and a promise for when none is;
// - `ticket`, `last`: each submit's number, and the number of the last one rendered, so an answer
//   older than one already on the page is dropped;
// - `renders`: how many submit answers were rendered, so a `<Live>` answer held during one knows;
// - `living`, `live`: whether a `<Live>` swap is in progress, and a promise for its end, which a
//   submit waits for before rendering;
// - `navigations`: how many `<Link>` navigations started, so a submit's answer that arrives after
//   one is dropped (the router changes the URL only once the next page has loaded).
const forms = (globalThis[Symbol.for("sp/forms")] ??= { pending: 0, idle: Promise.resolve(), ticket: 0, last: 0, renders: 0, living: false, live: Promise.resolve(), navigations: 0 });
forms.navigations ??= 0;
let idleNow = () => {};
const begin = () => {
  if (forms.pending++ === 0) forms.idle = new Promise((resolve) => { idleNow = resolve; });
  return ++forms.ticket;
};
const end = () => {
  if (--forms.pending === 0) idleNow();
};

const routerLink = (ref) =>
  ref instanceof HTMLAnchorElement &&
  (ref.target === "" || ref.target === "_self") &&
  !ref.hasAttribute("download") &&
  ref.origin === window.location.origin;

// The block template a page renders in (`<body data-sp-template>`) and the one a link's target
// renders in (`data-sp-template` on the link): the router region swaps only between pages of one
// template, so a link to a page with other chrome (the theme's header, or none) loads the page.
// A link with no template (an address no app page serves) loads the page: what answers there has
// no router region to swap.
const sameTemplate = (ref) => {
  const target = ref.dataset.spTemplate;
  return target !== undefined && target === document.body.dataset.spTemplate;
};

// Attributes are read with getAttribute: a field named `action`, `method` or `target` shadows the
// form's own properties.
const attr = (el, name) => (el && el.hasAttribute(name) ? el.getAttribute(name) : null);

// The form's post URL when this submit is one the script sends: a form-encoded post, in this tab,
// to this site, from a form inside the router region. Anything else stays the browser's.
const submitUrl = (form, submitter) => {
  if (!(form instanceof HTMLFormElement) || !form.closest("[data-wp-router-region]")) return null;
  const method = (attr(submitter, "formmethod") ?? attr(form, "method") ?? "get").toLowerCase();
  const target = attr(submitter, "formtarget") ?? attr(form, "target") ?? "";
  const encoding = (attr(submitter, "formenctype") ?? attr(form, "enctype") ?? "application/x-www-form-urlencoded").toLowerCase();
  if (method !== "post" || (target !== "" && target !== "_self") || encoding !== "application/x-www-form-urlencoded") return null;
  let url;
  try {
    url = new URL(attr(submitter, "formaction") ?? attr(form, "action") ?? "", document.baseURI);
  } catch {
    return null;
  }
  return url.origin === window.location.origin ? url : null;
};

// A URL without the flash marker: the history entry and the router's page cache use it, so the
// marker never reaches a URL someone copies, bookmarks or shares.
const withoutMarker = (href) => {
  const url = new URL(href, window.location.href);
  url.searchParams.delete(MARKER);
  return url.href;
};
const pagePath = (href) => {
  const url = new URL(withoutMarker(href));
  return url.pathname + url.search;
};

// A page the browser loaded with the marker (a form posted without this script, or the fallback
// below): the address bar shows it without. The flash was read with this document already.
if (new URL(window.location.href).searchParams.has(MARKER)) {
  window.history.replaceState(window.history.state, "", withoutMarker(window.location.href));
}

// The browser's own submit, when the router can't load: nothing has been sent. The pressed button
// is enabled for the moment the browser reads it; the submit event this fires is let through.
const native = new WeakSet();
const nativeSubmit = (form, submitter) => {
  native.add(form);
  const pressed = submitter?.isConnected && submitter.form === form ? submitter : null;
  const wasDisabled = pressed?.disabled;
  if (pressed) pressed.disabled = false;
  try {
    form.requestSubmit(pressed);
  } catch {
    native.delete(form);
    HTMLFormElement.prototype.submit.call(form);
  } finally {
    if (pressed) pressed.disabled = wasDisabled;
  }
};

// A failure after the post may have reached the server: said in the form, in a live region, and
// not sent again. Also said on a form whose answer a later submit's answer overtook.
const showError = (form, message) => {
  let box = form.querySelector(":scope > [data-sp-form-error]");
  if (!box) {
    box = document.createElement("p");
    box.className = "sp-form-error";
    box.setAttribute("data-sp-form-error", "");
    box.setAttribute("role", "alert");
    box.setAttribute("aria-live", "assertive");
    form.append(box);
  }
  const config = getConfig("sp/router") ?? {};
  box.textContent = (message === "overtaken" ? config.overtaken : config.failed) ||
    (message === "overtaken" ? "Another form on this page was sent after this one, and its result is the one shown." : "Something went wrong sending this form. Reload the page to see whether it went through.");
};

// After a submit's page is swapped in: a polite announcement of what came back. A paragraph the
// page shows with `role="status"` or `role="alert"` (a flash message) is what it says, else the
// page's title. One visually hidden live region per page, outside the router region.
const announce = () => {
  let region = document.getElementById("sp-form-announcer");
  if (!region) {
    region = document.createElement("div");
    region.id = "sp-form-announcer";
    region.setAttribute("aria-live", "polite");
    region.setAttribute("aria-atomic", "true");
    region.style.cssText = "position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0";
    document.body.append(region);
  }
  const said = document.querySelector('[data-wp-router-region] [role="status"], [data-wp-router-region] [role="alert"]:not([data-sp-form-error])');
  const text = (said?.textContent ?? "").trim() || document.title;
  region.textContent = "";
  setTimeout(() => { region.textContent = text; }, 50);
};

// After a success, each field of the form shows what the server rendered for it in `html`, as a
// page load would: what the visitor typed goes. The router's diff sets a field's `value`, `checked`
// and `selected` only where its own record of them changed, and never the attributes `reset()`
// reads, so the state is copied from the answer itself. The form is found by its place among the
// forms with the same `action`; a field by its place in the form.
const settle = (form, html) => {
  const action = attr(form, "action");
  const live = [...document.querySelectorAll("form")].filter((f) => attr(f, "action") === action);
  const doc = new DOMParser().parseFromString(html, "text/html");
  const served = [...doc.querySelectorAll("form")].filter((f) => attr(f, "action") === action)[live.indexOf(form)];
  if (!served) return;
  const mine = [...form.querySelectorAll("input, textarea, select")];
  const theirs = [...served.querySelectorAll("input, textarea, select")];
  if (mine.length !== theirs.length) return;
  mine.forEach((el, i) => {
    const from = theirs[i];
    if (el.tagName !== from.tagName || el.name !== from.name) return;
    if (el instanceof HTMLSelectElement) {
      const options = [...from.options];
      [...el.options].forEach((o, j) => {
        o.defaultSelected = options[j]?.defaultSelected ?? false;
        o.selected = o.defaultSelected;
      });
    } else if (el instanceof HTMLTextAreaElement) {
      el.defaultValue = from.defaultValue;
      el.value = from.defaultValue;
    } else if (el.type === "checkbox" || el.type === "radio") {
      el.defaultChecked = from.defaultChecked;
      el.checked = from.defaultChecked;
    } else if (el.type !== "file" && el.type !== "hidden") {
      el.defaultValue = from.defaultValue;
      el.value = from.defaultValue;
    }
  });
};

const canPrefetch = (ref) => routerLink(ref) && sameTemplate(ref) && !getConfig("core/router")?.clientNavigationDisabled;

store("sp/router", {
  callbacks: {
    preparePrefetch() {
      const { ref } = getElement();
      // Core's document speculation and its region router have separate prefetch caches.
      if (!canPrefetch(ref) || ref.classList.contains("no-prefetch")) return;
      ref.classList.add("no-prefetch");
      return () => ref.classList.remove("no-prefetch");
    },
  },
  actions: {
    navigate: withSyncEvent(function* (event) {
      const { ref } = getElement();
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
          !routerLink(ref) || !sameTemplate(ref)) return;
      event.preventDefault();
      // Counted before anything waits: a submit whose answer comes in after this click drops it.
      forms.navigations++;
      const { actions } = yield import("@wordpress/interactivity-router");
      yield actions.navigate(ref.href);
    }),
    *prefetch() {
      const { ref } = getElement();
      if (!canPrefetch(ref)) return;
      const { actions } = yield import("@wordpress/interactivity-router");
      yield actions.prefetch(ref.href);
    },
    submit: withSyncEvent(function* (event) {
      const { ref: form } = getElement();
      const submitter = event.submitter ?? null;
      if (native.delete(form) || event.defaultPrevented) return;
      // A page core marks as not client-navigable would be loaded again by the router, and the
      // flash is read once: the browser posts it, as without JavaScript.
      if (getConfig("core/router")?.clientNavigationDisabled) return;
      const url = submitUrl(form, submitter);
      if (!url) return;
      event.preventDefault();
      // One submit at a time per form: another while one is in flight does nothing.
      if (form.hasAttribute(PENDING)) return;
      // The body the browser would send, with the pressed button, collected before anything is disabled.
      const body = new URLSearchParams();
      // `new FormData(form, submitter)` adds the pressed button in browsers since 2023; an older one
      // ignores (or rejects) the second argument, and the button is added here instead.
      let data;
      try {
        data = new FormData(form, submitter);
      } catch {
        data = new FormData(form);
      }
      for (const [name, value] of data) body.append(name, typeof value === "string" ? value : value.name);
      if (submitter?.name && !data.getAll(submitter.name).includes(submitter.value ?? "")) body.append(submitter.name, submitter.value ?? "");
      // Pending: `aria-busy` on the form (style `form[aria-busy="true"]`) and its enabled submit
      // buttons (inside it, or outside with `form="id"`) disabled, each marked so only those are
      // enabled again. The focused element gets focus back if the swap drops it.
      const focused = document.activeElement;
      const buttons = [...document.querySelectorAll(SUBMITS)].filter((b) => b.form === form && !b.disabled);
      form.querySelectorAll("[data-sp-form-error]").forEach((box) => box.remove());
      form.setAttribute(PENDING, "");
      form.setAttribute("aria-busy", "true");
      for (const b of buttons) {
        b.setAttribute(PENDING, "");
        b.disabled = true;
      }
      // The buttons back as they were before the swap, so the router's diff applies the server's
      // `disabled` to them (it never touches what the page set by hand), and only the ones this
      // submit disabled.
      const release = () => {
        for (const b of buttons) {
          if (!b.hasAttribute(PENDING)) continue;
          b.removeAttribute(PENDING);
          b.disabled = false;
        }
      };
      const done = () => {
        form.removeAttribute(PENDING);
        form.removeAttribute("aria-busy");
        release();
        if (focused instanceof HTMLElement && focused.isConnected && (document.activeElement === document.body || document.activeElement === null)) focused.focus({ preventScroll: true });
      };
      // Where the visitor is now: a navigation while the post is in flight wins over its answer.
      const from = pagePath(window.location.href);
      const navigations = forms.navigations;
      const ticket = begin();
      let keepPending = false;
      try {
        let actions;
        try {
          ({ actions } = yield import("@wordpress/interactivity-router"));
        } catch {
          // Nothing was sent: the browser posts the form, which stays pending while the page loads.
          keepPending = true;
          nativeSubmit(form, submitter);
          return;
        }
        let html = null;
        let response = null;
        try {
          response = yield fetch(url.href, { method: "POST", body, credentials: "same-origin", redirect: "follow", headers: { Accept: "text/html", "X-SP-Template": document.body.dataset.spTemplate ?? "", "X-SP-From": from } });
          const type = (response.headers.get("content-type") ?? "").split(";")[0].trim().toLowerCase();
          // A page from this site: a 2xx, or any page the action's 303 came back to. The action ran
          // and the page it returns to may now answer 404 (a deleted post's own page) or 500; that
          // page is what the visitor sees, as without JavaScript. Handed to the router as `html`,
          // it is swapped in like any other (the router's own 200-only check is for pages it fetches).
          const page = type === "text/html" && new URL(response.url).origin === window.location.origin;
          if (page && (response.ok || (response.redirected && response.status >= 400))) html = yield response.text();
        } catch {
          // No answer, or one cut off: the post may have run. Not sent again.
          html = null;
        }
        if (html === null) {
          showError(form);
          return;
        }
        // A `<Live>` swap in progress finishes first; then this answer takes effect only if the
        // visitor is still where they submitted from, the form is still on the page, and no later
        // submit's answer is on the page already. Checked before the full-page fallback branches so
        // a stale answer in another block template (or without a router region) never overrides a
        // later navigation or submit.
        yield forms.live;
        if (forms.navigations !== navigations || pagePath(window.location.href) !== from || !form.isConnected) return;
        if (ticket < forms.last) {
          showError(form, "overtaken");
          return;
        }
        // An answer at another address, in another block template, has other chrome around its
        // region: it loads in full, as a <Link> to it would. The server kept its flash for that load
        // (the fetch said which template and page this is, `X-SP-Template`, `X-SP-From`), so the page
        // reads it once more, with the marker. The page the form is on, come back as its 404, swaps.
        const answerTemplate = /<body\b[^>]*\sdata-sp-template="([^"]*)"/.exec(html)?.[1];
        if (answerTemplate !== undefined && answerTemplate !== document.body.dataset.spTemplate && response.redirected && pagePath(response.url) !== from) {
          keepPending = true;
          forms.last = ticket;
          forms.navigations++;
          window.location.assign(response.url);
          return;
        }
        if (!html.includes("data-wp-router-region")) {
          // It came back to a page the router can't swap into: that page loads in full.
          if (response.redirected) {
            keepPending = true;
            forms.last = ticket;
            forms.navigations++;
            window.location.assign(withoutMarker(response.url));
          } else {
            showError(form);
          }
          return;
        }
        forms.last = ticket;
        forms.renders++;
        const href = withoutMarker(response.url);
        release();
        // `force` puts this render in the router's page cache under the page's URL, so going back
        // to it shows what the visitor last saw, and nothing fetches the page again. Coming back to
        // the page the form is on replaces its history entry instead of adding one.
        yield actions.navigate(href, {
          html,
          force: true,
          replace: pagePath(href) === from,
          screenReaderAnnouncement: false,
        });
        // A form whose action succeeded starts over, as it does when the browser loads the page
        // without this script (Forms::outcome marks the form).
        if (form.isConnected && form.getAttribute("data-sp-form-state") === "success") settle(form, html);
        announce();
      } finally {
        if (!keepPending) done();
        end();
      }
    }),
  },
});
