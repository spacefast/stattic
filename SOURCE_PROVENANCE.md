# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e0cfe7ba946088ed0396be3e8c259995ab9406bd`
- Runtime source hash: `431b7f139a2493544bbd03d088d7fbac8a82b37f389926cd5cf501bd6e2a0e04`
- Matching release tag: `runtime-e0cfe7ba946088ed0396be3e8c259995ab9406bd`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
