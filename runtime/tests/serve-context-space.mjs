import { readFile, writeFile } from "node:fs/promises";
/** Serve the compiled context Space and its native WordPress content runtime. */
import { createServer, request as proxyRequest } from "node:http";
import path from "node:path";

const types = {
  ".html": "text/html",
  ".js": "text/javascript",
  ".css": "text/css",
  ".json": "application/json",
  ".svg": "image/svg+xml",
};
createServer(async (request, response) => {
  const url = new URL(request.url ?? "/", "http://127.0.0.1:9419");
  // Disposable loopback-only preview conveniences. Production uses Space access.
  if (url.pathname === "/__preview/editor" || url.pathname === "/__preview/reader") {
    const native =
      url.pathname === "/__preview/editor"
        ? await (
            await fetch("http://127.0.0.1:9417/wp-json/spacefast-context-preview/v1/session", {
              method: "POST",
            })
          ).json()
        : JSON.parse(
            await readFile(".cache/context-acceptance/preview-editor-session.json", "utf8"),
          );
    if (url.pathname === "/__preview/editor")
      await writeFile(
        ".cache/context-acceptance/preview-editor-session.json",
        JSON.stringify(native),
      );
    response.writeHead(303, {
      location: "/",
      "set-cookie":
        url.pathname === "/__preview/editor"
          ? [
              `${native.cookie}=${encodeURIComponent(native.value)}; Path=/; HttpOnly; SameSite=Lax`,
              `sf_context_preview_nonce=${native.nonce}; Path=/; HttpOnly; SameSite=Strict`,
            ]
          : [
              `${native.cookie}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`,
              "sf_context_preview_nonce=; Path=/; HttpOnly; SameSite=Strict; Max-Age=0",
            ],
    });
    response.end();
    return;
  }
  // As on a Space: the whole REST lane under the visitor's session, plus core
  // static assets.
  if (url.pathname.startsWith("/wp-json/") || url.pathname.startsWith("/wp-includes/")) {
    const headers = { ...request.headers, host: "127.0.0.1:9417" };
    if (url.pathname.startsWith("/wp-json/")) {
      const nonce = (request.headers.cookie ?? "")
        .split(";")
        .map((part) => part.trim())
        .find((part) => part.startsWith("sf_context_preview_nonce="));
      // WordPress validates this nonce against that browser's signed native
      // cookie. Independent preview sessions must not invalidate one another.
      if (nonce) headers["x-wp-nonce"] = nonce.slice("sf_context_preview_nonce=".length);
    }
    const upstream = proxyRequest(
      {
        hostname: "127.0.0.1",
        port: 9417,
        path: request.url,
        method: request.method,
        headers,
      },
      (result) => {
        response.writeHead(result.statusCode ?? 502, result.headers);
        result.pipe(response);
      },
    );
    upstream.on("error", () => {
      response.writeHead(502);
      response.end("The content runtime is unavailable.");
    });
    request.pipe(upstream);
    return;
  }
  if (/^\/(?:wp-admin|wp-login\.php|zero-admin)(?:\/|$)/.test(url.pathname)) {
    response.writeHead(404);
    response.end("Not found");
    return;
  }
  try {
    const config = JSON.parse(
      await readFile(".cache/context-acceptance/space-preview.json", "utf8"),
    );
    const requested = decodeURIComponent(url.pathname);
    const filename = requested === "/" ? config.shellPath : requested.slice(1);
    const target = path.resolve(config.publicRoot, filename);
    if (!target.startsWith(config.publicRoot + path.sep)) {
      response.writeHead(404);
      response.end("Not found");
      return;
    }
    const bytes = await readFile(target);
    response.writeHead(200, {
      "content-type": types[path.extname(target)] ?? "application/octet-stream",
    });
    response.end(bytes);
  } catch {
    response.writeHead(404);
    response.end("Not found");
  }
}).listen(9419, "127.0.0.1", () => console.log("Context Space: http://127.0.0.1:9419/"));
