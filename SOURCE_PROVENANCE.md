# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `caa0e795f84d5a7e6f345315676c0c52400b0cf6`
- Runtime source hash: `4a5655beb95f8067b8b24c0101b2ed3194ba03ba0cc3c8e59c1c658f4981ad72`
- Matching release tag: `runtime-caa0e795f84d5a7e6f345315676c0c52400b0cf6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
