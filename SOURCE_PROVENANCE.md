# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5259bed6ca8f338d373196d2ae74db29839732c7`
- Runtime source hash: `e200c4aad923d660098f7fc3310abe82c52b3bb1cd76a0661da89f4c3518c86b`
- Matching release tag: `runtime-5259bed6ca8f338d373196d2ae74db29839732c7`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
