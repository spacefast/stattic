# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fb2e4b490301dcb18bdd25ee609f1b7698a96ebf`
- Runtime source hash: `c94853acdbd86cd238ce5760a08117d0ae6b540c8b420304578ef9cfce62304a`
- Matching release tag: `runtime-fb2e4b490301dcb18bdd25ee609f1b7698a96ebf`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
