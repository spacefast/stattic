# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a34f72e0564e659967ac63d3453cb169885bb8f9`
- Runtime source hash: `5813e6da5015c99145ddf8fc1595fa3cb14d192b0b04177242f18d27a872d4ac`
- Matching release tag: `runtime-a34f72e0564e659967ac63d3453cb169885bb8f9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
