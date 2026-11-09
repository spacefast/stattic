# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `aec56b0b74f495239c383088178d106bcd5b6929`
- Runtime source hash: `b9bed194eb1147c3b44feb88ef7e08b5a23058c53ba0080e9150ec48540246c8`
- Matching release tag: `runtime-aec56b0b74f495239c383088178d106bcd5b6929`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
