# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c76fd6a9e35188e6a1cefbb79a2f022121c7209e`
- Runtime source hash: `6440514f555cbe6aeff831e2ccf73dd245a7b0755eb8c3fc054b1c9012fc6627`
- Matching release tag: `runtime-c76fd6a9e35188e6a1cefbb79a2f022121c7209e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
