<?php
declare(strict_types=1);

namespace SpacePressPlugin;

/**
 * The deploy receiver `spacepress push` talks to, under /wp-json/spacepress-deploy/v1. Callers sign in the way
 * core accepts for REST (an application password from `spacepress login`).
 *
 *   GET  /site                              what this site is and runs
 *   GET  /builds/<build>/baseline           the live build's schema snapshot and migrations
 *   POST /requirements                      install the pinned plugins the site lacks
 *   POST /plugin                            replace this plugin with the one the command line carries
 *   POST /deployments                       register a build; says which trees to upload
 *   POST /deployments/<id>/trees/<kind>     one part of a tree's ZIP
 *   GET  /deployments/<id>/plan             what making it live would install (mounted to inspect)
 *   POST /deployments/<id>/activate         install it and make it live
 *   POST /live                              make an earlier, initialized build live again
 */
final class Receiver
{
    private const PART = 1048576;
    /** The most a part may be: the part size the site advertises is at most {@see PART}. */
    private const PART_LIMIT = 16 * 1048576;
    /** A tree's ZIP, all parts together. SpacePress's own runtime tree is about 60 MB unpacked. */
    private const ZIP_LIMIT = 256 * 1048576;

    public static function routes(): void
    {
        $read = static fn (): bool|\WP_Error => self::authorized(false);
        $write = static fn (): bool|\WP_Error => self::authorized(true);
        $route = static function (string $path, string $method, callable $handler, callable $permission): void {
            register_rest_route(Site::REST, $path, ['methods' => $method, 'permission_callback' => $permission,
                'callback' => static function (\WP_REST_Request $request) use ($handler) {
                    try {
                        return rest_ensure_response($handler($request));
                    } catch (Refusal $refusal) {
                        return new \WP_Error($refusal->problem, $refusal->getMessage(), ['status' => $refusal->status] + $refusal->detail);
                    } catch (\Throwable $error) {
                        return new \WP_Error('sp_failed', $error->getMessage(), ['status' => 500]);
                    }
                }]);
        };
        $build = '(?P<build>[0-9a-f]{64})';
        $id = '(?P<id>[0-9a-f]{32})';
        $route('/site', 'GET', [self::class, 'site'], $read);
        $route("/builds/$build/baseline", 'GET', [self::class, 'baseline'], $read);
        $route('/requirements', 'POST', [self::class, 'requirements'], $write);
        $route('/plugin', 'POST', [self::class, 'plugin'], $write);
        $route('/deployments', 'POST', [self::class, 'create'], $write);
        $route("/deployments/$id/trees/(?P<kind>runtime|app)", 'POST', [self::class, 'part'], $write);
        $route("/deployments/$id/plan", 'GET', [self::class, 'plan'], $write);
        $route("/deployments/$id/activate", 'POST', [self::class, 'activate'], $write);
        $route('/live', 'POST', [self::class, 'live'], $write);
    }

    /**
     * `spacepress login` asks for its credential on WordPress's Authorize Application screen. A running
     * SpacePress app scopes what that screen creates for an agent (read-only, no personal data) and adds its
     * scope form there; the command line is not an agent, and a scoped credential can't deploy. So for
     * this one `app_id` the form gets no scope choices, and the credential is left as WordPress makes
     * it: everything its user can do. The screen says so.
     */
    public static function authorizeCli(): void
    {
        // Only for someone who may deploy: anyone else approving this link gets the scoped
        // credential every other approval gets, and the receiver turns it away.
        $callback = wp_parse_url((string) wp_unslash($_REQUEST['success_url'] ?? ''));
        if (($GLOBALS['pagenow'] ?? '') !== 'authorize-application.php' || ($_REQUEST['app_id'] ?? null) !== Site::CLI || !Site::mayDeploy()
            // The command line listens on this machine. A link that names it and sends the
            // credential anywhere else keeps the scope form.
            || ($callback['scheme'] ?? '') !== 'http' || ($callback['host'] ?? '') !== '127.0.0.1') {
            return;
        }
        remove_all_actions('wp_authorize_application_password_form');
        add_action('wp_authorize_application_password_form', static function (): void {
            echo '<div class="notice notice-info inline"><p>', esc_html('This is the SpacePress command line (spacepress login). The application password it gets is not scoped: it can do everything your account can, and it is what spacepress push deploys with. Revoke it under Users, Profile, Application Passwords.'), '</p></div>';
        });
    }

