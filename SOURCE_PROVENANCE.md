# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5f2a0a17498704b5926ea254cb99fd529cfc7298`
- Runtime source hash: `2948d60d0b8cf59e2709015cdd4003999fc38cf03cd43cc77e8dade26d1fc0ad`
- Matching release tag: `runtime-5f2a0a17498704b5926ea254cb99fd529cfc7298`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
