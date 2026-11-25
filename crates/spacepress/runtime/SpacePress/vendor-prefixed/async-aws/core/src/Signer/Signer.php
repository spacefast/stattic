<?php

namespace SpacePress\Vendor\AsyncAws\Core\Signer;

use SpacePress\Vendor\AsyncAws\Core\Credentials\Credentials;
use SpacePress\Vendor\AsyncAws\Core\Request;
use SpacePress\Vendor\AsyncAws\Core\RequestContext;

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
