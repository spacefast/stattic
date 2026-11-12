# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1e427ace5f1b61133443120dd05dc077e42cb1e6`
- Runtime source hash: `06567ec988738587be365bd8b5e326a9e5114ab481641e4077ecd51471b11fe1`
- Matching release tag: `runtime-1e427ace5f1b61133443120dd05dc077e42cb1e6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
