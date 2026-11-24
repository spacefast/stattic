# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `99a6ded39070b0dbfe3e18b6dbf6a39bf09e7faa`
- Runtime source hash: `f2c69d8aef60ffc1949aea4459063cb04ec8679dedfb5c4b18e48cc704d942c9`
- Matching release tag: `runtime-99a6ded39070b0dbfe3e18b6dbf6a39bf09e7faa`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
