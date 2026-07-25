# Spacefast control-plane adapter

Spacefast is the managed control plane for Stattic. It consumes released
Builder, bundle and Runtime contracts; it does not vendor a second compiler.

## What moved into Stattic

- the Rust semantic compiler and finalizer;
- Markdown, Gutenberg, theme, layout, routing and policy lowering;
- the `stattic` Builder CLI and WASI preparation host;
- `stattic.bundle.v1` construction and verification;
- PHP serving artifacts and compiler-free Runtime admission;
- the optional Zero runner.

## What stays in Spacefast

- accounts, authorization, Spaces and Versions;
- Git and framework build execution;
- artifact storage, upload resumption and deduplication;
- trusted target binding and secret delivery;
- placement, domains, CDN, activation, rollback and public proof;
- durable operations, logs, metrics and billing.

## Native publish flow

1. Spacefast creates a version intent and assigns `spaceId` and `versionId`.
2. A local `sf publish` invokes the released Stattic Builder with those
   coordinates. A remote build invokes the same pinned Builder release.
3. Spacefast verifies `bundle.json`, the closed artifact manifest, the Builder
   provenance and the declared Runtime ABI before storing the artifact.
4. The placement worker sends the exact bundle to Runtime admission. It must
   not send source files to the Runtime and must not fall back to server
   compilation.
5. The Runtime returns a durable admission receipt containing the coordinates,
   `contentDigest`, `deploymentDigest`, Runtime ABI and the two negative
   requirements:

   ```json
   {
     "serverBuildRequired": false,
     "rustFinalizerRequired": false
   }
   ```

6. Spacefast records the receipt, activates the admitted version with a
   compare-and-swap operation, and proves the public route serves that exact
   deployment digest.

The same `contentDigest` can be reused by many Spaces. Authorization,
activation and rollback remain coordinate-scoped even though coordinates do
not affect compiled identity.

## Release consumption

Spacefast should pin one Stattic release manifest containing:

- the Builder CLI digest;
- the WASI artifact digest;
- the PHP Runtime archive digest;
- the optional Zero runner digest;
- supported bundle formats and Runtime ABIs.

Spacefast may wrap the standalone CLI for product UX, but the wrapper must call
the public Builder boundary. Copying compiler source back into the control
plane would recreate the split this extraction removes.

## Profiles

`portable-static` is implemented now. It is fully prebuilt and requires no Rust
on the server.

Target-bound Zero is deliberately not represented as portable static. A later
Spacefast binding adapter must emit a distinct profile and binding digest while
using the same Rust semantic core. Until that contract exists, admission must
reject a bundle that requests Zero or a server build.
