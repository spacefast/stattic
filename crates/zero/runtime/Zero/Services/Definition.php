<?php

declare(strict_types=1);

namespace Zero\Services;

/** Native job definitions: the existing outbox, runner and Action Scheduler own delivery. */
final class Definition
{
    public static function jobs(): array
    {
        return [
            'zero.services.mail' => [
                'input' => ['submissionId' => ['type' => 'text', 'notNull' => true]],
                'handler' => [Mail::class, 'deliver'], 'retries' => 5, 'agents' => false,
            ],
            'zero.services.checkout' => [
                'input' => ['orderId' => ['type' => 'text', 'notNull' => true]],
                'handler' => [Payments::class, 'createSession'], 'retries' => 8, 'agents' => false,
            ],
        ];
    }
}
