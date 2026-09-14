# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `10d073073454642a2f74fedb32fc36de0f4a9877`
- Runtime source hash: `e140c9710cefa2ba593c1d7078af81fed81328f442c01531ad0265feead520c7`
- Matching release tag: `runtime-10d073073454642a2f74fedb32fc36de0f4a9877`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
