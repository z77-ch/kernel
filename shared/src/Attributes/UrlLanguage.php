<?php

namespace Z77\Shared\Attributes;

/**
 * A page that belongs to a GUEST act renders in the URL's language, also when the browser
 * has a signed-in member (ADR-056, owner decision 2026-11-07, z77-rudaz.ch).
 *
 * Where a module renders signed-in members in their account's language
 * (`languageFromAccount`), the Dispatcher otherwise adopts that language and strips the
 * URL prefix. That is wrong for a door page: an invitation link mailed in English and
 * opened in a browser where someone else is signed in must stay English — the link's
 * language is the recipient's, not the signed-in account's. The same holds for the login
 * card, a confirmation link, a release or view link without login.
 *
 * On the controller class (every action) or on one action method. A subclass of a marked
 * controller keeps the mark (the Dispatcher looks at the parents too); a project that
 * COPIES a controller instead of extending it copies the attribute with it.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class UrlLanguage
{
}
