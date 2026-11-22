# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8af3e0914e13523eed9d68d0b4d8b3ea333d0bbc`
- Runtime source hash: `3fcd0a02078dbe24095a4337a22c19c8bb409323bb0b9ee07d667d2b336dbba4`
- Matching release tag: `runtime-8af3e0914e13523eed9d68d0b4d8b3ea333d0bbc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
