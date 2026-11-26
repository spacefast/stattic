# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `dd1b74ab81376d8bb6dbf032bde5d6ce529d3eec`
- Runtime source hash: `8cc76b6110a277e3c24f417664b3d3db294b3fc92cfd80f6b431e94b12d3cb28`
- Matching release tag: `runtime-dd1b74ab81376d8bb6dbf032bde5d6ce529d3eec`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
