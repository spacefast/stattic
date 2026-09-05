# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `468d6f2a07f01aebd4699e774d9971a944c02285`
- Runtime source hash: `c06ae8d2ccc9667f5280a6123b8ebe0ebeafcc469dc609065f196c90b078f785`
- Matching release tag: `runtime-468d6f2a07f01aebd4699e774d9971a944c02285`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
