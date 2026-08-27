# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c4b2905250dbee39d5b1a12ed978096f3a99fc8e`
- Runtime source hash: `df1a5f584406608286c06168c2740ff0830a348091f097342962dc0bd9c4ffc2`
- Matching release tag: `runtime-c4b2905250dbee39d5b1a12ed978096f3a99fc8e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
