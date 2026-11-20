<?php

namespace Z77\Shared\Site;

/**
 * Answers the PUBLIC website's logo for the brand mark of the frontend (`partials/brandMark` with
 * `'siteLogo' => true` — module-frontend's default header and footer, 2026-11-20). Registered by
 * ONE module under the config key `siteLogo` (a class name), read by {@see SiteLogo::current()}.
 * First source: module-mandator's `Logo\WebLogo` — the website variant of the logo set-up window,
 * a document in the DMS's public area.
 *
 * Unlike {@see BrandLogoSource} the answer is an ADDRESS, not a file: the website logo is public
 * content the browser should cache, so the page links it (`/media/…?v=…`) instead of inlining it.
 *
 * Contract: no-argument constructor (services through DI); null = nothing to show, the mark then
 * shows its wordmark. A throw is treated the same (logged): a logo never stops a page.
 */
interface SiteLogoSource
{
    public function siteLogo(): ?SiteLogo;
}
