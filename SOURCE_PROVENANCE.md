# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `f43aa09566ecd4f3d82419c20da70aa21cb77de1`
- Runtime source hash: `33521998c10cbd27fa04e5251628b13e30a510c800821ca906fa1c4fca499300`
- Matching release tag: `runtime-f43aa09566ecd4f3d82419c20da70aa21cb77de1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
