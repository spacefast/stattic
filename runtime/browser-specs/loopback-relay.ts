// Carries the test database's one TCP port across the loopback-only network
// namespace (confined.sh) over a Unix socket file, and nothing else.
//
//   loopback-relay.ts unix-to-tcp <socket file> <port>   (outside: socket → 127.0.0.1:<port>)
//   loopback-relay.ts tcp-to-unix <port> <socket file>   (inside: 127.0.0.1:<port> → socket)
//
// It runs on the host, before confined.sh enters the namespace, so it imports
// built-ins only (and confined.sh starts it with `bun --no-install`): nothing
// it loads can make Bun resolve or fetch a package.
import net from "node:net";

const [mode, first, second, ...extra] = process.argv.slice(2);
if ((mode !== "unix-to-tcp" && mode !== "tcp-to-unix") || !first || !second || extra.length > 0) {
  console.error(
    "usage: loopback-relay.ts unix-to-tcp <socket file> <port> | tcp-to-unix <port> <socket file>",
  );
  process.exit(2);
}

function splice(client: net.Socket, upstream: net.Socket) {
  client.pipe(upstream);
  upstream.pipe(client);
  for (const [from, to] of [
    [client, upstream],
    [upstream, client],
  ] as const) {
    from.on("error", () => to.destroy());
    from.on("close", () => to.destroy());
  }
}

const server =
  mode === "unix-to-tcp"
    ? net
        .createServer((client) => splice(client, net.connect(Number(second), "127.0.0.1")))
        .listen(first)
    : net
        .createServer((client) => splice(client, net.connect(second)))
        .listen(Number(first), "127.0.0.1");

for (const signal of ["SIGINT", "SIGTERM"] as const)
  process.on(signal, () => server.close(() => process.exit(0)));
