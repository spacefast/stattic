# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b2fd62b9136472a675d40c80ccaedc87c77c8899`
- Runtime source hash: `deb1f0fcb7ced97f4d204580160e13a396fecbf80a50995638511ef3b59444f5`
- Matching release tag: `runtime-b2fd62b9136472a675d40c80ccaedc87c77c8899`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
