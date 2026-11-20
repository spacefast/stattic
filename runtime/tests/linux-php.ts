// Linux transport for the shared PHP fixture, not another runtime installer.
import { spawnSync } from "node:child_process";
import { randomUUID } from "node:crypto";
import path from "node:path";

import { WORDPRESS_IMAGE } from "./zero-wordpress.ts";

// Reuse the WordPress fixture's pinned PHP 8.5 image and its runtime extensions.
// Each fixture container, with the environment its `docker run` client got
// (undefined until `recordLinuxPhpClientEnv`), so cleanup reaches the same
// daemon the start did.
const containers = new Map<string, NodeJS.ProcessEnv | undefined>();

/** Records the exact environment `docker run` was started with for `name`. */
export function recordLinuxPhpClientEnv(name: string, env: NodeJS.ProcessEnv): void {
  if (containers.has(name)) containers.set(name, env);
}

/**
 * The `docker` client environment for a fixture started from an isolated
 * runtime: the Docker variables, PATH for its helpers, SSH_AUTH_SOCK for an
 * SSH-backed context, and the config directory that locates contexts. None of
 * it is forwarded into the container; `linuxPhpCommand` forwards only the keys
 * it is given. Cleanup reuses whatever environment the start used (see
 * `recordLinuxPhpClientEnv`), so a non-isolated fixture keeps inheriting this
 * process's environment for both, as before.
 */
export function dockerClientEnv(): NodeJS.ProcessEnv {
  const kept = Object.fromEntries(
    Object.entries(process.env).filter(
      ([key]) => key === "PATH" || key === "SSH_AUTH_SOCK" || key.startsWith("DOCKER_"),
    ),
  );
  // The caller's HOME replaces this one for the container (`--env HOME`), so
  // name the client's config directory explicitly; it locates contexts.
  const home = process.env.HOME;
  return kept.DOCKER_CONFIG || !home
    ? kept
    : { ...kept, DOCKER_CONFIG: path.join(home, ".docker") };
}

export function stopLinuxPhp(name: string): void {
  // The environment the fixture was started with; this process's own when none
  // was recorded.
  const result = spawnSync("docker", ["rm", "--force", name], {
    encoding: "utf8",
    env: containers.get(name) ?? process.env,
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
  for (const name of containers.keys()) stopLinuxPhp(name);
});

export function linuxPhpCommand(root: string, env: NodeJS.ProcessEnv, phpArgs: string[]) {
  const name = `spacefast-runtime-test-${randomUUID()}`;
  containers.set(name, undefined);
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
      // PATH stays the image's own: a host PATH names directories the image
      // does not have. Everything else in `env` is the fixture's.
      ...Object.keys(env)
        .filter((key) => env[key] !== undefined && key !== "PATH")
        .flatMap((key) => ["--env", key]),
      "--entrypoint",
      "php",
      WORDPRESS_IMAGE,
      ...phpArgs,
    ],
  };
}
