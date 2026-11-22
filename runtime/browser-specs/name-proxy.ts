// A forward proxy for the PHP engines a fixture starts. They reach the
// production names the fixture serves (api.spacefast.com, each Space's
// hostname) through it: libcurl reads HTTP_PROXY/HTTPS_PROXY. `local` maps a
// served hostname to the loopback port that serves it; any other destination
// gets 403. It is how those names resolve here, not the boundary: confined.sh
// keeps every process off the network whether or not it uses this proxy.
import net from "node:net";

import { z } from "zod";

export async function startNameProxy(local: (host: string) => number | undefined) {
  const server = net.createServer((client) => {
    client.once("data", (head) => {
      const line = head.toString("latin1").split("\r\n", 1)[0] ?? "";
      const connect = /^CONNECT ([^\s:]+):(\d+) HTTP\/1\.[01]$/.exec(line);
      const absolute = /^[A-Z]+ http:\/\/([^\s/:]+)(?::(\d+))?\//.exec(line);
      const port = local((connect?.[1] ?? absolute?.[1] ?? "").toLowerCase());
      if (port === undefined) {
        client.end("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        return;
      }
      const upstream = net.connect(port, "127.0.0.1", () => {
        if (connect) client.write("HTTP/1.1 200 Connection Established\r\n\r\n");
        else upstream.write(head);
        upstream.pipe(client);
        client.pipe(upstream);
      });
      upstream.on("error", () => client.destroy());
      client.on("error", () => upstream.destroy());
    });
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  const { port } = z.object({ port: z.number() }).parse(server.address());
  const url = `http://127.0.0.1:${port}`;
  return {
    env: {
      HTTP_PROXY: url,
      HTTPS_PROXY: url,
      http_proxy: url,
      https_proxy: url,
      ALL_PROXY: url,
      NO_PROXY: "127.0.0.1,localhost,::1",
      no_proxy: "127.0.0.1,localhost,::1",
    },
    stop: () => server.close(),
  };
}
