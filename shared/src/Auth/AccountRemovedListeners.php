<?php

namespace Z77\Shared\Auth;

use Z77\Core\DI;

/**
 * Tells every module that an account is gone: the registry pattern of `authBridges` and
 * `mailAttachments`. A module DECLARES under its config key `accountRemovedListeners` a list of
 * {@see AccountRemovedListener} classes, a project adds its own in
 * `App/Config/accountRemovedListenersConfig.inc.php`; a module that is not installed declares
 * nothing, so neither the backend nor module-member depends on who listens.
 *
 * ```php
 * // module-dms: dmsConfig.inc.php
 * 'accountRemovedListeners' => [\Z77\Module\Dms\Services\ZipBundleAccountListener::class],
 * ```
 *
 * **It never throws, which is where it differs from those registries.** The account is gone when
 * this runs, and the person's removal must not look failed because a clean-up did: a listener that
 * throws, and a declaration that does not hold, are logged and skipped, and the others still run.
 */
final class AccountRemovedListeners
{
    public const CONFIG_KEY = 'accountRemovedListeners';

    /** @param AuthUser $account the removed identity: its realm and id, nobody's session */
    public static function announce(AuthUser $account): void
    {
        foreach (self::declared() as $class) {
            try {
                $listener = new $class();
                if (!$listener instanceof AccountRemovedListener) {
                    throw new \LogicException('it does not implement ' . AccountRemovedListener::class);
                }
                $listener->accountRemoved($account);
            } catch (\Throwable $e) {
                error_log('AccountRemovedListeners: ' . $class . ' failed — ' . $e->getMessage());
            }
        }
    }

    /** @return list<string> in module order, each class once */
    private static function declared(): array
    {
        $classes = [];
        try {
            $modules = DI::getModuleManager();
            foreach ($modules->getModuleKeys() as $moduleKey) {
                $lists = $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
                $lists[] = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []) ?? [];
                foreach ($lists as $list) {
                    foreach ((array) $list as $class) {
                        $classes[(string) $class] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('AccountRemovedListeners: the declarations could not be read — ' . $e->getMessage());
        }

        return array_keys($classes);
    }
}
