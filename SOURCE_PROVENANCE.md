# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c7ce8f5695a8ae5bb876577c262f6f2317548e71`
- Runtime source hash: `9c0bbda816a35bafd5fd6817fc465dc7d3017dbdaf6b783795d63754f12e5b6e`
- Matching release tag: `runtime-c7ce8f5695a8ae5bb876577c262f6f2317548e71`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
