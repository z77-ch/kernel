<?php

/**
 * HTML-escape for safe output.
 * Returns the escaped string — does NOT echo. Use in templates: <?= e($value) ?>.
 *
 * No strip_tags — htmlspecialchars alone neutralizes all tags by converting
 * < to &lt;. Stripping tags first would silently destroy user input
 * (e.g. "Use <strong> tags" would lose the word "<strong>").
 */
function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    );
}

/**
 * Pass-through for trusted HTML — no escaping.
 * Explicit marker that the caller knows the value is safe.
 *
 * Use only for HTML that was generated server-side or already sanitized,
 * NEVER for user input. For untrusted user-HTML use purify() (not yet implemented).
 */
function raw(?string $value): string
{
    return $value ?? '';
}

/**
 * Translates a UI string key against the current language's dictionary
 * (`data/framework/i18n/{lang}.json`), falling back to the default language and
 * finally to the key itself. Use in templates: <?= e(t('footer.tagline')) ?>.
 *
 * Returns the raw translation — NOT escaped — so the caller controls escaping
 * (wrap in e() for text, leave raw only for trusted markup). Placeholders use the
 * same {$key} syntax as replacePlaceholders() and are escaped.
 *
 * Example:
 *   t('footer.pages')                       → 'Pages'   / 'Pages'
 *   t('greeting', ['name' => $user])        → 'Hallo Peter' / 'Bonjour Peter'
 */
function t(string $key, array $params = [], ?string $language = null): string
{
    return \Z77\Core\DI::getTranslator()->t($key, $params, $language);
}

/**
 * Localizes a canonical (default-language) internal URL for a target language
 * (ADR-014): each path segment is mapped canonical → localized via SlugTranslator,
 * and the language prefix is prepended for non-default languages. The default
 * language keeps canonical, prefix-less URLs.
 *
 *   localizedUrl('/privacy', 'fr')  → '/fr/confidentialite'
 *   localizedUrl('/privacy', 'de')  → '/privacy'        (default = canonical)
 *   localizedUrl('/', 'fr')         → '/fr'
 *
 * $language defaults to the current request language. External/anchor URLs
 * (not starting with '/') are returned unchanged.
 */
function localizedUrl(string $canonicalUrl, ?string $language = null): string
{
    if ($canonicalUrl === '' || $canonicalUrl[0] !== '/') {
        return $canonicalUrl;
    }

    $language ??= \Z77\Core\DI::getRequest()->getLanguage();
    $default    = \Z77\Core\DI::getI18n()->getDefaultLanguage();
    $slug       = \Z77\Core\DI::getSlugTranslator();

    $segments = array_values(array_filter(explode('/', $canonicalUrl), fn($s) => $s !== ''));
    $localized = array_map(fn($s) => $slug->toLocalized($s, $language), $segments);
    $path = $localized === [] ? '' : '/' . implode('/', $localized);

    if ($language === $default) {
        return $path === '' ? '/' : $path;
    }
    return '/' . $language . $path;
}

/**
 * Replaces {$key} placeholders in a template string with values from the array.
 * Values are HTML-escaped by default — set $escape=false for trusted HTML.
 *
 * Example:
 *   replacePlaceholders('Hi {$name}', ['name' => 'Peter'])
 *     → 'Hi Peter'
 *   replacePlaceholders('Mail: {$email}', ['email' => '<a href="mailto:p@z77.ch">p@z77.ch</a>'], false)
 *     → 'Mail: <a href="mailto:p@z77.ch">p@z77.ch</a>'
 */
function replacePlaceholders(string $template, array $values, bool $escape = true): string
{
    $replacements = [];
    foreach ($values as $key => $value) {
        $stringValue = (string) $value;
        $replacements['{$' . $key . '}'] = $escape ? e($stringValue) : $stringValue;
    }
    return strtr($template, $replacements);
}
