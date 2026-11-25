<?php

declare(strict_types=1);

namespace SpacePress\Identity;

use SpacePress\Identity\Providers\OidcClient;

final class Providers
{
    public function __construct(
        private Security $security,
        private Accounts $accounts,
        private Sessions $sessions,
    ) {}
    /**
     * Google is the one built-in provider; sites add others through `sp_identity_providers`:
     * `issuer`, `client_id`, `secret` (empty for a public client), optional `discovery` entries
     * that override the issuer's discovery document, and `alg` (`RS256` default, or `EdDSA`).
     */
    public static function config(): array
    {
        $providers = [];
        if (defined("SP_IDENTITY_GOOGLE_CLIENT_ID")) {
            $providers["google"] = [
                "issuer" => "https://accounts.google.com",
                "client_id" => SP_IDENTITY_GOOGLE_CLIENT_ID,
                "secret" => defined("SP_IDENTITY_GOOGLE_CLIENT_SECRET") ? SP_IDENTITY_GOOGLE_CLIENT_SECRET : "",
            ];
        }
        return apply_filters("sp_identity_providers", $providers);
    }
    public function client(string $name): OidcClient
    {
        $config = self::config()[$name] ?? null;
        if (!$config) {
            throw new Failure(
                "provider_not_configured",
                "This sign-in provider has not been configured.",
                503,
            );
        }
        return new OidcClient(
            $config["issuer"],
            $config["client_id"],
            $config["secret"],
            self::callbackUrl($name),
            $config["discovery"] ?? null,
            $config["alg"] ?? "RS256",
        );
    }
    /**
     * The redirect URI sent to a provider, below the issuer's route prefix (`SP_AUTH_PREFIX` /
     * `sp_auth_prefix`, default `/identity`). The default stays `<issuer>/provider/callback?provider=<name>`,
     * so a provider already registered with it keeps working. Both that and the path form
     * `<issuer>/callback/<name>` are served; a host picks the one its provider is registered with
     * through `sp_identity_callback_url`, which receives the default, the provider and the path form.
     */
    public static function callbackUrl(string $name): string
    {
        $base = Plugin::basePath();
        $query = home_url($base . "/provider/callback?provider=" . rawurlencode($name));
        $path = home_url($base . "/callback/" . rawurlencode($name));
        $filtered = apply_filters("sp_identity_callback_url", $query, $name, $path);
        return is_string($filtered) && $filtered !== "" ? $filtered : $query;
    }
    /** The provider a callback route names: `/callback/<name>`, or `/provider/callback` with `?provider=`. */
    public static function callbackProvider(string $route, array $query): ?string
    {
        if ($route === "/provider/callback") {
            return sanitize_key(is_string($query["provider"] ?? null) ? $query["provider"] : "");
        }
        if (preg_match('#\A/callback/([^/]+)\z#', $route, $match) === 1) {
            return sanitize_key(rawurldecode($match[1]));
        }
        return null;
    }
    /** Where the hosted screen may continue after sign-in (issuer-relative): a pending authorization, nothing else. */
    public static function continuation(mixed $target): string
    {
        return is_string($target) && str_starts_with($target, "/authorize?") && strlen($target) <= 4096
            ? $target
            : "";
    }
    public function start(string $name, ?array $linkSession = null, array $extra = [], string $returnTo = ""): string
    {
        if ($linkSession) {
            $this->sessions->recent($linkSession);
        }
        $request = $this->client($name)->start($extra);
        $binding = Security::token();
        $id = $this->security->challenge(
            "oidc",
            (int) ($linkSession["wp_user_id"] ?? 0),
            (string) ($linkSession["id"] ?? ""),
            ["provider" => $name, "store" => $request["store"], "return_to" => self::continuation($returnTo)],
            $binding,
            300,
        );
        // Path=/ so the __Host- prefix applies over HTTPS: a sibling host cannot plant it.
        setcookie(Sessions::cookieName("zi_oidc"), $id . "." . $binding, [
            "expires" => time() + 300,
            "path" => "/",
            "secure" => is_ssl(),
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        return $request["url"];
    }
    /** @return array{uid: int, return_to: string} */
    public function callback(string $name): array
    {
        [$id, $binding] = array_pad(
            explode(".", (string) ($_COOKIE[Sessions::cookieName("zi_oidc")] ?? ""), 2),
            2,
            "",
        );
        $challenge = $this->security->consume($id, "oidc", $binding);
        $payload = $challenge["payload"];
        if ($payload["provider"] !== $name) {
            throw new Failure(
                "invalid_state",
                "The provider sign-in expired or is invalid.",
            );
        }
        $linkUid = (int) $challenge["wp_user_id"];
        if ($linkUid) {
            $session = $this->sessions->require();
            if ($session["id"] !== $challenge["session_id"]) {
                throw new Failure(
                    "link_session_changed",
                    "Sign in again before linking.",
                    403,
                );
            }
            $this->sessions->recent($session);
        }
        try {
            $result = $this->client($name)->finish($payload["store"], $_GET);
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->security->event(0, "provider.rejected", [
                "provider" => $name,
            ]);
            throw new Failure(
                "provider_validation_failed",
                "Provider identity could not be verified. Please start again.",
                401,
            );
        }
        $claims = (object) $result["claims"];
        $info = (object) $result["info"];
        $email =
            ($info->email_verified ?? false) === true &&
            is_string($info->email ?? null)
                ? Accounts::email($info->email)
                : null;
        return [
            "uid" => $this->accounts->provider(
                $claims->iss,
                $claims->sub,
                $email,
                is_string($info->name ?? null) ? $info->name : "",
                $linkUid ?: null,
                $linkUid ? $challenge["session_id"] : null,
            ),
            "return_to" => self::continuation($payload["return_to"] ?? ""),
        ];
    }
}
