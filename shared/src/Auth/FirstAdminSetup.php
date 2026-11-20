<?php

namespace Z77\Shared\Auth;

use Z77\Shared\Repositories\BackendUserRepository;

/**
 * The first-admin setup of a NON-INTERACTIVE install, in one place: where its one-time token
 * lives, where it is redeemed, and when it is still pending (security.md «install / setup model»).
 *
 *   - `Install` writes the token and names it in its closing lines,
 *   - `SetupController` redeems it (and locks once a user exists),
 *   - `LoginController` links to the setup while it is pending (login.md LOGIN-SETUP-HINT-001).
 *
 * Paths are taken relative to the project root the caller passes: the installer has no
 * ABS_BASE_PATH, a request does.
 */
final class FirstAdminSetup
{
    /** Project-relative path of the token file: filesystem only, never under public/. */
    public const TOKEN_FILE = 'data/framework/auth/SETUP_TOKEN';

    /** The setup screen. No friendly alias by design (a bootstrap route stays out of navigation.json). */
    public const URL = '/backend/system/setup/setup';

    public static function tokenPath(string $baseDir): string
    {
        return rtrim(str_replace('\\', '/', $baseDir), '/') . '/' . self::TOKEN_FILE;
    }

    /** Cheap: one is_file(). Says nothing about the token's content. */
    public static function hasToken(string $baseDir): bool
    {
        return is_file(self::tokenPath($baseDir));
    }

    /** The on-disk token, or null when the file is missing or empty (setup not available). */
    public static function readToken(string $baseDir): ?string
    {
        $path = self::tokenPath($baseDir);
        if (!is_file($path)) {
            return null;
        }
        $raw = trim((string) file_get_contents($path));
        return $raw === '' ? null : $raw;
    }

    /** Single use: burnt after the admin was created, and dropped once any user exists. */
    public static function discardToken(string $baseDir): void
    {
        $path = self::tokenPath($baseDir);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Once any backend user exists, the setup is locked for good. */
    public static function userExists(BackendUserRepository $users): bool
    {
        return $users->findAll() !== [];
    }

    /**
     * True while the first admin is still to be created: a token on disk and no backend user.
     * The token is checked first, so an installed system pays one is_file() and never loads
     * the user store for this.
     */
    public static function isPending(BackendUserRepository $users, string $baseDir): bool
    {
        return self::hasToken($baseDir) && !self::userExists($users);
    }
}
