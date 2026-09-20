# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bb77360b1bc68c8bdf84a2f73900545db35015c9`
- Runtime source hash: `fb646d97a8cf7dd2e7eed7c33379e3814ab094e3e341a9d382b2fbae3ef687cb`
- Matching release tag: `runtime-bb77360b1bc68c8bdf84a2f73900545db35015c9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
