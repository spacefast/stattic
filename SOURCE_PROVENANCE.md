# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8b5237e841c0cea495020efecec19f2f6c7ac937`
- Runtime source hash: `7a0d18ebac3583a474a97847d5b99c22a19ec910591563895634f2223d7d445f`
- Matching release tag: `runtime-8b5237e841c0cea495020efecec19f2f6c7ac937`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
