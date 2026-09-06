# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2b7f8b989cec7d0b5e03d53323ab979c78a58576`
- Runtime source hash: `f70f4d7a2728e54884f4a4bfcab0e3df7378264dc907b771c818ecf2dc3edd7b`
- Matching release tag: `runtime-2b7f8b989cec7d0b5e03d53323ab979c78a58576`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
