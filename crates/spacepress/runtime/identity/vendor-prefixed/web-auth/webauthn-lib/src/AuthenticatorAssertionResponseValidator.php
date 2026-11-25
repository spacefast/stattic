<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\Webauthn;

use SpacePress\Identity\Vendor\Psr\EventDispatcher\EventDispatcherInterface;
use SpacePress\Identity\Vendor\Psr\Log\LoggerInterface;
use SpacePress\Identity\Vendor\Psr\Log\NullLogger;
use Throwable;
use function spacepress_identity_vendor_trigger_deprecation as trigger_deprecation;
use SpacePress\Identity\Vendor\Webauthn\CeremonyStep\CeremonyStepManager;
use SpacePress\Identity\Vendor\Webauthn\Event\AuthenticatorAssertionResponseValidationFailedEvent;
use SpacePress\Identity\Vendor\Webauthn\Event\AuthenticatorAssertionResponseValidationSucceededEvent;
use SpacePress\Identity\Vendor\Webauthn\Event\BackupEligibilityChangedEvent;
use SpacePress\Identity\Vendor\Webauthn\Event\BackupStatusChangedEvent;
use SpacePress\Identity\Vendor\Webauthn\Event\CanDispatchEvents;
use SpacePress\Identity\Vendor\Webauthn\Event\NullEventDispatcher;
use SpacePress\Identity\Vendor\Webauthn\Exception\AuthenticatorResponseVerificationException;
use SpacePress\Identity\Vendor\Webauthn\MetadataService\CanLogData;

class AuthenticatorAssertionResponseValidator implements CanLogData, CanDispatchEvents
{
    private LoggerInterface $logger;

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        private readonly CeremonyStepManager $ceremonyStepManager
    ) {
        $this->eventDispatcher = new NullEventDispatcher();
        $this->logger = new NullLogger();
    }

    public static function create(CeremonyStepManager $ceremonyStepManager): self
    {
        return new self($ceremonyStepManager);
    }

    /**
     * @see https://www.w3.org/TR/webauthn/#verifying-assertion
     */
    public function check(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse $authenticatorAssertionResponse,
        PublicKeyCredentialRequestOptions $publicKeyCredentialRequestOptions,
        string $host,
        ?string $userHandle,
    ): CredentialRecord {
        if ($credentialRecord instanceof PublicKeyCredentialSource) {
            spacepress_identity_vendor_trigger_deprecation(
                'web-auth/webauthn-lib',
                '5.3',
                'Passing a PublicKeyCredentialSource to "%s::check()" is deprecated, pass a CredentialRecord instead.',
                self::class
            );
        }

        try {
            $this->logger->info('Checking the authenticator assertion response', [
                'credentialRecord' => $credentialRecord,
                'authenticatorAssertionResponse' => $authenticatorAssertionResponse,
                'publicKeyCredentialRequestOptions' => $publicKeyCredentialRequestOptions,
                'host' => $host,
                'userHandle' => $userHandle,
            ]);

            $this->ceremonyStepManager->process(
                $credentialRecord,
                $authenticatorAssertionResponse,
                $publicKeyCredentialRequestOptions,
                $userHandle,
                $host
            );

            // Store previous backup state values to detect changes
            $previousBackupEligible = $credentialRecord->backupEligible;
            $previousBackupStatus = $credentialRecord->backupStatus;

            $credentialRecord->counter = $authenticatorAssertionResponse->authenticatorData->signCount; // 26.1.
            $credentialRecord->backupEligible = $authenticatorAssertionResponse->authenticatorData->isBackupEligible(); // 26.2.
            $credentialRecord->backupStatus = $authenticatorAssertionResponse->authenticatorData->isBackedUp(); // 26.2.
            if ($credentialRecord->uvInitialized === false) {
                $credentialRecord->uvInitialized = $authenticatorAssertionResponse->authenticatorData->isUserVerified(); // 26.3.
            }

            // Dispatch events if backup state changed
            if ($previousBackupEligible !== $credentialRecord->backupEligible) {
                $this->eventDispatcher->dispatch(
                    new BackupEligibilityChangedEvent(
                        $credentialRecord,
                        $previousBackupEligible,
                        $credentialRecord->backupEligible
                    )
                );
            }
            if ($previousBackupStatus !== $credentialRecord->backupStatus) {
                $this->eventDispatcher->dispatch(
                    new BackupStatusChangedEvent(
                        $credentialRecord,
                        $previousBackupStatus,
                        $credentialRecord->backupStatus
                    )
                );
            }
            /*
             * 26.3.
             * OPTIONALLY, if response.attestationObject is present, update credentialRecord.attestationObject to the value of response.attestationObject and update credentialRecord.attestationClientDataJSON to the value of response.clientDataJSON.
             */

            // All good. We can continue.
            $this->logger->info('The assertion is valid');
            $this->logger->debug('Credential Record', [
                'credentialRecord' => $credentialRecord,
            ]);
            $this->eventDispatcher->dispatch(
                $this->createAuthenticatorAssertionResponseValidationSucceededEvent(
                    $authenticatorAssertionResponse,
                    $publicKeyCredentialRequestOptions,
                    $host,
                    $userHandle,
                    $credentialRecord
                )
            );
            // 27.
            return $credentialRecord;
        } catch (AuthenticatorResponseVerificationException $throwable) {
            $this->logger->error('An error occurred', [
                'exception' => $throwable,
            ]);
            $this->eventDispatcher->dispatch(
                $this->createAuthenticatorAssertionResponseValidationFailedEvent(
                    $credentialRecord,
                    $authenticatorAssertionResponse,
                    $publicKeyCredentialRequestOptions,
                    $host,
                    $userHandle,
                    $throwable
                )
            );
            throw $throwable;
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    protected function createAuthenticatorAssertionResponseValidationSucceededEvent(
        AuthenticatorAssertionResponse $authenticatorAssertionResponse,
        PublicKeyCredentialRequestOptions $publicKeyCredentialRequestOptions,
        string $host,
        ?string $userHandle,
        CredentialRecord $credentialRecord
    ): AuthenticatorAssertionResponseValidationSucceededEvent {
        return new AuthenticatorAssertionResponseValidationSucceededEvent(
            $authenticatorAssertionResponse,
            $publicKeyCredentialRequestOptions,
            $host,
            $userHandle,
            $credentialRecord
        );
    }

    protected function createAuthenticatorAssertionResponseValidationFailedEvent(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse $authenticatorAssertionResponse,
        PublicKeyCredentialRequestOptions $publicKeyCredentialRequestOptions,
        string $host,
        ?string $userHandle,
        Throwable $throwable
    ): AuthenticatorAssertionResponseValidationFailedEvent {
        return new AuthenticatorAssertionResponseValidationFailedEvent(
            $credentialRecord,
            $authenticatorAssertionResponse,
            $publicKeyCredentialRequestOptions,
            $host,
            $userHandle,
            $throwable
        );
    }
}
