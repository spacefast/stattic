import { spawn } from 'node:child_process';
import { watch } from 'node:fs';
import { once } from 'node:events';
import { access, cp, lstat, mkdir, mkdtemp, open, readFile, readdir, realpath, rename, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { createHash, randomUUID } from 'node:crypto';
import { BlockList, connect, createServer, isIP } from 'node:net';
import { lookup } from 'node:dns/promises';
import { delimiter, dirname, isAbsolute, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const STUDIO_VERSION = '2.2.0';
// The supported floor (src/platform.rs WORDPRESS). Studio's version picker only offers current releases,
// so the floor resolves to the stable update WordPress offers from it; setup.php also runs Platform::problems().
const WORDPRESS_VERSION = '7.1';
const modulePath = await realpath(fileURLToPath(import.meta.url));
const here = dirname(modulePath);
const exists = async (path) => access(path).then(() => true, () => false);
const json = async (path) => JSON.parse(await readFile(path, 'utf8'));
export async function wordpressVersion(fetcher = fetch) {
  try {
    const response = await fetcher(`https://api.wordpress.org/core/version-check/1.7/?version=${WORDPRESS_VERSION}`, { signal: AbortSignal.timeout(15000) });
    if (!response.ok) return WORDPRESS_VERSION;
    const data = await response.json();
    const update = data.offers?.find((offer) => ['autoupdate', 'upgrade'].includes(offer.response));
    return typeof update?.version === 'string' ? update.version : WORDPRESS_VERSION;
  } catch {
    // Keep the supported floor when the update service is unavailable.
    return WORDPRESS_VERSION;
  }
}
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
  throw new Error(`Cannot find wp-studio@${STUDIO_VERSION}. Run typedpress dev with Node.js 22+ and npm installed.`);
}

function execute(file, args, { capture = false, log = false, timeout = 180000, env = process.env, input = 'ignore' } = {}) {
  return new Promise((resolveResult, reject) => {
    const output = capture || log ? 'pipe' : 'inherit';
    const child = spawn(file, args, { stdio: [input, output, output], env });
    let stdout = '', stderr = '', timedOut = false;
    const timer = setTimeout(() => { timedOut = true; child.kill('SIGTERM'); }, timeout);
    child.stdout?.on('data', (chunk) => { stdout += chunk; });
    child.stderr?.on('data', (chunk) => { stderr += chunk; });
    child.once('error', (error) => { clearTimeout(timer); reject(error); });
    child.once('close', (code, signal) => {
      clearTimeout(timer);
      if (code === 0) {
        if (log) { process.stdout.write(stdout); process.stderr.write(stderr); }
        resolveResult(stdout);
      } else reject(Object.assign(new Error(`${timedOut ? 'Timed out' : `Failed (${code ?? signal})`}: ${args[0]}\n${stderr || stdout}`), { stdout }));
    });
  });
}

async function plainDirectory(path) {
  if ((await lstat(path)).isSymbolicLink()) throw new Error(`Refusing a symbolic link at ${path}`);
  if (!(await lstat(path)).isDirectory()) throw new Error(`Expected a directory at ${path}`);
}

export async function ownedState(project, create) {
  const first = join(project, '.tp');
  if (await exists(first)) await plainDirectory(first);
  else if (create) await mkdir(first);
  const state = join(first, 'dev');
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
  if (!create) throw new Error('No local development site. Run typedpress dev first.');
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
    throw new Error('Another command is acquiring the development site. If it was interrupted, inspect .tp/dev/session-claim.');
  }
  try {
    if (await exists(path)) {
      const session = await json(path).catch(() => null);
      if (!Number.isSafeInteger(session?.pid) || session.pid <= 0) throw new Error('Development lock is incomplete; inspect .tp/dev/session.json before removing it.');
      let active = true;
      try { process.kill(session.pid, 0); }
      catch (error) { if (error.code !== 'ESRCH') throw error; active = false; }
      if (active) throw new Error('Another typedpress dev command owns this project. Use typedpress dev stop first.');
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

export async function migrationHistory(project) {
  const directory = join(project, 'tp/_generated/migrations');
  if (!await exists(directory)) return {};
  const history = {};
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0))) {
    if (!entry.isDirectory()) continue;
    for (const file of ['ops.json', 'migration.sql']) {
      const path = join(directory, entry.name, file);
      if (await exists(path)) {
        history[`${entry.name}/${file}`] = createHash('sha256').update(await readFile(path)).digest('hex');
      }
    }
  }
  return history;
}

async function appRevision(content) {
  const manifest = await readFile(join(content, 'typedpress-manifest.json'));
  const { app } = JSON.parse(manifest);
  const plugin = await readFile(join(content, 'plugins', `typedpress-app-${app}.zip`));
  return createHash('sha256').update(manifest).update(plugin).digest('hex');
}

const fileHash = async (path) => createHash('sha256').update(await readFile(path)).digest('hex');

// What identifies each plugin's files in a build: the hash of the ZIP the build wrote beside the
// folder (TypedPress's own plugins), else the tree hash the manifest pins (a dependency), else the
// version. A plugin whose print is unchanged since the last publish is not copied again.
export async function pluginPrints(content, manifest) {
  const prints = {};
  for (const [slug, version] of Object.entries(manifest.plugins)) {
    const zip = join(content, 'plugins', `${slug}.zip`);
    prints[slug] = await exists(zip) ? await fileHash(zip)
      : manifest.dependencies.find((dependency) => dependency.slug === slug)?.treeSha256 ?? String(version);
  }
  return prints;
}

async function availablePort(port) {
  await new Promise((resolvePort, reject) => {
    const server = createServer();
    server.once('error', () => reject(new Error(`Port ${port} is occupied. Choose another with --port.`)));
    server.listen(port, '127.0.0.1', () => server.close(resolvePort));
  });
}

