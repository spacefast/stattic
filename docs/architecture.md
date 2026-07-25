# Architecture

## Product boundary

Stattic owns portable compilation and serving:

```text
files / Markdown / Gutenberg / framework output
                         |
                         v
                  Stattic Builder
                         |
                         v
                  stattic.bundle.v1
                         |
                         v
              verify -> admit -> activate
                         |
                         v
                   Stattic Runtime
```

Spacefast consumes this boundary and adds cloud orchestration:

```text
Spacefast accounts, Git builds, domains, placement and lifecycle
                              |
                              v
              Stattic contracts + Builder + Runtime
```

Stattic never imports Spacefast control-plane code.

## Components

### Stattic Builder

The `stattic` CLI invokes the existing Rust semantic core. The first portable
host uses an isolated synthetic storage root and the production finalizer
pipeline, then exports the immutable version as a bundle. This keeps Markdown,
Gutenberg, theme, routing and policy behavior on the same authority while the
filesystem-specific core is progressively narrowed.

The current full filesystem finalize remains native because Zero compilation
uses the native QuickJS runner. Pure preparation operations are already
available through WASI. A future `build_bundle` WASI host will support the
static-only profile without native process requirements.

### Stattic Runtime

PHP verifies the descriptor, the closed artifact manifest, every byte digest
and the deployment digest. It then atomically installs the precompiled payload.
Admission has no fallback build lane and succeeds when process functions are
disabled.

Static request handling reads the compiled PHP and JSON artifacts. Zero is a
separate capability and may require `stattic-zero-runner`; it is not part of
the `portable-static` profile.

### Spacefast adapter

Spacefast is responsible for:

- identities, Spaces and Versions;
- hosted framework builds;
- trusted Builder releases and provenance;
- artifact upload and deduplication;
- target binding, secrets and Zero migration;
- Runtime placement, activation CAS and public proof;
- domains, CDN, rollback, logs, metrics and billing.

Every Spacefast source path must converge on `stattic.bundle.v1`. Prebuilt
bundles are verified and admitted without recompilation.

## Identity

`spaceId` and `versionId` are universal deployment coordinates. They are
present in descriptors, receipts and Runtime storage paths.

They do not determine portable bytes:

```text
contentDigest     = H(canonical payload manifest)
bindingDigest     = null for portable-static
deploymentDigest  = H(profile || contentDigest || bindingDigest)
```

Two Spaces can therefore use identical compiled content without sharing
authorization or activation state.

## Transitional boundary

The extracted core still exposes several Spacefast-named internal formats and
reserved paths. They are compatibility inputs, not new portable contracts.
Before 1.0 they must either:

1. become neutral `stattic.*` contracts; or
2. move behind a Spacefast adapter crate/package.

No second parser or compatibility fork should be introduced during that work.

