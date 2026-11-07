<?php

namespace Zero\Vendor\AsyncAws\Core\Credentials;

use Zero\Vendor\AsyncAws\Core\Result;

/**
 * @internal
 */
trait DateFromResult
{
    private function getDateFromResult(Result $result): ?\DateTimeImmutable
    {
        $response = $result->info()['response'];
        if (null !== $date = $response->getHeaders(false)['date'][0] ?? null) {
            return new \DateTimeImmutable($date);
        }

        return null;
    }
}