// `changed(slug)` says whether a plugin's files differ from what the site holds; by default all do.
export async function publishPlugins(content, managed, changed = () => true) {
  const manifest = await json(join(content, 'typedpress-manifest.json'));
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
      if (!managed.owner.generated.includes(slug)) throw new Error(`Plugin ${slug} already exists and is not managed by TypedPress. Move or rename it before continuing.`);
    }
  }
  for (const slug of plugins) {
    const destination = join(target, slug);
    if (!changed(slug) && managed.owner.generated.includes(slug) && await exists(destination)) continue;
    const stage = join(target, `.tp-${slug}-${randomUUID()}`);
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
  const activate = [...manifest.dependencies.map((d) => d.slug), 'typedpress-runtime'];
  for (const slug of ['typedpress-identity', `typedpress-app-${manifest.app}`, 'typedpress-admin']) {
    if (plugins.includes(slug)) activate.push(slug);
  }
  const spec = {
    site: managed.owner.site,
    wordpress: WORDPRESS_VERSION, sqlite: '3.1.2', localized: manifest.locales !== null,
  };
  await atomicJson(join(managed.state, 'setup.json'), spec);
  if (managed.owner.app !== manifest.app) {
    managed.owner.app = manifest.app;
    await atomicJson(managed.marker, managed.owner);
  }
  return { manifest, activate, spec };
}

// `typedpress dev --url` and `--host`: the address WordPress writes into every page (`url`), and the
// address of this machine TypedPress listens on (`listen`). Studio 2.2.0 serves a site on localhost
// only, and names it http://localhost:<port> or a `.local` domain behind its own proxy on ports
// 80 and 443, so neither is Studio's to set.
// A host name as DNS spells it: letters, digits and hyphens between dots. Nothing else reaches the
// PHP file addressPhp writes, or the address WordPress prints into pages.
const HOSTNAME = /^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i;

// An IP address that stands for every interface: 0.0.0.0 or ::, however it is spelled (Node's own
// address matching, which also reads IPv4-mapped IPv6 such as ::ffff:0:0).
const EVERY = new BlockList();
EVERY.addAddress('0.0.0.0', 'ipv4');
EVERY.addAddress('::', 'ipv6');
const unspecified = (ip) => isIP(ip) !== 0 && EVERY.check(ip, isIP(ip) === 6 ? 'ipv6' : 'ipv4');

