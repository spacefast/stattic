<?php

declare(strict_types=1);

namespace Zero\Identity\Vendor\CBOR;

interface Normalizable
{
    /**
     * @return mixed|null
     */
    public function normalize();
}
