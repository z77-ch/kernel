<?php

namespace Z77\Shared\Content;

/**
 * Minimal, safe inline formatter for block text.
 *
 * Whitelist only: **bold** → <strong>, *italic* → <em>, [label](url) → <a>.
 * Strategy: escape the whole string FIRST (so any HTML the author typed is inert),
 * THEN introduce our own whitelisted tags. The only HTML in the output is what this
 * class emits — there is no path for raw author HTML to reach the page.
 *
 * Block-level tags (<h1>, <p>, <ul> …) are NOT this class's job; they come from the
 * block renderers. This handles only inline spans inside one text value.
 */
final class InlineMarkdown
{
    /** Schemes allowed in link targets; everything else (javascript:, data: …) is rejected. */
    private const SAFE_SCHEMES = ['http://', 'https://', 'mailto:'];

    public function toHtml(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // links first (before * handling, so URLs with * are untouched)
        $html = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function (array $m): string {
                $url = $this->safeUrl($m[2]);
                if ($url === null) {
                    return $m[0]; // not a safe URL → leave the (already escaped) literal
                }
                return '<a href="'.$url.'">'.$m[1].'</a>';
            },
            $html
        );

        // **bold**
        $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
        // *italic* (single star, not part of a ** pair)
        $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html);

        return $html;
    }

    /**
     * Validate a link target. Input is already HTML-escaped (came from inside the
     * escaped text), so attribute-injection is impossible; we only gate the scheme.
     * Allowed: site-relative ('/…', '#…') and the safe schemes.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if ($url[0] === '/' || $url[0] === '#') {
            return $url;
        }
        $lower = strtolower($url);
        foreach (self::SAFE_SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return $url;
            }
        }

        return null;
    }
}
