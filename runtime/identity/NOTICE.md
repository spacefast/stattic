# Spacefast Identity runtime component

`spacefast-identity.zip` is the PHP 8.2 compatible, dependency-scoped Spacefast
Identity 0.1.2 distribution. It is licensed GPL-2.0-or-later. The archive retains
its source, Composer dependencies, license texts and JavaScript notices.

`lock.json` pins the exact archive. `build.ts` verifies that digest before
extracting the immutable runtime component. Host integration lives in
`runtime/engine/wordpress/space-users.php`; changes to the dependency require a
new packaged archive and digest, with the package's behavior tests passing.

This patch distribution applies the schema-initialization ownership fix from
[spacefast-identity#2](https://github.com/spacefast/spacefast-identity/pull/2)
to the 0.1.1 archive. Only `src/Schema.php` and the plugin version header change;
its schema and session format remain those of 0.1.1. The upstream implementation
and its concurrent migration test live in that PR. The complete packaged
behavior and migration suites pass against real WordPress and MariaDB.
