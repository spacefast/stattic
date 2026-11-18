<?php

declare(strict_types=1);

namespace TypedPress\Vendor\AsyncAws\Core\Credentials;

use TypedPress\Vendor\AsyncAws\Core\Configuration;

/**
 * Returns null.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
final class NullProvider implements CredentialProvider
{
    public function getCredentials(Configuration $configuration): ?Credentials
    {
        return null;
    }
}
