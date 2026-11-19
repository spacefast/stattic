// Linux transport for the shared PHP fixture, not another runtime installer.
import { spawnSync } from "node:child_process";
import { randomUUID } from "node:crypto";

import { WORDPRESS_IMAGE } from "./zero-wordpress.ts";

// Reuse the WordPress fixture's pinned PHP 8.5 image and its runtime extensions.
const containers = new Set<string>();

export function stopLinuxPhp(name: string): void {
  const result = spawnSync("docker", ["rm", "--force", name], {
    encoding: "utf8",
    timeout: 10_000,
  });
  // A missing-container error can race with a daemon-side create. Retain
  // ownership for exit cleanup rather than treating that error as removal.
  if (result.status === 0) {
    containers.delete(name);
  } else if (!result.stderr?.includes("No such container")) {
    console.error(`Failed to remove PHP fixture ${name}: ${result.error ?? result.stderr}`);
  }
}

process.once("exit", () => {
  for (const name of containers) stopLinuxPhp(name);
});

export function linuxPhpCommand(root: string, env: NodeJS.ProcessEnv, phpArgs: string[]) {
  const name = `spacefast-runtime-test-${randomUUID()}`;
  containers.add(name);
  return {
    name,
    binary: "docker",
    args: [
      "run",
      "--pull",
      "missing",
      "--rm",
      "--init",
      "--name",
      name,
      "--platform",
      "linux/amd64",
      "--network",
      "host",
      // Preserve absolute paths in the protocol and artifacts. Only the
      // run-owned root is writable; no repo, credentials or socket is mounted.
      "--mount",
      `type=bind,source=${root},target=${root}`,
      "--mount",
      `type=bind,source=${import.meta.dir},target=${import.meta.dir},readonly`,
      // This image declares a VOLUME here. A tmpfs avoids anonymous volumes.
      "--mount",
      "type=tmpfs,destination=/var/www/html",
      "--workdir",
      root,
      "--user",
      `${process.getuid?.() ?? 1000}:${process.getgid?.() ?? 1000}`,
      ...Object.keys(env)
        .filter((key) => env[key] !== undefined)
        .flatMap((key) => ["--env", key]),
      "--entrypoint",
      "php",
      WORDPRESS_IMAGE,
      ...phpArgs,
    ],
  };
}
