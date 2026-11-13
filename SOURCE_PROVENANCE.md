# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a412c7dbf2f76b2615b00e806cd5924289748968`
- Runtime source hash: `57bf739468b7437ae6fec6929b526e6643a08c791acb872504f5045aa41ce5c3`
- Matching release tag: `runtime-a412c7dbf2f76b2615b00e806cd5924289748968`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
