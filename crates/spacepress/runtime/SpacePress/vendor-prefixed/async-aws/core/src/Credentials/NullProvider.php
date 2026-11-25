<?php

declare(strict_types=1);

namespace SpacePress\Vendor\AsyncAws\Core\Credentials;

use SpacePress\Vendor\AsyncAws\Core\Configuration;

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
