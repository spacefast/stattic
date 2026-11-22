# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `29d440781d09da3ac0d8cbada79501d09eb05364`
- Runtime source hash: `dc9ee6818059ee37a685cf272fa35a3eb6889e1e32dd30bb643f8e4d3dee69ea`
- Matching release tag: `runtime-29d440781d09da3ac0d8cbada79501d09eb05364`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
