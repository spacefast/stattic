# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `cf4648a854b1186a3a513deb6e868c025734f301`
- Runtime source hash: `ae73197a2a1c738ae4f0ee4306708233b297905975fb8efcec4227c594604e6c`
- Matching release tag: `runtime-cf4648a854b1186a3a513deb6e868c025734f301`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
