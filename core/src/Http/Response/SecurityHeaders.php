<?php

namespace Z77\Core\Http\Response;

use Z77\Shared\Libraries\ConfigLocator;

/**
 * The security headers of an HTML answer (security.md SEC-HEAD-001): sent by {@see HtmlResponse},
 * so a page fresh from a controller, a page-cache hit and a 304 all carry them, and by the error
 * pages. A file goes through {@see DeliveryPolicy} instead, with a stricter set of its own; JSON and
 * fetch answers are not documents a browser could frame or sniff into a page.
 *
 * What is enforced: nosniff, the referrer policy, no framing from another origin. What only observes:
 * the page CSP, as `Content-Security-Policy-Report-Only` without a report URL, so a violation is a
 * line in the browser's console and nothing is blocked. Inline scripts of the framework's own pages
 * would break under it (security.md lists them), and a project's templates are unknown.
 *
 * Strict-Transport-Security is deliberately NOT here: a browser remembers it for as long as it says,
 * up to a year, and an installation without working HTTPS on every host is then unreachable for
 * whoever visited once. A project that knows its hosts adds it in its file.
 *
 * A project records only what deviates, in `config/client/securityHeaders.inc.php` (optional, not
 * seeded): header name => value, null or '' switches a header off, a new name adds one. Anything else
 * is logged: a value of another type keeps the default, an entry without a name is ignored.
 */
final class SecurityHeaders
{
    public const DEFAULTS = [
        'X-Content-Type-Options'              => 'nosniff',
        'Referrer-Policy'                     => 'strict-origin-when-cross-origin',
        'X-Frame-Options'                     => 'SAMEORIGIN',
        'Content-Security-Policy'             => "frame-ancestors 'self'",
        'Content-Security-Policy-Report-Only' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'",
    ];

    /**
     * Sends the set. A header the action already set stays: the password reset's `no-referrer` must
     * not be weakened by the general policy, and PHP's header() would replace it.
     */
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }
        $already = [];
        foreach (headers_list() as $line) {
            $already[strtolower(trim(explode(':', $line, 2)[0]))] = true;
        }
        foreach (self::resolved(self::projectValues()) as $name => $value) {
            if (!isset($already[strtolower($name)])) {
                header($name . ': ' . $value);
            }
        }
    }

    /**
     * The defaults with a project's deviations applied. Pure.
     *
     * @param array<string, mixed> $project
     * @return array<string, string>
     */
    public static function resolved(array $project): array
    {
        $merged = self::DEFAULTS;
        foreach ($project as $name => $value) {
            if (!is_string($name)) {
                error_log("SecurityHeaders: entry {$name} is not a header name, ignored: the file holds name => value");
                continue;
            }
            if ($value !== null && !is_string($value)) {
                error_log("SecurityHeaders: the value of '{$name}' is neither text nor null, it keeps its default");
                continue;
            }
            // The name decides, not its spelling: `x-frame-options` must not send a second header.
            foreach (array_keys($merged) as $known) {
                if (strcasecmp($name, $known) === 0) {
                    unset($merged[$known]);
                }
            }
            $merged[$name] = trim((string) $value);
        }

        return array_filter($merged, static fn(string $value): bool => $value !== '');
    }

    /**
     * Read per answer and not through the cached config: the error pages also run before the
     * framework is up, and a changed file must count at once. Whatever is wrong with the file, the
     * page still goes out with the defaults.
     *
     * @return array<string, mixed>
     */
    private static function projectValues(): array
    {
        try {
            $file = ConfigLocator::path('securityHeaders.inc.php');
            if ($file === null) {
                return [];
            }
            $values = require $file;

            return is_array($values) ? $values : [];
        } catch (\Throwable $e) {
            error_log('SecurityHeaders: project values not read — ' . $e->getMessage());

            return [];
        }
    }
}
