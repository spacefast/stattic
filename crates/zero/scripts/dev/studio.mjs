import { spawn } from 'node:child_process';
import { watch } from 'node:fs';
import { access, cp, lstat, mkdir, open, readFile, readdir, realpath, rename, rm, writeFile } from 'node:fs/promises';
import { createHash, randomUUID } from 'node:crypto';
import { createServer } from 'node:net';
import { delimiter, dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const STUDIO_VERSION = '2.1.1';
const WORDPRESS_VERSION = '7.1.4';
const here = dirname(fileURLToPath(import.meta.url));
const exists = async (path) => access(path).then(() => true, () => false);
const json = async (path) => JSON.parse(await readFile(path, 'utf8'));
const inside = (parent, path) => {
  const rel = relative(parent, path);
  return rel === '' || (!isAbsolute(rel) && rel !== '..' && !rel.startsWith(`..${sep}`));
};

async function atomicJson(path, value) {
  const temp = `${path}.${randomUUID()}.tmp`;
  await writeFile(temp, JSON.stringify(value, null, 2) + '\n', { mode: 0o600 });
  await rename(temp, path);
}

async function studioBinary() {
  // npx supplies the pinned package on PATH; don't depend on Studio's private JS exports.
  for (const directory of process.env.PATH.split(delimiter)) {
    const npmPackage = resolve(directory, '../wp-studio/package.json');
    if (await exists(npmPackage)) {
      const pkg = await json(npmPackage);
      if (pkg.name === 'wp-studio' && pkg.version === STUDIO_VERSION) return join(dirname(npmPackage), 'dist/cli/main.mjs');
    }
    const candidate = join(directory, 'studio');
    if (!await exists(candidate)) continue;
    const main = await realpath(candidate);
    const packagePath = resolve(dirname(main), '../../package.json');
    if (!await exists(packagePath)) continue;
    const pkg = await json(packagePath);
    if (pkg.name === 'wp-studio' && pkg.version === STUDIO_VERSION) return main;
  }
  throw new Error(`Cannot find wp-studio@${STUDIO_VERSION}. Run zero dev with Node.js 22+ and npm installed.`);
}

function execute(file, args, { capture = false, timeout = 180000, env = process.env, input = 'ignore' } = {}) {
  return new Promise((resolveResult, reject) => {
    const child = spawn(file, args, { stdio: [input, capture ? 'pipe' : 'inherit', capture ? 'pipe' : 'inherit'], env });
    let stdout = '', stderr = '', timedOut = false;
    const timer = setTimeout(() => { timedOut = true; child.kill('SIGTERM'); }, timeout);
    child.stdout?.on('data', (chunk) => { stdout += chunk; });
    child.stderr?.on('data', (chunk) => { stderr += chunk; });
    child.once('error', (error) => { clearTimeout(timer); reject(error); });
    child.once('close', (code, signal) => {
      clearTimeout(timer);
      if (code === 0) resolveResult(stdout);
      else reject(new Error(`${timedOut ? 'Timed out' : `Failed (${code ?? signal})`}: ${args[0]}\n${stderr || stdout}`));
    });
  });
}

async function plainDirectory(path) {
  if ((await lstat(path)).isSymbolicLink()) throw new Error(`Refusing a symbolic link at ${path}`);
  if (!(await lstat(path)).isDirectory()) throw new Error(`Expected a directory at ${path}`);
}

export async function ownedState(project, create) {
  const zero = join(project, '.zero');
  if (await exists(zero)) await plainDirectory(zero);
  else if (create) await mkdir(zero);
  const state = join(zero, 'dev');
  const marker = join(state, 'owner.json');
  if (await exists(state)) {
    await plainDirectory(state);
    if (!await exists(marker)) throw new Error(`Unowned development directory: ${state}. Move it aside after inspecting it.`);
    const owner = await json(marker);
    if (owner.version !== 1 || owner.project !== project || owner.site !== join(state, 'wordpress')) {
      throw new Error(`Development site ownership does not match ${project}. Nothing was changed.`);
    }
    if (await exists(owner.site)) await plainDirectory(owner.site);
    return { state, marker, owner };
  }
  if (!create) throw new Error('No local development site. Run zero dev first.');
  await mkdir(state, { mode: 0o700 });
  const owner = { version: 1, project, site: join(state, 'wordpress'), generated: [], created: false };
  await atomicJson(marker, owner);
  return { state, marker, owner };
}

export async function lock(state) {
  const path = join(state, 'session.json');
  const gate = join(state, 'session-claim');
  try { await mkdir(gate); }
  catch (error) {
    if (error.code !== 'EEXIST') throw error;
    throw new Error('Another command is acquiring the development site. If it was interrupted, inspect .zero/dev/session-claim.');
  }
  try {
    if (await exists(path)) {
      const session = await json(path).catch(() => null);
      if (!Number.isSafeInteger(session?.pid) || session.pid <= 0) throw new Error('Development lock is incomplete; inspect .zero/dev/session.json before removing it.');
      let active = true;
      try { process.kill(session.pid, 0); }
      catch (error) { if (error.code !== 'ESRCH') throw error; active = false; }
      if (active) throw new Error('Another zero dev command owns this project. Use zero dev stop first.');
      await rm(path);
    }
    const file = await open(path, 'wx', 0o600);
    try { await file.writeFile(JSON.stringify({ pid: process.pid })); }
    finally { await file.close(); }
    return () => rm(path, { force: true });
  } finally {
    await rm(gate, { recursive: true });
  }
}

async function migrationHistory(project) {
  const directory = join(project, 'zero/migrations');
  if (!await exists(directory)) return {};
  const history = {};
  for (const name of (await readdir(directory)).filter((name) => name.endsWith('.sql')).sort()) {
    history[name] = createHash('sha256').update(await readFile(join(directory, name))).digest('hex');
  }
  return history;
}

async function availablePort(port) {
  await new Promise((resolvePort, reject) => {
    const server = createServer();
    server.once('error', () => reject(new Error(`Port ${port} is occupied. Choose another with --port.`)));
    server.listen(port, '127.0.0.1', () => server.close(resolvePort));
  });
}

export async function publishPlugins(content, managed) {
  const manifest = await json(join(content, 'zero-manifest.json'));
  if (managed.owner.app && managed.owner.app !== manifest.app) {
    throw new Error('Changing the application name needs a separate local site; the existing app and data were preserved.');
  }
  const plugins = Object.keys(manifest.plugins);
  if (!plugins.every((slug) => /^[a-z0-9][a-z0-9-]*$/.test(slug))) throw new Error('Invalid plugin slug in build manifest.');
  const target = join(managed.owner.site, 'wp-content/plugins');
  await plainDirectory(target);
  // Preflight the entire set before replacing anything. Extra plugins belong to the site owner.
  for (const slug of plugins) {
    const destination = join(target, slug);
    if (await exists(destination)) {
      await plainDirectory(destination);
      if (!managed.owner.generated.includes(slug)) throw new Error(`Plugin ${slug} already exists and is not managed by Zero. Move or rename it before continuing.`);
    }
  }
  for (const slug of plugins) {
    const destination = join(target, slug);
    const stage = join(target, `.zero-${slug}-${randomUUID()}`);
    const backup = `${stage}.previous`;
    await cp(join(content, 'plugins', slug), stage, { recursive: true, dereference: true });
    const previous = await exists(destination);
    try {
      if (previous) await rename(destination, backup);
      await rename(stage, destination);
    } catch (error) {
      if (previous && await exists(backup) && !await exists(destination)) await rename(backup, destination);
      throw error;
    } finally {
      await rm(stage, { recursive: true, force: true });
    }
    await rm(backup, { recursive: true, force: true });
    if (!managed.owner.generated.includes(slug)) {
      managed.owner.generated.push(slug);
      await atomicJson(managed.marker, managed.owner);
    }
  }
  const activate = [...manifest.dependencies.map((d) => d.slug), 'zero-runtime'];
  for (const slug of ['zero-identity', `zero-app-${manifest.app}`, 'zero-admin']) {
    if (plugins.includes(slug)) activate.push(slug);
  }
  const spec = {
    site: managed.owner.site,
    wordpress: '7.1', sqlite: '3.1.2', localized: manifest.locales !== null,
  };
  await atomicJson(join(managed.state, 'setup.json'), spec);
  managed.owner.app = manifest.app;
  await atomicJson(managed.marker, managed.owner);
  return { manifest, activate };
}

export async function sourceDigest(project) {
  const digest = createHash('sha256');
  async function visit(directory, prefix = '') {
    const entries = await readdir(directory, { withFileTypes: true });
    entries.sort((a, b) => a.name.localeCompare(b.name));
    for (const entry of entries) {
      const name = prefix + entry.name;
      if (['.zero', '.git', 'node_modules', 'zero'].includes(entry.name) || name === 'zero.gen.ts' || entry.isSymbolicLink()) continue;
      const path = join(directory, entry.name);
      if (entry.isDirectory()) await visit(path, `${name}/`);
      else if (/\.[cm]?[jt]sx?$/.test(name) || name.startsWith('public/')) {
        digest.update(name); digest.update(await readFile(path));
      }
    }
  }
  await visit(project);
  return digest.digest('hex');
}

// Native recursive watchers descend into generated trees before event filters run.
// Watch authored directories individually so rebuild output is never traversed.
export async function watchSources(project, onChange, onError) {
  const ignored = new Set(['.zero', '.git', 'node_modules', 'zero']);
  const watchers = new Map();
  let closed = false, refreshing, refreshAgain = false;
  const close = () => {
    closed = true;
    for (const { watcher } of watchers.values()) watcher.close();
    watchers.clear();
  };
  const changed = () => { if (!closed) onChange(); };
  const failed = (error) => { if (!closed) onError(error); };
  async function refresh() {
    if (closed) return;
    if (refreshing) { refreshAgain = true; return refreshing; }
    refreshing = (async () => {
      do {
        refreshAgain = false;
        const present = new Set();
        async function visit(directory) {
          if (closed) return;
          try {
            const identity = await lstat(directory);
            if (closed || !identity.isDirectory()) return;
            const previous = watchers.get(directory);
            if (previous && (previous.device !== identity.dev || previous.inode !== identity.ino)) {
              previous.watcher.close(); watchers.delete(directory);
            }
            if (!watchers.has(directory)) {
              const watcher = watch(directory, (event, filename) => {
                if (closed || !filename || ignored.has(String(filename))) return;
                const name = relative(project, join(directory, String(filename)));
                if (name === 'zero.gen.ts') return;
                if (event === 'rename') {
                  // Discover new directories before notifying: files may have been created
                  // before their directory acquired its own watch handle.
                  void refresh().then(changed, failed);
                } else if (/\.[cm]?[jt]sx?$/.test(name) || name.split(/[\\/]/)[0] === 'public') {
                  changed();
                }
              });
              watcher.on('error', failed);
              watchers.set(directory, { watcher, device: identity.dev, inode: identity.ino });
            }
            present.add(directory);
            for (const entry of await readdir(directory, { withFileTypes: true })) {
              if (entry.isDirectory() && !ignored.has(entry.name)) await visit(join(directory, entry.name));
            }
          } catch (error) {
            if (!['ENOENT', 'ENOTDIR'].includes(error.code)) throw error;
            watchers.get(directory)?.watcher.close(); watchers.delete(directory);
            present.delete(directory);
          }
        }
        await visit(project);
        for (const [directory, { watcher }] of watchers) {
          if (!present.has(directory)) { watcher.close(); watchers.delete(directory); }
        }
      } while (refreshAgain && !closed);
    })();
    try { await refreshing; }
    finally { refreshing = undefined; }
  }
  try { await refresh(); }
  catch (error) { close(); throw error; }
  return { close };
}

async function main() {
  if (Number(process.versions.node.split('.')[0]) < 22) throw new Error('zero dev requires Node.js 22 or newer.');
  const [mode, directory, zero, output, requestedPort = '8787', ...flags] = process.argv.slice(2);
  const noWatch = flags.includes('--no-watch');
  const project = await realpath(directory);
  const managed = await ownedState(project, mode === 'start');
  const studio = await studioBinary();
  const runStudio = (args, options) => execute(process.execPath, [studio, ...args, '--path', managed.owner.site], options);
  if (mode === 'wp') {
    await runStudio(['wp', ...process.argv.slice(4)], { input: 'inherit' });
    return;
  }
  if (mode === 'status') {
    await runStudio(['status', '--format', 'json']);
    return;
  }
  if (mode === 'stop') {
    if (await exists(join(managed.state, 'session.json'))) {
      const session = await json(join(managed.state, 'session.json'));
      try { process.kill(session.pid, 0); }
      catch (error) {
        if (error.code !== 'ESRCH') throw error;
        const release = await lock(managed.state);
        try { await runStudio(['stop']); } finally { await release(); }
        return;
      }
      await writeFile(join(managed.state, 'stop'), 'stop\n');
      await new Promise((resolveStopped, reject) => {
        const stateWatcher = watch(managed.state, () => {
          exists(join(managed.state, 'session.json')).then((locked) => { if (!locked) done(); });
        });
        const timer = setTimeout(() => done(new Error('Stop requested; the current build has not finished yet.')), 300000);
        const done = (error) => { stateWatcher.close(); clearTimeout(timer); error ? reject(error) : resolveStopped(); };
        stateWatcher.on('error', done);
        exists(join(managed.state, 'session.json')).then((locked) => { if (!locked) done(); });
      });
      console.log('WordPress stopped; local data was preserved.');
    } else {
      const release = await lock(managed.state);
      try { await runStudio(['stop']); } finally { await release(); }
    }
    return;
  }
  if (mode !== 'start') throw new Error(`Unknown dev command: ${mode}`);
  const content = resolve(output);
  if (inside(content, managed.state) || inside(managed.state, content)) throw new Error('--out must not overlap .zero/dev.');
  const release = await lock(managed.state);
  await rm(join(managed.state, 'stop'), { force: true });
  let watcher, timer, stopTimer, stopping = false, running = false, dirty = false, siteStarted = false, siteUrl;
  let finish;
  const finished = new Promise((resolveDone) => { finish = resolveDone; });
  const stop = async () => {
    if (stopping) return;
    stopping = true;
    watcher?.close(); clearTimeout(timer); clearInterval(stopTimer);
    if (!running) finish();
  };
  process.on('SIGINT', stop); process.on('SIGTERM', stop);
  const build = async (allowDestructive = false) => {
    const args = ['build', project, '--out', content];
    if (managed.owner.snapshot && !allowDestructive) {
      // A prebuilt/edited migration can hide an intermediate destructive step even if the final
      // snapshot looks unchanged. Only the dev-owned build may extend the accepted history.
      const history = JSON.stringify(await migrationHistory(project));
      if (history !== JSON.stringify(managed.owner.migrations) && history !== JSON.stringify(managed.owner.pending?.migrations)) {
        throw new Error('Migration history changed outside zero dev. Inspect it, then restart once with --allow-destructive to apply deliberately.');
      }
      const snapshot = join(managed.state, 'preserve.json');
      await atomicJson(snapshot, managed.owner.pending?.snapshot ?? managed.owner.snapshot);
      args.push('--preserve-data', snapshot);
    }
    await execute(zero, args, { timeout: 300000 });
    managed.owner.pending = {
      migrations: await migrationHistory(project),
      snapshot: await json(join(project, 'zero/migrations/snapshot.json')),
    };
    await atomicJson(managed.marker, managed.owner);
  };
  const configure = async () => {
    const snapshot = await json(join(project, 'zero/migrations/snapshot.json'));
    const manifest = await json(join(content, 'zero-manifest.json'));
    const retired = managed.owner.generated.filter((slug) => !(slug in manifest.plugins));
    for (const slug of retired) await plainDirectory(join(managed.owner.site, 'wp-content/plugins', slug));
    if (retired.length) await runStudio(['wp', 'plugin', 'deactivate', ...retired]);
    const { activate } = await publishPlugins(content, managed);
    const active = JSON.parse(await runStudio(['wp', 'plugin', 'list', '--status=active', '--format=json'], { capture: true })).map((plugin) => plugin.name);
    // Each activation gets a fresh WordPress bootstrap, including plugins_loaded dependencies.
    for (const slug of activate) {
      if (!active.includes(slug)) await runStudio(['wp', 'plugin', 'activate', slug]);
    }
    await runStudio(['wp', 'eval-file', join(here, 'setup.php'), join(managed.state, 'setup.json'), '--use-include']);
    await runStudio(['wp', 'zero', 'migrate']);
    await runStudio(['wp', 'zero-identity', 'app', 'add', '--name=Zero development', '--id=app_zero_dev',
      `--redirect=${siteUrl}/auth/callback`, `--origin=${siteUrl}`]);
    for (const slug of retired) await rm(join(managed.owner.site, 'wp-content/plugins', slug), { recursive: true });
    managed.owner.generated = Object.keys(manifest.plugins);
    managed.owner.snapshot = snapshot;
    managed.owner.migrations = await migrationHistory(project);
    delete managed.owner.pending;
    await atomicJson(managed.marker, managed.owner);
  };
  try {
    running = true;
    if (!await exists(join(project, 'zero.config.ts'))) throw new Error(`No zero.config.ts in ${project}`);
    let digest = await sourceDigest(project);
    await build(flags.includes('--allow-destructive'));
    if (!managed.owner.created) {
      await availablePort(Number(requestedPort));
      if (await exists(managed.owner.site) && (await readdir(managed.owner.site)).length) {
        throw new Error('A previous Studio creation was interrupted. Inspect .zero/dev/wordpress; Zero will not adopt or delete it automatically.');
      }
      await runStudio(['create', '--name', `Zero ${project.split(sep).at(-1)}`, '--wp', WORDPRESS_VERSION, '--php', '8.4',
        '--admin-username', 'admin', '--admin-password', 'zero-test-admin', '--skip-browser', '--skip-log-details'],
        { timeout: 600000, env: { ...process.env, STUDIO_BASE_PORT: requestedPort } });
      managed.owner.created = true;
      await atomicJson(managed.marker, managed.owner);
    } else {
      await runStudio(['start', '--skip-browser', '--skip-log-details']);
    }
    siteStarted = true;
    const status = JSON.parse(await runStudio(['status', '--format', 'json'], { capture: true }));
    const url = status.siteUrl.replace(/\/$/, '');
    siteUrl = url;
    const mu = join(managed.owner.site, 'wp-content/mu-plugins/zero-dev-runtime.php');
    if (!managed.owner.devMu && await exists(mu)) throw new Error('zero-dev-runtime.php already exists and is not managed by Zero.');
    await writeFile(mu, "<?php\n// Local development only: allow identity callbacks on the site's HTTP origin.\nif (!defined('ZERO_IDENTITY_DEV_HTTP')) define('ZERO_IDENTITY_DEV_HTTP', true);\n");
    managed.owner.devMu = true;
    await atomicJson(managed.marker, managed.owner);
    await configure();
    const response = await fetch(`${url}/wp-json/zero/v1/_schema`, { signal: AbortSignal.timeout(15000) });
    if (!response.ok) throw new Error(`Zero application is not ready: HTTP ${response.status}. Run zero doctor --site ${url}`);
    console.log(`\nWebsite:   ${url}/\nWordPress: ${url}/wp-admin/\nPlugins:   ${url}/wp-admin/plugins.php`);
    if (await exists(join(project, 'public/index.html'))) console.log(`App:       ${url}/wp-content/plugins/zero-app-${encodeURIComponent(managed.owner.app)}/public/index.html`);
    if (managed.owner.generated.includes('zero-admin')) console.log(`Zero Admin: ${url}/zero-admin/`);
    console.log('Initial login: admin / zero-test-admin (local development only; unchanged if you edited it)\n');
    console.log(`Data persists in ${managed.owner.site}\nStop: zero dev stop ${JSON.stringify(project)}`);
    running = false;
    if (await exists(join(managed.state, 'stop'))) stopping = true;
    if (noWatch) return;
    if (stopping) return;
    async function rebuild() {
      if (running || stopping) { dirty = !stopping; return; }
      running = true;
      try {
        do {
          dirty = false;
          const next = await sourceDigest(project);
          if (next === digest) continue;
          digest = next;
          console.log('\nSources changed; rebuilding…');
          try { await build(); await configure(); console.log('Updated. Refresh the page to see your changes.'); }
          catch (error) { console.error(error.message); console.error('Fix the error and save to retry.'); }
        } while (dirty && !stopping);
      } catch (error) { console.error(error.message); }
      finally { running = false; if (stopping) finish(); }
    }
    watcher = await watchSources(project, () => {
      if (stopping) return;
      dirty = true; clearTimeout(timer); timer = setTimeout(rebuild, 200);
    }, (error) => { console.error(`File watcher: ${error.message}`); void stop(); });
    stopTimer = setInterval(() => { exists(join(managed.state, 'stop')).then((requested) => { if (requested) void stop(); }); }, 250);
    console.log('Watching config, TypeScript and public files. Ctrl+C stops WordPress; content is retained.');
    void rebuild();
    await finished;
  } finally {
    watcher?.close(); clearTimeout(timer); clearInterval(stopTimer);
    process.off('SIGINT', stop); process.off('SIGTERM', stop);
    if (await exists(join(managed.state, 'stop'))) stopping = true;
    if (siteStarted && (stopping || !noWatch)) {
      await runStudio(['stop']).catch((error) => console.error(error.message));
    }
    await rm(join(managed.state, 'stop'), { force: true });
    await release();
  }
}

if (process.argv[1] && pathToFileURL(resolve(process.argv[1])).href === import.meta.url) {
  main().catch((error) => { console.error(`zero dev: ${error.message}`); process.exitCode = 1; });
}
