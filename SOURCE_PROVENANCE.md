# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ce8f8bc343bf64a8dabd64235e52eb33ea1bf0a7`
- Runtime source hash: `132d480c625864ba7065b2ccd8d46da71562c8206a61119ac9ac841fda16d5ee`
- Matching release tag: `runtime-ce8f8bc343bf64a8dabd64235e52eb33ea1bf0a7`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
