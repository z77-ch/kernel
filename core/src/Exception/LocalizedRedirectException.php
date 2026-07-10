<?php

namespace Z77\Core\Exception;

/**
 * Signals that the current request reached a page through a non-preferred URL
 * form and must be permanently redirected to its localized, canonical-for-SEO
 * form (ADR-014, 301). Thrown by Request during parsing — after a successful
 * route match, so the target is guaranteed to resolve — and converted into a
 * RedirectResponse by the bootstrap routing-control catch.
 */
class LocalizedRedirectException extends \Exception
{
    public function __construct(private string $targetUrl)
    {
        parent::__construct('Localized redirect to: ' . $targetUrl);
    }

    public function getTargetUrl(): string
    {
        return $this->targetUrl;
    }
}
