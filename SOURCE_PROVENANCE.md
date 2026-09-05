# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8447a47e19aaeeef3d8080a32f3c4d65e73c111f`
- Runtime source hash: `76f302e5cd922e8f699a6d5c26d8c5f186c3067617c75300a19e57b3fe27f332`
- Matching release tag: `runtime-8447a47e19aaeeef3d8080a32f3c4d65e73c111f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
