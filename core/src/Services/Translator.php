<?php

namespace Z77\Core\Services;

use Z77\Core\DI;

/**
 * UI string translator (ADR-013 / i18n.md). Resolves a translation key against a
 * per-language dictionary (`data/framework/i18n/{lang}.json`):
 *   current language → defaultLanguage → the key itself (visible miss marker).
 *
 * This handles developer-owned static UI strings (template chrome, labels). Data
 * labels (navigation names) resolve through the same `t()` keys; content documents
 * are per-language files (see content.md), NOT this dictionary.
 *
 * NOT the language authority — that is {@see I18n}. This service only reads strings.
 */
class Translator
{
    /** @var array<string, array<string, string>> language → (key → value), lazy-loaded */
    private array $loaded = [];

    /**
     * Keys that are also rendered server-side but additionally needed by
     * client-created markup (shared UI vocabulary), so they must travel to JS
     * even though they are outside the `js.*` namespace. See clientDictionary().
     */
    private const SHARED_CLIENT_KEYS = ['common.close'];

    /**
     * @param array<string, string|int|float> $params {$key} placeholders, escaped by `replacePlaceholders()`
     */
    public function t(string $key, array $params = [], ?string $language = null): string
    {
        $language ??= DI::getRequest()->getLanguage();
        $default    = DI::getI18n()->getDefaultLanguage();

        $value = $this->lookup($language, $key)
            ?? ($language !== $default ? $this->lookup($default, $key) : null)
            ?? $key;

        return $params === [] ? $value : replacePlaceholders($value, $params);
    }

    /**
     * The JS-facing subset of the dictionary for the shared `core.js`: every
     * `js.*` key (JS-only strings) plus SHARED_CLIENT_KEYS, resolved for
     * $language with the default-language fallback. The set of keys here is
     * exactly what the client needs — it is inlined into the page head as a
     * JSON data island (see {@see HtmlView}) and read once at boot.
     *
     * @return array<string, string>
     */
    public function clientDictionary(?string $language = null): array
    {
        $language ??= DI::getRequest()->getLanguage();
        $default    = DI::getI18n()->getDefaultLanguage();

        $merged = $this->dictionary($default);
        if ($language !== $default) {
            $merged = array_merge($merged, $this->dictionary($language));
        }

        return array_filter(
            $merged,
            fn(string $key) => str_starts_with($key, 'js.')
                || in_array($key, self::SHARED_CLIENT_KEYS, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function lookup(string $language, string $key): ?string
    {
        $value = $this->dictionary($language)[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> lazy-loaded, cached per language */
    private function dictionary(string $language): array
    {
        return $this->loaded[$language] ??= $this->load($language);
    }

    /** @return array<string, string> */
    private function load(string $language): array
    {
        $path = ABS_BASE_PATH . '/data/framework/i18n/' . $language . '.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
