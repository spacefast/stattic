# Stattic repository principles

Stattic is the portable build-and-runtime layer. Spacefast is one managed cloud
control plane that consumes Stattic; this repository must never depend on the
Spacefast control plane.

- One semantic compiler: Rust owns Markdown, Gutenberg, themes, layouts,
  routing, policy and bundle generation.
- Build before serving: `portable-static` bundles require neither a server-side
  build nor a Rust finalizer.
- Coordinates are not content: `spaceId` and `versionId` locate a deployment
  but do not contribute to `contentDigest` or `deploymentDigest`.
- Runtime admission verifies a closed artifact manifest and fails closed.
- PHP serving code consumes compiled artifacts. It must not reinterpret source.
- Spacefast-specific authorization, placement, billing, domains and durable
  workflow orchestration stay in Spacefast adapters.
- Never test behavior by asserting on implementation source text. Execute the
  Builder or Runtime boundary and assert its output.

This is a pre-1.0 extraction. Delete obsolete compatibility paths instead of
creating parallel implementations.

