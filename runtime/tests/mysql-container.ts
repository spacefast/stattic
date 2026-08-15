// A throwaway MySQL server for the runtime suites that need a real one: the
// capability broker's differential corpus, the Functions relay's brokered
// callbacks, the native Zero runner, and the management dump of a version's
// Zero database. They all drive a real driver against a real server, so none of
// them can stand in a fake for it — and they share this one bring-up so the
// image digest, the readiness rule and the teardown cannot drift apart.
import net from "node:net";

const MYSQL_IMAGE =
  "mirror.gcr.io/library/mysql:8.4@sha256:c831a0f11348d402b43d77453e17d770be2eef356615a2823fe0f5a0d6c8b9af";
// First-boot datadir initialisation dominates this and scales with how loaded
// the host is; 60s is not enough on a machine already running other stacks.
// Exported so a suite's own beforeAll deadline can be stated as this plus what
// the rest of its setup costs, rather than as a second guess at the same number.
export const MYSQL_SETUP_TIMEOUT_MS = 300_000;

export type MysqlContainer = {
  /** The docker container name, for `docker exec` against the live server. */
  name: string;
  /** The seeded database's connection URL, as the engines take it. */
  url: string;
  /**
   * Run SQL in the seeded database, throwing its stderr on failure and
   * answering with the server's own output (headerless and tab-separated, so a
   * caller can assert on it without parsing a table).
   */
  exec(sql: string): string;
};

// Every container this process started, so a suite can tear down what it began
// even when the start itself threw partway through.
const started: string[] = [];

/**
 * Boots a MySQL container with `database` already created, waits until both the
 * server and its published host port answer, and hands back its connection URL.
 */
export async function startMysqlContainer(input: {
  /** Names the container, so a leaked one says which suite left it. */
  namePrefix: string;
  database: string;
  rootPassword: string;
}): Promise<MysqlContainer> {
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
      `MYSQL_ROOT_PASSWORD=${input.rootPassword}`,
      "-e",
      `MYSQL_DATABASE=${input.database}`,
      "-p",
      `127.0.0.1:${port}:3306`,
      MYSQL_IMAGE,
    ],
    stdout: "pipe",
    stderr: "pipe",
  });
  if (run.exitCode !== 0) {
    throw new Error(`mysql container failed to start:\n${run.stderr.toString()}`);
  }
  await waitForMysql(name, input.rootPassword);
  await waitForTcpPort(port);
  return {
    name,
    url: `mysql://root:${input.rootPassword}@127.0.0.1:${port}/${input.database}`,
    exec(sql: string) {
      const result = Bun.spawnSync({
        cmd: [
          "docker",
          "exec",
          name,
          "mysql",
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

/** Removes every container this process started. Safe when none did. */
export function stopMysqlContainers(): void {
  while (started.length > 0) {
    const name = started.pop();
    if (name) {
      Bun.spawnSync({ cmd: ["docker", "rm", "-f", name] });
    }
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

async function waitForMysql(container: string, rootPassword: string): Promise<void> {
  const deadline = Date.now() + MYSQL_SETUP_TIMEOUT_MS;
  for (;;) {
    const ping = Bun.spawnSync({
      cmd: [
        "docker",
        "exec",
        container,
        "mysqladmin",
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
