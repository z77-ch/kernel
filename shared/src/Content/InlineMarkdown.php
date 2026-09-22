<?php

namespace Z77\Shared\Content;

/**
 * Minimal, safe inline formatter for block text.
 *
 * Whitelist only: **bold** → <strong>, *italic* → <em>, [label](url) → <a>, and —
 * with a profile that allows it — a newline → <br> (exactly `<br>`; the newline
 * itself is dropped).
 * Strategy: escape the whole string FIRST (so any HTML the author typed is inert),
 * THEN introduce our own whitelisted tags. The only HTML in the output is what this
 * class emits — there is no path for raw author HTML to reach the page.
 *
 * Two modes:
 *   - no profile (legacy): bold, italic and links on site-relative / http(s) /
 *     mailto targets — what every existing renderer relies on;
 *   - with an {@see InlineProfile} (schema-aware path, ADR-044): only the features
 *     and link targets the field allows; everything else stays literal text.
 *
 * Block-level tags (<h1>, <p>, <ul> …) are NOT this class's job; they come from the
 * block renderers. This handles only inline spans inside one text value.
 */
final class InlineMarkdown
{
    /** Schemes allowed in legacy link targets; everything else (javascript:, data: …) is rejected. */
    private const SAFE_SCHEMES = ['http://', 'https://', 'mailto:'];

    /** Attribute names an action may set (the values are escaped). */
    private const ATTRIBUTE_NAME = '/^[a-z][a-z0-9-]*$/';

    public function toHtml(string $text, ?InlineProfile $profile = null): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // links first (before * handling, so URLs with * are untouched)
        if ($profile === null || $profile->allows('link')) {
            $html = preg_replace_callback(
                '/\[([^\]]+)\]\(([^)]+)\)/',
                fn(array $m): string => $profile === null
                    ? $this->legacyLink($m)
                    : $this->profileLink($m, $profile),
                $html
            );
        }

        if ($profile === null || $profile->allows('bold')) {
            $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
        }
        if ($profile === null || $profile->allows('italic')) {
            // *italic* (single star, not part of a ** pair)
            $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html);
        }
        if ($profile !== null && $profile->allows('break')) {
            $html = preg_replace('/\r\n|\r|\n/', '<br>', $html);
        }

        return $html;
    }

    /** @param array<int, string> $m [full, label, url] — both already escaped */
    private function legacyLink(array $m): string
    {
        $url = $this->safeUrl($m[2]);
        if ($url === null) {
            return $m[0]; // not a safe URL → leave the (already escaped) literal
        }
        return '<a href="' . $url . '">' . $m[1] . '</a>';
    }

    /** @param array<int, string> $m [full, label, url] — both already escaped */
    private function profileLink(array $m, InlineProfile $profile): string
    {
        $url    = trim($m[2]);
        $target = $this->targetOf($url);

        if ($target === null || !$profile->allowsTarget($target)) {
            return $m[0];
        }

        if ($target === 'action') {
            $action = $profile->action(substr($url, strlen('action:')));
            if ($action === null) {
                return $m[0]; // unknown action → literal text, never a dead link
            }
            $href  = $this->attr($profile->localizePath((string)($action['href'] ?? '#')));
            $attrs = '';
            foreach ((array)($action['attributes'] ?? []) as $name => $value) {
                if (is_string($name) && preg_match(self::ATTRIBUTE_NAME, $name)) {
                    $attrs .= ' ' . $name . ($value === '' ? '' : '="' . $this->attr((string)$value) . '"');
                }
            }
            return '<a href="' . $href . '"' . $attrs . '>' . $m[1] . '</a>';
        }

        if ($target === 'page' && $url[0] === '/') {
            // $url is escaped; localise the real path, then escape it again.
            $url = $this->attr($profile->localizePath(html_entity_decode($url, ENT_QUOTES, 'UTF-8')));
        }

        $tab = ($target === 'external' && $profile->newTab()) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $url . '"' . $tab . '>' . $m[1] . '</a>';
    }

    /** Classifies a link target; null = not allowed in any profile. */
    private function targetOf(string $url): ?string
    {
        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }
        $lower = strtolower($url);
        return match (true) {
            str_starts_with($lower, 'action:')   => 'action',
            str_starts_with($lower, '/media/')   => 'media',
            $url[0] === '/' || $url[0] === '#'   => 'page',
            str_starts_with($lower, 'mailto:')   => 'mailto',
            str_starts_with($lower, 'tel:')      => 'tel',
            str_starts_with($lower, 'http://'),
            str_starts_with($lower, 'https://')  => 'external',
            default                              => null,
        };
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate a legacy link target. Input is already HTML-escaped (came from inside
     * the escaped text), so attribute-injection is impossible; we only gate the scheme.
     * Allowed: site-relative ('/…', '#…', but not protocol-relative '//…') and the
     * safe schemes.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
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
