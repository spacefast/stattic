# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d34a7bbccf4cd7d9d5cf5d9d7955695a84969153`
- Runtime source hash: `b1abd4087f0a221f17cbee0b503f97d119f9c1349630d0befcc72be37bab5ca8`
- Matching release tag: `runtime-d34a7bbccf4cd7d9d5cf5d9d7955695a84969153`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
