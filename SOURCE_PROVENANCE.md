# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `667bc519ec241ce5172bf343a5d33c4ff44b1623`
- Runtime source hash: `f507321462f1e99feda8fd54cfd078f895a593ad7e8fccb67dc0d82e000963c9`
- Matching release tag: `runtime-667bc519ec241ce5172bf343a5d33c4ff44b1623`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
