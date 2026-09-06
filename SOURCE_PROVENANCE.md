# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4dd37bc8aaa82bd0fab021930112d9d860132605`
- Runtime source hash: `c62d52c65e2393117a4b4fa21b8a31527f00b60cf75078cd42659029c535282f`
- Matching release tag: `runtime-4dd37bc8aaa82bd0fab021930112d9d860132605`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
