# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `208c3ff1e6e06b69278d10d6ba9fe58d1c1afaa5`
- Runtime source hash: `136b84ec2918e06bc2155047b44c0b989f8c03819ae9ce90ee85e66e8a9db8ab`
- Matching release tag: `runtime-208c3ff1e6e06b69278d10d6ba9fe58d1c1afaa5`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
