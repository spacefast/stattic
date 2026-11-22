import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

// Loads a browser entry from Vite in place of its built launcher. A launcher the state file does
// not name (a later build renamed it, or dev is not serving) loads as built.
export const FRONTEND_PLUGIN = `<?php
// Written by typedpress dev. Local development only.
add_filter('script_module_loader_src', static function ($src, $id) {
    if (!str_starts_with($id, 'typedpress-app-script-')) return $src;
    $path = wp_parse_url($src, PHP_URL_PATH);
    if (!is_string($path) || strtok($src, '?#') !== home_url($path)) return $src;
    $state = __DIR__ . '/typedpress-frontend/launchers.json';
    if (!is_readable($state)) return $src;
    $launchers = json_decode((string) file_get_contents($state), true);
    $vite = is_array($launchers) ? ($launchers[$path] ?? null) : null;
    if (!is_string($vite)) return $src;
    // A path is on the site's own address, where a proxy passes /__tp_vite/ on to Vite.
    return str_starts_with($vite, '/') ? home_url($vite) : $vite;
}, 10, 2);
`;

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]']);

// Where a browser that has the site open reaches Vite, which listens on `host`:
//   ''      the site's own address: something in front of the site passes /__tp_vite/ on to Vite,
//           found by asking the site for Vite's ping. The only way for an https site, whose pages
//           may not load scripts over http.
//   origin  Vite's own http address, when the browser is where it listens: the site is opened on
//           this machine, or on the address --host names.
//   null    nowhere: the site is opened from another machine through a proxy that passes only
//           WordPress on.
export async function viteAddress({ site, local, host, listen, port, ping, token, fetcher = fetch }) {
  const announced = new URL(site);
  if (announced.origin !== new URL(local).origin) {
    try {
      const answer = await fetcher(`${announced.origin}${ping}`, { signal: AbortSignal.timeout(3000) });
      if (answer.ok && (await answer.text()).trim() === token) return '';
    } catch {
      // Not reachable from here, or the path is not passed on: decided below.
    }
  }
  if (announced.protocol !== 'http:') return null;
  const direct = `http://${host.includes(':') ? `[${host}]` : host}:${port}`;
  if (listen) return direct;
  return LOOPBACK.has(announced.hostname) ? direct : null;
}

// The built launchers of the recorded entries, each with the address that takes its place.
export const launcherMap = (entries, paths) => Object.fromEntries(Object.entries(entries)
  .flatMap(([name, entry]) => name in paths ? entry.scripts.map((launcher) => [launcher, paths[name]]) : []));

export async function startFrontend(project, managed, { site, local, host, listen, watch, write }) {
  const mu = join(managed.owner.site, 'wp-content/mu-plugins/typedpress-frontend.php');
  const state = join(dirname(mu), 'typedpress-frontend/launchers.json');
  if (managed.owner.frontendMu) await write(state, null);
  if (!watch) return;

  const require = createRequire(join(project, 'package.json'));
  let module;
  try { module = require.resolve('@typedpress/frontend'); }
  catch (error) {
    if (error.code === 'MODULE_NOT_FOUND') return;
    throw error;
  }
  const record = async () => {
    try { return JSON.parse(await readFile(join(project, '.tp/frontend-dev.json'), 'utf8')); }
    catch (error) {
      if (error.code === 'ENOENT') return null;
      throw error;
    }
  };
  const settings = await record();
  const entries = Object.entries(settings?.entries ?? {}).filter(([, entry]) => entry.scripts.length);
  if (!entries.length) return;

  if (!managed.owner.frontendMu && await access(mu).then(() => true, () => false)) {
    throw new Error('typedpress-frontend.php already exists and is not managed by TypedPress.');
  }
  await mkdir(dirname(state), { recursive: true });
  await writeFile(mu, FRONTEND_PLUGIN);
  managed.owner.frontendMu = true;
  await write(managed.marker, managed.owner);
  // Vite reads NODE_ENV to choose React's development runtime and enable Refresh. It is set for
  // this process alone: Studio and the compiler are started with the environment dev was given.
  process.env.NODE_ENV = 'development';
  const { serveFrontend } = await import(pathToFileURL(module));
  const server = await serveFrontend({
    root: project, host, site, frameworks: settings.frameworks, entries: Object.fromEntries(entries),
    // The port of the last start, so a proxy pointed at it stays right; another when it is taken.
    port: managed.owner.frontendPort ?? 0,
    reach: (vite) => viteAddress({ site, local, host, listen, ...vite }),
  });
  if (managed.owner.frontendPort !== server.port) {
    managed.owner.frontendPort = server.port;
    await write(managed.marker, managed.owner);
  }
  if (!server.paths) {
    console.log(`Fast Refresh is off: ${site} does not pass /__tp_vite/ on to Vite, so browser entries load as built and each save rebuilds them. To turn it on, point that path of your proxy at http://localhost:${server.port}/__tp_vite/ and start again.`);
    return;
  }
  const publish = async () => {
    const current = await record();
    if (!current?.entries) return;
    server.update(current.entries);
    await write(state, launcherMap(current.entries, server.paths));
  };
  try {
    await publish();
    return {
      // Every recorded entry is served from its source, so the compiler need not rebuild the
      // bundle on each save. An entry with no launcher (a stylesheet entry) still loads as built.
      serving: Object.values(settings.entries).every((entry) => entry.scripts.length > 0),
      // After a build: a rebuilt bundle names its launchers by content, so the map follows it.
      refresh: publish,
      async close() {
        try { await write(state, null); }
        finally { await server.close(); }
      },
    };
  } catch (error) {
    await server.close();
    throw error;
  }
}
