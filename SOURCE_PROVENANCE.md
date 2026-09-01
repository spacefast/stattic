# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6b4f31ca8b00f33d4e07a24d387f736e237d1085`
- Runtime source hash: `0efde79f39fcfc58ce41a2ea2f8a0362a3b4bbe478722b605cd97f3e8ec5d60c`
- Matching release tag: `runtime-6b4f31ca8b00f33d4e07a24d387f736e237d1085`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
