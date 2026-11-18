=== TypedPress Admin ===
Requires at least: 7.1
Requires PHP: 8.2
License: GPL-2.0-or-later

The default admin for TypedPress apps on WordPress, at /wp-admin/new/. Emitted by `typedpress build` unless the config sets `admin: false`; classic /wp-admin/ stays available.

== Installation ==

1. Run `typedpress build`.
2. Install the plugins from the generated artifact. Use `typedpress-manifest.json` for
   the required versions and dependency order. Building or declaring a plugin
   downloads and packages it; it does not activate it or complete its setup.
   Keep existing plugin settings and follow each plugin's own setup instructions.
3. Activate the manifest's dependencies first, then `typedpress-runtime`,
   `typedpress-identity` when included, and `typedpress-app-<name>`. Activate `typedpress-admin`
   after the app. TypedPress Admin needs an active TypedPress app to register its screens.
4. Sign in with a WordPress account: you land on `/wp-admin/new/`. For a
   subdirectory install, include that path, for example `/blog/wp-admin/new/`.
   On a server that does not read `.htaccess`, the same page is
   `/wp-admin/admin.php?page=typedpress-admin`.

== WordPress integration ==

WordPress owns the login session, cookies, users, capabilities and REST nonces.
Content collections use WordPress's native block editor and Secure Custom
Fields' REST field. WordPress includes the editor; a separate Gutenberg
plugin is optional. TypedPress Admin does not require a separate identity provider
for its WordPress login.

Media stays in WordPress's existing media system. TypedPress's existing media API
applies its access rules to uploads, deletes, alt text and focal points; core
WordPress REST owns media reads and caption/title editing. Existing plugin
ownership and storage configuration remain in place.

Collections in the sidebar expands to the app's readable collections. Choose
All collections to return to the overview. Classic WordPress screens TypedPress
Admin has no screen of its own for (Comments, Tools, the remaining Settings
pages, plugin screens) open inside TypedPress Admin. Developer mode, on your profile,
adds Classic admin to the sidebar, which opens the full native WordPress admin;
choose TypedPress in the WordPress sidebar to return. TypedPress Admin also includes the
native Users, Settings, Plugins, Themes and Design routes.
The upstream dashboard is the home route, /wp-admin/new/; demonstration widgets
explicitly label their example data. See docs/admin.md for provenance, build
commands and external-service boundaries.
