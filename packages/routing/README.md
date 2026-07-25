# `@stattic/routing`

Routing parser and matcher for Stattic `_redirects` and `_headers` files, plus
the Rust/WASI preparation host used by the Builder.

Install it directly when you need to inspect, compile, or test Stattic routing
behavior in your own tooling.

## Install

```bash
npm install @stattic/routing
```

## Compile Routing Files

```ts
import { compileRoutingFiles } from "@stattic/routing/compile";

const routing = compileRoutingFiles({
  redirects: "/old /new 301\n/api/* https://api.example.com/:splat 200",
  headers: "/*\n  x-frame-options: DENY",
});

if (routing.diagnostics.length > 0) {
  console.warn(routing.diagnostics);
}
```

## Match Requests

```ts
import { headersForRequest, matchRedirect } from "@stattic/routing/match";

const request = {
  url: new URL("https://example.com/old"),
  headers: new Headers(),
};

const redirect = matchRedirect({ compilation: routing, request });
const headers = headersForRequest(routing, request);
```

The package includes a browser-safe TypeScript compiler and a Node WASI host.
Set `STATTIC_RUNTIME_WASI_WASM` during packaging to supply an explicit Rust
artifact; a missing configured artifact fails closed.
