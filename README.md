# Stattic runtime source

This repository mirrors the source corresponding to Spacefast's published
Stattic runtime engine. The current snapshot comes from monorepo revision
`6ed867db75fca3b12c7df4ccc854065bacb780f8`.

Release tags use `runtime-<monorepo revision>` and point at the public source
commit used for that release. The attached `runtime-engine.zip` is the
installable wp.cloud engine; it contains the PHP runtime and one multicall
native executable, `bin/stattic-runtime`.

See [SOURCE_PROVENANCE.md](SOURCE_PROVENANCE.md) for the exact source identity
and mirror boundary.
