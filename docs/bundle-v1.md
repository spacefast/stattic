# `stattic.bundle.v1`

The first bundle representation is a directory. Archive serialization can be
added later without changing identity because digests cover canonical payload
entries, not archive metadata.

```text
site.stattic/
├── bundle.json
└── payload/
    ├── files/
    ├── serving.php
    ├── php-manifest.php
    ├── headers.php
    ├── redirects.php
    ├── file-shards/
    └── other profile-declared artifacts
```

## Descriptor

```json
{
  "format": "stattic.bundle.v1",
  "profile": "portable-static",
  "spaceId": "local",
  "versionId": "v1",
  "contentDigest": "sha256:...",
  "bindingDigest": null,
  "deploymentDigest": "sha256:...",
  "builder": {
    "abi": "stattic.builder.v1",
    "version": "0.1.0"
  },
  "requirements": {
    "runtimeAbi": "static-runtime-v2",
    "serverBuild": false,
    "rustFinalizer": false,
    "zeroRunner": false
  },
  "artifacts": [
    {
      "path": "files/index.html",
      "size": 123,
      "sha256": "..."
    }
  ]
}
```

## Canonical identity

Artifacts are sorted by path. The content hash input is:

```text
"stattic.bundle.content.v1\0"
for each artifact:
  path "\0" decimal_size "\0" lowercase_sha256 "\0"
```

The deployment hash input is:

```text
"stattic.bundle.deployment.v1\0"
profile "\0"
contentDigest "\0"
bindingDigest-or-empty
```

Space and version coordinates are intentionally excluded. Changing coordinates
must not change compiled identity.

## Admission rules

A `portable-static` Runtime must:

- reject unknown formats and profiles;
- require `bindingDigest: null`;
- require all build and Zero requirements to be false;
- reject unsafe, duplicate, missing and extra payload paths;
- hash every payload file before staging;
- verify content and deployment digests;
- commit the complete version with one rename;
- replay an exact already-admitted deployment idempotently;
- reject the same version coordinate with a different deployment digest.

No admission failure may trigger a server-side build.

