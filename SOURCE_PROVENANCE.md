# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4fbec4825698e1bdea501588934395034e8dc7bd`
- Runtime source hash: `8035f868028939d217a8830a240d9e342cf5e2aeb8f57f946e97984466fde7a0`
- Matching release tag: `runtime-4fbec4825698e1bdea501588934395034e8dc7bd`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
