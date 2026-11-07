=== Zero Admin ===
Requires at least: 7.1
Requires PHP: 8.2
License: GPL-2.0-or-later

Optional admin screens for Zero apps on WordPress. Emitted by `zero build` when the config sets `admin: true`.

== Installation ==

1. Set `admin: true` in your Zero config and run `zero build`.
2. Install the plugins from the generated artifact. Use `zero-manifest.json` for
   the required versions and dependency order. Building or declaring a plugin
   downloads and packages it; it does not activate it or complete its setup.
   Keep existing plugin settings and follow each plugin's own setup instructions.
3. Activate the manifest's dependencies first, then `zero-runtime`,
   `zero-identity` when included, and `zero-app-<name>`. Activate `zero-admin`
   after the app. Zero Admin needs an active Zero app to register its screens.
4. Enable a pretty permalink structure in WordPress, then open the site's
   `/zero-admin/` URL and sign in with a WordPress account. For a subdirectory
   install, include that path, for example `/blog/zero-admin/`.

== WordPress integration ==

WordPress owns the login session, cookies, users, capabilities and REST nonces.
Content collections use WordPress's native block editor and Secure Custom
Fields' REST field. WordPress 7.1 includes the editor; a separate Gutenberg
plugin is optional. Zero Admin does not require a separate identity provider
for its WordPress login.

Media stays in WordPress's existing media system. Zero's existing media API
applies its access rules to uploads, deletes, alt text and focal points; core
WordPress REST owns media reads and caption/title editing. Existing plugin
ownership and storage configuration remain in place.

Collections in the sidebar expands to the app's readable collections. Choose
All collections to return to the overview. Classic admin opens the full native
WordPress admin, including its sidebar and plugin screens. Choose Zero in the
WordPress sidebar to return to Zero Admin. Zero Admin also includes the native Users, Settings, Plugins, Themes and Design
routes. Classic admin remains available for every WordPress plugin screen.
The upstream dashboard is at /zero-admin/dashboard; demonstration widgets
explicitly label their example data. See docs/admin.md for provenance, build
commands and external-service boundaries.
