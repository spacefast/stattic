<?php

declare(strict_types=1);

namespace Zero;

/** A JavaScript `Error` (or subclass, named by $jsName) thrown by compiled code or a Js helper. */
class JsError extends \Exception
{
    public function __construct(string $message, public readonly string $jsName = 'Error')
    {
        parent::__construct($message);
    }

    /** Receipt snapshots persist JS-visible fields, never PHP frames containing callbacks. */
    public function __serialize(): array
    {
        return [$this->getMessage(), $this->jsName];
    }

    public function __unserialize(array $data): void
    {
        $this->__construct($data[0], $data[1]);
    }
}
