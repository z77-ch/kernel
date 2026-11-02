<?php

namespace Z77\Shared\Auth;

/**
 * Something a module keeps per account, and clears when the account is gone. Declared in the
 * module's config under the key `accountRemovedListeners` ({@see AccountRemovedListeners}), so the
 * module that removes an account needs to know nothing about who else holds data for it.
 *
 * Called after the account is really removed, outside any session of the person: `$account` is the
 * removed identity (realm and id only), never the session of whoever removed it. Must have a
 * constructor without required arguments. A failure is logged and stops nothing.
 */
interface AccountRemovedListener
{
    public function accountRemoved(AuthUser $account): void;
}
