# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c92ea3cb6b8f7c88260a682312ecca8039b3f074`
- Runtime source hash: `7cf39c80dd3abb56ccbbda37bf4a56d57bef58bf444572c129b752c28ba52b8e`
- Matching release tag: `runtime-c92ea3cb6b8f7c88260a682312ecca8039b3f074`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
