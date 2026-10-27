# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `df1f18d2fbbc68742634df67935839905698dc6b`
- Runtime source hash: `fa0351604b9f8d98f836738a8f0e939089aa6bfadc70f27f4f0843aae7ae06af`
- Matching release tag: `runtime-df1f18d2fbbc68742634df67935839905698dc6b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
