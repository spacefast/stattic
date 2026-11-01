# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `cf431b51333ae7b55d859024465c870ded038be8`
- Runtime source hash: `a252dd4358d7a273d5ac02b2f6b8a86cd727bca25d6d5de29e319058eaea2eaa`
- Matching release tag: `runtime-cf431b51333ae7b55d859024465c870ded038be8`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
