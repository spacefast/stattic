# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `786a2863cc8a393876fe9111129a8b10cad098f3`
- Runtime source hash: `c9ae08f3a41024bfaa59f68406f39a277c6c4bf1f028c7ad2a68efbeb40ac774`
- Matching release tag: `runtime-786a2863cc8a393876fe9111129a8b10cad098f3`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
