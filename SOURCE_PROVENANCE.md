# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8e1a6d7ad43dfc46b2e1892ceefd5ed59612f40d`
- Runtime source hash: `a252dd4358d7a273d5ac02b2f6b8a86cd727bca25d6d5de29e319058eaea2eaa`
- Matching release tag: `runtime-8e1a6d7ad43dfc46b2e1892ceefd5ed59612f40d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
