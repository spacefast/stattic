# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `216746df01a9d4560d2d070e6293ba40c44c0752`
- Runtime source hash: `46b4ecaada0ca89046c290afb4871f49faa950024062e75a8bbf0a34edbde51d`
- Matching release tag: `runtime-216746df01a9d4560d2d070e6293ba40c44c0752`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
