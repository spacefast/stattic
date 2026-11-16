# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b756661f35893a66e81fa964cae3d4f19d89c793`
- Runtime source hash: `bb0786c926a50dc92e3cdbfebceed8852d0e02fcf4081e3901a07104c024bac7`
- Matching release tag: `runtime-b756661f35893a66e81fa964cae3d4f19d89c793`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
