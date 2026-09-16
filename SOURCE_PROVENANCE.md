# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8ffdcf1d4ce7d5990aa62e3f4a529c2e59c2f4f0`
- Runtime source hash: `8bcba4aa1d78e965c0280cbd0ff4ca7963bb1aa5831e9eb65892050a166a2a83`
- Matching release tag: `runtime-8ffdcf1d4ce7d5990aa62e3f4a529c2e59c2f4f0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
