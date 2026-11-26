# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `49b706b5182ff0dd1fc17a8d8e308a2d034c963c`
- Runtime source hash: `6aadfdfeb1c8bb6410d3670a5de55b25e7f23c61a4a42f04e9c91027db644dd4`
- Matching release tag: `runtime-49b706b5182ff0dd1fc17a8d8e308a2d034c963c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
