# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `488a6865cdf4092bae7c90afa83c38483458a6a5`
- Runtime source hash: `7304249ffa5ef4f925c6251f96d218fb97807c28465e71dc408d0065bef11b03`
- Matching release tag: `runtime-488a6865cdf4092bae7c90afa83c38483458a6a5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
