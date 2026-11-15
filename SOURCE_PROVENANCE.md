# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `69f9e17cca9b63092a0c01d2006a190eac602883`
- Runtime source hash: `e0a53b4e9b89c58871446b61c8db4a8ead2366cd00e21a4122041b693f3a4ba6`
- Matching release tag: `runtime-69f9e17cca9b63092a0c01d2006a190eac602883`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
