# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `efd363777fdf9bd873c0a4b4dbb08295b60cf891`
- Runtime source hash: `26ce8d6889196d745f5d0b8966e9dfc8fc31de1e8fdea80dce57120f2fdcaaa1`
- Matching release tag: `runtime-efd363777fdf9bd873c0a4b4dbb08295b60cf891`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
