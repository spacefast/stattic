# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7e58e645821505d957631d85dbd0b3beb781f81e`
- Runtime source hash: `20b7a81826fe912ab7a25515a668d5f633f56fa8dd85a459d22918ba201b6f53`
- Matching release tag: `runtime-7e58e645821505d957631d85dbd0b3beb781f81e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
