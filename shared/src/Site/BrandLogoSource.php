<?php

namespace Z77\Shared\Site;

use Z77\Shared\Mail\InlineImage;

/**
 * Answers the installation's LOGO for the screens that show the brand mark with `'logo' => true`
 * (`partials/brandMark` of `Z77\Shared`; first: the backend login screens, owner 2026-11-20).
 * Registered by ONE module under the config key `brandLogo` (a class name), read by
 * {@see BrandLogo::current()}. First source: module-mandator's `Logo\LoginLogo` — the login
 * variant of the mandator's logo set-up (2026-11-20), else its old «Logo (E-Mail)» path.
 *
 * The answer is an {@see InlineImage} — the same judgement as the mails' logo: a readable PNG,
 * JPEG or GIF with its display size and the alt text; nothing is resized here.
 *
 * Contract: no-argument constructor (services through DI); null = nothing to show, the mark then
 * shows its wordmark. A throw is treated the same (logged): a logo never stops a page.
 */
interface BrandLogoSource
{
    public function logo(): ?InlineImage;
}
