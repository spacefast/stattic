<?php

declare(strict_types=1);

namespace Zero\Identity;

use Zero\Identity\Providers\OidcClient;

final class Providers
{
    public function __construct(
        private Security $security,
        private Accounts $accounts,
        private Sessions $sessions,
    ) {}
    /** Google is the one built-in provider; sites add others through `zero_identity_providers`. */
    public static function config(): array
    {
        $providers = [];
        if (defined("ZERO_IDENTITY_GOOGLE_CLIENT_ID")) {
            $providers["google"] = [
                "issuer" => "https://accounts.google.com",
                "client_id" => ZERO_IDENTITY_GOOGLE_CLIENT_ID,
                "secret" => defined("ZERO_IDENTITY_GOOGLE_CLIENT_SECRET") ? ZERO_IDENTITY_GOOGLE_CLIENT_SECRET : "",
            ];
        }
        return apply_filters("zero_identity_providers", $providers);
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
            home_url("/identity/provider/callback?provider=" . rawurlencode($name)),
            $config["discovery"] ?? null,
        );
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
        setcookie("zi_oidc", $id . "." . $binding, [
            "expires" => time() + 300,
            "path" => Plugin::path() . "/provider/callback",
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
            explode(".", (string) ($_COOKIE["zi_oidc"] ?? ""), 2),
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
