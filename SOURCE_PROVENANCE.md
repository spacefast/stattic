# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `967a5e782636077953ebb26959deb0f73c145ee4`
- Runtime source hash: `a98a5857803483ec566ff0e7411aa76d942ce03cbf7213fda284a3f41a6e89f0`
- Matching release tag: `runtime-967a5e782636077953ebb26959deb0f73c145ee4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
