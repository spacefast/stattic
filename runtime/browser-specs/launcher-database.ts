import { requireLoopbackOnly } from "./confinement.ts";

/**
 * A fixture runs only as the launcher starts it: in its loopback-only network
 * namespace, on the database run.ts created for this run
 * (`sf_test_browser_<id>`) on the local test Postgres. Run directly it would
 * fall back to the shared dev database, so it stops here, before it imports
 * the control plane or seeds anything.
 */
export async function requireLauncherDatabase(): Promise<void> {
  await requireLoopbackOnly();
  // The whole URL, as run.ts builds it: no query string or fragment, which
  // pg reads before the host and port and could point at another server.
  if (
    !/^postgres(?:ql)?:\/\/[A-Za-z0-9._~%-]+(?::[A-Za-z0-9._~%-]*)?@127\.0\.0\.1:(?:25432|5432)\/sf_test_browser_[a-f0-9]{12}$/.test(
      process.env.SPACEFAST_TEST_DATABASE_URL ?? "",
    )
  ) {
    console.error(
      "this fixture runs only under the browser proof's launcher, on the database it created on the local test Postgres (127.0.0.1): use `bash runtime/browser-specs/run-ci.sh [spec]`",
    );
    process.exit(2);
  }
}
