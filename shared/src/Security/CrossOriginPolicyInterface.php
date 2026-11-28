<?php

namespace Z77\Shared\Security;

use Z77\Core\Http\Request;

/**
 * The project's answer to «may this origin post to this door?» — step 6 of the
 * {@see \Z77\Core\Services\CrossOriginPostGuard}. Declared per action in the module config
 * (`crossOriginPosts`), instantiated by the guard from its FQCN.
 *
 * The guard has already checked that `$origin` is present and well-formed
 * (`scheme://host[:port]`, lowercase). The policy decides on the project's own data — the
 * widget entry the body names, its domain list. It MUST NOT trust anything else of the request
 * than what it compares, and it MUST answer false when in doubt: false is a 403.
 */
interface CrossOriginPolicyInterface
{
    public function allows(string $origin, Request $request): bool;
}
