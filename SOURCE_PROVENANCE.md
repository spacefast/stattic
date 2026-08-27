# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a29a9852b1d2456fb2fcfc5cd21588e203d233d0`
- Runtime source hash: `e5b6f812baffe2e56284ba28bde421d7d42e2fabf9af0d7cddf1a396a6e31170`
- Matching release tag: `runtime-a29a9852b1d2456fb2fcfc5cd21588e203d233d0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
