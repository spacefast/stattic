<?php

namespace Zero\Vendor\AsyncAws\Core\Signer;

use Zero\Vendor\AsyncAws\Core\Credentials\Credentials;
use Zero\Vendor\AsyncAws\Core\Request;
use Zero\Vendor\AsyncAws\Core\RequestContext;

/**
 * Interface for signing a request.
 *
 * @author Jérémy Derussé <jeremy@derusse.com>
 */
interface Signer
{
    public function sign(Request $request, Credentials $credentials, RequestContext $context): void;

    public function presign(Request $request, Credentials $credentials, RequestContext $context): void;
}
