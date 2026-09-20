# Spacefast Identity runtime component

`spacefast-identity.zip` is the PHP 8.2 compatible, dependency-scoped Spacefast
Identity 0.1.0 distribution. It is licensed GPL-2.0-or-later. The archive retains
its source, Composer dependencies, license texts and JavaScript notices.

`lock.json` pins the exact archive. `build.ts` verifies that digest before
extracting the immutable runtime component. Host integration lives in
`runtime/engine/wordpress/space-users.php`; changes to the dependency require a
new packaged archive and digest, with the package's behavior tests passing.
