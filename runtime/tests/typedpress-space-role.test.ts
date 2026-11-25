// The Spacefast plugin for TypedPress, on real WordPress and MariaDB: a person
// signed in to the Space is one WordPress user, their Space role is lent on
// each request from the live Space session and never stored, the role ends
// the moment the Grant behind it narrows, and nothing the app or WordPress
// does makes the role outlive the session. Disposable DB credentials are
// intentionally local to this isolated test server.
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
import { downloadPinnedPlugin, installWordpress } from "./zero-wordpress.ts";

// The real control-plane minter signs the sign-in handoffs, under a key this
// test owns, so the runtime checks exactly what production sends. It caches
// the first key it reads, so this file runs in its own process (run.sh).
const platformKey = generateKeyPairSync("ed25519");
process.env.SPACEFAST_RUNTIME_JWT_PRIVATE_KEY = Buffer.from(
  platformKey.privateKey.export({ format: "pem", type: "pkcs8" }),
).toString("base64");
process.env.AUTH_WPCOM_CLIENT_ID = "runtime-typedpress-role-test";
process.env.AUTH_WPCOM_CLIENT_SECRET = "runtime-typedpress-role-test-secret";
process.env.WP_CLOUD_API_TOKEN = "runtime-typedpress-role-test-token";
process.env.WP_CLOUD_CLIENT_NAME = "runtime-typedpress-role-test";
// The account issuer a sign-in names is the API the box is configured against.
process.env.SPACEFAST_API_URL = "https://api.spacefast.com";
const [{ mintAuthorityToken }, { runtimeJwks }] = await Promise.all([
  import("../../apps/control-plane/src/access/authorize.ts"),
  import("../../apps/control-plane/src/runtime/auth.ts"),
]);

const SPACE = "spc_tp_role";
const VERSION = "ver_tp_role";
const HOST = "tp-role.test";
const ROLE_CAPABILITIES = {
  viewer: ["page.view"],
  commenter: ["page.view", "comments.read", "comments.write"],
  editor: ["page.view", "comments.read", "comments.write", "content.publish"],
  manager: ["page.view", "comments.read", "comments.write", "content.publish", "access.manage"],
} as const;
type Role = keyof typeof ROLE_CAPABILITIES;

const whoSchema = z.object({
  user: z.number(),
  login: z.string(),
  stored_roles: z.array(z.string()),
  app_role: z.string().nullable(),
  can_edit_others_posts: z.boolean(),
  can_manage_options: z.boolean(),
  can_install_plugins: z.boolean(),
  can_export: z.boolean(),
  can_edit_users: z.boolean(),
  can_unfiltered_html: z.boolean(),
  platform_cookie_visible: z.boolean(),
  app_cookie_visible: z.boolean(),
  application_password: z.boolean(),
});

