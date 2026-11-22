import { randomUUID } from 'node:crypto';

// What open pages of the dev site follow: one small file, live.json, that Studio serves beside the
// reload client (live-client.js). The client reads it from the site's own origin, so the signal
// reaches a browser wherever the site is opened (`--url` behind a proxy, `--host`, https) and no
// PHP worker is held open for it.
//
// `session` names this `typedpress dev` process and `revision` counts its builds that changed the
// site: a page rendered at another pair reloads. `diagnostics` is the failed build's errors, shown
// over the page until a build succeeds.
export async function liveUpdates({ publish }) {
  const session = randomUUID();
  let revision = 0;
  let diagnostics = [];
  await publish({ session, revision, diagnostics });
  return {
    async updated(reload) {
      await publish({ session, revision: revision + (reload ? 1 : 0), diagnostics: [] });
      if (reload) revision++;
      diagnostics = [];
    },
    async failed(error) {
      const reported = error.diagnostics?.length ? error.diagnostics : [{ message: error.message }];
      await publish({ session, revision, diagnostics: reported });
      diagnostics = reported;
    },
    // No session: pages rendered from now on carry no client, and open ones wait for the next start.
    close: () => publish(null),
  };
}
