# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `46ad8738a028983844ba2a25b2c54801aaf175d0`
- Runtime source hash: `2b8497476c17b052bc604e8d319b9a131d672ff5023336ac6c9151a4d3a6e80a`
- Matching release tag: `runtime-46ad8738a028983844ba2a25b2c54801aaf175d0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
