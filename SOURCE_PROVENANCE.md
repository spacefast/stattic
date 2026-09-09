# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `350df54967052e5c726281207812c0461b39152b`
- Runtime source hash: `318a8aac7039be64a6231ef848f8f51e326a2fc17e6f92c9d663126a5ac5b387`
- Matching release tag: `runtime-350df54967052e5c726281207812c0461b39152b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
