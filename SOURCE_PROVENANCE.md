# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `cc0027e8f974eae05b393d892c12d378e98ce7f3`
- Runtime source hash: `56ff92734fed12a6a9467f0b6f98115a6aa4750e4088a37301d839375c34d226`
- Matching release tag: `runtime-cc0027e8f974eae05b393d892c12d378e98ce7f3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
