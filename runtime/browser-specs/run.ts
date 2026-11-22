// The launcher of the runtime browser proofs, run only inside confined.sh
// (`bash runtime/browser-specs/run-ci.sh [spec]`). This entry imports
// built-ins only and checks the loopback-only namespace first; only then does
// it load the launcher itself, so no package is resolved (or, without
// `--no-install`, fetched) before the check.
import { requireLoopbackOnly } from "./confinement.ts";

await requireLoopbackOnly();
const { main } = await import("./run-main.ts");
await main();
