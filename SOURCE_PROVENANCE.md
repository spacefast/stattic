# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7755b94772d8044db28cb04e5620751692eb1c3b`
- Runtime source hash: `17f40ad593b72ed5a7e63f64a30276543c2c8bda90b40837e2f08b59352d5482`
- Matching release tag: `runtime-7755b94772d8044db28cb04e5620751692eb1c3b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
