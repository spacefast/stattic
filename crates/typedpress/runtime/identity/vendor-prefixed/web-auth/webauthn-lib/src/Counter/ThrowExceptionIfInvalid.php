<?php

declare(strict_types=1);

namespace TypedPress\Identity\Vendor\Webauthn\Counter;

use TypedPress\Identity\Vendor\Psr\Log\LoggerInterface;
use TypedPress\Identity\Vendor\Psr\Log\NullLogger;
use function typedpress_identity_vendor_trigger_deprecation as trigger_deprecation;
use TypedPress\Identity\Vendor\Webauthn\CredentialRecord;
use TypedPress\Identity\Vendor\Webauthn\Exception\CounterException;
use TypedPress\Identity\Vendor\Webauthn\MetadataService\CanLogData;
use TypedPress\Identity\Vendor\Webauthn\PublicKeyCredentialSource;

final class ThrowExceptionIfInvalid implements CounterChecker, CanLogData
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        if ($credentialRecord instanceof PublicKeyCredentialSource) {
            typedpress_identity_vendor_trigger_deprecation(
                'web-auth/webauthn-lib',
                '5.3',
                'Passing a PublicKeyCredentialSource to "%s::check()" is deprecated, pass a CredentialRecord instead.',
                self::class
            );
        }

        try {
            $currentCounter > $credentialRecord->counter || throw CounterException::create(
                $currentCounter,
                $credentialRecord->counter,
                'Invalid counter.'
            );
        } catch (CounterException $throwable) {
            $this->logger->error('The counter is invalid', [
                'current' => $currentCounter,
                'new' => $credentialRecord->counter,
            ]);
            throw $throwable;
        }
    }
}
