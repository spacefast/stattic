# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7be03b713f4c26eec40709bc72302735ba4b7e5a`
- Runtime source hash: `be9dd880c399afe969418ce3ef43b0cc306d6ba8d75ba594126e194ac707de87`
- Matching release tag: `runtime-7be03b713f4c26eec40709bc72302735ba4b7e5a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
