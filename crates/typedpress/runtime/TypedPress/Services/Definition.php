<?php

declare(strict_types=1);

namespace TypedPress\Services;

/** Native job definitions: the jobs runner and Action Scheduler own delivery. */
final class Definition
{
    public static function jobs(): array
    {
        return [
            'typedpress.services.mail' => [
                'input' => ['submissionId' => ['type' => 'text', 'notNull' => true]],
                'handler' => [Mail::class, 'deliver'], 'retries' => 5, 'agents' => false,
            ],
            'typedpress.services.checkout' => [
                'input' => ['orderId' => ['type' => 'text', 'notNull' => true]],
                'handler' => [Payments::class, 'createSession'], 'retries' => 8, 'agents' => false,
            ],
        ];
    }
}
