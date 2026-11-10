# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1c48733bbb82d767b7c853b7b29255d366a7d680`
- Runtime source hash: `97649eb18b7062c3e5977c47c7adfe18820872a3eea8c2beff424d7d547a14d4`
- Matching release tag: `runtime-1c48733bbb82d767b7c853b7b29255d366a7d680`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
