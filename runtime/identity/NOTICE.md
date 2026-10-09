# Spacefast Identity runtime component

`spacefast-identity.zip` is the PHP 8.2 compatible, dependency-scoped Spacefast
Identity 0.1.3 distribution. It is licensed GPL-2.0-or-later. The archive retains
its source, Composer dependencies, license texts and JavaScript notices.

`lock.json` pins the exact archive. `build.ts` verifies that digest before
extracting the immutable runtime component. Host integration lives in
`runtime/engine/wordpress/space-users.php`; changes to the dependency require a
new packaged archive and digest, with the package's behavior tests passing.

This patch distribution carries the schema ownership fix from
[spacefast-identity#2](https://github.com/spacefast/spacefast-identity/pull/2)
and backports the bootstrap recovery fixes from
[spacefast-identity#4](https://github.com/spacefast/spacefast-identity/pull/4)
to the shipped schema and session format. Relative to 0.1.2, only
`src/Schema.php`, `src/Plugin.php`, and the plugin version header change.
Waiters re-read the persisted schema version under the lock and clear stale
option caches. Unavailable boot returns retryable Identity responses, fails
native sign-in closed, and stops user-principal provisioning before it can
create a conflicting account mapping. Privacy export and erasure callbacks
report initialization failure explicitly instead of completing with missing
Identity data. Unrelated pages continue.

The exact archive passes the shipped-schema migration and complete packaged
behavior suites against real WordPress, PHP 8.5 and MariaDB, including stale
option-cache and bootstrap lock-contention regressions. The source PR owns
those regression tests in its packaged acceptance workflow.
