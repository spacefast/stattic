# WordPress plugin templates

`zero-runtime.php` loads the one shared runtime. `zero-app.php` registers one
compiled app after plugins load and handles its activation. Both headers carry
the compiler version and required platform floors. The app version includes the
schema fingerprint; the runtime checks the schema series before registering it.
App metadata is read from that schema rather than global `ZERO_APP_*` constants,
so trying to activate another app produces a located runtime notice without
redefining the first app's global state.

`zero build` writes these under `.zero/wp-content/plugins`, alongside
the identity bundle, acquired dependencies and generated ZIPs. One
`zero-manifest.json` describes the complete artifact. App-specific schema,
functions, MySQL migrations and public files live in `zero-app-<name>`; shared
PHP and prefixed dependencies live only in `zero-runtime/zero`.
