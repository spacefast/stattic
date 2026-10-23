# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `79ca7dba0c70f9265d4ece88179c14ae2a68bdc3`
- Runtime source hash: `24440785f94c34180f3aa01c225f0ebe4ffe6c3eb394c82cea3d0920b3c33e09`
- Matching release tag: `runtime-79ca7dba0c70f9265d4ece88179c14ae2a68bdc3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
