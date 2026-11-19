# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b010b821d9535ab9565a7b53e55a12325831f698`
- Runtime source hash: `b4f2655a96cf493888d8f390336f7304aa5c555eb40fc07451e051bc9118f764`
- Matching release tag: `runtime-b010b821d9535ab9565a7b53e55a12325831f698`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
