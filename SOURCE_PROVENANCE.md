# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d3f775f007627f105bc81947230af81adee63f5b`
- Runtime source hash: `b6b0ec95aba3c74f907605d45eb6971412a42cc846d06807f182b552d9bedeb8`
- Matching release tag: `runtime-d3f775f007627f105bc81947230af81adee63f5b`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
