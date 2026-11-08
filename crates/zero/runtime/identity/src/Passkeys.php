<?php

declare(strict_types=1);

namespace Zero\Identity;

use Zero\Identity\Vendor\Webauthn\{
    PublicKeyCredentialCreationOptions,
    PublicKeyCredentialRequestOptions,
    PublicKeyCredentialRpEntity,
    PublicKeyCredentialUserEntity,
    PublicKeyCredentialParameters,
    AuthenticatorSelectionCriteria,
    PublicKeyCredential,
    CredentialRecord,
    AuthenticatorAttestationResponse,
    AuthenticatorAssertionResponse,
    AuthenticatorAttestationResponseValidator,
    AuthenticatorAssertionResponseValidator,
};

final class Passkeys
{
    private \Zero\Identity\Vendor\Symfony\Component\Serializer\SerializerInterface $serializer;
    private \Zero\Identity\Vendor\Webauthn\CeremonyStep\CeremonyStepManagerFactory $factory;
    private string $host;
    public function __construct(private DB $db, private Security $security)
    {
        $manager = new \Zero\Identity\Vendor\Webauthn\AttestationStatement\AttestationStatementSupportManager(
            [
                new \Zero\Identity\Vendor\Webauthn\AttestationStatement\NoneAttestationStatementSupport(),
            ],
        );
        $this->serializer = (new \Zero\Identity\Vendor\Webauthn\Denormalizer\WebauthnSerializerFactory(
            $manager,
        ))->create();
        $this->factory = new \Zero\Identity\Vendor\Webauthn\CeremonyStep\CeremonyStepManagerFactory();
        $this->factory->setAllowedOrigins([untrailingslashit(home_url())]);
        $this->host = wp_parse_url(home_url(), PHP_URL_HOST);
    }
    public function options(?array $session): array
    {
        if ($session) {
            $uid = (int) $session["wp_user_id"];
            $user = get_userdata($uid);
            $exclude = [];
            foreach (
                $this->db->rows(
                    "SELECT record FROM {$this->db->prefix}passkeys WHERE wp_user_id=%d",
                    $uid,
                )
                as $row
            ) {
                $record = $this->serializer->deserialize(
                    $row["record"],
                    CredentialRecord::class,
                    "json",
                );
                $exclude[] = $record->getPublicKeyCredentialDescriptor();
            }
            $options = PublicKeyCredentialCreationOptions::create(
                PublicKeyCredentialRpEntity::create(
                    get_bloginfo("name") ?: "Identity",
                    $this->host,
                ),
                PublicKeyCredentialUserEntity::create(
                    $user->user_login,
                    (string) $uid,
                    $user->display_name,
                ),
                random_bytes(32),
                [
                    PublicKeyCredentialParameters::createPk(-7),
                    PublicKeyCredentialParameters::createPk(-257),
                ],
                AuthenticatorSelectionCriteria::create(
                    null,
                    "required",
                    "required",
                ),
                "none",
                $exclude,
                60000,
            );
        } else {
            $options = PublicKeyCredentialRequestOptions::create(
                random_bytes(32),
                $this->host,
                [],
                "required",
                60000,
            );
        }
        $json = $this->serializer->serialize($options, "json");
        $binding = Security::token();
        $id = $this->security->challenge(
            $session ? "passkey_register" : "passkey_login",
            (int) ($session["wp_user_id"] ?? 0),
            (string) ($session["id"] ?? ""),
            ["options" => $json],
            $binding,
        );
        setcookie(Sessions::cookieName("zi_passkey"), $binding, [
            "expires" => time() + 300,
            "path" => "/",
            "secure" => is_ssl(),
            "httponly" => true,
            "samesite" => "Strict",
        ]);
        return ["challenge_id" => $id, "options" => json_decode($json, true)];
    }
    public function finish(string $id, array $credential, ?array $session): int
    {
        $c = $this->security->consume(
            $id,
            $session ? "passkey_register" : "passkey_login",
            (string) ($_COOKIE[Sessions::cookieName("zi_passkey")] ?? ""),
            $session ? $session["id"] : null,
        );
        try {
            $public = $this->serializer->deserialize(
                wp_json_encode($credential),
                PublicKeyCredential::class,
                "json",
            );
        } catch (\Throwable $e) {
            throw new Failure(
                "invalid_passkey",
                "Passkey response is invalid.",
                401,
            );
        }
        if (!($public instanceof PublicKeyCredential)) {
            throw new Failure("invalid_passkey", "Invalid passkey.", 401);
        }
        if ($session) {
            if (
                !($public->response instanceof AuthenticatorAttestationResponse)
            ) {
                throw new Failure(
                    "invalid_passkey",
                    "Registration response required.",
                    401,
                );
            }
            $options = $this->serializer->deserialize(
                $c["payload"]["options"],
                PublicKeyCredentialCreationOptions::class,
                "json",
            );
            try {
                $record = AuthenticatorAttestationResponseValidator::create(
                    $this->factory->creationCeremony(),
                )->check($public->response, $options, $this->host);
            } catch (\Throwable $e) {
                throw new Failure(
                    "invalid_passkey",
                    "Passkey registration could not be verified.",
                    401,
                );
            }
            $uid = (int) $session["wp_user_id"];
            $this->db->transaction(function () use ($uid, $record, $session) {
                $this->db->lock($uid);
                $s = $this->db->row(
                    "SELECT * FROM {$this->db->prefix}sessions WHERE id=%s FOR UPDATE",
                    $session["id"],
                );
                if (
                    !$s ||
                    $s["revoked_at"] !== null ||
                    (int) $s["expires_at"] <= time() ||
                    (int) $s["verified_at"] < time() - 300
                ) {
                    throw new Failure(
                        "reverification_required",
                        "Confirm your identity again.",
                        403,
                    );
                }
                $this->db->insert("passkeys", [
                    "id" => hash("sha256", $record->publicKeyCredentialId),
                    "wp_user_id" => $uid,
                    "record" => $this->serializer->serialize($record, "json"),
                    "created_at" => time(),
                    "name" => "Passkey",
                ]);
                $this->security->event($uid, "passkey.registered");
            });
            return $uid;
        }
        if (!($public->response instanceof AuthenticatorAssertionResponse)) {
            throw new Failure(
                "invalid_passkey",
                "Assertion response required.",
                401,
            );
        }
        $key = hash("sha256", $public->rawId);
        $row = $this->db->row(
            "SELECT wp_user_id FROM {$this->db->prefix}passkeys WHERE id=%s",
            $key,
        );
        if (!$row) {
            throw new Failure(
                "invalid_passkey",
                "Passkey is not registered.",
                401,
            );
        }
        $uid = (int) $row["wp_user_id"];
        return $this->db->transaction(function () use (
            $uid,
            $key,
            $public,
            $c,
        ) {
            $this->db->lock($uid);
            $row = $this->db->row(
                "SELECT * FROM {$this->db->prefix}passkeys WHERE id=%s FOR UPDATE",
                $key,
            );
            if (!$row) {
                throw new Failure(
                    "invalid_passkey",
                    "Passkey was removed.",
                    401,
                );
            }
            $record = $this->serializer->deserialize(
                $row["record"],
                CredentialRecord::class,
                "json",
            );
            $options = $this->serializer->deserialize(
                $c["payload"]["options"],
                PublicKeyCredentialRequestOptions::class,
                "json",
            );
            try {
                $record = AuthenticatorAssertionResponseValidator::create(
                    $this->factory->requestCeremony(),
                )->check(
                    $record,
                    $public->response,
                    $options,
                    $this->host,
                    (string) $uid,
                );
            } catch (\Throwable $e) {
                throw new Failure(
                    "invalid_passkey",
                    "Passkey assertion could not be verified.",
                    401,
                );
            }
            $this->db->run(
                "UPDATE {$this->db->prefix}passkeys SET record=%s WHERE id=%s",
                $this->serializer->serialize($record, "json"),
                $key,
            );
            return $uid;
        });
    }
}
