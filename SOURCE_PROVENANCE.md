# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `724b7eb6784a0f8aa3742584333e32271f683a78`
- Runtime source hash: `f61c0d9b7819d7685b45f65118c12b361703cafa6dec4ba4668fa21bf26d5f11`
- Matching release tag: `runtime-724b7eb6784a0f8aa3742584333e32271f683a78`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
