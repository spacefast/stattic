// A browser proof fixture, started only by run.ts. This entry imports
// built-ins only and checks the loopback-only namespace and the run's
// database first; only then does it load the fixture itself, so no package is
// resolved (or, without `--no-install`, fetched) before the check.
import { requireLauncherDatabase } from "./launcher-database.ts";

await requireLauncherDatabase();
const { main } = await import("./sign-in-flow-fixture-main.ts");
await main();
