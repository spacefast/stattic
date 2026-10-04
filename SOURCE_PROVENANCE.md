# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `29d44bb5a302c93e29253524b32b0bea0077a9ae`
- Runtime source hash: `e295e8e615f10c34dd47e67f956e3b18d35fcca05d7c2643fb38c40bc6bbd425`
- Matching release tag: `runtime-29d44bb5a302c93e29253524b32b0bea0077a9ae`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
