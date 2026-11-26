# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7b2911cb618b947389f7b19770c2b6007785e79d`
- Runtime source hash: `b3de9aceec60417ec8d93668ab782146170bce826190d8284e991e2559247ae9`
- Matching release tag: `runtime-7b2911cb618b947389f7b19770c2b6007785e79d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
