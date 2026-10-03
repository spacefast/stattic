# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `605041bc15d745f7d6147ada0acd810973e4f4c5`
- Runtime source hash: `c94853acdbd86cd238ce5760a08117d0ae6b540c8b420304578ef9cfce62304a`
- Matching release tag: `runtime-605041bc15d745f7d6147ada0acd810973e4f4c5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
