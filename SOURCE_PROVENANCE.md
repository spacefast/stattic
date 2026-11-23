# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b9da4dc3b55e832bb62c43f9ac5bf114bc318cba`
- Runtime source hash: `c9335ca944277fa048be9bb283de70d41419552fcdf9f2714efa077134a37d2d`
- Matching release tag: `runtime-b9da4dc3b55e832bb62c43f9ac5bf114bc318cba`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
