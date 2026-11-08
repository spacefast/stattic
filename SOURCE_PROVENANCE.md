# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `504bfc41561842f033d72ee2193fa531f47ee349`
- Runtime source hash: `8801952be1c1fb211a93e30431a1058ee3ad98beaaf03d76a45e8371e86afee5`
- Matching release tag: `runtime-504bfc41561842f033d72ee2193fa531f47ee349`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
