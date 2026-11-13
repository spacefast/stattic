# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b1706a9af605d8966efb34148e93551bec85b612`
- Runtime source hash: `7e03e3ab6d04c063a4ad1c5d50105cb6285b8027704ee6e9a38e5b3afbe28299`
- Matching release tag: `runtime-b1706a9af605d8966efb34148e93551bec85b612`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
