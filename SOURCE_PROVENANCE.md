# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `283cac905885272bc47d1016dd810101c8db09c0`
- Runtime source hash: `d47bd2b43005faa57310e237ada215e164009e6251d583b8d8fb5cd21375f660`
- Matching release tag: `runtime-283cac905885272bc47d1016dd810101c8db09c0`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
