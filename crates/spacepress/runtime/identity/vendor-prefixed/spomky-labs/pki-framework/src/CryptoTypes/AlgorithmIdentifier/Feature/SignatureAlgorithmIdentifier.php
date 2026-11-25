<?php

declare(strict_types=1);

namespace SpacePress\Identity\Vendor\SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Feature;

use SpacePress\Identity\Vendor\SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\AlgorithmIdentifier;

/**
 * Algorithm identifier for signature algorithms.
 */
interface SignatureAlgorithmIdentifier extends AlgorithmIdentifierType
{
    /**
     * Check whether signature algorithm supports given key algorithm.
     */
    public function supportsKeyAlgorithm(AlgorithmIdentifier $algo): bool;
}
