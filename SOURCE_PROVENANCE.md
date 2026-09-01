# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e593b4183aeeaab3809e95121b209466f187e9c8`
- Runtime source hash: `d5fb3fcc86cc9fdc88247d092f24b3f67a419a15f34f5c53ac682071db1593b4`
- Matching release tag: `runtime-e593b4183aeeaab3809e95121b209466f187e9c8`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
