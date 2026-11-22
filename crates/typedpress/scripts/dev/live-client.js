// typedpress dev's reload client. It reads live.json, which `typedpress dev` rewrites beside this script,
// from the site's own origin: the page reloads when a build changed the site, and a failed build's
// errors are shown over the page until a build succeeds.
(() => {
  // A framed document follows its top window.
  if (window.top !== window.self) return;
  const source = new URL(document.currentScript.src);
  // Beside this script, at the address the document itself was opened at: the same site whether
  // that is Studio's own address or the one --url or --host announces, and never another origin.
  const state = new URL(new URL('live.json', source).pathname, location.origin);
  // The build this document was rendered from: `<session>.<revision>`, as live.json names them.
  const rendered = source.searchParams.get('build');
  let loaded = rendered;
  // One small static file from Studio, a few times a second while the tab is shown; less often
  // while the site does not answer or no `typedpress dev` is watching.
  const QUICK = 250;
  const SLOW = 3000;
  const RELOADED = 'typedpress-dev-reloaded';
  let overlay;
  let shown = '';
  let dismissed = '';
  let waiting = false;

  const dismiss = () => {
    dismissed = shown;
    overlay?.remove();
    overlay = undefined;
  };

  function show(diagnostics) {
    const key = diagnostics.length ? JSON.stringify(diagnostics) : '';
    if (key === shown) return;
    shown = key;
    overlay?.remove();
    overlay = undefined;
    // A dismissed error stays away until the build fails differently.
    if (!key || key === dismissed) return;
    dismissed = '';

    overlay = document.createElement('typedpress-dev-error');
    const root = overlay.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = `
      :host { position: fixed; inset: 0; z-index: 2147483647; display: grid;
        align-items: start; overflow: auto; background: #120e18df; color: #fff; }
      section { box-sizing: border-box; width: min(48rem, calc(100% - 2rem));
        margin: 8vh auto; padding: 1.5rem; border: 1px solid #69465a;
        border-top: .25rem solid #ff8c9b; border-radius: .5rem; background: #221b27;
        font: 1rem/1.5 system-ui, sans-serif; overflow-wrap: anywhere; }
      header { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin: 0 0 1rem; }
      h1 { margin: 0; font-size: 1.25rem; }
      button { padding: .25rem .75rem; border: 1px solid #69465a; border-radius: .25rem;
        background: none; color: inherit; font: inherit; cursor: pointer; }
      button:hover, button:focus-visible { border-color: #ffadb6; }
      a { color: #ffadb6; }
      code, pre { font-family: ui-monospace, monospace; }
      pre { white-space: pre-wrap; }
    `;
    const panel = document.createElement('section');
    panel.setAttribute('role', 'alert');
    const top = document.createElement('header');
    const heading = document.createElement('h1');
    heading.textContent = 'TypedPress build failed';
    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'Dismiss';
    close.addEventListener('click', dismiss);
    top.append(heading, close);
    panel.append(top);
    for (const diagnostic of diagnostics) {
      const { code, location: place, message, help } = diagnostic;
      if (code) {
        const link = document.createElement('a');
        link.href = `https://spacepress.dev/docs/reference/language-support#:~:text=${encodeURIComponent(code)}`;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = `error[${code}]`;
        panel.append(link);
      }
      if (place) {
        const file = document.createElement('p');
        const label = document.createElement('code');
        label.textContent = `${place.file}:${place.line}:${place.column}`;
        file.append(label);
        panel.append(file);
      }
      const detail = document.createElement('pre');
      detail.textContent = help ? `${message}\n\n${help}` : message;
      panel.append(detail);
    }
    root.append(style, panel);
    document.body.append(overlay);
  }

  // The reload this tab already made for `build`, kept across it. A page that still carries another
  // build afterwards is a stored copy (a cache in front of the site): reloading again would loop.
  // A document rendered from the live build forgets it, so a page this tab shows again from history
  // (Back restores it with its old build) still reloads for that build.
  function settled() {
    try {
      sessionStorage.removeItem(RELOADED);
    } catch {
      // No storage: nothing was kept.
    }
  }

  function reloadedFor(build) {
    try {
      if (sessionStorage.getItem(RELOADED) === build) return true;
      sessionStorage.setItem(RELOADED, build);
    } catch {
      // No storage (a sandboxed page): reload, as a page with storage does the first time.
    }
    return false;
  }

  async function check() {
    // A tab nobody looks at asks nothing; it catches up the moment it is shown.
    if (document.hidden) {
      waiting = true;
      return;
    }
    let live = null;
    try {
      const response = await fetch(state, { cache: 'no-store' });
      if (response.ok) live = await response.json();
    } catch {
      // The site is restarting or stopped: ask again, less often.
    }
    if (live) {
      const build = `${live.session}.${live.revision}`;
      if (build === rendered) settled();
      if (build !== loaded) {
        if (!reloadedFor(build)) {
          // A reload can be cancelled (the block editor asks before it drops unsaved changes): the
          // page stays, so it keeps asking. A reload that goes ahead drops this timer with the page.
          setTimeout(check, QUICK);
          location.reload();
          return;
        }
        loaded = build;
      }
      show(live.diagnostics ?? []);
    }
    setTimeout(check, live ? QUICK : SLOW);
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && waiting) {
      waiting = false;
      void check();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && overlay) dismiss();
  });
  void check();
})();
