<?php

namespace Z77\Shared\Content;

use Z77\Core\Config\AuthRole;
use Z77\Core\DI;
use Z77\Core\Http\RequestMode;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\ValueObjects\UserPreferences;

/**
 * Is the page editor on for this request? (ADR-045 §4, switch addendum)
 *
 * The ONE decision both halves of the page editor read:
 *   - PageContent::view() — marks the slots (ContentView::forEditor());
 *   - AbstractFrontendController::html() — adds content-edit.js.
 * If they asked different questions, a page could carry markers without the
 * script or the script without markers.
 *
 * On = a full page (not a fetch fragment) + a user with at least `editor` +
 * the user's switch «Seite bearbeiten» on for this view area (UserPreferences
 * `content_edit`, toggled in the admin overlay). Off is the default for
 * everyone: the page is then exactly what a visitor gets, except the overlay.
 * Read per request from the session user; the preference is stored on the
 * user, nothing else is remembered.
 */
final class PageEditing
{
    private function __construct() {}

    /** May the current user switch the page editor on here? (full page + editor role) */
    public static function available(): bool
    {
        return self::availableFor(
            DI::getRequest()->getMode() === RequestMode::Page,
            DI::getAuthService()->getCurrentUser()
        );
    }

    /** Markers and pencils for this request? */
    public static function active(): bool
    {
        if (!self::available()) {
            return false;
        }
        return self::activeFor(
            true,
            DI::getAuthService()->getCurrentUser(),
            DI::getCurrentUserService()->getPreferences(),
            DI::getRequest()->getModule()
        );
    }

    /** The rule of {@see available()} without DI (tests/content-page-editor.php). */
    public static function availableFor(bool $isPage, ?AuthUser $user): bool
    {
        return $isPage && $user !== null && $user->hasAtLeast(AuthRole::EDITOR);
    }

    /** The rule of {@see active()} without DI (tests/content-page-editor.php). */
    public static function activeFor(bool $isPage, ?AuthUser $user, UserPreferences $prefs, string $viewArea): bool
    {
        return self::availableFor($isPage, $user) && $prefs->isContentEditEnabled($viewArea);
    }
}
