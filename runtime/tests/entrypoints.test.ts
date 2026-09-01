// The routing table under /__spacefast/ is generated from the engine manifest
// (scripts/check-runtime-entrypoints.mjs). This suite is the behavioral half of
// that contract: every path the generator routes must really execute its own
// script on the wp.cloud request path — custom-redirects.php returning so the
// web server serves the file — and every path must have a probe, so the codegen
// can never outrun the coverage.
import { afterAll, beforeAll, expect, test } from "bun:test";
import {
  copyFileSync,
  mkdtempSync,
  mkdirSync,
  readFileSync,
  realpathSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";

import { writeActiveReleasePointer } from "./active-release.ts";
import { ENTRYPOINT_PATHS, ENTRYPOINT_PROBES } from "./entrypoint-probes.ts";
import { startRuntime, type Runtime } from "./harness.ts";

let rt: Runtime;

beforeAll(async () => {
  rt = await startRuntime();
}, 30000);

afterAll(() => rt?.stop());

test("every manifest entrypoint carries its own behavioral probe", () => {
  expect(Object.keys(ENTRYPOINT_PROBES).toSorted()).toEqual(ENTRYPOINT_PATHS);
});

test("wp.cloud config is decryptable before a clean route enters the runtime", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-wpcloud-config-"));
  try {
    const engineRoot = path.join(root, ".stattic/releases/test/engine");
    mkdirSync(path.join(engineRoot, "shared"), { recursive: true });
    mkdirSync(path.join(engineRoot, "entrypoints"), { recursive: true });
    // The gate reads the real context.php: it needs the generated entrypoint
    // table and _stattic_request_uri_path() from it, so the fixture defers to
    // the engine's own copy rather than restating either.
    writeFileSync(
      path.join(engineRoot, "shared/context.php"),
      `<?php require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/shared/context.php"))};`,
    );
    writeFileSync(
      path.join(engineRoot, "init.php"),
      '<?php echo json_encode(["dbPassword" => defined("DB_PASSWORD") ? DB_PASSWORD : null]);',
    );
    mkdirSync(path.join(root, ".stattic"), { recursive: true });
    writeActiveReleasePointer(path.join(root, ".stattic"), "releases/test");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(root, "custom-redirects.php"),
    );
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        "putenv('DB_PASSWORD=encrypted-data-key');",
        `$_SERVER['DOCUMENT_ROOT'] = ${JSON.stringify(root)};`,
        "$_SERVER['SCRIPT_FILENAME'] = '/scripts/wordpress.php';",
        "$_SERVER['REQUEST_METHOD'] = 'GET';",
        "$_SERVER['REQUEST_URI'] = '/api/echo';",
        "$_SERVER['HTTP_HOST'] = 'example.test';",
        `require ${JSON.stringify(path.join(root, "custom-redirects.php"))};`,
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", driver],
      stdout: "pipe",
      stderr: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual({ dbPassword: "encrypted-data-key" });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

// The /wp-admin + /wp-json lane, end to end through the real gate: the real
// custom-redirects.php classification, the real shared/content-admin.php
// predicate, hold check, session verification and WordPress-context helper.
// Only the storage primitives and the route lookup are fixtures.
test("the content admin gate refuses a platform hold before it honors a session", () => {
  // realpathSync: macOS tmpdirs live behind the /var -> /private/var symlink,
  // and the gate hands the resolved private root to WordPress.
  const root = realpathSync(mkdtempSync(path.join(os.tmpdir(), "spacefast-content-admin-gate-")));
  try {
    const sharedRoot = path.join(root, ".stattic/releases/test/engine/shared");
    mkdirSync(sharedRoot, { recursive: true });
    writeFileSync(
      path.join(sharedRoot, "bootstrap-config.php"),
      "<?php define('SPACEFAST_DASHBOARD_ORIGIN', 'https://box-default.sf.localhost');",
    );
    writeFileSync(
      path.join(sharedRoot, "context.php"),
      `<?php
function _stattic_dashboard_origin(): string {
  return defined('SPACEFAST_DASHBOARD_ORIGIN') ? SPACEFAST_DASHBOARD_ORIGIN : '';
}
function _stattic_normalize_hostname(string $host): string { return strtolower($host); }
function _stattic_problem_response(int $status, string $code, string $message): never {
  echo json_encode(['refused' => [$status, $code]]);
  exit(0);
}
function _stattic_id_valid(string $value): bool {
  return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $value) === 1;
}
function _stattic_absolute_url_origin(string $value): ?string {
  $parts = parse_url($value);
  if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
  return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
}
`,
    );
    writeFileSync(
      path.join(sharedRoot, "cache-policy.php"),
      "<?php function _stattic_cache_policy(array $lane = []): array { return ['cache_control' => 'private, no-store']; }",
    );
    writeFileSync(
      path.join(sharedRoot, "storage.php"),
      `<?php
function _stattic_lazy_minted_secret(string $root, string $name, int $bytes): ?string {
  return str_repeat('ab', $bytes);
}
function _sf_pointer_read(string $key, string $path): array {
  return ['kind' => 'present', 'value' => ['space_id' => 'spc_test', 'access_generation' => 7]];
}
// spc_test is a managed-WordPress Space: it carries a content-model release, so
// the REST door hands off to WordPress rather than 404-ing a static Space.
function _stattic_private_tree_read_pointer(string $path, int $maxBytes = 256): ?string {
  return 'sha256:' . str_repeat('a', 64);
}
`,
    );
    writeFileSync(path.join(sharedRoot, "artifacts.php"), "<?php");
    // The one fact the gate consults about the host, scripted per case.
    writeFileSync(
      path.join(sharedRoot, "content-access.php"),
      `<?php
function _stattic_content_access_target(string $root, string $host): array {
  $hold = getenv('SPACEFAST_TEST_HOLD');
  return $hold === false || $hold === ''
    ? ['kind' => 'present', 'open' => true, 'space_id' => 'spc_test', 'version_id' => 'ver_1', 'serving' => []]
    : ['kind' => 'absent', 'hold' => $hold];
}
`,
    );
    copyFileSync(
      path.resolve(import.meta.dir, "../engine/shared/content-admin.php"),
      path.join(sharedRoot, "content-admin.php"),
    );
    mkdirSync(path.join(root, ".stattic"), { recursive: true });
    writeActiveReleasePointer(path.join(root, ".stattic"), "releases/test");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(root, "custom-redirects.php"),
    );
    // WordPress's front controller, reporting the context the gate handed it.
    // Same keys as the driver's own echo below, so a REST row and an admin row
    // are compared on the same terms and only `served_by` tells them apart.
    writeFileSync(
      path.join(root, "wp-blog-header.php"),
      [
        "<?php",
        "echo json_encode([",
        "  'user_id' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null,",
        "  'space_id' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,",
        "  'private_root' => $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null,",
        "  'frame_origin' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] ?? null,",
        "  'file_edit_locked' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,",
        "  'served_by' => 'wordpress',",
        "]);",
        "",
      ].join("\n"),
    );
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        // Mint the session with the same code the launch entry uses, carrying a
        // launch origin the box env deliberately does not match.
        `require ${JSON.stringify(path.join(sharedRoot, "context.php"))};`,
        `require ${JSON.stringify(path.join(sharedRoot, "storage.php"))};`,
        `require ${JSON.stringify(path.join(sharedRoot, "content-admin.php"))};`,
        `$session = _stattic_content_admin_mint_session(${JSON.stringify(path.join(root, ".stattic/storage"))}, 'space.example', 57, ['kind' => 'user', 'issuer' => 'spacefast', 'subject' => 'sub_entrypoints_test'], ['space_id' => 'spc_test', 'access_generation' => 7], 'editor', 'https://launch.sf.localhost', ['surface' => 'wordpress']);`,
        `$_SERVER['DOCUMENT_ROOT'] = ${JSON.stringify(root)};`,
        `$_SERVER['SCRIPT_FILENAME'] = ${JSON.stringify(root)} . '/' . (getenv('SPACEFAST_TEST_SCRIPT') ?: 'wp-admin/edit.php');`,
        "$_SERVER['REQUEST_METHOD'] = 'GET';",
        "$_SERVER['REQUEST_URI'] = getenv('SPACEFAST_TEST_URI') ?: '/wp-admin/edit.php';",
        "$_SERVER['HTTP_HOST'] = 'space.example';",
        "$restRoute = getenv('SPACEFAST_TEST_REST_ROUTE');",
        "if (is_string($restRoute) && $restRoute !== '') { $_GET['rest_route'] = $restRoute; }",
        "$_COOKIE[_stattic_content_admin_cookie_name()] = getenv('SPACEFAST_TEST_NO_COOKIE') ? '' : $session['token'];",
        `require ${JSON.stringify(path.join(root, "custom-redirects.php"))};`,
        "echo json_encode([",
        "  'user_id' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_USER_ID'] ?? null,",
        "  'space_id' => $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] ?? null,",
        "  'private_root' => $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null,",
        "  'frame_origin' => $GLOBALS['SPACEFAST_CONTENT_ADMIN_FRAME_ORIGIN'] ?? null,",
        "  'file_edit_locked' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,",
        "]);",
      ].join("\n"),
    );

    const run = (env: Record<string, string>) => {
      const result = Bun.spawnSync({
        cmd: ["php", "-d", "auto_prepend_file=", driver],
        stdout: "pipe",
        stderr: "pipe",
        env: { ...process.env, ...env },
      });
      expect(result.exitCode, result.stderr.toString()).toBe(0);
      return JSON.parse(result.stdout.toString());
    };

    // The frame policy comes from the origin the launch carried, not the
    // box-wide dashboard default.
    const admitted = {
      user_id: 57,
      space_id: "spc_test",
      private_root: path.join(root, ".stattic/storage"),
      frame_origin: "https://launch.sf.localhost",
      file_edit_locked: true,
    };
    // The admin screens are real WordPress scripts: the gate establishes the
    // context and returns, and PHP runs them next.
    expect(run({})).toEqual(admitted);
    // The REST API the admin screens save through — in both spellings — cannot
    // be reached by returning: this engine installs its own index.php over the
    // one WordPress routes /wp-json through, so the gate has to name WordPress's
    // front controller itself. Reaching it, carrying the same context, is the
    // assertion.
    const handedOff = { ...admitted, served_by: "wordpress" };
    expect(
      run({ SPACEFAST_TEST_URI: "/wp-json/wp/v2/types", SPACEFAST_TEST_SCRIPT: "index.php" }),
    ).toEqual(handedOff);
    expect(
      run({
        SPACEFAST_TEST_URI: "/?rest_route=/wp/v2/types",
        SPACEFAST_TEST_SCRIPT: "index.php",
        SPACEFAST_TEST_REST_ROUTE: "/wp/v2/types",
      }),
    ).toEqual(handedOff);
    // A platform hold outranks a live session: the host answers the platform's
    // page to every visitor, so it must not stay editable for a session TTL.
    expect(run({ SPACEFAST_TEST_HOLD: "tombstone" })).toEqual({
      refused: [404, "content_admin_space_not_found"],
    });
    expect(run({ SPACEFAST_TEST_HOLD: "platform_error" })).toEqual({
      refused: [503, "content_admin_space_unavailable"],
    });
    expect(run({ SPACEFAST_TEST_NO_COOKIE: "1" })).toEqual({
      refused: [401, "content_admin_session_invalid"],
    });
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("runtime config and dashboard origin recover after the provider class loads", () => {
  const script = String.raw`
require $argv[1];
require $argv[2];
$before = _stattic_dashboard_origin();
class Atomic_Persistent_Data implements IteratorAggregate {
  public function getIterator(): Traversable {
    return new ArrayIterator(['SPACEFAST_DASHBOARD_ORIGIN' => 'https://my.sf.localhost']);
  }
}
_stattic_runtime_bootstrap_config();
echo json_encode([
  'before' => $before,
  'after' => _stattic_dashboard_origin(),
]);
`;
  const result = Bun.spawnSync({
    cmd: [
      "php",
      "-r",
      script,
      path.resolve(import.meta.dir, "../engine/shared/bootstrap-config.php"),
      path.resolve(import.meta.dir, "../engine/shared/context.php"),
    ],
    stdout: "pipe",
    stderr: "pipe",
  });

  expect(result.exitCode, result.stderr.toString()).toBe(0);
  expect(JSON.parse(result.stdout.toString())).toEqual({
    before: "",
    after: "https://my.sf.localhost",
  });
});

test("a CLI process with no request passes through custom-redirects unserved", () => {
  // The purge worker (and wp-cli) load WordPress from the CLI, which includes
  // custom-redirects.php. Classifying that process as a visitor request for '/'
  // serves the space and exits the tool mid-run — the bug that left engine
  // purge records queued forever. The contract: no REQUEST_METHOD, no serving.
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-cli-guard-"));
  try {
    const engineRoot = path.join(root, ".stattic/releases/test/engine");
    mkdirSync(path.join(engineRoot, "shared"), { recursive: true });
    mkdirSync(path.join(engineRoot, "entrypoints"), { recursive: true });
    writeFileSync(
      path.join(engineRoot, "shared/context.php"),
      `<?php require_once ${JSON.stringify(path.resolve(import.meta.dir, "../engine/shared/context.php"))};`,
    );
    writeFileSync(path.join(engineRoot, "init.php"), '<?php echo "SERVED"; exit(0);');
    mkdirSync(path.join(root, ".stattic"), { recursive: true });
    writeActiveReleasePointer(path.join(root, ".stattic"), "releases/test");
    copyFileSync(
      path.resolve(import.meta.dir, "../custom-redirects.php"),
      path.join(root, "custom-redirects.php"),
    );
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        `require ${JSON.stringify(path.join(root, "custom-redirects.php"))};`,
        "echo 'CLI_TOOL_RAN';",
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: ["php", "-d", "auto_prepend_file=", driver],
      stdout: "pipe",
      stderr: "pipe",
    });
    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(result.stdout.toString()).toBe("CLI_TOOL_RAN");
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test("custom-redirects passes a nonce-bound FPM readiness probe through the private namespace", async () => {
  const nonce = "a".repeat(32);
  const publicPath = `/__spacefast/php-fpm-readiness-${nonce}.php`;
  const probePath = path.join(rt.root, publicPath);
  mkdirSync(path.dirname(probePath), { recursive: true });
  copyFileSync(path.resolve(import.meta.dir, "../php-fpm-readiness.php"), probePath);

  const response = await fetch(`${rt.baseUrl}${publicPath}?nonce=${nonce}`);

  expect(response.status).toBe(200);
  expect(response.headers.get("content-type")).toContain("application/json");
  expect(await response.json()).toEqual({
    nonce,
    php_version: expect.any(String),
    php_version_id: expect.any(Number),
    php_sapi: expect.any(String),
  });
});

test("the provider gate refuses unmanaged WordPress entrypoints before WordPress runs", async () => {
  for (const pathname of [
    "/wp-login.php",
    "/wp-login.php/continue",
    "/xmlrpc.php",
    "/wp-comments-post.php",
    "/wp-content/plugins/example/direct.php",
  ]) {
    const response = await fetch(`${rt.baseUrl}${pathname}`);
    expect(response.status, pathname).toBe(404);
    expect(response.headers.get("cache-control"), pathname).toBe("private, no-store");
    expect(await response.text(), pathname).toBe("Not Found");
  }
});

test("the loader restores full PHP error logging without leaking diagnostics to stdout", () => {
  const root = mkdtempSync(path.join(os.tmpdir(), "spacefast-error-reporting-"));
  try {
    const errorLog = path.join(root, "php-error.log");
    const missingPath = path.join(root, "warning-probe-missing.txt");
    const driver = path.join(root, "driver.php");
    writeFileSync(
      driver,
      [
        "<?php",
        `require ${JSON.stringify(path.resolve(import.meta.dir, "../custom-redirects.php"))};`,
        `file_get_contents(${JSON.stringify(missingPath)});`,
        "echo json_encode([",
        "    'all' => error_reporting() === E_ALL,",
        "    'logging' => filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOL),",
        "    'displaying' => filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL),",
        "]);",
      ].join("\n"),
    );

    const result = Bun.spawnSync({
      cmd: [
        "php",
        "-d",
        "auto_prepend_file=",
        "-d",
        "error_reporting=0",
        "-d",
        "log_errors=0",
        "-d",
        "display_errors=1",
        "-d",
        `error_log=${errorLog}`,
        driver,
      ],
      stdout: "pipe",
      stderr: "pipe",
    });

    expect(result.exitCode, result.stderr.toString()).toBe(0);
    expect(JSON.parse(result.stdout.toString())).toEqual({
      all: true,
      logging: true,
      displaying: false,
    });
    expect(result.stderr.toString()).toBe("");
    expect(readFileSync(errorLog, "utf8")).toContain(missingPath);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

for (const entrypointPath of ENTRYPOINT_PATHS) {
  test(`${entrypointPath} runs its own script through the custom-redirects gate`, async () => {
    const probe = ENTRYPOINT_PROBES[entrypointPath];
    if (probe === undefined) throw new Error(`no probe declared for ${entrypointPath}`);
    await probe.verify(await probe.request(rt));
  });
}
