// App-session verification for Zero on a Users-enabled Space.
//
// The php-fpm worker on a wp.cloud box cannot spawn WP-CLI (no `php`/`wp` in
// the pool's mount namespace) and cannot boot WordPress from the auto_prepend
// pass, so sf_auth() verifies the `sfi_session` cookie straight against the
// site's database. This suite is that seam: a real MySQL server carrying the
// identity plugin's tables, wp-config.php with the salts and prefix, and the
// engine's verifier from cookie to identity. Tenant HTTP PHP is disabled, so
// this owner runs the verifier directly instead of dispatching tenant code.
import { afterAll, beforeAll, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { createHmac, randomBytes } from "node:crypto";
import { writeFileSync } from "node:fs";
import path from "node:path";

import { type Runtime, startRuntime } from "./harness.ts";
import {
  MYSQL_SETUP_TIMEOUT_MS,
  type MysqlContainer,
  startMysqlContainer,
  stopMysqlContainers,
} from "./mysql-container.ts";

const HOST = "users-verify.test";
const SPACE = "spc_users_verify";
const OTHER_SPACE = "spc_users_verify_other";
const CONTAINER_NAME_PREFIX = "stattic-space-users-verify";
const ROOT_PASSWORD = "space-users-verify-pw";
const DATABASE = "users_verify";
const AUTH_KEY = "unit-auth-key-" + "k".repeat(40);
const AUTH_SALT = "unit-auth-salt-" + "s".repeat(40);
const SUBJECT = `usr_${"a".repeat(64)}`;

const usersSettings = {
  enabled: true,
  providers: {
    google: { mode: "disabled" },
    gravatar: { enabled: false },
    spacefast: { enabled: true },
  },
};

let rt: Runtime;
let mysql: MysqlContainer;

/** The identity plugin's session secret hash: HMAC-SHA256 keyed by wp_salt('auth'). */
function sessionHash(secret: string): string {
  return createHmac("sha256", AUTH_KEY + AUTH_SALT)
    .update(secret)
    .digest("hex");
}

function seedSession(input: {
  userId: number;
  secret: string;
  expiresAt: number;
  revoked?: boolean;
}): void {
  mysql.exec(
    `INSERT INTO wp_sfi_sessions (id, wp_user_id, secret_hash, csrf, created_at, expires_at, verified_at, revoked_at, label)` +
      ` VALUES ('${randomBytes(16).toString("hex")}', ${input.userId}, '${sessionHash(input.secret)}', '${"c".repeat(64)}',` +
      ` ${input.expiresAt - 60}, ${input.expiresAt}, ${input.expiresAt - 60}, ${input.revoked ? input.expiresAt - 30 : "NULL"}, 'test');`,
  );
}

beforeAll(async () => {
  mysql = await startMysqlContainer({
    namePrefix: CONTAINER_NAME_PREFIX,
    database: DATABASE,
    rootPassword: ROOT_PASSWORD,
  });
  // The WordPress rows and the identity plugin's tables the verify reads.
  mysql.exec(
    [
      "CREATE TABLE wp_users (ID BIGINT UNSIGNED PRIMARY KEY, display_name VARCHAR(250) NOT NULL);",
      "CREATE TABLE wp_usermeta (umeta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(255), meta_value LONGTEXT);",
      "CREATE TABLE wp_options (option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) UNIQUE, option_value LONGTEXT NOT NULL);",
      "CREATE TABLE wp_sfi_accounts (wp_user_id BIGINT UNSIGNED PRIMARY KEY, primary_email_id BIGINT UNSIGNED NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', created_at BIGINT NOT NULL);",
      "CREATE TABLE wp_sfi_sessions (id CHAR(32) PRIMARY KEY, wp_user_id BIGINT UNSIGNED NOT NULL, secret_hash CHAR(64) NOT NULL UNIQUE, csrf CHAR(64) NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, verified_at BIGINT NOT NULL, revoked_at BIGINT NULL, label VARCHAR(200) NOT NULL);",
      "INSERT INTO wp_users VALUES (7, 'Alice App'), (8, 'Bob Elsewhere'), (9, 'Carol Suspended');",
      `INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (7, '_spacefast_app_user', '${SPACE}'), (7, '_spacefast_space_id', '${SPACE}'), (8, '_spacefast_app_user', '${OTHER_SPACE}'), (8, '_spacefast_space_id', '${OTHER_SPACE}'), (9, '_spacefast_app_user', '${SPACE}'), (9, '_spacefast_space_id', '${SPACE}');`,
      `INSERT INTO wp_options (option_name, option_value) VALUES ('spacefast_app_subject_7_${SPACE}', '${SUBJECT}'), ('spacefast_app_subject_8_${OTHER_SPACE}', 'usr_${"b".repeat(64)}'), ('spacefast_app_subject_9_${SPACE}', 'usr_${"c".repeat(64)}');`,
      "INSERT INTO wp_sfi_accounts VALUES (7, NULL, 'active', 1), (8, NULL, 'active', 1), (9, NULL, 'suspended', 1);",
    ].join(" "),
  );
  const url = new URL(mysql.url);
  rt = await startRuntime({
    env: {
      // Ignore ambient API database configuration; this fixture owns its MySQL tuple.
      DATABASE_URL: "",
      SPACEFAST_ZERO_DATABASE_URL: "",
      // The provider's own DB_* tuple, as the box exposes it to the engine.
      DB_HOST: `${url.hostname}:${url.port}`,
      DB_NAME: DATABASE,
      DB_USER: decodeURIComponent(url.username),
      DB_PASSWORD: decodeURIComponent(url.password),
    },
    phpBinary: process.env.SPACEFAST_REAL_PHP ?? "php",
  });
  // The site's wp-config.php, the way the provider writes it: salts as
  // constants, then WordPress boots. Only its constants are read here.
  writeFileSync(
    path.join(rt.root, "wp-config.php"),
    [
      "<?php",
      `define('AUTH_KEY',         '${AUTH_KEY}');`,
      `define('AUTH_SALT',        '${AUTH_SALT}');`,
      "$table_prefix  = 'wp_';",
      "require_once(ABSPATH . 'wp-settings.php');",
      "",
    ].join("\n"),
  );
}, MYSQL_SETUP_TIMEOUT_MS + 60_000);

afterAll(() => {
  rt?.stop();
  stopMysqlContainers(CONTAINER_NAME_PREFIX);
});

test("the verifier accepts only live Users sessions for this Space and an active account", () => {
  const now = Math.floor(Date.now() / 1000);
  const live = "live-secret-" + randomBytes(8).toString("hex");
  const revoked = "revoked-secret-" + randomBytes(8).toString("hex");
  const expired = "expired-secret-" + randomBytes(8).toString("hex");
  const otherSpace = "other-space-secret-" + randomBytes(8).toString("hex");
  const suspended = "suspended-secret-" + randomBytes(8).toString("hex");
  seedSession({ userId: 7, secret: live, expiresAt: now + 3600 });
  seedSession({ userId: 7, secret: revoked, expiresAt: now + 3600, revoked: true });
  seedSession({ userId: 7, secret: expired, expiresAt: now - 1 });
  seedSession({ userId: 8, secret: otherSpace, expiresAt: now + 3600 });
  seedSession({ userId: 9, secret: suspended, expiresAt: now + 3600 });

  const verify = (cookie: string) => {
    const url = new URL(mysql.url);
    const result = spawnSync(
      process.env.SPACEFAST_REAL_PHP ?? "php",
      [
        "-r",
        "require $argv[1] . '/shared/context.php'; require $argv[1] . '/runtime/space-users.php'; " +
          "$auth = _stattic_space_users_verify_session($argv[2], $argv[3], $argv[4], json_decode($argv[5], true), $argv[6]);" +
          "if (class_exists('Spacefast\\\\Identity\\\\Plugin', false)) throw new RuntimeException('Session verification booted WordPress.');" +
          "echo json_encode($auth, JSON_THROW_ON_ERROR);",
        rt.engineRoot,
        rt.storageRoot,
        SPACE,
        HOST,
        JSON.stringify(usersSettings),
        cookie,
      ],
      {
        encoding: "utf8",
        env: {
          ...process.env,
          DATABASE_URL: "",
          SPACEFAST_ZERO_DATABASE_URL: "",
          DB_HOST: `${url.hostname}:${url.port}`,
          DB_NAME: DATABASE,
          DB_USER: decodeURIComponent(url.username),
          DB_PASSWORD: decodeURIComponent(url.password),
        },
      },
    );
    expect(result.status, result.stderr || result.stdout).toBe(0);
    return JSON.parse(result.stdout);
  };

  expect(verify(live)).toEqual({
    user: { id: SUBJECT, displayName: "Alice App" },
    userId: SUBJECT,
    displayName: "Alice App",
    provider: "space-users",
    isAuthenticated: true,
    isGuest: false,
  });
  for (const cookie of ["", revoked, expired, otherSpace, suspended, "not-a-session"]) {
    expect(verify(cookie)).toBeNull();
  }
});
