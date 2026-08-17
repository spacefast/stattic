# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `98f917e9d4e5ea9ba34776f268381e4e913d6c75`
- Runtime source hash: `1fccce58771d373a697cbeebb6498664358a54d794056f72ec67434d180cb322`
- Matching release tag: `runtime-98f917e9d4e5ea9ba34776f268381e4e913d6c75`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
