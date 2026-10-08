# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `22af2b65b94e8a096e3d1e8387cf1d254b792503`
- Runtime source hash: `dcb0ea745b751874d21e986b6afa57ca3eddd936965e2dc631879721d9a5c0cc`
- Matching release tag: `runtime-22af2b65b94e8a096e3d1e8387cf1d254b792503`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
