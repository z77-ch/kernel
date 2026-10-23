<?php

namespace Z77\Shared\Files;

use Z77\Core\DI;

/**
 * A stored path → a readable file on this server (2026-10-23, the mandator's logos).
 *
 * A setting such as «Logo» holds a path a user typed or copied («Pfad kopieren» in the Drive):
 * either a file of the project (`assets/logo.png`, relative to the project root) or a path another
 * module owns — the Drive's `/media/…` path names no file on disk (the bytes live in the blob store,
 * the static copy under `public/media` is a lazily filled cache). {@see path()} answers the file
 * for both: a project file first, then every registered {@see LocalFileResolver} in module order.
 * The kernel knows no module — module-dms registers its resolver under `localFileResolvers`.
 *
 * Registration: config key `localFileResolvers` (a list of {@see LocalFileResolver} classes) or an
 * extension file `App/Config/localFileResolversConfig.inc.php` — the registry pattern of
 * `mailAttachments` / `mailInlineImages`.
 */
final class LocalFiles
{
    public const CONFIG_KEY = 'localFileResolvers';

    /** The readable file a stored path names, or null (empty, a directory, nothing resolves it). */
    public static function path(string $stored): ?string
    {
        $stored = trim($stored);
        if ($stored === '' || str_contains($stored, '..')) {
            return null;
        }
        if (defined('ABS_BASE_PATH')) {
            $file = ABS_BASE_PATH . '/' . ltrim($stored, '/');
            if (is_file($file) && is_readable($file)) {
                return $file;
            }
        }
        foreach (self::resolvers() as $resolver) {
            $file = $resolver->resolve($stored);
            if ($file !== null && is_file($file) && is_readable($file)) {
                return $file;
            }
        }

        return null;
    }

    /** @return list<LocalFileResolver> */
    private static function resolvers(): array
    {
        $modules   = DI::getModuleManager();
        $resolvers = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $lists = [$modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []) ?? []];
            foreach ($modules->getConfigExtensions($moduleKey, self::CONFIG_KEY) as $extension) {
                $lists[] = $extension;
            }
            foreach ($lists as $classes) {
                foreach ((array) $classes as $class) {
                    if (is_string($class) && is_subclass_of($class, LocalFileResolver::class)) {
                        $resolvers[$class] ??= new $class();
                    }
                }
            }
        }

        return array_values($resolvers);
    }
}
