# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ffc9d95c8856b81a989703db169992f2b2077d39`
- Runtime source hash: `1dc583913526ffff6de51ee411abde574c908652eda129993dba2272051f80ae`
- Matching release tag: `runtime-ffc9d95c8856b81a989703db169992f2b2077d39`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
