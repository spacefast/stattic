// Signing in to a SpacePress preview: the person the Space session names is a
// WordPress user in the preview's own SQLite database, and the Space's team
// runs that preview's admin through the live Space role lent on each request
// (wordpress/typedpress.php, wordpress/space-role.php). Disposable DB
// credentials are intentionally local to this isolated test server.
import { Database } from "bun:sqlite";
import { test } from "bun:test";
import assert from "node:assert/strict";
import { createHash, generateKeyPairSync, randomUUID } from "node:crypto";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import os from "node:os";
import path from "node:path";

import { z } from "zod";

import { prepareZeroV2SourcePublish } from "../../packages/cli/src/zero-v2-source-publish.ts";
import { deploy, get, initializeSpacePress, putRoute, startRuntime } from "./harness.ts";
import { MYSQL_SETUP_TIMEOUT_MS, startMysqlContainer } from "./mysql-container.ts";
import { installWordpress } from "./zero-wordpress.ts";

// The real control-plane minter signs the sign-in handoffs, under a key this
// test owns. It caches the first key it reads, so this file runs in its own
// process (run.sh).
const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-spacepress-preview-sign-in-test";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-spacepress-preview-sign-in-test-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-spacepress-preview-sign-in-test-token";
process.env.WP_CLOUD_CLIENT_NAME = "runtime-spacepress-preview-sign-in-test";
process.env.SPACEFAST_API_URL = "https://api.spacefast.com";
const [{ mintAuthorityToken }, { runtimeJwks }] = await Promise.all([
  import("../../apps/control-plane/src/access/authorize.ts"),
  import("../../apps/control-plane/src/runtime/auth.ts"),
]);

const SPACE = "spc_sp_sign_in";
const TEAM = "team_sp_sign_in";
const LIVE = "sp-sign-in-live.test";
const versionHost = (version: string) => `${version.replaceAll("_", "-")}.sp-sign-in.test`;
const VIEW: ("page.view" | "comments.read" | "comments.write")[] = [
  "page.view",
  "comments.read",
  "comments.write",
];
const MANAGE = [...VIEW, "content.publish", "access.manage"];

const whoSchema = z.object({
  user: z.number(),
  login: z.string(),
  prefix: z.string(),
  stored_roles: z.array(z.string()),
  can_manage_options: z.boolean(),
  can_install_plugins: z.boolean(),
  can_edit_users: z.boolean(),
});

async function boxPlugin(work: string, slug: string, version: string, digest: string) {
  const url = `https://downloads.wordpress.org/plugin/${slug}.${version}.zip`;
  let response: Response | undefined;
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    // oxlint-disable-next-line no-await-in-loop -- attempts are sequential retries
    response = await fetch(url, { signal: AbortSignal.timeout(60_000) }).catch(() => undefined);
    if (response?.status === 200) break;
    // oxlint-disable-next-line no-await-in-loop -- backoff between sequential retries
    await response?.body?.cancel().catch(() => {});
    if (response && response.status < 500) break;
    // oxlint-disable-next-line no-await-in-loop -- backoff between sequential retries
    await Bun.sleep(attempt * 2_000);
  }
  if (response?.status !== 200) throw new Error(`${url}: ${response?.status ?? "no response"}`);
  const bytes = new Uint8Array(await response.arrayBuffer());
  assert.equal(new Bun.CryptoHasher("sha256").update(bytes).digest("hex"), digest);
  const zip = path.join(work, `${slug}.zip`);
  writeFileSync(zip, bytes);
  return zip;
}

