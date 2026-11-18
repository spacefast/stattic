<?php

namespace TypedPress\Vendor\AsyncAws\Core\Signer;

use TypedPress\Vendor\AsyncAws\Core\Credentials\Credentials;
use TypedPress\Vendor\AsyncAws\Core\Request;
use TypedPress\Vendor\AsyncAws\Core\RequestContext;

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
