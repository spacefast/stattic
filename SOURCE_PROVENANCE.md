# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4c3b2c6037a5d3ca56433d7d031145f5c1d1f606`
- Runtime source hash: `10ed7f1e0e16b2073fccd4df992231596c479258fcf696fe1b3ddd4a177b5321`
- Matching release tag: `runtime-4c3b2c6037a5d3ca56433d7d031145f5c1d1f606`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
