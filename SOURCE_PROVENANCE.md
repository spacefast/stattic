# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `086eab2f915a06289243c9dd282a135cdfdbf692`
- Runtime source hash: `f703a223d036432c102d6fe68e4fa566603f071b362bfb8467a55a9a0b72e772`
- Matching release tag: `runtime-086eab2f915a06289243c9dd282a135cdfdbf692`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
