# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e02fe290f7ead8dede6bc409707597007e942da0`
- Runtime source hash: `9aa8ea92c70c17241df34d8ebbe2173a0ca2301b7cc714d157ba564d7dda2ae1`
- Matching release tag: `runtime-e02fe290f7ead8dede6bc409707597007e942da0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
