# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b58079717610157e582c766c0ff4f6884d8a8afa`
- Runtime source hash: `8784aaeab03a0851fa8ec5d3789c848ca5bfd32f66622bf28a13d8c02fcae6eb`
- Matching release tag: `runtime-b58079717610157e582c766c0ff4f6884d8a8afa`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