test(
  "a team member signed in to a SpacePress preview runs its admin, in the preview's own database",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-spacepress-sign-in-"));
    let db: Awaited<ReturnType<typeof startMysqlContainer>> | undefined;
    let rt: Awaited<ReturnType<typeof startRuntime>> | undefined;
    try {
      db = await startMysqlContainer({
        namePrefix: "sf-spacepress-sign-in",
        database: "wordpress",
        rootPassword: "disposable-zero-v2-proof",
        flavor: "mariadb",
      });
      rt = await startRuntime({
        env: { SPACEFAST_ZERO_DATABASE_URL: db.url, PHP_CLI_SERVER_WORKERS: "4" },
      });
      const runtime = rt;
      const wp = await installWordpress(runtime, work, db, LIVE);
      // Installed and inactive, as box plugin repair leaves SpacePress boxes.
      wp(
        "plugin",
        "install",
        await boxPlugin(
          work,
          "two-factor",
          "0.17.0",
          "4d1b0f8e5d82e24876c2b4e86f119122e28a8dbaf76dfde495173226c3356f37",
        ),
      );
      wp(
        "plugin",
        "install",
        await boxPlugin(
          work,
          "sqlite-database-integration",
          "3.1.3",
          "f6ce35e8419d0b9326221a907627d905e25c2a5ba2afc86492811f14c851efe0",
        ),
      );
      // Who WordPress says the visitor is, from inside the request, and where.
      writeFileSync(
        path.join(runtime.root, "wp-content/mu-plugins/sp-sign-in-proof.php"),
        `<?php
add_action('rest_api_init', static function () {
  register_rest_route('sp-sign-in-proof/v1', '/who', [
    'methods' => ['GET'], 'permission_callback' => '__return_true',
    'callback' => static function () {
      global $wpdb;
      $user = wp_get_current_user();
      return [
        'user' => (int) $user->ID,
        'login' => (string) $user->user_login,
        'prefix' => (string) $wpdb->prefix,
        'stored_roles' => $user->exists() ? array_values((array) get_userdata($user->ID)->roles) : [],
        'can_manage_options' => current_user_can('manage_options'),
        'can_install_plugins' => current_user_can('install_plugins'),
        'can_edit_users' => current_user_can('edit_users'),
      ];
    },
  ]);
});
`,
      );

      const project = path.join(work, "app");
      mkdirSync(path.join(project, "sp/app"), { recursive: true });
      writeFileSync(
        path.join(project, "spacepress.config.ts"),
        'import {defineConfig} from "spacepress"; export default defineConfig({name: "signin", admin: false});',
      );
      writeFileSync(
        path.join(project, "sp/app/page.tsx"),
        "export default function Home(){return <main><h1>SpacePress sign-in page</h1></main>;}",
      );
      writeFileSync(
        path.join(project, "sp/actions.ts"),
        'import {mutation} from "spacepress"; export const whoami = mutation({access:"anyone"}, async (_input: {}, ctx) => ({role: ctx.user === null ? null : ctx.user.role}));',
      );
      const step = await prepareZeroV2SourcePublish(project);
      assert.ok(step);
      try {
        for (const versionId of ["ver_live", "ver_next"]) {
          // oxlint-disable-next-line no-await-in-loop -- versions publish in order
          await deploy(runtime, {
            spaceId: SPACE,
            versionId,
            files: Object.fromEntries(
              (step.additionalFiles ?? []).map((file) => [
                path.relative(file.root, file.absolutePath),
                readFileSync(file.absolutePath),
              ]),
            ),
            finalize: {
              zero_generation: "spacepress",
              zero_v2: { variableValues: {}, previewVariableValues: {} },
            },
          });
        }
      } finally {
        await step.cleanup?.();
      }
      assert.equal(
        (await initializeSpacePress(runtime, SPACE, "ver_live", null)).initialized,
        true,
      );

      // The Space's Grants as the control plane compiles them: the owning team
      // manages live and views every version; an outsider was shared every
      // version and holds no role on the live Space.
      const outsiderAuthority = `external:${createHash("sha256").update("spacefast-share\0outsider").digest("hex")}`;
      const grant = (
        id: string,
        audience: Record<string, string>,
        capabilities: string[],
        target: "live" | "all_versions",
      ) => ({
        id,
        generation: 1,
        audience,
        resources: { include: ["/**"], exclude: [] },
        capabilities,
        constraints: {},
        target: { kind: target },
        source: { kind: "managed", reference: `test:${id}` },
      });
      const route = {
        version_id: "ver_live",
        hostnames: [LIVE],
        production_hostnames: [LIVE],
        version_hostnames: ["ver_live", "ver_next"].map((version_id) => ({
          hostname: versionHost(version_id),
          version_id,
        })),
        config: {
          zero_generation: "spacepress",
          projection_generation: 1,
          visitor_issuer: "spacefast-api",
          visitor_jwks: runtimeJwks(),
          authorization: {
            generation: 1,
            sessionVersion: 0,
            sessionKey: "d".repeat(64),
            fence: "none",
            acquireUrl: "https://access.spacefast.test/acquire/sp-sign-in",
            spaceClaimed: true,
            teamId: TEAM,
            membershipEpoch: 0,
            accessPage: {
              displayName: null,
              accountUrl: "https://api.spacefast.com/v1/auth/oauth2/authorize",
              issuer: "https://api.spacefast.com/v1/auth",
              connections: [],
              exchange: {
                passwordUrl: "https://access.spacefast.test/acquire/sp-sign-in/password",
                tokenUrl: "https://access.spacefast.test/acquire/sp-sign-in/token",
                requestUrl: "https://access.spacefast.test/acquire/sp-sign-in/request",
                credential: "spacepress-sign-in-exchange-credential-0123456789",
              },
            },
            grants: [
              grant("grt_team_live", { kind: "team", teamId: TEAM }, MANAGE, "live"),
              grant(
                "grt_team_versions",
                { kind: "team", teamId: TEAM },
                MANAGE.filter((c) => c !== "content.publish"),
                "all_versions",
              ),
              grant(
                "grt_outsider_versions",
                { kind: "external", issuer: "spacefast-share", subject: "outsider" },
                VIEW,
                "all_versions",
              ),
            ],
          },
        },
      };
      await putRoute(runtime, SPACE, "production", route);

      // Each person signs in on the host they visit, the way the token
      // endpoint's handoff does: the team member with a real-authority member
      // session, the outsider with their share consent.
      const signIn = async (host: string, who: "member" | "outsider") => {
        const authority =
          who === "member"
            ? `external:${createHash("sha256").update("spacefast-share\0member").digest("hex")}`
            : outsiderAuthority;
        const mint: Parameters<typeof mintAuthorityToken>[0] = {
          sub: authority,
          authorities: [authority],
          principal: `account:ps1_${who}`,
          spaceId: SPACE,
          sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
          generation: 1,
          sessionVersion: 0,
          audience: host,
          jti: `jti_${randomUUID()}`,
        };
        const minted = await mintAuthorityToken(
          who === "member"
            ? {
                ...mint,
                spaceOrigin: { authorities: ["member:mem_sp_sign_in"], capabilities: VIEW },
              }
            : mint,
        );
        const response = await get(
          runtime,
          host,
          `/__spacefast/in/${encodeURIComponent(minted.token)}?return=/`,
        );
        assert.equal(response.status, 303, (await response.clone().text()) + runtime.diagnostics());
        const cookie = (response.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
        assert.ok(cookie.startsWith("spacefast_session_dev=sfv2_"), cookie);
        return cookie;
      };
      const who = async (host: string, cookie: string) => {
        const response = await get(runtime, host, "/wp-json/sp-sign-in-proof/v1/who", {
          headers: { cookie },
        });
        assert.equal(response.status, 200, (await response.clone().text()) + runtime.diagnostics());
        return whoSchema.parse(await response.json());
      };

      // The role the app's own action lane reads.
      const whoami = async (host: string, cookie: string) => {
        const response = await get(runtime, host, "/wp-json/spacepress/v1/actions/whoami", {
          method: "POST",
          headers: {
            cookie,
            "content-type": "application/json",
            origin: `http://${host}`,
            "sec-fetch-site": "same-origin",
          },
          body: "{}",
        });
        assert.equal(response.status, 200, (await response.clone().text()) + runtime.diagnostics());
        return z
          .object({ data: z.object({ role: z.string().nullable() }) })
          .parse(await response.json()).data.role;
      };

      const preview = versionHost("ver_next");
      const cookies = {
        member: await signIn(preview, "member"),
        outsider: await signIn(preview, "outsider"),
      };
      const member = await who(preview, cookies.member);
      const outsider = await who(preview, cookies.outsider);
      // Each is their own row, the one the platform keeps, in the preview's
      // own tables.
      assert.match(member.prefix, /^sfp[0-9a-f]{12}_$/);
      assert.equal(outsider.prefix, member.prefix);
      assert.match(member.login, /^spacefast_[0-9a-f]{32}$/);
      assert.match(outsider.login, /^spacefast_[0-9a-f]{32}$/);
      assert.notEqual(member.user, outsider.user);
      // The team member is the preview's administrator, lent and never stored.
      assert.equal(await whoami(preview, cookies.member), "admin");
      assert.equal(member.can_manage_options, true);
      assert.deepEqual(member.stored_roles, []);
      assert.equal(member.can_install_plugins, false);
      assert.equal(member.can_edit_users, false);
      // A Grant on every version is access to the preview, not a role in it.
      assert.notEqual(await whoami(preview, cookies.outsider), "admin");
      assert.equal(outsider.can_manage_options, false);
      assert.deepEqual(outsider.stored_roles, []);

      // The rows are in the preview's file, and none reached the site's MySQL.
      const sqlite = new Database(
        path.join(runtime.storageRoot, "spaces", SPACE, "previews", "ver_next", "database.sqlite"),
      );
      const logins = z
        .array(z.object({ user_login: z.string() }))
        .parse(
          sqlite
            .query(
              `SELECT user_login FROM ${member.prefix}users WHERE user_login LIKE 'spacefast_%' ORDER BY ID`,
            )
            .all(),
        );
      sqlite.close();
      assert.deepEqual(
        logins.map((row) => row.user_login),
        [member.login, outsider.login],
      );
      assert.equal(
        String(
          wp(
            "eval",
            `global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'spacefast\\\\_%'");`,
          ),
        ).trim(),
        "0",
      );

      // Before a Space's first SpacePress promote its route still names the
      // previous runtime. The preview is SpacePress all the same: the app API
      // and core's ability run route lend the team member the preview's admin
      // role and keep the answer out of the edge cache.
      for (const previousGeneration of ["none", "legacy_0_8"]) {
        await putRoute(runtime, SPACE, "production", {
          ...route,
          config: { ...route.config, zero_generation: previousGeneration },
        });
        for (const restPath of [
          "/wp-json/spacepress/v1/actions/whoami",
          "/wp-json/wp-abilities/v1/abilities/signin/whoami/run",
        ]) {
          const response = await get(runtime, preview, restPath, {
            method: "POST",
            headers: {
              cookie: cookies.member,
              "content-type": "application/json",
              origin: `http://${preview}`,
              "sec-fetch-site": "same-origin",
            },
            body: restPath.endsWith("/run") ? JSON.stringify({ input: {} }) : "{}",
          });
          const text = await response.text();
          assert.equal(response.status, 200, `${previousGeneration} ${restPath}: ${text}`);
          assert.equal(
            response.headers.get("a8c-edge-cache"),
            "no-cache",
            `${previousGeneration} ${restPath}`,
          );
          const body: unknown = JSON.parse(text);
          const role = z
            .union([
              z
                .object({ data: z.object({ role: z.string().nullable() }) })
                .transform((v) => v.data.role),
              z.object({ role: z.string().nullable() }).transform((v) => v.role),
            ])
            .parse(body);
          assert.equal(role, "admin", `${previousGeneration} ${restPath}`);
        }
      }
      await putRoute(runtime, SPACE, "production", route);

      // A version link that serves the live version runs on the site's own
      // WordPress, where a version host lends no role.
      const liveCookie = await signIn(versionHost("ver_live"), "member");
      const liveVersion = await who(versionHost("ver_live"), liveCookie);
      assert.equal(liveVersion.prefix, "wp_");
      assert.notEqual(await whoami(versionHost("ver_live"), liveCookie), "admin");
      assert.equal(liveVersion.can_manage_options, false);
    } catch (error) {
      throw new Error(`SpacePress preview sign-in acceptance failed.\n${rt?.diagnostics() ?? ""}`, {
        cause: error,
      });
    } finally {
      rt?.stop();
      await db?.stop();
      rmSync(work, { recursive: true, force: true });
    }
  },
  MYSQL_SETUP_TIMEOUT_MS + 240_000,
);
