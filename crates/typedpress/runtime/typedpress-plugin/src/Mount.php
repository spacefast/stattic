<?php
declare(strict_types=1);

namespace TypedPressPlugin;

/**
 * Mounts one build per request through TypedPress's loader (docs/host-contract.md): the live build, or,
 * for the two deploy requests that name a deployment, prove they hold its token and come from someone
 * who may deploy, the build that deployment uploaded. The plugin is the host here, so it also supplies what a host supplies: the
 * app's variables and mail delivery.
 */
final class Mount
{
    /** @var ?array{build: string, mode: string, deployment: ?string} */
    private static ?array $mounted = null;
    private static ?string $error = null;

    /**
     * Mount on `plugins_loaded` priority 1, where the loader itself would do it, but inside this
     * plugin's own callback: a build that can't mount (a required plugin was deactivated, its files
     * are gone) then leaves WordPress serving without the app instead of failing every request.
     */
    public static function run(): void
    {
        add_action('plugins_loaded', [self::class, 'mount'], 1);
    }

    public static function mount(): void
    {
        // The site's own policy on installing code, before a build adds its hardening to it.
        Site::fileMods();
        try {
            [$build, $mode, $deployment] = self::selected();
            if ($build === null) {
                return;
            }
            $manifest = Site::manifest($build);
            if ($manifest === null || !Site::complete($manifest)) {
                throw new \RuntimeException("TypedPress build $build is not in the build store");
            }
            Site::checkCommerce($manifest);
            $root = (string) Site::root();
            require_once $root . '/runtime/' . $manifest['runtime'] . '/typedpress-loader.php';
            \TypedPress\Loader\Loader::load($root, $build, [
                'variables' => self::variables(),
                'url' => Site::url(),
                'assetsDir' => Site::assets(),
                // The receiver's requests run none of the app's own code: a dry run's `/site` and
                // uploads mount the live build only to report whether it mounts.
                'inspect' => $mode === 'inspect' || ($mode === 'live' && Site::route() !== null),
            ]);
            if (\TypedPress\Wp\Runtime::plugin() === null) {
                throw new \RuntimeException('The build did not mount');
            }
            self::$mounted = ['build' => $build, 'mode' => $mode, 'deployment' => $deployment];
            // Last, so a host's own transport answers first and this one only sends what nothing took.
            // Current runtimes fall back to wp_mail themselves; a build compiled before that refuses mail
            // in managed mode without a transport, and the plugin may still mount one (a rollback).
            add_filter('tp_mail_transport', [self::class, 'mail'], PHP_INT_MAX, 2);
        } catch (\Throwable $error) {
            // WordPress keeps serving without the app; the TypedPress screen and `typedpress push` show why.
            self::$error = $error->getMessage();
            $missing = array_column(array_filter(Requirements::checks(), static fn (array $check): bool => !$check['ok'] && !$check['optional']), 'label');
            if ($missing !== []) {
                self::$error = 'The app needs ' . implode(', ', $missing) . ', which ' . (count($missing) === 1 ? 'is' : 'are') . ' missing or inactive on this site.';
            }
            error_log('[typedpress] ' . self::$error);
        }
    }

    /** @return ?array{build: string, mode: string, deployment: ?string} */
    public static function mounted(): ?array
    {
        return self::$mounted;
    }

    public static function error(): ?string
    {
        return self::$error;
    }

