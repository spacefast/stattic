<?php

declare(strict_types=1);

namespace TypedPress\Vendor\Rowbot\URL\Component\Host;

use TypedPress\Vendor\Rowbot\URL\Component\Host\Serializer\HostSerializerInterface;
use TypedPress\Vendor\Rowbot\URL\Component\Host\Serializer\IPv6AddressSerializer;

/**
 * @see https://url.spec.whatwg.org/#concept-ipv6
 */
class IPv6Address extends AbstractHost implements HostInterface
{
    /**
     * @var non-empty-list<int>
     */
    private array $address;

    /**
     * @param non-empty-list<int> $address
     */
    public function __construct(array $address)
    {
        $this->address = $address;
    }

    public function equals(HostInterface $other): bool
    {
        return $other instanceof self && $this->address === $other->address;
    }

    public function getSerializer(): HostSerializerInterface
    {
        return new IPv6AddressSerializer($this->address);
    }
}
