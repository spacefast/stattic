# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8132a7d83b5265a99306da2ab0c254d677461348`
- Runtime source hash: `a7e6f43ec2499276df843e94f2017d51843db4cd13d17c11474c1956c9807374`
- Matching release tag: `runtime-8132a7d83b5265a99306da2ab0c254d677461348`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
