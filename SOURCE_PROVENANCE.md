# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6eac4659445a4995986fd047656351a448093133`
- Runtime source hash: `b8c95e3a6c39aed991a92f4838779d94471d0c85407939fd22a637ace1de2cef`
- Matching release tag: `runtime-6eac4659445a4995986fd047656351a448093133`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
