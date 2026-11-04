# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `70a9f510d3ca3afcc8aa85dd3020257aeb99be65`
- Runtime source hash: `29910b259bcc5c769ff8344508729230109297a32a5d842325cc000e8eb52e98`
- Matching release tag: `runtime-70a9f510d3ca3afcc8aa85dd3020257aeb99be65`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
