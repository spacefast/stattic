=== SpacePress ===
Stable tag: {{spVersion}}
Requires at least: {{wpVersion}}
Requires PHP: {{phpVersion}}
License: GPL-2.0-or-later

Runs a SpacePress app on this WordPress site.

1. Activate the plugin. It installs the plugins SpacePress depends on; the SpacePress screen in wp-admin lists them.
2. In your project: `spacepress login https://your-site.example`, then `spacepress push`.

The plugin keeps each deployed build in a private directory under wp-content and runs the one marked live.
Deactivating it stops the app and leaves its content in the database.
