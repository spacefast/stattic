# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4954a81099983c506511ad362e78349940b2ab23`
- Runtime source hash: `0aaa18184ed887723c0dd9da0e639fd51fe92c2ad8af028400361ff7e8eba2ce`
- Matching release tag: `runtime-4954a81099983c506511ad362e78349940b2ab23`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
