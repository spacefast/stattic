# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `53bf4565f372962464bfeed7faac1aa0bea4d694`
- Runtime source hash: `27923aea0f278a13df2b32012a80a5ae74ba9c117de502091b4fc19ef5bdbe4c`
- Matching release tag: `runtime-53bf4565f372962464bfeed7faac1aa0bea4d694`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
