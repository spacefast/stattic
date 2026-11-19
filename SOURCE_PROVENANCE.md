# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5249068f8bd00970ed13fd2f65973f7a6205b80b`
- Runtime source hash: `5c70d4593eb04304dd1143bbff7151dfda5782b82a4be9c49051aa347ce09d4b`
- Matching release tag: `runtime-5249068f8bd00970ed13fd2f65973f7a6205b80b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
