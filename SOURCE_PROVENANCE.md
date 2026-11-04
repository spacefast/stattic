# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2f71d056d4409f5362c1782d5fe3a482334911b9`
- Runtime source hash: `5a29441fb85f4942b83a9ccc95204c6090662e0c7b02b190de21a93a1b18f9a9`
- Matching release tag: `runtime-2f71d056d4409f5362c1782d5fe3a482334911b9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
