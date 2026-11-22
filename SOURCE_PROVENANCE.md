# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4ef8115ea72be092a5f65deb3c695fa973bc41b0`
- Runtime source hash: `b773102e1126eb36deff5aa9d23d9be3da54a0e595aedf86bae29613dba12767`
- Matching release tag: `runtime-4ef8115ea72be092a5f65deb3c695fa973bc41b0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
