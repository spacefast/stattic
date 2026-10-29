# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ea2b41012d9568bdfee00b9ac4ecbeea9bdef2c3`
- Runtime source hash: `b5074b6c9db8384a5b8d02fad6b58ee0fe8d16c5cccc1bf9a29b2402dba9fb8c`
- Matching release tag: `runtime-ea2b41012d9568bdfee00b9ac4ecbeea9bdef2c3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
