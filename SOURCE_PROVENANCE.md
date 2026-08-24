# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `42140d9b8d6cd2d43f433f26a1c052b52f02919b`
- Runtime source hash: `ba436db23f7e5d873830b2051786d277367d1de32a4a84d45e053dbb4471ea1a`
- Matching release tag: `runtime-42140d9b8d6cd2d43f433f26a1c052b52f02919b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
