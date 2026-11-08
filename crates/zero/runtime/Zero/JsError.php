<?php

declare(strict_types=1);

namespace Zero;

/** A JavaScript `Error` (or subclass, named by $jsName) thrown by compiled code or a Js helper. */
class JsError extends \Exception
{
    /** `AggregateError.errors`: the rejection reasons, in iterable order. */
    public ?JsArray $errors = null;

    public function __construct(string $message, public readonly string $jsName = 'Error', public bool $hasMessage = true)
    {
        parent::__construct($message);
    }

    /** `delete error.message`: reads fall back to Error.prototype.message, the empty string. */
    public function deleteMessage(): void
    {
        $this->message = '';
        $this->hasMessage = false;
    }

    /** Receipt snapshots persist JS-visible fields, never PHP frames containing callbacks. */
    public function __serialize(): array
    {
        return [$this->getMessage(), $this->jsName, $this->hasMessage];
    }

    public function __unserialize(array $data): void
    {
        $this->__construct($data[0], $data[1], $data[2] ?? true);
    }
}
