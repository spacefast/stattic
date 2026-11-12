# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `64b7613be511b307a7aee97033743572953341ae`
- Runtime source hash: `3f24181b6c46a3994cb4217ac32f5d06a881c3cb34e635ac2d418534bee4b9d4`
- Matching release tag: `runtime-64b7613be511b307a7aee97033743572953341ae`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
