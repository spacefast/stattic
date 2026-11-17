# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3a9eaa9d2426ec9eefd4292099b7dad0dcc1a129`
- Runtime source hash: `118532d36b57393620bbfd280f65d0c79df90de02fe4736bd980a93909d6e91a`
- Matching release tag: `runtime-3a9eaa9d2426ec9eefd4292099b7dad0dcc1a129`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
