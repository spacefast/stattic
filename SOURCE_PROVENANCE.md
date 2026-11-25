# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7cf49904b462fdb1d8b4ce0ab2d03523b1b98981`
- Runtime source hash: `3a7122dc836f29950a9208748b931ed10f82e621e3c6b7358369b37768e9530f`
- Matching release tag: `runtime-7cf49904b462fdb1d8b4ce0ab2d03523b1b98981`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
