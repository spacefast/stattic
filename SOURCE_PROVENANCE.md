# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c39cb54c10bd1751ff918b226ccb89553017b12b`
- Runtime source hash: `e1e6a0bc7f6cf37192b96b36ffc3a81afe8e947b5a03ab074f2c85d7d25981e6`
- Matching release tag: `runtime-c39cb54c10bd1751ff918b226ccb89553017b12b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
