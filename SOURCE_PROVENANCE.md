# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0d592383dc7e5082e65d8d0bd7015146a70c57e9`
- Runtime source hash: `88d2e09e775a2157a5e7f16339dcbe4e003376ec8226ec4b3f5030e50bbcc773`
- Matching release tag: `runtime-0d592383dc7e5082e65d8d0bd7015146a70c57e9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
