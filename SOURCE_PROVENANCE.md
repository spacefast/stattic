# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `04422a9dee29161385a4e5a0cf46f41474876a23`
- Runtime source hash: `b01702c0e290f0e46f8b1bad7645f8b87b4f57d2425d5d46db0df9de2a10b4f3`
- Matching release tag: `runtime-04422a9dee29161385a4e5a0cf46f41474876a23`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
