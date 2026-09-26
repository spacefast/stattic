# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `145ff3a3cef60c5568d05305b4011db78947c417`
- Runtime source hash: `badfe16163f4ebc12b66ad86c6fb99c5be817fb281bae48027d02d5c8d04bc01`
- Matching release tag: `runtime-145ff3a3cef60c5568d05305b4011db78947c417`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
