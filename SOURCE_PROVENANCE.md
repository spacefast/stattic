# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `22cb219fdde110626c5f06d2b10e5f98801d80bc`
- Runtime source hash: `df1a5f584406608286c06168c2740ff0830a348091f097342962dc0bd9c4ffc2`
- Matching release tag: `runtime-22cb219fdde110626c5f06d2b10e5f98801d80bc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
