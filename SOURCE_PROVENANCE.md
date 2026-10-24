# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5bd42d154e4c0956fd548f728b76c1dd911fbae4`
- Runtime source hash: `b572ba03c201947bbd3606e1dba72e50cf30cc5588953c771ad56ca6a2fbb200`
- Matching release tag: `runtime-5bd42d154e4c0956fd548f728b76c1dd911fbae4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
