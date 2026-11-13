# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1e622c213ab278d78b0938c0d60491f22b7156af`
- Runtime source hash: `7f07038f2882550b539ad4fd206df333d91e5267a25a1022d4d8f8e5af0e4f7d`
- Matching release tag: `runtime-1e622c213ab278d78b0938c0d60491f22b7156af`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
