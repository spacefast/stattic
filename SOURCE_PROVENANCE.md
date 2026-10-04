# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `14ddc6a980f35754d5bd184e2d415866825b32a2`
- Runtime source hash: `faa2d25d1207807b2d627df1a880c053b7a7c53f9dd4e731fc5800724a4afbef`
- Matching release tag: `runtime-14ddc6a980f35754d5bd184e2d415866825b32a2`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
