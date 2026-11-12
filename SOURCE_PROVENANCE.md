# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `88b31c83ad7ba1a30d7eb2160f383d4163d742f8`
- Runtime source hash: `b1d757a36ec63006fb77675b066dc5cb1e43b98e7ba6da145c13be47343c7b5c`
- Matching release tag: `runtime-88b31c83ad7ba1a30d7eb2160f383d4163d742f8`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