test(
  "a TypedPress app lends the live Space role per request, stores none, and gives no installation-wide power",
  async () => {
    const work = mkdtempSync(path.join(os.tmpdir(), "sf-tp-role-"));
    let db: Awaited<ReturnType<typeof startMysqlContainer>> | undefined;
    let rt: Awaited<ReturnType<typeof startRuntime>> | undefined;
    try {
      db = await startMysqlContainer({
        namePrefix: "sf-tp-role",
        database: "wordpress",
        rootPassword: "disposable-zero-v2-proof",
        flavor: "mariadb",
      });
      rt = await startRuntime({ env: { SPACEFAST_ZERO_DATABASE_URL: db.url } });
      const runtime = rt;
      const wp = await installWordpress(runtime, work, db, HOST);
      // As a TypedPress box has it (typedpress-publish.test.ts pins the bytes).
      wp(
        "plugin",
        "install",
        await downloadPinnedPlugin(
          work,
          "two-factor",
          "0.17.0",
          "4d1b0f8e5d82e24876c2b4e86f119122e28a8dbaf76dfde495173226c3356f37",
        ),
      );
      // A finalize that doesn't go live is a preview's, and needs the driver
      // previews open their own database with.
      wp(
        "plugin",
        "install",
        await downloadPinnedPlugin(
          work,
          "sqlite-database-integration",
          "3.1.3",
          "f6ce35e8419d0b9326221a907627d905e25c2a5ba2afc86492811f14c851efe0",
        ),
      );

      // What the app's own code sees, from inside the request: who WordPress
      // says the visitor is, what TypedPress reads as their role, what they
      // may do, and which cookies reached the app.
      writeFileSync(
        path.join(runtime.root, "wp-content/mu-plugins/tp-role-proof.php"),
        `<?php
add_action('rest_api_init', static function () {
  register_rest_route('tp-role-proof/v1', '/who', [
    'methods' => ['GET', 'POST'], 'permission_callback' => '__return_true',
    'callback' => static function () {
      $user = wp_get_current_user();
      $app = class_exists('\\\\SpacePress\\\\Wp\\\\WpAuth')
        ? \\SpacePress\\Wp\\WpAuth::current()
        : (class_exists('\\\\TypedPress\\\\Wp\\\\WpAuth') ? \\TypedPress\\Wp\\WpAuth::current() : null);
      return [
        'user' => (int) $user->ID,
        'login' => (string) $user->user_login,
        'stored_roles' => $user->exists() ? array_values((array) get_userdata($user->ID)->roles) : [],
        'app_role' => is_array($app) ? $app['role'] : null,
        'can_edit_others_posts' => current_user_can('edit_others_posts'),
        'can_manage_options' => current_user_can('manage_options'),
        'can_install_plugins' => current_user_can('install_plugins'),
        'can_export' => current_user_can('export'),
        'can_edit_users' => current_user_can('edit_users'),
        'can_unfiltered_html' => current_user_can('unfiltered_html'),
        'platform_cookie_visible' => (bool) array_filter(array_keys($_COOKIE), static fn ($n) => str_starts_with((string) $n, 'spacefast_'))
          || str_contains((string) ($_SERVER['HTTP_COOKIE'] ?? ''), 'spacefast_session'),
        'app_cookie_visible' => ($_COOKIE['app_pref'] ?? null) === 'dark',
        'application_password' => $user->exists() && wp_is_application_passwords_available_for_user($user),
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
        'import {defineConfig, userProjection, text, email} from "spacepress"; export const members = userProjection({ email: email().unique().max(191), name: text().max(80) }, { access: { read: "self", update: "self", create: "anyone", delete: "admin" } }); export const users = { members }; export default defineConfig({name:"roles",admin:false});',
      );
      writeFileSync(
        path.join(project, "sp/app/page.tsx"),
        "export default function Home(){return <main><h1>Role proof</h1></main>;}",
      );
      writeFileSync(
        path.join(project, "sp/actions.ts"),
        'import {mutation} from "spacepress"; export const whoami = mutation({access:"anyone"}, async (_input: {}, ctx) => ({role: ctx.user === null ? null : ctx.user.role}));',
      );
      const step = await prepareZeroV2SourcePublish(project);
      assert.ok(step);
      try {
        await deploy(runtime, {
          spaceId: SPACE,
          versionId: VERSION,
          files: Object.fromEntries(
            (step.additionalFiles ?? []).map((file) => [
              path.relative(file.root, file.absolutePath),
              readFileSync(file.absolutePath),
            ]),
          ),
          finalize: { zero_generation: "spacepress" },
        });
      } finally {
        await step.cleanup?.();
      }

      // One consent Grant per person, on the whole live Space, carrying what
      // their Space role gives. `folder` holds a manager's capabilities on one
      // folder only: access to that folder, not a role in the app.
      const grant = (subject: string, role: Role, include = ["/**"]) => ({
        id: `grt_${subject}`,
        generation: 1,
        audience: { kind: "external", issuer: "spacefast-share", subject },
        resources: { include, exclude: [] },
        capabilities: ROLE_CAPABILITIES[role],
        constraints: {},
        target: { kind: "live" },
        source: { kind: "system", reference: `share-consent:${subject}:grt_source_${subject}` },
      });
      // The folder holder also views the whole Space, as a viewer.
      const folderView = { ...grant("folder", "viewer"), id: "grt_folder_view" };
      // A real-authority session names the Space's own team Grant, not a
      // consent copy: the team's members are managers.
      const teamManagers = {
        ...grant("team", "manager"),
        id: "grt_team_managers",
        audience: { kind: "team", teamId: "team_tp_role" },
        source: { kind: "managed", reference: "test:team" },
      };
      const activate = (
        generation: number,
        roles: Record<string, Role>,
        teamManager: "manager" | "viewer" = "manager",
      ) =>
        putRoute(runtime, SPACE, "production", {
          version_id: VERSION,
          hostnames: [HOST],
          production_hostnames: [HOST],
          config: {
            zero_generation: "spacepress",
            projection_generation: generation,
            visitor_issuer: "spacefast-api",
            visitor_jwks: runtimeJwks(),
            authorization: {
              generation,
              sessionVersion: 0,
              sessionKey: "c".repeat(64),
              fence: "none",
              acquireUrl: "https://access.spacefast.test/acquire/tp-role",
              spaceClaimed: true,
              teamId: "team_tp_role",
              membershipEpoch: 0,
              accessPage: {
                displayName: null,
                accountUrl: "https://api.spacefast.com/v1/auth/oauth2/authorize",
                issuer: "https://api.spacefast.com/v1/auth",
                connections: [],
                exchange: {
                  passwordUrl: "https://access.spacefast.test/acquire/tp-role/password",
                  tokenUrl: "https://access.spacefast.test/acquire/tp-role/token",
                  requestUrl: "https://access.spacefast.test/acquire/tp-role/request",
                  credential: "typedpress-role-exchange-credential-0123456789",
                },
              },
              grants: [
                ...Object.entries(roles).map(([subject, role]) => grant(subject, role)),
                { ...grant("folder", "manager", ["/notes/**"]) },
                folderView,
                ...(teamManager === "manager"
                  ? [teamManagers]
                  : [{ ...teamManagers, capabilities: ROLE_CAPABILITIES.viewer }]),
              ],
            },
          },
        });
      // Promote initializes the version on the site before its route moves.
      await initializeSpacePress(runtime, SPACE, VERSION, null);
      await activate(1, { manager: "manager", editor: "editor", viewer: "viewer" });

      // Each person signs in the way the token endpoint's handoff does.
      const signIn = async (subject: string) => {
        const authority = `external:${createHash("sha256").update(`spacefast-share\0${subject}`).digest("hex")}`;
        const minted = await mintAuthorityToken({
          sub: authority,
          authorities: [authority],
          principal: `account:ps1_${subject}`,
          spaceId: SPACE,
          sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
          generation: 1,
          sessionVersion: 0,
          audience: HOST,
          jti: `jti_${randomUUID()}`,
        });
        const response = await get(
          runtime,
          HOST,
          `/__spacefast/in/${encodeURIComponent(minted.token)}?return=/`,
        );
        assert.equal(response.status, 303, await response.clone().text());
        const cookie = (response.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
        assert.ok(cookie.startsWith("spacefast_session_dev=sfv2_"), cookie);
        return cookie;
      };
      // A Sign in with Spacefast session on an engine with real-authority
      // sessions: the member authority, capped at view and comment on the
      // Space's origin.
      const signInAsMember = async () => {
        const consent =
          `external:${createHash("sha256").update("spacefast-share\0member").digest("hex")}` as const;
        const minted = await mintAuthorityToken({
          sub: consent,
          authorities: [consent],
          spaceOrigin: {
            authorities: ["member:mem_tp_role"],
            capabilities: ["page.view", "comments.read", "comments.write"],
          },
          principal: "account:ps1_member",
          spaceId: SPACE,
          sessionId: createHash("sha256").update(randomUUID()).digest("hex"),
          generation: 1,
          sessionVersion: 0,
          audience: HOST,
          jti: `jti_${randomUUID()}`,
        });
        const response = await get(
          runtime,
          HOST,
          `/__spacefast/in/${encodeURIComponent(minted.token)}?return=/`,
        );
        assert.equal(response.status, 303, await response.clone().text());
        return (response.headers.get("set-cookie") ?? "").split(";")[0] ?? "";
      };
      const sameOrigin = { origin: `http://${HOST}`, "sec-fetch-site": "same-origin" };
      const who = async (
        cookie: string,
        init: { method?: string; headers?: Record<string, string> } = {},
      ) => {
        const response = await get(runtime, HOST, "/wp-json/tp-role-proof/v1/who", {
          method: init.method ?? "GET",
          headers: { cookie: `${cookie}; app_pref=dark`, ...sameOrigin, ...init.headers },
        });
        assert.equal(response.status, 200, (await response.clone().text()) + runtime.diagnostics());
        assert.equal(response.headers.get("cache-control"), "private, no-store");
        return whoSchema.parse(await response.json());
      };
      const whoami = async (cookie: string) => {
        const response = await get(runtime, HOST, "/wp-json/spacepress/v1/actions/whoami", {
          method: "POST",
          headers: { cookie, "content-type": "application/json", ...sameOrigin },
          body: "{}",
        });
        assert.equal(response.status, 200, (await response.clone().text()) + runtime.diagnostics());
        return z
          .object({ data: z.object({ role: z.string().nullable() }) })
          .parse(await response.json()).data.role;
      };
      // The generated client runs the same action as the app's Ability.
      const runWhoami = async (cookie: string) => {
        const response = await get(
          runtime,
          HOST,
          "/wp-json/wp-abilities/v1/abilities/roles/whoami/run",
          {
            method: "POST",
            headers: { cookie, "content-type": "application/json", ...sameOrigin },
            body: JSON.stringify({ input: {} }),
          },
        );
        assert.equal(response.status, 200, (await response.clone().text()) + runtime.diagnostics());
        assert.equal(response.headers.get("cache-control"), "private, no-store");
        return z.object({ role: z.string().nullable() }).parse(await response.json()).role;
      };

      const cookies = {
        manager: await signIn("manager"),
        editor: await signIn("editor"),
        viewer: await signIn("viewer"),
        folder: await signIn("folder"),
      };
      const seen = {
        manager: await who(cookies.manager),
        editor: await who(cookies.editor),
        viewer: await who(cookies.viewer),
        folder: await who(cookies.folder),
      };
      // Each person is their own WordPress user, the row the platform keeps.
      const users = Object.values(seen).map((s) => s.user);
      assert.ok(users.every((id) => id > 0));
      assert.equal(new Set(users).size, users.length);
      for (const s of Object.values(seen)) {
        assert.match(s.login, /^spacefast_[0-9a-f]{32}$/);
        // Nothing is stored: the role is lent by this request.
        assert.deepEqual(s.stored_roles, []);
        // Installation-wide powers stay off for everyone, managers included.
        assert.equal(s.can_install_plugins, false);
        assert.equal(s.can_export, false);
        assert.equal(s.can_edit_users, false);
        assert.equal(s.can_unfiltered_html, false);
        // No WordPress credential that could outlive the Space session.
        assert.equal(s.application_password, false);
        // The app sees its own cookie, never the platform's session.
        assert.equal(s.platform_cookie_visible, false);
        assert.equal(s.app_cookie_visible, true);
      }
      // The Space roles, as the app reads them.
      assert.equal(seen.manager.app_role, "admin");
      assert.equal(seen.manager.can_manage_options, true);
      assert.equal(seen.editor.app_role, "editor");
      assert.equal(seen.editor.can_edit_others_posts, true);
      assert.equal(seen.editor.can_manage_options, false);
      assert.equal(seen.viewer.app_role, "subscriber");
      assert.equal(seen.viewer.can_edit_others_posts, false);
      // A manager of one folder is a viewer of the app.
      assert.equal(seen.folder.app_role, "subscriber");
      assert.equal(seen.folder.can_manage_options, false);
      // TypedPress's own action lane reads the same role.
      assert.equal(await whoami(cookies.manager), "admin");
      assert.equal(await whoami(cookies.viewer), "subscriber");
      assert.equal(await runWhoami(cookies.manager), "admin");
      assert.equal(await runWhoami(cookies.viewer), "subscriber");

      // A real-authority member session: capped at view and comment on the
      // page, yet the app is lent the person's live role from the team's
      // manager Grant, and loses it the moment that Grant narrows.
      const member = await signInAsMember();
      const memberSeen = await who(member);
      assert.equal(memberSeen.app_role, "admin");
      assert.equal(memberSeen.can_manage_options, true);
      assert.deepEqual(memberSeen.stored_roles, []);
      assert.equal(memberSeen.can_install_plugins, false);
      await activate(2, { manager: "manager", editor: "editor", viewer: "viewer" }, "viewer");
      const memberNarrowed = await who(member);
      assert.equal(memberNarrowed.user, memberSeen.user);
      assert.equal(memberNarrowed.app_role, "subscriber");
      assert.equal(memberNarrowed.can_manage_options, false);

      // The person's Grant narrows to viewer: the same session, the next
      // request, a viewer. Nothing to renew, nothing stored to undo.
      await activate(3, { manager: "viewer", editor: "editor", viewer: "viewer" });
      const narrowed = await who(cookies.manager);
      assert.equal(narrowed.user, seen.manager.user);
      assert.equal(narrowed.app_role, "subscriber");
      assert.equal(narrowed.can_manage_options, false);
      assert.deepEqual(narrowed.stored_roles, []);
      // ...and a write from another origin never carries the role at all.
      const foreign = await get(runtime, HOST, "/wp-json/tp-role-proof/v1/who", {
        method: "POST",
        headers: {
          cookie: cookies.editor,
          origin: "https://elsewhere.test",
          "sec-fetch-site": "cross-site",
        },
      });
      assert.notEqual(foreign.status, 500, await foreign.clone().text());
      if (foreign.status === 200) {
        const answer = whoSchema.parse(await foreign.json());
        assert.equal(answer.can_edit_others_posts, false);
      }

      // Without the Space session the row is nobody: no stored role and no
      // WordPress credential of its own signs in as it.
      const anonymous = await get(runtime, HOST, "/wp-json/tp-role-proof/v1/who", {
        headers: sameOrigin,
      });
      assert.equal(anonymous.status, 403, "the protected Space still needs a Spacefast sign-in");
    } catch (error) {
      throw new Error(`TypedPress Space role acceptance failed.\n${rt?.diagnostics() ?? ""}`, {
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
