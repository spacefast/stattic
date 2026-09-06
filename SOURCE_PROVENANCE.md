# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fb979ee8ee036355f48097e3a9dd2402d7651af2`
- Runtime source hash: `9a38af0196f27bea6a27de50f7d2d8d663d3392c95c75efa011931b119a9085f`
- Matching release tag: `runtime-fb979ee8ee036355f48097e3a9dd2402d7651af2`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
