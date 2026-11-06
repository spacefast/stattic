# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ce01a70b432a610928438822f32a5e24813ae85b`
- Runtime source hash: `fa5940480e1636668a55db09b5f4828a565928eb80dc35d0f36d7470defa177a`
- Matching release tag: `runtime-ce01a70b432a610928438822f32a5e24813ae85b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
