# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4f1a95ce017130855ba8c0ee1a5dbb305f0cfdd6`
- Runtime source hash: `b08abae14defea61886b771c942c034f2ec4b50e4e1678a0a91dfb702733b6e9`
- Matching release tag: `runtime-4f1a95ce017130855ba8c0ee1a5dbb305f0cfdd6`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
