// Every entry point of the browser proof (run.ts and each fixture) checks for
// itself that it runs with loopback-only networking, before it reads a
// database or starts anything: every addressed network interface is loopback,
// the namespace has no non-local IPv4 routes, and a connect to a documentation
// address (192.0.2.1, TEST-NET-1) finds no route. A UDP connect only looks the
// route up; it sends nothing, so the check costs no packet even on a host that
// has a route.
//
// We check `networkInterfaces()` and `/proc/self/net/route` rather than raw
// `/proc/self/net/dev` names because Linux kernels with tunnel modules loaded
// (ipip, ip_gre, sit, ip6tnl, vti) instantiate down, unaddressed fallback
// devices (`tunl0`, `gre0`, `gretap0`, …) inside every fresh network namespace.
import { readFileSync } from "node:fs";
import { networkInterfaces } from "node:os";

const LAUNCHER = "use `bash runtime/browser-specs/run-ci.sh [spec]`";

function refuse(what: string): never {
  console.error(
    `${what}: the browser proof runs only in a loopback-only network namespace; ${LAUNCHER}`,
  );
  process.exit(2);
}

export async function requireLoopbackOnly(): Promise<void> {
  const interfaces = networkInterfaces();
  const nonLoopback = Object.entries(interfaces).filter(
    ([name, entries]) => name !== "lo" || (entries ?? []).some((entry) => !entry.internal),
  );
  if (nonLoopback.length > 0) {
    const names = nonLoopback.map(([name]) => name);
    const named = names.slice(0, 3).join(", ");
    refuse(
      `network devices other than loopback are active (${named}${names.length > 3 ? `, and ${names.length - 3} more` : ""})`,
    );
  }
  const routes = readFileSync("/proc/self/net/route", "utf8")
    .split("\n")
    .slice(1)
    .map((line) => line.trim())
    .filter(Boolean);
  if (routes.length > 0) {
    refuse(`non-loopback IPv4 routes are present (${routes.length})`);
  }
  let reached = false;
  try {
    const probe = await Bun.udpSocket({ connect: { hostname: "192.0.2.1", port: 9 } });
    probe.close();
    reached = true;
  } catch (error) {
    const code = error instanceof Error && "code" in error ? String(error.code) : String(error);
    if (code !== "ENETUNREACH")
      refuse(`a connect to 192.0.2.1 failed with ${code}, not ENETUNREACH`);
  }
  if (reached) refuse("a connect to 192.0.2.1 found a route");
}
