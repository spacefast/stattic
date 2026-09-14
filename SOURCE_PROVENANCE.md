# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0d543f79b310ced4ebc3f131e3219a05cfb59b0d`
- Runtime source hash: `5b965305baa789827cab70ce76104223c34b518d03402ff24d9bfa0cdb134846`
- Matching release tag: `runtime-0d543f79b310ced4ebc3f131e3219a05cfb59b0d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
