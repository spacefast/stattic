# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `98a84811a0e34f8d04da13620bb4cce4d3f0ff10`
- Runtime source hash: `43d755296973823369c4d7acb2b13805d199a301cca380f2b84b7e13ab16a131`
- Matching release tag: `runtime-98a84811a0e34f8d04da13620bb4cce4d3f0ff10`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
