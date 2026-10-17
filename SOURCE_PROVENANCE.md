# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `66c4fae07b844686de3cd33cbae2f3a5317e5e14`
- Runtime source hash: `ad12a218cb9c7123e9b4d75c9b726beb384d5aee64d7d2048b9c8b3c49faa430`
- Matching release tag: `runtime-66c4fae07b844686de3cd33cbae2f3a5317e5e14`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
