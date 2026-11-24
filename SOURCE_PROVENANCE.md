# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2db19e73e69581ce08acc953751bcaf48de541d6`
- Runtime source hash: `31902215b25bd115fce4f4cc94dd215bf3ae7fabd62fc5e658b3cbe5a854ebf3`
- Matching release tag: `runtime-2db19e73e69581ce08acc953751bcaf48de541d6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
