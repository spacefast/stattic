# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d0ff973340d1813b521398bc823a74a17561e833`
- Runtime source hash: `59727dccec63297ac97f9bbfef18ff963e5c0636cbf0c24b5799d5ed2360056b`
- Matching release tag: `runtime-d0ff973340d1813b521398bc823a74a17561e833`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
