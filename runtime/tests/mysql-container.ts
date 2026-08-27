// A throwaway MySQL server for the suites that drive a real driver against a
// real server: the capability broker's differential corpus, the Functions
// relay's brokered callbacks, the native Zero runner, and the management dump of
// a version's Zero database. They share one bring-up so the image digest, the
// readiness rule and the teardown cannot drift apart.
import net from "node:net";

const MYSQL_IMAGE =
  "mirror.gcr.io/library/mysql:8.4@sha256:c831a0f11348d402b43d77453e17d770be2eef356615a2823fe0f5a0d6c8b9af";
const MARIADB_IMAGE =
  "mirror.gcr.io/library/mariadb:11.8@sha256:2439dcd7d14010ecd1ff7a4e1c5abe8e208c34fe35290744deeeaac3569043c3";
// First-boot datadir initialisation dominates this and scales with host load;
// 60s is not enough on a machine already running other stacks. Exported so a
// suite states its beforeAll deadline as this plus its own setup cost.
export const MYSQL_SETUP_TIMEOUT_MS = 300_000;

export type MysqlContainer = {
  /** The docker container name, for `docker exec` against the live server. */
  name: string;
  /** The seeded database's connection URL, as the engines take it. */
  url: string;
  /** Stop this one container without touching another suite's server. */
  stop(): void;
  /**
   * Run SQL in the seeded database. Throws its stderr on failure; returns the
   * server's headerless, tab-separated output, so a caller can assert on it
   * without parsing a table.
   */
  exec(sql: string): string;
};

// Every container this process started, so teardown still works when a start
// threw partway through.
const started: string[] = [];

/**
 * Boots a MySQL container with `database` already created and waits until both
 * the server and its published host port answer.
 */
export async function startMysqlContainer(input: {
  /** Names the container, so a leaked one says which suite left it. */
  namePrefix: string;
  database: string;
  rootPassword: string;
  flavor?: "mysql" | "mariadb";
}): Promise<MysqlContainer> {
  const flavor = input.flavor ?? "mysql";
  const image = flavor === "mariadb" ? MARIADB_IMAGE : MYSQL_IMAGE;
  const envPrefix = flavor === "mariadb" ? "MARIADB" : "MYSQL";
  const client = flavor === "mariadb" ? "mariadb" : "mysql";
  const name = `${input.namePrefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  started.push(name);
  const port = await freeTcpPort();
  const run = Bun.spawnSync({
    cmd: [
      "docker",
      "run",
      "-d",
      "--rm",
      "--name",
      name,
      "-e",
      `${envPrefix}_ROOT_PASSWORD=${input.rootPassword}`,
      "-e",
      `${envPrefix}_DATABASE=${input.database}`,
      "-p",
      `127.0.0.1:${port}:3306`,
      image,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (run.exitCode !== 0) {
    throw new Error(`mysql container failed to start:\n${run.stderr.toString()}`);
  }
  await waitForMysql(name, input.rootPassword, flavor);
  await waitForTcpPort(port);
  return {
    name,
    url: `mysql://root:${input.rootPassword}@127.0.0.1:${port}/${input.database}`,
    stop() {
      const index = started.indexOf(name);
      if (index !== -1) started.splice(index, 1);
      Bun.spawnSync({ cmd: ["docker", "rm", "-f", name] });
    },
    exec(sql: string) {
      const result = Bun.spawnSync({
        cmd: [
          "docker",
          "exec",
          name,
          client,
          "-uroot",
          `-p${input.rootPassword}`,
          // Non-ASCII fixture rows travel through this client, so the wire
          // charset is named rather than inherited from the image default.
          "--default-character-set=utf8mb4",
          "-N",
          "-B",
          input.database,
          "-e",
          sql,
        ],
        stdout: "pipe",
        stderr: "pipe",
      });
      if (result.exitCode !== 0) {
        throw new Error(`mysql statement failed: ${sql}\n${result.stderr.toString()}`);
      }
      return result.stdout.toString().trim();
    },
  };
}

/** Removes containers this process started, optionally scoped to a suite prefix. */
export function stopMysqlContainers(namePrefix?: string): void {
  for (let index = started.length - 1; index >= 0; index -= 1) {
    const name = started[index];
    if (!name || (namePrefix && !name.startsWith(`${namePrefix}-`))) {
      continue;
    }
    started.splice(index, 1);
    Bun.spawnSync({ cmd: ["docker", "rm", "-f", name] });
  }
}

async function freeTcpPort(): Promise<number> {
  const server = net.createServer();
  server.listen(0, "127.0.0.1");
  await new Promise((resolve) => server.once("listening", resolve));
  const port = (server.address() as net.AddressInfo).port;
  server.close();
  return port;
}

async function waitForMysql(
  container: string,
  rootPassword: string,
  flavor: "mysql" | "mariadb",
): Promise<void> {
  const admin = flavor === "mariadb" ? "mariadb-admin" : "mysqladmin";
  const deadline = Date.now() + MYSQL_SETUP_TIMEOUT_MS;
  for (;;) {
    const ping = Bun.spawnSync({
      cmd: [
        "docker",
        "exec",
        container,
        admin,
        "ping",
        "-h",
        "127.0.0.1",
        "-uroot",
        `-p${rootPassword}`,
        "--silent",
      ],
      stdout: "pipe",
      stderr: "pipe",
    });
    if (ping.exitCode === 0) {
      return;
    }
    if (Date.now() > deadline) {
      throw new Error(`mysql container did not become ready:\n${ping.stderr.toString()}`);
    }
    // oxlint-disable-next-line eslint/no-await-in-loop -- readiness is a sequential poll of one server, not parallel work
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
}

async function waitForTcpPort(port: number): Promise<void> {
  const deadline = Date.now() + 20_000;
  for (;;) {
    // oxlint-disable-next-line eslint/no-await-in-loop -- each probe must finish before deciding whether to retry
    const connected = await new Promise<boolean>((resolve) => {
      const socket = net.createConnection({ host: "127.0.0.1", port });
      socket.once("connect", () => {
        socket.destroy();
        resolve(true);
      });
      socket.once("error", () => {
        socket.destroy();
        resolve(false);
      });
      socket.setTimeout(500, () => {
        socket.destroy();
        resolve(false);
      });
    });
    if (connected) {
      return;
    }
    if (Date.now() > deadline) {
      throw new Error(`mysql host port did not become ready: 127.0.0.1:${port}`);
    }
    // oxlint-disable-next-line eslint/no-await-in-loop -- retry delay belongs to the sequential readiness poll
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
}
