# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `38821e6e2cf67dc0311331d503e553116bc09653`
- Runtime source hash: `b5990930fe6cadda2d2c2bcb140b4a506213957769c036ae79253f314188baa6`
- Matching release tag: `runtime-38821e6e2cf67dc0311331d503e553116bc09653`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