    /** The CLI approval runs after the app has scoped a newly created credential. */
    public static function createdPassword(mixed $user, mixed $item, mixed $password, mixed $args): void
    {
        if (!is_array($item) || !is_array($args) || ($args['app_id'] ?? null) !== Site::CLI || array_key_exists('sp_scope', $args) || !Site::deployer((int) $user)) {
            return;
        }
        $passwords = \WP_Application_Passwords::get_user_application_passwords((int) $user);
        foreach ($passwords as $i => $stored) {
            if (($stored['uuid'] ?? null) === ($item['uuid'] ?? '') && array_key_exists('sp_scope', $stored)) {
                unset($passwords[$i]['sp_scope']);
                update_user_meta((int) $user, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $passwords);
            }
        }
    }

    /**
     * Whether this request may use the receiver: every route's permission check, and what {@see Mount}
     * asks before it loads a deployment's uploaded code. It runs before WordPress initializes there, so
     * it uses nothing a build supplies.
     */
    /** The application password this request signed in with (its UUID), or null: a cookie, or nobody. */
    public static function password(): ?string
    {
        // Resolves the user first; core records the password it accepted while doing so.
        if (get_current_user_id() === 0 || !function_exists('rest_get_authenticated_app_password')) {
            return null;
        }
        $uuid = rest_get_authenticated_app_password();

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public static function authorized(bool $writes): bool|\WP_Error
    {
        if (!Site::mayDeploy()) {
            return new \WP_Error('sp_forbidden', is_user_logged_in()
                ? 'Deploying a SpacePress app needs an administrator' . (is_multisite() ? ' of the network' : '') . '.'
                : 'Sign in with an application password (spacepress login).', ['status' => rest_authorization_required_code()]);
        }
        // A credential SpacePress scoped for an agent (`wp spacepress identity agent add`) is narrower than its
        // user on purpose. Deploying installs code, which would be a way out of any scope.
        $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
        if (is_string($uuid)) {
            $password = \WP_Application_Passwords::get_user_application_password(get_current_user_id(), $uuid);
            if (is_array($password) && (array_key_exists('sp_scope', $password) || Legacy::scoped($password))) {
                return new \WP_Error('sp_forbidden', 'This application password is scoped for an agent and cannot deploy. Sign in with spacepress login.', ['status' => 403]);
            }
        }
        if ($writes && Site::closed() !== null) {
            return new \WP_Error('sp_closed', Site::closed(), ['status' => 403]);
        }

        return true;
    }

    public static function site(): array
    {
        $live = Site::live();
        $manifest = $live === null ? null : Site::manifest($live);
        $history = Site::history();
        $limit = wp_convert_hr_to_bytes((string) ini_get('post_max_size'));

        return [
            'protocol' => Site::PROTOCOL,
            'plugin' => SP_PLUGIN_VERSION,
            'pluginHash' => SP_PLUGIN_HASH,
            // This receiver runs no app code on its own requests and publishes nothing before a
            // build is made live, so `spacepress push --dry-run` may upload to it and plan.
            'dryRun' => true,
            'home' => untrailingslashit(home_url()),
            'name' => get_bloginfo('name'),
            'multisite' => is_multisite(),
            'wordpress' => (string) get_bloginfo('version'),
            'php' => PHP_VERSION,
            'requirements' => Requirements::checks(),
            'ready' => Requirements::ready(),
            'server' => Requirements::server(),
            'installed' => (object) Requirements::installed(),
            'closed' => Site::closed() ?? Site::conflict(),
            'setup' => admin_url('admin.php?page=' . Setup::PAGE),
            // A part is sent as a POST body, which PHP drops whole when it is over post_max_size.
            'part' => $limit > 0 ? (int) min(self::PART, intdiv($limit, 2)) : self::PART,
            'live' => $manifest === null ? null : [
                'build' => $live,
                'name' => $manifest['name'],
                'compiler' => (string) ($manifest['compiler'] ?? ''),
                'runtime' => $manifest['runtime'],
                'app' => $manifest['app'],
                'at' => $history === [] ? null : end($history)['at'],
            ],
            'baseline' => Site::baseline(),
            'mounted' => Mount::mounted(),
            'mountError' => Mount::error(),
            'warnings' => array_values(array_filter([Site::exposure()])),
        ];
    }

    /** What the next compile diffs against: the build's schema snapshot and its migration ledger. */
    public static function baseline(\WP_REST_Request $request): array
    {
        $manifest = Site::manifest($request['build']);
        $app = $manifest === null ? null : Site::root() . '/app/' . $manifest['app'];
        if ($app === null || !is_file($app . '/snapshot.json')) {
            // Never an older build instead: the database is at this one's schema.
            throw new Refusal('sp_unknown_build', $request['build'] === Site::baseline()
                ? 'The build this site deployed last is no longer in its build store, so the next build has nothing to be compared with. Restore the store directory from a backup.'
                : 'This site has no such build.', 404);
        }
        $migrations = [];
        foreach (scandir($app . '/migrations') ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $app . '/migrations/' . $entry;
            if (is_dir($dir)) {
                foreach (['ops.json', 'migration.sql'] as $file) {
                    if (is_file($dir . '/' . $file)) {
                        $migrations[$entry . '/' . $file] = (string) file_get_contents($dir . '/' . $file);
                    }
                }
            }
        }

        return ['build' => $request['build'], 'name' => $manifest['name'], 'snapshot' => (string) file_get_contents($app . '/snapshot.json'), 'migrations' => (object) $migrations];
    }

    /** Install the plugins a build needs: `pins` maps each slug to the version and SHA-256 to install. */
    public static function requirements(\WP_REST_Request $request): array
    {
        $pins = $request->get_json_params()['pins'] ?? null;
        if (!is_array($pins) || $pins === []) {
            throw new Refusal('sp_invalid', 'Send the plugins to install as `pins`.', 400);
        }
        try {
            $installed = Requirements::install($pins);
        } catch (\RuntimeException $error) {
            throw new Refusal('sp_requirements', $error->getMessage(), 502);
        }

        return ['installed' => $installed, 'plugins' => (object) Requirements::installed()];
    }

    /**
     * Replace this plugin with the uploaded one (`spacepress.zip`, as `spacepress plugin` writes it). A live SpacePress
     * app turns off plugin uploads in wp-admin, so the command line that deploys also keeps the plugin
     * current. Whoever may deploy may already install PHP here.
     */
    public static function plugin(\WP_REST_Request $request): array
    {
        $body = $request->get_body();
        if ($body === '' || strlen($body) > 8 * 1048576 || !hash_equals((string) $request->get_param('sha256'), hash('sha256', $body))) {
            throw new Refusal('sp_invalid', 'The plugin did not arrive whole; send it again.', 400);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $package = wp_tempnam('spacepress.zip');
        try {
            file_put_contents($package, $body);
            $archive = new \ZipArchive();
            $slug = dirname(plugin_basename(SP_PLUGIN_FILE));
            if ($archive->open($package, \ZipArchive::RDONLY) !== true || $archive->locateName('spacepress/spacepress.php') === false || $slug !== 'spacepress') {
                throw new Refusal('sp_invalid', 'This is not the SpacePress plugin, or the plugin is not installed as wp-content/plugins/spacepress.', 422);
            }
            for ($i = 0; $i < $archive->numFiles; $i++) {
                if (!str_starts_with((string) $archive->getNameIndex($i), 'spacepress/') || str_contains((string) $archive->getNameIndex($i), '..')) {
                    throw new Refusal('sp_invalid', 'The plugin archive holds a file outside spacepress/.', 422);
                }
            }
            $archive->close();
            $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
            $result = $upgrader->install($package, ['overwrite_package' => true]);
            if ($result !== true) {
                throw new Refusal('sp_plugin', 'Could not replace the SpacePress plugin: ' . (is_wp_error($result) ? $result->get_error_message() : implode(' ', $upgrader->skin->get_upgrade_messages())), 500);
            }
        } finally {
            @unlink($package);
        }

        return ['updated' => true];
    }

    /** Register a build by its manifest. Answers with a deployment ID, its token, and the trees to send. */
    public static function create(\WP_REST_Request $request): array
    {
        $bytes = $request->get_json_params()['manifest'] ?? null;
        if (!is_string($bytes) || strlen($bytes) > 65536) {
            throw new Refusal('sp_invalid', 'Send the build manifest as `manifest`.', 400);
        }
        try {
            $manifest = Site::parseManifest($bytes);
        } catch (\RuntimeException $error) {
            throw new Refusal('sp_invalid', $error->getMessage(), 400);
        }
        if (Site::conflict() !== null) {
            throw new Refusal('sp_conflict', Site::conflict(), 409);
        }
        $live = Site::live();
        $running = $live === null ? null : Site::manifest($live);
        // A pre-release build named a renamed app for the earlier brand; a current one names it as it is.
        if ($running !== null && (Legacy::build($running) ? Legacy::appName($running['name']) : $running['name']) !== $manifest['name']) {
            throw new Refusal('sp_other_app', "This site runs the app \"{$running['name']}\". A site runs one SpacePress app; this build is \"{$manifest['name']}\".", 409);
        }
        self::current($manifest);
        $build = hash('sha256', $bytes);
        // The first build a site takes names its store.
        $root = Site::store();
        Trees::prepare($root);
        if (!is_file("$root/build/$build.json")) {
            Trees::write($root, "build/$build.json", $bytes);
        }
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        // Two pushes at once each add their own record to what the other stored.
        Site::locked(static function () use ($id, $token, $build): void {
            $pending = array_filter(Site::pending(), static fn (array $record): bool => (int) ($record['expires'] ?? 0) > time());
            $pending[$id] = ['build' => $build, 'token' => hash('sha256', $token), 'expires' => time() + HOUR_IN_SECONDS, 'user' => get_current_user_id()];
            update_option(Site::PENDING, $pending, false);
        });

        return [
            'deployment' => $id,
            'token' => $token,
            'build' => $build,
            'need' => [
                'runtime' => !is_dir("$root/runtime/{$manifest['runtime']}"),
                'app' => !is_dir("$root/app/{$manifest['app']}"),
            ],
        ];
    }

    /**
     * A build carries the migrations from the build it was compiled against. It installs only on top
     * of that build: when another deployment went live in between, its migrations would be numbered
     * and planned against a schema the database no longer has.
     */
    private static function current(array $manifest): void
    {
        $baseline = Site::baseline();
        if (($manifest['baseline'] ?? null) !== $baseline) {
            throw new Refusal('sp_stale', $baseline === null
                ? 'This build was compiled against another build, and this site has none. Run spacepress push again.'
                : 'Another deployment went live after this build was compiled. Run spacepress push again.', 409, ['baseline' => $baseline]);
        }
    }

    /** @return array{build: string, manifest: array} */
    private static function deployment(string $id): array
    {
        $record = Site::pending()[$id] ?? null;
        if (!is_array($record) || (int) ($record['expires'] ?? 0) <= time()) {
            throw new Refusal('sp_unknown_deployment', 'This deployment is not open: it was finished, or it expired. Run spacepress push again.', 404);
        }
        $manifest = Site::manifest((string) $record['build']);
        if ($manifest === null) {
            throw new Refusal('sp_unknown_deployment', 'This deployment\'s build is no longer in the store. Run spacepress push again.', 404);
        }

        return ['build' => (string) $record['build'], 'manifest' => $manifest];
    }

    /**
     * One part of a tree's ZIP: `index` of `total`, and the whole ZIP's `sha256`. The last part joins
     * them, checks the checksum, and stores the tree if its contents are what the build names. Nothing
     * of it is public until the build is made live ({@see activate()}).
     */
    public static function part(\WP_REST_Request $request): array
    {
        $deployment = self::deployment($request['id']);
        $kind = (string) $request['kind'];
        $index = (int) $request->get_param('index');
        $total = (int) $request->get_param('total');
        $sha = (string) $request->get_param('sha256');
        if ($total < 1 || $total > 4096 || $index < 0 || $index >= $total || !Site::validId($sha)) {
            throw new Refusal('sp_invalid', 'A part needs index, total and the ZIP\'s sha256.', 400);
        }
        $body = $request->get_body();
        if ($body === '') {
            throw new Refusal('sp_invalid', 'The part arrived empty; it may be larger than this server accepts (post_max_size).', 413);
        }
        if (strlen($body) > self::PART_LIMIT) {
            throw new Refusal('sp_invalid', 'The part is larger than this site takes; send parts of the size it names.', 413);
        }
        $root = Site::store();
        $parts = "$root/.tmp/deploy-{$request['id']}-$kind";
        Trees::prepare($root);
        // One part at a time: the parts already held are counted and this one is stored under a
        // lock, so parts sent at once can't each pass a count the other changes. The lock is one
        // file per store that is never removed: a lock file that comes and goes can be held by two
        // requests at once, each on its own copy.
        $lock = fopen("$root/.uploads.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the uploaded tree');
        }
        try {
            return self::store($root, $parts, $deployment, $kind, $index, $total, $sha, $body);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Store one part, and with the last one join, check and publish the tree. Called under the tree's lock. */
    private static function store(string $root, string $parts, array $deployment, string $kind, int $index, int $total, string $sha, string $body): array
    {
        wp_mkdir_p($parts);
        $held = 0;
        foreach (scandir($parts) ?: [] as $name) {
            if (ctype_digit($name) && (int) $name !== $index) {
                $held += (int) filesize("$parts/$name");
            }
        }
        if ($held + strlen($body) > self::ZIP_LIMIT) {
            Trees::remove($parts);
            throw new Refusal('sp_invalid', "The $kind tree is larger than a deployment may be.", 413);
        }
        if (file_put_contents("$parts/$index", $body) === false) {
            throw new \RuntimeException('Cannot store the uploaded part');
        }
        if ($index < $total - 1) {
            return ['received' => $index, 'complete' => false];
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $zip = "$parts/tree.zip";
        try {
            $out = fopen($zip, 'wb');
            $joined = 0;
            for ($i = 0; $i < $total; $i++) {
                $in = @fopen("$parts/$i", 'rb');
                if ($out === false || $in === false) {
                    throw new Refusal('sp_invalid', "Part $i of the $kind tree is missing; send it again.", 409);
                }
                $joined += (int) stream_copy_to_stream($in, $out);
                fclose($in);
                if ($joined > self::ZIP_LIMIT) {
                    throw new Refusal('sp_invalid', "The $kind tree is larger than a deployment may be.", 413);
                }
            }
            fclose($out);
            if (!hash_equals($sha, (string) hash_file('sha256', $zip))) {
                throw new Refusal('sp_invalid', "The $kind tree did not arrive whole; send it again.", 409);
            }
            try {
                Trees::publish($root, $kind, $deployment['manifest'][$kind], $zip);
            } catch (\RuntimeException $error) {
                throw new Refusal('sp_invalid', $error->getMessage(), 422);
            }
        } finally {
            Trees::remove($parts);
        }

        return ['received' => $index, 'complete' => true];
    }

    /** The mounted build must be this deployment's, mounted the way the route needs it. */
    private static function mounted(string $id, array $deployment, string $mode): void
    {
        $mounted = Mount::mounted();
        if ($mounted === null || $mounted['deployment'] !== $id || $mounted['build'] !== $deployment['build'] || $mounted['mode'] !== $mode) {
            throw new Refusal('sp_not_mounted', Mount::error() ?? 'Send this deployment\'s token in X-SpacePress-Deployment, signed in with an application password (spacepress login).', 409);
        }
    }

    /** What making this build live would run on this site. Reads only; none of the app's code runs. */
    public static function plan(\WP_REST_Request $request): array
    {
        $deployment = self::deployment($request['id']);
        self::mounted($request['id'], $deployment, 'inspect');
        $plan = \SpacePress\Loader\Loader::inspect((string) Site::root(), $deployment['build']);

        return ['build' => $deployment['build'], 'steps' => $plan['steps'], 'refusals' => $plan['refusals'], 'live' => Site::live()];
    }

    /** Install the build (tables, migrations, pages, schedules, seed) and make it the live one. */
    public static function activate(\WP_REST_Request $request): array
    {
        $deployment = self::deployment($request['id']);
        self::mounted($request['id'], $deployment, 'activate');
        Site::checkCommerce($deployment['manifest']);
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $root = Site::store();
        $build = $deployment['build'];
        // A build's browser files are made public here, when it goes live, and not when its trees
        // are uploaded: a dry run uploads them too.
        foreach (['runtime', 'app'] as $kind) {
            Trees::mirror($root, Site::assets(), $kind, $deployment['manifest'][$kind]);
        }
        $id = (string) $request['id'];
        try {
            // One deployment installs at a time. The baseline is read again inside the lock: two pushes
            // compiled against the same build can't both install.
            $receipt = Site::locked(static function () use ($root, $build, $deployment, $id): array {
                self::current($deployment['manifest']);
                $receipt = \SpacePress\Loader\Loader::initialize($root, $build);
                $canSyncPublic = class_exists(\SpacePress\Wp\PublicFiles::class) && \SpacePress\Wp\Runtime::plugin() !== null;
                if ($canSyncPublic && !method_exists(\SpacePress\Wp\PublicFiles::class, 'syncSymlinks')) {
                    throw new \RuntimeException('Active runtime cannot synchronize public files during activation.');
                }
                Site::makeLive($build, $deployment['manifest'], 'deploy');
                if ($canSyncPublic) {
                    \SpacePress\Wp\PublicFiles::syncSymlinks(\SpacePress\Wp\Runtime::plugin());
                }
                $pending = Site::pending();
                unset($pending[$id]);
                update_option(Site::PENDING, $pending, false);

                return $receipt;
            });
        } catch (Refusal $refusal) {
            throw $refusal;
        } catch (\Throwable $error) {
            throw new Refusal('sp_not_installed', $error->getMessage(), 409, ['live' => Site::live()]);
        }

        return ['live' => $build, 'name' => $deployment['manifest']['name'], 'migrations' => $receipt['migrations'], 'seed' => $receipt['seed'], 'home' => untrailingslashit(home_url())];
    }

    /** Make an earlier build live again. It must have been initialized here; nothing is installed. */
    public static function live(\WP_REST_Request $request): array
    {
        $build = $request->get_json_params()['build'] ?? null;
        try {
            self::rollback(is_string($build) ? $build : '');
        } catch (\RuntimeException $error) {
            throw new Refusal('sp_unknown_build', $error->getMessage(), 409);
        }

        return ['live' => $build];
    }

    public static function rollback(string $build): void
    {
        // Behind the same lock as a deployment, so neither loses the other's entry in the history.
        Site::locked(static function () use ($build): void {
            $manifest = Site::manifest($build);
            if ($manifest !== null && Legacy::build($manifest)) {
                throw new \RuntimeException('This build was made by a pre-release SpacePress, which this plugin cannot run. Push the project again.');
            }
            if ($manifest === null || !Site::complete($manifest) || !Site::ready($build)) {
                throw new \RuntimeException('Only a build that was live on this site before can be made live again.');
            }
            Site::checkCommerce($manifest);
            $root = (string) Site::root();
            foreach (['runtime', 'app'] as $kind) {
                Trees::mirror($root, Site::assets(), $kind, $manifest[$kind]);
            }
            $canSyncPublic = class_exists(\SpacePress\Wp\PublicFiles::class) && class_exists(\SpacePress\Schema::class);
            if ($canSyncPublic && !method_exists(\SpacePress\Wp\PublicFiles::class, 'syncSymlinksForDir')) {
                throw new \RuntimeException('Active runtime cannot synchronize public files during rollback.');
            }
            Site::makeLive($build, $manifest, 'rollback');
            if ($canSyncPublic) {
                $appDir = $root . '/app/' . $manifest['app'] . '/plugins/spacepress-app-' . $manifest['name'];
                $schemaFile = $appDir . '/sp/app/schema.php';
                if (is_file($schemaFile)) {
                    $assetsPublic = Site::assets() . '/app/' . $manifest['app'] . '/plugins/spacepress-app-' . $manifest['name'] . '/public';
                    \SpacePress\Wp\PublicFiles::syncSymlinksForDir($appDir, $assetsPublic, \SpacePress\Schema::load($schemaFile));
                }
            }
        });
    }
}

/** A deploy request the site refuses, as a REST error with a status. */
final class Refusal extends \RuntimeException
{
    public function __construct(public readonly string $problem, string $message, public readonly int $status, public readonly array $detail = [])
    {
        parent::__construct($message);
    }
}
