# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `33fe6558e489552693aa7706b17d4f3b2a66d522`
- Runtime source hash: `2e60c190f3a337997e9baabad1a99764bf8365384543a5ede8e649c8e31e60f8`
- Matching release tag: `runtime-33fe6558e489552693aa7706b17d4f3b2a66d522`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