export function siteAddress(flags, port) {
  const value = (name) => flags.find((flag) => flag.startsWith(`--${name}=`))?.slice(name.length + 3);
  const host = value('host'), announced = value('url');
  let url = null;
  if (host !== undefined) {
    // WordPress writes one address into every page, so "every interface" names none. A bare number
    // (`0`, `2130706433`) is an IPv4 address to the resolver, so it is refused too.
    // A scoped IPv6 address (fe80::1%eth0) has no form in a URL WordPress can write.
    const named = isIP(host) ? !unspecified(host) && !host.includes('%') : HOSTNAME.test(host) && !/^[0-9.]+$/.test(host);
    if (!named) throw new Error('--host takes one address or name of this machine, such as 10.0.0.24 (ip -4 addr lists them).');
    if (flags.includes('--no-watch')) throw new Error('--host needs typedpress dev to keep running, because that process is what listens there. Use --detach instead of --no-watch.');
    url = `http://${isIP(host) === 6 ? `[${host}]` : host}:${port}`;
  }
  if (announced !== undefined) {
    const parsed = URL.canParse(announced) ? new URL(announced) : null;
    if (!parsed || !['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password || parsed.pathname !== '/' || parsed.search || parsed.hash) {
      throw new Error('--url takes an address such as https://box.example.ts.net:8443, with no path: WordPress serves the site at its root.');
    }
    const name = parsed.hostname.replace(/^\[(.*)\]$/, '$1');
    if (!(isIP(name) ? !unspecified(name) : HOSTNAME.test(name))) {
      throw new Error('--url takes an address such as https://box.example.ts.net:8443: a host name of letters, digits, hyphens and dots, or an IP address.');
    }
    url = parsed.origin;
  }
  return { url, listen: host ?? null };
}

// --host as the address the relay binds: a name resolved once, here, so what is checked is what is
// bound, and an address standing for every interface is refused whatever spelled it.
export async function listenAddress(host) {
  const { address } = await lookup(host);
  if (unspecified(address)) throw new Error(`--host ${host} is ${address}, which is every interface of this machine; name one address of it.`);
  return address;
}

// A PHP single-quoted string holding `text`.
const phpString = (text) => `'${text.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;

// What WordPress reads before it loads. `home` and `siteurl` are answered before Studio's WP_HOME
// and WP_SITEURL (http://localhost:<port>, defined ahead of wp-config.php) are consulted, and early
// enough for the content and plugin URLs WordPress fixes before any plugin runs. The request's own
// host and scheme are the address too, as WordPress documents for a site behind a proxy, but from
// this file instead of a header any client can send.
export const addressPhp = (url) => `<?php
// Written by typedpress dev --url / --host, and removed by a start without them.
$tp_dev_url = ${phpString(url)};
$_SERVER['HTTP_HOST'] = substr( $tp_dev_url, strpos( $tp_dev_url, '://' ) + 3 );
if ( 0 === strpos( $tp_dev_url, 'https://' ) ) {
	$_SERVER['HTTPS'] = 'on';
}
$tp_dev_address = static function () use ( $tp_dev_url ) {
	return $tp_dev_url;
};
foreach ( array( 'pre_option_home', 'pre_option_siteurl' ) as $tp_dev_option ) {
	if ( function_exists( 'add_filter' ) ) {
		add_filter( $tp_dev_option, $tp_dev_address );
	} else {
		$GLOBALS['wp_filter'][ $tp_dev_option ][10][] = array( 'function' => $tp_dev_address, 'accepted_args' => 1 );
	}
}
unset( $tp_dev_url, $tp_dev_address, $tp_dev_option );
`;

const ADDRESS_FILE = 'tp-dev-address.php';
const ADDRESS_LINE = `if ( is_file( ABSPATH . '${ADDRESS_FILE}' ) ) { require ABSPATH . '${ADDRESS_FILE}'; } // typedpress dev --url / --host`;

// Where in wp-config.php the address line goes, found by PHP's own tokenizer: PHP code that reads
// the file at the path in $tp_config and prints the byte offset of the `require_once ABSPATH .
// 'wp-settings.php';` that loads WordPress, or -1 when the file is not shaped so that it surely is
// that one. The require must be the file's last code (only whitespace, comments, a `?>` and blank
// text after it), as in wp-config-sample.php and the configs WordPress and Studio write, and the
// code just before it must be a bare `;` or `}` (one that ends a statement or block, not a brace
// inside a string, which is no token of its own) or the open tag. A require in a string, a
// heredoc, a comment, an attribute or text outside PHP is not code; one in a function, class or
// braced block is followed by that block's `}`; one that is an unbraced if/else/while body
// follows `)` or `else`. Files
// with alternative control syntax (`if (…): … endif;`), which can leave a block open at the end of
// a file, are refused, and so are files that name wp-settings.php more than once or use return,
// exit, die, goto or __halt_compiler anywhere: the last require might then never run.
export const SETTINGS_OFFSET = String.raw`
$code = array();
$offset = 0;
foreach (token_get_all(file_get_contents($tp_config)) as $token) {
  $id = is_array($token) ? $token[0] : null;
  $text = is_array($token) ? $token[1] : $token;
  $skip = in_array($id, array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CLOSE_TAG), true) || ($id === T_INLINE_HTML && trim($text) === '');
  if (!$skip) $code[] = array($id, $text, $offset);
  $offset += strlen($text);
}
$found = -1;
$ends = array(T_ENDIF, T_ENDWHILE, T_ENDFOR, T_ENDFOREACH, T_ENDSWITCH, T_ENDDECLARE);
$alternative = false;
$settings = 0;
foreach ($code as $t) {
  $alternative = $alternative || in_array($t[0], $ends, true);
  // A config that loads wp-settings.php twice, or stops early: the last require might never run.
  if ($t[0] === T_CONSTANT_ENCAPSED_STRING && ($t[1] === "'wp-settings.php'" || $t[1] === '"wp-settings.php"')) $settings++;
  if ($t[0] === T_RETURN || $t[0] === T_EXIT || $t[0] === T_HALT_COMPILER || $t[0] === T_GOTO) $alternative = true;
}
if ($settings !== 1) $alternative = true;
$is = function ($t, $want) {
  if ($want === 'require') return $t[0] === T_REQUIRE_ONCE || $t[0] === T_REQUIRE;
  if ($want === 'ABSPATH') return $t[0] === T_STRING && $t[1] === 'ABSPATH';
  if ($want === 'file') return $t[0] === T_CONSTANT_ENCAPSED_STRING && ($t[1] === "'wp-settings.php'" || $t[1] === '"wp-settings.php"');
  return $t[0] === null && $t[1] === $want;
};
foreach (array(array('require', 'ABSPATH', '.', 'file', ';'), array('require', '(', 'ABSPATH', '.', 'file', ')', ';')) as $shape) {
  $i = count($code) - count($shape);
  if ($alternative || $i < 1) continue;
  $match = true;
  foreach ($shape as $k => $want) $match = $match && $is($code[$i + $k], $want);
  $before = $code[$i - 1];
  if ($match && ($before[0] === T_OPEN_TAG || ($before[0] === null && ($before[1] === ';' || $before[1] === '}')))) $found = $code[$i][2];
}
echo $found;
`;

// wp-config.php with the one line that reads the address file, put on its own line before the
// require_once of wp-settings.php that PHP runs. `offset` is SETTINGS_OFFSET's answer for `config`.
// Only where that require starts its line (indentation aside), so the line put before it can't
// split a statement; anything else is refused before a file is written.
export function withAddressLine(config, offset) {
  if (config.includes(ADDRESS_FILE)) return config;
  const bytes = Buffer.from(config, 'utf8');
  const at = offset >= 0 ? bytes.subarray(0, offset).toString('utf8').length : -1;
  const start = at >= 0 ? config.lastIndexOf('\n', at - 1) + 1 : -1;
  if (at < 0 || config.slice(start, at).trim() !== '') {
    throw new Error("wp-config.php does not load wp-settings.php in a statement of its own (require_once ABSPATH . 'wp-settings.php';); --url and --host cannot set the address.");
  }
  return `${config.slice(0, start)}${ADDRESS_LINE}\n${config.slice(start)}`;
}

// `php(code)` runs PHP code and answers what it printed.
export const settingsOffset = async (php, config) => {
  const out = (await php(`$tp_config = ${phpString(config)};${SETTINGS_OFFSET}`)).trim();
  if (!/^-?\d+$/.test(out)) throw new Error(`Could not read wp-config.php with PHP's tokenizer: ${out.slice(0, 200)}`);
  return Number(out);
};

async function announce(site, url, php) {
  const file = join(site, ADDRESS_FILE);
  if (!url) return rm(file, { force: true });
  const config = join(site, 'wp-config.php');
  const source = await readFile(config, 'utf8');
  const patched = source.includes(ADDRESS_FILE) ? source : withAddressLine(source, await settingsOffset(php, config));
  await writeFile(file, addressPhp(url));
  if (patched !== source) await writeFile(config, patched);
}

// --host: connections to this machine's address, passed to Studio's listener and back unchanged.
// The address bound is the one `host` resolves to, refused when that is every interface.
export async function forward(host, port, upstream) {
  const bind = await listenAddress(host);
  const open = new Set();
  const server = createServer((client) => {
    const site = connect(upstream, 'localhost');
    for (const [socket, other] of [[client, site], [site, client]]) {
      open.add(socket);
      socket.on('error', () => {});
      socket.once('close', () => { open.delete(socket); other.destroy(); });
    }
    client.pipe(site).pipe(client);
  });
  await new Promise((done, fail) => {
    server.once('error', (error) => fail(new Error(`Cannot listen on ${host}:${port}: ${error.message}`)));
    server.listen(port, bind, done);
  });
  return {
    port: server.address().port,
    close: () => new Promise((done) => { server.close(done); for (const socket of open) socket.destroy(); }),
  };
}

// Directory names never watched anywhere, and the project paths TypedPress writes itself.
const IGNORED_NAMES = new Set(['.tp', '.git', 'node_modules']);
// public/typedpress-assets is the compiler's output too: a build writes the browser entries' bundle
// there again, which must not read as a source change and start the next build.
const GENERATED = new Set(['tp/_generated', 'tp/dist', 'public/typedpress-styles', 'public/typedpress-assets']);
const ignoredPath = (name) => IGNORED_NAMES.has(name.split('/').at(-1)) || GENERATED.has(name);
const isSource = (name) => /\.(?:[cm]?[jt]sx?|css|svg)$/.test(name) || name.split('/')[0] === 'public' || /^tp\/app\/(.*\/)?page\.md$/.test(name) || name === 'tp/theme.json' || name === 'package.json';
// Tailwind can scan markup and Vite can read fonts and images referenced from CSS.
const isFrontendSource = (name) => /\.(?:html?|mdx?|png|jpe?g|gif|webp|avif|woff2?|ttf|otf)$/.test(name);

// The Studio site's must-use plugin, written on every start. While `typedpress dev` watches,
// live.json (scripts/dev/live.mjs) names the session and its build, and every document a person
// opens carries the reload client, all from the site's own address.
export const DEV_PLUGIN = `<?php
// Written by typedpress dev. Local development only.
// Allow identity callbacks on the site's HTTP origin.
if (!defined('TP_IDENTITY_DEV_HTTP')) define('TP_IDENTITY_DEV_HTTP', true);
// Studio's PHP looks at a file's modification time at most every two seconds
// (opcache.revalidate_freq=2), so a request just after a rebuild would run the previous build's
// code. From here on (the plugins load after this file) every request checks.
ini_set('opcache.revalidate_freq', '0');
(static function () {
    // What typedpress dev publishes while it watches. With none (--no-watch, or it stopped), the
    // site is as a build leaves it.
    $state = __DIR__ . '/typedpress-dev/live.json';
    $live = is_readable($state) ? json_decode((string) file_get_contents($state), true) : null;
    if (!is_array($live) || !isset($live['session'], $live['revision'])) return;
    // A page rendered to be kept (a prerender, typedpress export) carries no reload client.
    if (isset($_SERVER['HTTP_X_TYPEDPRESS_PRERENDER']) || isset($_SERVER['HTTP_X_TYPEDPRESS_EXPORT'])) return;
    // Pages render from the current build on each request, never from a prerendered file.
    add_filter('tp_prebuilt_serve', '__return_false');
    // A reload sends the request again, so only a GET answer carries the client: never the page a
    // form submission returns (wp-login.php after a failed login), which a reload would resubmit.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    $client = static fn (): string => plugins_url('typedpress-dev/live-client.js', __FILE__);
    $enqueue = static function () use ($live, $client): void {
        wp_enqueue_script('typedpress-dev', add_query_arg('build', $live['session'] . '.' . (int) $live['revision'], $client()), [], null, ['strategy' => 'defer']);
    };
    // Themed pages, the login page, wp-admin, and TypedPress Admin, which prints its own document.
    foreach (['wp_enqueue_scripts', 'login_enqueue_scripts', 'admin_enqueue_scripts', 'tp_admin_init'] as $hook) {
        add_action($hook, $enqueue);
    }
    // An HTML file from public/ (/index.html): the runtime drops every output buffer, sends the
    // status, the headers and the file, and exits. The file's bytes pass through a buffer opened
    // at that status, so the answer carries the client and the file on disk is never edited. The
    // browser keeps no copy: the next load is the file as it is then.
    add_filter('status_header', static function ($header, $code) use ($live, $client) {
        if ((int) $code !== 200 || !doing_filter('do_parse_request')
            || !class_exists(\\TypedPress\\Wp\\PublicFiles::class) || !class_exists(\\TypedPress\\Wp\\Plugin::class)) {
            return $header;
        }
        $plugin = \\TypedPress\\Wp\\Plugin::instance();
        $file = $plugin === null ? null : \\TypedPress\\Wp\\PublicFiles::fallbackFile($plugin);
        if ($file === null || preg_match('/\\\\.html?$/i', $file) !== 1) return $header;
        $tag = '<script src="' . esc_url(add_query_arg('build', $live['session'] . '.' . (int) $live['revision'], $client())) . '" defer></script>';
        ob_start(static function (string $html) use ($tag): string {
            if ($html === '') return $html;
            $at = strripos($html, '</body>');
            $html = $at === false ? $html . $tag : substr_replace($html, $tag, $at, 0);
            if (!headers_sent()) {
                header('Content-Length: ' . strlen($html));
                header('Cache-Control: no-store');
                header_remove('ETag');
            }
            return $html;
        });
        return $header;
    }, 10, 2);
})();
`;

export async function sourceDigest(project) {
  const digest = createHash('sha256');
  let frontend = false;
  try {
    const pkg = JSON.parse(await readFile(join(project, 'package.json'), 'utf8'));
    frontend = ['dependencies', 'devDependencies'].some((key) => typeof pkg?.[key]?.['@typedpress/frontend'] === 'string');
  } catch {
    // The compiler reports a malformed package.json when it builds.
  }
  async function visit(directory, prefix = '') {
    const entries = await readdir(directory, { withFileTypes: true });
    entries.sort((a, b) => a.name.localeCompare(b.name));
    for (const entry of entries) {
      const name = prefix + entry.name;
      if (ignoredPath(name) || entry.isSymbolicLink()) continue;
      const path = join(directory, entry.name);
      if (entry.isDirectory()) await visit(path, `${name}/`);
      else if (isSource(name) || (frontend && isFrontendSource(name))) {
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
                if (closed || !filename) return;
                const name = relative(project, join(directory, String(filename))).split(sep).join('/');
                if (ignoredPath(name) || [...GENERATED].some((dir) => name.startsWith(`${dir}/`))) return;
                if (event === 'rename') {
                  // Discover new directories before notifying: files may have been created
                  // before their directory acquired its own watch handle.
                  void refresh().then(changed, failed);
                } else if (isSource(name) || isFrontendSource(name)) {
                  changed();
                }
              });
              watcher.on('error', failed);
              watchers.set(directory, { watcher, device: identity.dev, inode: identity.ino });
            }
            present.add(directory);
            for (const entry of await readdir(directory, { withFileTypes: true })) {
              const child = relative(project, join(directory, entry.name)).split(sep).join('/');
              if (entry.isDirectory() && !ignoredPath(child)) await visit(join(directory, entry.name));
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

// `typedpress export` from local content: a throwaway WordPress on SQLite, in a temporary directory, with
// the build's plugins active and tp/content/ seeded as a publish seeds a Space. Prints one JSON
// line ({url, credential}) once it serves, keeps serving until its standard input closes, then stops
// and removes the site. The site is never a project's dev site and holds nothing but the export's input.
async function exportSite(content, seed) {
  const work = await mkdtemp(join(tmpdir(), 'typedpress-export-'));
  const site = join(work, 'wordpress');
  const studio = await studioBinary();
  const run = (args, options) => execute(process.execPath, [studio, ...args, '--path', site], options);
  const port = await new Promise((done, fail) => {
    const server = createServer();
    server.once('error', fail);
    server.listen(0, '127.0.0.1', () => { const { port: free } = server.address(); server.close(() => done(free)); });
  });
  let created = false;
  let printed = false;
  // Ctrl-C or a stop signal reaches this process too: the site is removed before it exits.
  let stopping = null;
  const stop = () => { stopping ??= cleanup().finally(() => process.exit(130)); return stopping; };
  process.once('SIGINT', stop);
  process.once('SIGTERM', stop);
  process.once('SIGHUP', stop);
  // `typedpress` closes this pipe when it is done or gone: from then on, the site has no reason to live.
  const released = new Promise((done) => { process.stdin.once('end', done); process.stdin.once('close', done); });
  process.stdin.resume();
  released.then(() => { if (!printed) stop(); });
  async function cleanup() {
    if (created) {
      await run(['stop'], { capture: true }).catch(() => {});
      await execute(process.execPath, [studio, 'delete', site, '--no-files'], { capture: true }).catch(() => {});
      created = false;
    }
    await rm(work, { recursive: true, force: true });
  }
  try {
    await run(['create', '--name', `TypedPress export ${randomUUID().slice(0, 8)}`, '--wp', await wordpressVersion(), '--php', '8.4',
      '--admin-username', 'admin', '--admin-password', randomUUID(), '--skip-browser', '--skip-log-details'],
      { capture: true, timeout: 600000, env: { ...process.env, STUDIO_BASE_PORT: String(port) } });
    created = true;
    const manifest = await json(join(content, 'typedpress-manifest.json'));
    const plugins = Object.keys(manifest.plugins);
    if (!plugins.every((slug) => /^[a-z0-9][a-z0-9-]*$/.test(slug))) throw new Error('Invalid plugin slug in build manifest.');
    for (const slug of plugins) await cp(join(content, 'plugins', slug), join(site, 'wp-content/plugins', slug), { recursive: true, dereference: true });
    const activate = [...manifest.dependencies.map((d) => d.slug), 'typedpress-runtime'];
    for (const slug of ['typedpress-identity', `typedpress-app-${manifest.app}`]) if (plugins.includes(slug)) activate.push(slug);
    for (const slug of activate) await run(['wp', 'plugin', 'activate', slug], { capture: true });
    const spec = join(work, 'setup.json');
    await atomicJson(spec, { site, wordpress: WORDPRESS_VERSION, sqlite: '3.1.2', localized: manifest.locales !== null });
    await run(['wp', 'eval-file', join(here, 'setup.php'), spec, '--use-include'], { capture: true });
    // WordPress's sample post, page and comment are not the project's content: gone before TypedPress
    // stores anything of its own.
    await run(['wp', 'post', 'delete', '1', '2', '3', '--force'], { capture: true }).catch(() => {});
    await run(['wp', 'typedpress', 'migrate'], { capture: true });
    if (seed && await exists(seed)) {
      const records = join(site, 'wp-content/typedpress-export-seed');
      await cp(seed, records, { recursive: true });
      await run(['wp', 'typedpress', 'seed', `--records=${records}`], { capture: true });
    }
    const password = (await run(['wp', 'user', 'application-password', 'create', 'admin', 'typedpress export', '--porcelain'], { capture: true })).trim();
    const status = JSON.parse(await run(['status', '--format', 'json'], { capture: true }));
    process.stdout.write(JSON.stringify({ url: status.siteUrl.replace(/\/$/, ''), credential: `admin:${password}` }) + '\n');
    printed = true;
    await released;
  } finally {
    // A failed startup must not leave the parent's lifetime pipe keeping Node alive.
    process.stdin.pause();
    if (stopping) await stopping;
    else await cleanup();
  }
}

async function main() {
  if (Number(process.versions.node.split('.')[0]) < 22) throw new Error('typedpress dev requires Node.js 22 or newer.');
  if (process.argv[2] === 'export') return exportSite(resolve(process.argv[3]), process.argv[4] ? resolve(process.argv[4]) : null);
  const [mode, directory, first, output, requestedPort = '8787', ...flags] = process.argv.slice(2);
  const noWatch = flags.includes('--no-watch');
  // A --url or --host that names no address is refused before anything is built or started.
  if (mode === 'start') siteAddress(flags, Number(requestedPort));
  const project = await realpath(directory);
  const managed = await ownedState(project, mode === 'start');
  const studio = await studioBinary();
  // The environment this command was given. Vite's NODE_ENV (scripts/dev/frontend.mjs) is this
  // process's alone and reaches neither Studio nor the compiler.
  const studioEnv = { ...process.env };
  const runStudio = (args, options) => execute(process.execPath, [studio, ...args, '--path', managed.owner.site], { env: studioEnv, ...options });
  if (mode === 'wp') {
    // `typedpress wp` and the site commands: WP-CLI's own stdio and exit status, untouched.
    const child = spawn(process.execPath, [studio, 'wp', ...process.argv.slice(4), '--path', managed.owner.site], { stdio: 'inherit' });
    const [code, signal] = await once(child, 'exit');
    process.exitCode = code ?? (signal === 'SIGINT' ? 130 : 1);
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
  if (inside(content, managed.state) || inside(managed.state, content)) throw new Error('--out must not overlap .tp/dev.');
  // Nothing to put in the background when the command returns by itself.
  if (flags.includes('--detach') && !noWatch) return detached(managed);
  const release = await lock(managed.state);
  const statePath = join(managed.state, 'state.json');
  const diagnosticPath = join(managed.state, 'diagnostics.json');
  await rm(join(managed.state, 'stop'), { force: true });
  let watcher, timer, stopTimer, stopping = false, running = false, dirty = false, siteStarted = false, siteUrl, relay, live;
  // What `typedpress dev status --json` reads: where the site is, the process that rebuilds it, and the
  // last build's error while it stands. Written once the site is up, and again when the error changes.
  let state, buildError = null;
  const record = async () => {
    if (state && JSON.stringify(state.error) !== JSON.stringify(buildError)) {
      state = { ...state, error: buildError };
      await atomicJson(statePath, state);
    }
  };
  let finish;
  const finished = new Promise((resolveDone) => { finish = resolveDone; });
  let frontend;
  const stop = async () => {
    if (stopping) return;
    stopping = true;
    watcher?.close(); clearTimeout(timer); clearInterval(stopTimer);
    if (!running) finish();
  };
  process.on('SIGINT', stop); process.on('SIGTERM', stop);
  let publishedRevision;
  const build = async (allowDestructive = false) => {
    // Before the history check, which would read a moved ledger as changed and point at --allow-destructive.
    if (await exists(join(project, 'tp/migrations'))) {
      throw new Error('tp/migrations/ moved to tp/_generated/migrations/; move it with `mkdir -p tp/_generated && rmdir tp/_generated/migrations 2>/dev/null; git mv tp/migrations tp/_generated/migrations`, then start again.');
    }
    const args = ['build', project, '--out', content, '--diagnostics-file', diagnosticPath];
    if (managed.owner.snapshot && !allowDestructive) {
      // A prebuilt/edited migration can hide an intermediate destructive step even if the final
      // snapshot looks unchanged. Only the dev-owned build may extend the accepted history.
      const history = JSON.stringify(await migrationHistory(project));
      if (history !== JSON.stringify(managed.owner.migrations) && history !== JSON.stringify(managed.owner.pending?.migrations)) {
        throw new Error('Migration history changed outside typedpress dev. Inspect it, then restart once with --allow-destructive to apply deliberately.');
      }
      const snapshot = join(managed.state, 'preserve.json');
      await atomicJson(snapshot, managed.owner.pending?.snapshot ?? managed.owner.snapshot);
      args.push('--preserve-data', snapshot);
    }
    await rm(diagnosticPath, { force: true });
    try {
      // While Vite serves the browser entries, the compiler leaves their built bundle alone.
      await execute(first, args, { timeout: 300000, log: true, env: frontend?.serving ? { ...studioEnv, TP_DEV_SERVES_FRONTEND: '1' } : studioEnv });
    } catch (error) {
      error.diagnostics = (await json(diagnosticPath).catch(() => null))?.diagnostics;
      throw error;
    }
    const revision = await appRevision(content);
    if (revision === publishedRevision && !managed.owner.pending) return revision;
    managed.owner.pending = {
      migrations: await migrationHistory(project),
      snapshot: (await json(join(content, 'typedpress-deployment.json'))).snapshot,
    };
    await atomicJson(managed.marker, managed.owner);
    return revision;
  };
  // `wp typedpress migrate`, checked against live rows (spec §2.9). With --allow-destructive, a refused
  // drop is confirmed at the counts the refusal reports; anything else (a required field rows lack)
  // stays an error to fix in the schema.
  const migrate = async (allowDestructive) => {
    try {
      await runStudio(['wp', 'typedpress', 'migrate'], { capture: true });
    } catch (error) {
      const refusal = /\{"error":.*\}/.exec(error.stdout ?? '')?.[0];
      const problem = refusal ? JSON.parse(refusal).error : null;
      if (!allowDestructive || problem?.code !== 'schema_refused' || problem.drop.length !== problem.refusals.length) throw error;
      await runStudio(['wp', 'typedpress', 'migrate', `--drop=${problem.drop.join(',')}`]);
    }
  };
  // What the site holds from the last configure that finished: nothing until the first one, and
  // nothing again after one that failed part-way, so the next one does every step.
  let applied = null;
  // Puts a build on the site. Only the steps the change needs run, because each `studio wp` starts
  // WordPress and takes about a second: a page, style or public-file edit copies the app plugin and
  // starts none.
  const configure = async (allowDestructive = false) => {
    const previous = applied;
    applied = null;
    const snapshot = (await json(join(content, 'typedpress-deployment.json'))).snapshot;
    const manifest = await json(join(content, 'typedpress-manifest.json'));
    const prints = await pluginPrints(content, manifest);
    const schema = await fileHash(join(content, 'plugins', `typedpress-app-${manifest.app}`, 'tp/app/schema.php'));
    const history = await migrationHistory(project);
    const retired = managed.owner.generated.filter((slug) => !(slug in manifest.plugins));
    for (const slug of retired) await plainDirectory(join(managed.owner.site, 'wp-content/plugins', slug));
    if (retired.length) await runStudio(['wp', 'plugin', 'deactivate', ...retired]);
    const { activate, spec } = await publishPlugins(content, managed, (slug) => prints[slug] !== previous?.prints[slug]);
    // The plugin set: activation, and the development identity app (TypedPress Identity may be new).
    const plugins = !previous || retired.length > 0 || Object.keys(prints).some((slug) => !(slug in previous.prints));
    if (plugins) {
      const active = JSON.parse(await runStudio(['wp', 'plugin', 'list', '--status=active', '--format=json'], { capture: true })).map((plugin) => plugin.name);
      // Each activation gets a fresh WordPress bootstrap, including plugins_loaded dependencies.
      for (const slug of activate) {
        if (!active.includes(slug)) await runStudio(['wp', 'plugin', 'activate', slug]);
      }
    }
    // The platform check: at startup, and when what it checks (a localized app) changed.
    if (!previous || JSON.stringify(spec) !== previous.spec) {
      await runStudio(['wp', 'eval-file', join(here, 'setup.php'), join(managed.state, 'setup.json'), '--use-include']);
    }
    // Storage: at startup, when the migration history changed, and when the compiled schema did
    // (the command also records the schema the storage matches). Page and function edits change neither.
    if (!previous || schema !== previous.schema || JSON.stringify(history) !== JSON.stringify(managed.owner.migrations)) {
      await migrate(allowDestructive);
    }
    if (plugins) {
      await runStudio(['wp', 'typedpress', 'identity', 'app', 'add', '--name=TypedPress development', '--id=app_zero_dev',
        `--redirect=${siteUrl}/auth/callback`, `--origin=${siteUrl}`]);
    }
    for (const slug of retired) await rm(join(managed.owner.site, 'wp-content/plugins', slug), { recursive: true });
    managed.owner.generated = Object.keys(manifest.plugins);
    managed.owner.snapshot = snapshot;
    managed.owner.migrations = history;
    delete managed.owner.pending;
    await atomicJson(managed.marker, managed.owner);
    applied = { prints, schema, spec: JSON.stringify(spec) };
  };
  try {
    running = true;
    if (!await exists(join(project, 'typedpress.config.ts'))) throw new Error(`No typedpress.config.ts in ${project}`);
    let digest = await sourceDigest(project);
    // --allow-destructive applies to the first build that reaches the site: when the first build
    // fails, the rebuild after the fix is that build, so the flag holds until a configure succeeds.
    let allowDestructive = flags.includes('--allow-destructive');
    const report = async (error) => {
      console.error(error.message);
      console.error('Fix the error and save to retry.');
      buildError = { message: error.diagnostics?.[0]?.message ?? error.message, diagnostics: error.diagnostics ?? [] };
      // Open pages and `dev status` learn of it; neither may hide the error itself.
      await Promise.all([live?.failed(error), record()]).catch((failure) => console.error(failure.message));
    };
    let startupError, initialRevision;
    try { initialRevision = await build(allowDestructive); }
    catch (error) {
      if (noWatch || !error.diagnostics?.length) throw error;
      startupError = error;
    }
    if (!managed.owner.created) {
      await availablePort(Number(requestedPort));
      if (await exists(managed.owner.site) && (await readdir(managed.owner.site)).length) {
        throw new Error('A previous Studio creation was interrupted. Inspect .tp/dev/wordpress; TypedPress will not adopt or delete it automatically.');
      }
      await runStudio(['create', '--name', `TypedPress ${project.split(sep).at(-1)}`, '--wp', await wordpressVersion(), '--php', '8.4',
        '--admin-username', 'admin', '--admin-password', 'typedpress-test-admin', '--skip-browser', '--skip-log-details'],
        { timeout: 600000, env: { ...studioEnv, STUDIO_BASE_PORT: requestedPort } });
      managed.owner.created = true;
      await atomicJson(managed.marker, managed.owner);
    } else {
      await runStudio(['start', '--skip-browser', '--skip-log-details']);
    }
    siteStarted = true;
    const status = JSON.parse(await runStudio(['status', '--format', 'json'], { capture: true }));
    // Where Studio listens, and where the site is opened: the same unless --url or --host says otherwise.
    const local = status.siteUrl.replace(/\/$/, '');
    const port = Number(new URL(local).port) || 80;
    const address = siteAddress(flags, port);
    const url = address.url ?? local;
    // The development identity app stays registered for this machine's own address.
    siteUrl = local;
    // Studio's own PHP, through WP-CLI without loading WordPress.
    await announce(managed.owner.site, address.url, (code) => runStudio(['wp', 'eval', '--skip-wordpress', code], { capture: true }));
    const { startFrontend } = await import(pathToFileURL(join(here, 'frontend.mjs')));
    frontend = await startFrontend(project, managed, {
      site: url, local, host: address.listen ? await listenAddress(address.listen) : 'localhost', listen: Boolean(address.listen),
      watch: !noWatch, write: atomicJson,
    });
    if (address.listen) relay = await forward(address.listen, port, port);
    const mu = join(managed.owner.site, 'wp-content/mu-plugins/typedpress-dev-runtime.php');
    if (!managed.owner.devMu && await exists(mu)) throw new Error('typedpress-dev-runtime.php already exists and is not managed by TypedPress.');
    await writeFile(mu, DEV_PLUGIN);
    managed.owner.devMu = true;
    await atomicJson(managed.marker, managed.owner);
    const devAssets = join(dirname(mu), 'typedpress-dev');
    await mkdir(devAssets, { recursive: true });
    const publish = (signal) => atomicJson(join(devAssets, 'live.json'), signal);
    // No session yet: a signal left by a process that was killed is not this one's.
    await publish(null);
    if (!startupError) {
      await configure(allowDestructive);
      allowDestructive = false;
      publishedRevision = initialRevision;
      const response = await fetch(`${local}/wp-json/typedpress/v1`, { signal: AbortSignal.timeout(15000) });
      if (!response.ok) throw new Error(`TypedPress application is not ready: HTTP ${response.status}. Run typedpress doctor --site ${local}`);
    }
    // The session starts once the site holds this build, so a page left open from an earlier
    // session reloads into it and not into what the site held before.
    if (!noWatch) {
      const { liveUpdates } = await import(pathToFileURL(join(here, 'live.mjs')));
      await cp(join(here, 'live-client.js'), join(devAssets, 'live-client.js'));
      live = await liveUpdates({ publish });
    }
    if (startupError) await report(startupError);
    const summary = [`\nWebsite:   ${url}/\nWordPress: ${url}/wp-admin/\nPlugins:   ${url}/wp-admin/plugins.php`];
    if (await exists(join(project, 'public/index.html'))) summary.push(`App:       ${url}/index.html`);
    if (managed.owner.generated.includes('typedpress-admin')) summary.push(`TypedPress Admin: ${url}/wp-admin/new/`);
    summary.push('Initial login: admin / typedpress-test-admin (local development only; unchanged if you edited it)\n');
    if (address.listen) summary.push(`Listening on ${address.listen} port ${port}: whoever can reach it is an administrator of this WordPress and can run code on this machine as you.`);
    else if (address.url) summary.push(`Listening on this machine only: point your proxy for ${url} at ${local}`);
    summary.push(`Data persists in ${managed.owner.site}\nStop: typedpress dev stop ${JSON.stringify(project)}`);
    console.log(summary.join('\n'));
    // What `typedpress dev status --json` and `typedpress mcp` read without starting Studio: where the site
    // is, and the process that rebuilds it on save (none after --no-watch).
    state = {
      version: 1, url, local, port,
      pid: noWatch ? null : process.pid, log: process.send ? join(managed.state, 'dev.log') : null,
      wordpress: status.wpVersion ?? null, php: status.phpVersion ?? null, startedAt: new Date().toISOString(),
      error: buildError,
    };
    await atomicJson(statePath, state);
    // Started by --detach: tell the waiting command the site is ready, and whether the first build
    // failed, then let it go.
    if (process.send) {
      process.send({ ready: true, summary: summary.join('\n'), failed: startupError?.message ?? null });
      process.channel?.unref();
    }
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
          try {
            const revision = await build(allowDestructive);
            // A rebuilt bundle names its launchers by content, so Vite's launcher map follows it,
            // whether or not the app plugin changed.
            await frontend?.refresh();
            const changed = revision !== publishedRevision || Boolean(managed.owner.pending);
            if (changed) {
              await configure(allowDestructive);
              allowDestructive = false;
            }
            await live.updated(changed);
            publishedRevision = revision;
            buildError = null;
            await record();
            console.log('Updated.');
          } catch (error) { await report(error); }
        } while (dirty && !stopping);
      } catch (error) { console.error(error.message); }
      finally { running = false; if (stopping) finish(); }
    }
    watcher = await watchSources(project, () => {
      if (stopping) return;
      // Long enough to take an editor's several writes of one save as one; a change that lands
      // during the build is built next (dirty).
      dirty = true; clearTimeout(timer); timer = setTimeout(rebuild, 80);
    }, (error) => { console.error(`File watcher: ${error.message}`); void stop(); });
    // The `typedpress dev` command this process runs under, when it stays attached (a --detach start
    // has a channel to the command that started it, which returns). Ctrl+C reaches this process
    // itself, but a `kill` of that command does not: when it is gone, stop as `dev stop` does.
    const attached = process.send ? NaN : Number(process.env.TP_DEV_PARENT);
    const abandoned = () => {
      if (!Number.isSafeInteger(attached) || attached <= 0) return false;
      try { process.kill(attached, 0); return false; }
      catch (error) { return error.code === 'ESRCH'; }
    };
    stopTimer = setInterval(() => {
      exists(join(managed.state, 'stop')).then((requested) => { if (requested) void stop(); });
      if (abandoned()) void stop();
    }, 250);
    console.log('Watching config, TypeScript, stylesheets, images and public files. Ctrl+C stops WordPress; content is retained.');
    void rebuild();
    await finished;
  } finally {
    watcher?.close(); clearTimeout(timer); clearInterval(stopTimer);
    await live?.close().catch((error) => console.error(error.message));
    await relay?.close();
    await frontend?.close().catch((error) => console.error(error.message));
    process.off('SIGINT', stop); process.off('SIGTERM', stop);
    if (await exists(join(managed.state, 'stop'))) stopping = true;
    if (siteStarted && (stopping || !noWatch)) {
      await runStudio(['stop']).catch((error) => console.error(error.message));
    }
    await rm(join(managed.state, 'stop'), { force: true });
    // This process no longer rebuilds the site; the URL and port stay for the next start.
    const recorded = await json(statePath).catch(() => null);
    if (recorded?.pid === process.pid) await atomicJson(statePath, { ...recorded, pid: null, log: null, error: null });
    await release();
  }
}

// What `typedpress dev --detach` says once the background process reports the site is up: standard
// output, standard error and the exit status. A first build that failed leaves the process running,
// as it does in the foreground (saving a fix rebuilds), but the command exits 1 and prints the
// error: a script or an agent that goes on after `typedpress dev --detach` must not take a site that
// does not serve the current source for a started app.
export function detachedReport({ summary, failed }, pid, log) {
  const out = `${summary}\nRunning in the background (pid ${pid}), rebuilding on save.\nLog:  ${log}`;
  if (!failed) return { out, error: '', code: 0 };
  return {
    out,
    error: `typedpress dev: the first build failed, so the site does not serve this source yet:\n${failed.trimEnd()}\n`
      + `WordPress is running in the background (pid ${pid}). Fix the error and save: it rebuilds, and typedpress dev status --json reports the error until a build succeeds.`,
    code: 1,
  };
}

// `typedpress dev --detach`: the same command as a background process in its own session, so it outlives
// the terminal or the agent that started it. Its output goes to .tp/dev/dev.log. This process
// waits for the one message the child sends when the site is ready, prints what the child printed,
// and returns; a child that stops before that has failed, and its output is the explanation.
async function detached(managed) {
  // A running session keeps its log: refuse before the file is opened for writing.
  const session = await json(join(managed.state, 'session.json')).catch(() => null);
  let active = Number.isSafeInteger(session?.pid) && session.pid > 0;
  if (active) {
    try { process.kill(session.pid, 0); }
    catch (error) { if (error.code !== 'ESRCH') throw error; active = false; }
  }
  if (active) throw new Error('Another typedpress dev command owns this project. Use typedpress dev stop first.');
  const log = join(managed.state, 'dev.log');
  const out = await open(log, 'w', 0o600);
  try {
    const args = process.argv.slice(2).filter((arg) => arg !== '--detach');
    const child = spawn(process.execPath, [fileURLToPath(import.meta.url), ...args], { detached: true, stdio: ['ignore', out.fd, out.fd, 'ipc'] });
    const outcome = await new Promise((done) => {
      child.once('message', done);
      child.once('error', (error) => done({ failed: error.message }));
      child.once('exit', (code, signal) => done({ failed: `exit ${code ?? signal}` }));
    });
    if (!outcome.ready) {
      process.stderr.write(await readFile(log, 'utf8'));
      throw new Error(`The background process stopped before the site was ready (${outcome.failed}). Its output is above.`);
    }
    child.disconnect(); child.unref();
    const report = detachedReport(outcome, child.pid, log);
    console.log(report.out);
    if (report.error) console.error(report.error);
    process.exitCode = report.code;
  } finally { await out.close(); }
}

const invokedPath = process.argv[1] && await realpath(process.argv[1]).catch(() => null);
if (invokedPath === modulePath) {
  main().catch((error) => { console.error(`typedpress dev: ${error.message}`); process.exitCode = 1; });
}