    /**
     * The build for this request. `X-TypedPress-Deployment: <id>:<token>` selects that deployment's build on
     * exactly two requests: `GET …/deployments/<id>/plan` mounts it to inspect, where none of the app's
     * own code runs, and `POST …/deployments/<id>/activate` mounts it to run. Mounting loads PHP the
     * deployment uploaded, so everything is settled first, by this plugin's own code: the route and
     * method, the token, and that the person asking may deploy and signed in with an application
     * password (as `typedpress push` does). A request that fails any of them gets the live build, and its
     * route then refuses it.
     *
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    private static function selected(): array
    {
        $header = $_SERVER['HTTP_X_TYPEDPRESS_DEPLOYMENT'] ?? null;
        if (is_string($header) && preg_match('/\A([0-9a-f]{32}):([0-9a-f]{64})\z/D', $header, $match) === 1) {
            // The request as WordPress will dispatch it: with a method override it is another request,
            // and {@see Site::route()} is null when it names a route in more than one place.
            $mode = Site::overridden() ? null : match ([(string) ($_SERVER['REQUEST_METHOD'] ?? ''), Site::route()]) {
                ['GET', '/deployments/' . $match[1] . '/plan'] => 'inspect',
                ['POST', '/deployments/' . $match[1] . '/activate'] => 'activate',
                default => null,
            };
            $record = $mode === null ? null : (Site::pending()[$match[1]] ?? null);
            if (is_array($record) && is_string($record['token'] ?? null) && hash_equals($record['token'], hash('sha256', $match[2]))
                && (int) ($record['expires'] ?? 0) > time() && Site::validId($record['build'] ?? null)) {
                // Last, and only for a holder of the token: this resolves the request's user. It must
                // be an application password: a browser's cookie is only good with a REST nonce, which
                // WordPress checks after plugins have loaded, and that is too late here. The build's
                // identity plugin is not loaded yet either, so its refusal of a suspended account is
                // read here too.
                // Core accepts an application password only once it knows the request is REST, which is
                // after plugins load: this one check accepts it now, for the holder of this deployment's
                // token on this deployment's own route. Every other request keeps core's rule.
                add_filter('application_password_is_api_request', '__return_true', PHP_INT_MAX);
                if (($GLOBALS['current_user'] ?? null) instanceof \WP_User && !$GLOBALS['current_user']->exists()) {
                    $GLOBALS['current_user'] = null; // the visitor a plugin that loaded earlier resolved
                }
                try {
                    $allowed = Receiver::authorized(true) === true && Receiver::password() !== null && !Site::suspended(get_current_user_id());
                } finally {
                    remove_filter('application_password_is_api_request', '__return_true', PHP_INT_MAX);
                    // WordPress keeps the user it resolved, and which password it accepted, for the rest of
                    // the request. Forget both, so the routes resolve the user again, as REST does, with
                    // every sign-in check the mounted build adds.
                    $GLOBALS['current_user'] = null;
                    rest_application_password_collect_status(null);
                }
                if ($allowed) {
                    return [$record['build'], $mode, $match[1]];
                }
            }
        }

        return [Site::live(), 'live', null];
    }

    /**
     * The app's runtime variables (`process.env.NAME` in its code): the `TP_VARIABLES` constant from
     * wp-config.php, an array of names to strings. Nothing else of the server's environment reaches
     * the app.
     *
     * @return array<string, string>
     */
    private static function variables(): array
    {
        $variables = defined('TP_VARIABLES') && is_array(TP_VARIABLES) ? TP_VARIABLES : [];
        $variables = apply_filters('tp_variables', $variables);

        return array_filter(is_array($variables) ? $variables : [], static fn ($value, $name): bool => is_string($name) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    /** `tp_mail_transport`: the app's mail leaves through WordPress's own `wp_mail`. */
    public static function mail(mixed $accepted, mixed $payload): mixed
    {
        if ($accepted !== null || !is_array($payload)) {
            return $accepted;
        }
        $mail = \TypedPress\Mail::wpMailArguments($payload);
        $alt = $mail['alt'];
        $configure = static function ($mailer) use ($alt): void {
            if ($alt !== null && is_object($mailer)) {
                $mailer->AltBody = $alt;
            }
        };
        add_action('phpmailer_init', $configure);
        try {
            return wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers']) === true;
        } finally {
            remove_action('phpmailer_init', $configure);
        }
    }
}
