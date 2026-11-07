<?php

namespace Z77\Core\Services;

use Z77\Core\DI;

/**
 * UI string translator (ADR-013 / i18n.md). Resolves a translation key against a
 * per-language dictionary:
 *   current language → defaultLanguage → the key itself (visible miss marker).
 *
 * A language's dictionary is merged from three layers, later wins
 * (I18N-LAYERS-001, 2026-11-07):
 *   1. PACKAGE  `<package>/res/i18n/{lang}.json` — the strings a module ships with its
 *               code (`member.*`, `dms.*`). Read live from the package, never seeded, so a
 *               `composer update` brings new keys to an installed project at once.
 *   2. PROJECT  `override/z77/<namespace>/res/i18n/{lang}.json` — the project's own keys
 *               (`rudaz.*`) and its rewording of a package key, versioned in its repo.
 *   3. RUNTIME  `data/framework/i18n/{lang}.json` — installer-seeded, edited by the
 *               backend translation tool. The installation's last word.
 * Layers 1 and 2 are found through the FileFinder's namespace paths: every namespace
 * root (override first, package last) may carry a `res/i18n/` directory.
 *
 * The CURRENT language is the request's, unless a caller renders for someone else:
 * {@see inLanguage()} sets it for the duration of a closure (a mail to a member in
 * his language, a job without a request). `t()`, `clientDictionary()` and
 * `localizedUrl()` all ask {@see currentLanguage()}.
 *
 * This handles developer-owned static UI strings (template chrome, labels). Data
 * labels (navigation names) resolve through the same `t()` keys; content documents
 * are per-language files (see content.md), NOT this dictionary.
 *
 * NOT the language authority — that is {@see I18n}. This service only reads strings.
 */
class Translator
{
    /** Directory below a namespace root that holds its dictionaries. */
    public const DICTIONARY_DIR = 'res/i18n';

    /** @var array<string, array<string, string>> language → (key → value), lazy-loaded */
    private array $loaded = [];

    /** @var list<string> languages set by inLanguage(), innermost last */
    private array $scope = [];

    /**
     * Keys that are also rendered server-side but additionally needed by
     * client-created markup (shared UI vocabulary), so they must travel to JS
     * even though they are outside the `js.*` namespace. See clientDictionary().
     */
    private const SHARED_CLIENT_KEYS = ['common.close'];

    /**
     * @param array<string, string|int|float> $params `{$key}` placeholders, inserted AS THEY ARE:
     *        the result is a plain string and the caller escapes it whole (`e(t(...))`). A
     *        placeholder escaped here and the caller's `e()` on top would show `&amp;`.
     */
    public function t(string $key, array $params = [], ?string $language = null): string
    {
        $language ??= $this->currentLanguage();
        $default    = DI::getI18n()->getDefaultLanguage();

        $value = $this->lookup($language, $key)
            ?? ($language !== $default ? $this->lookup($default, $key) : null)
            ?? $key;

        return $params === [] ? $value : replacePlaceholders($value, $params, false);
    }

    /**
     * Runs $render with $language as the current language — every `t()` without an explicit
     * language inside it, in templates and partials too, answers in that language. Unknown or
     * empty codes render the default ({@see I18n::resolve()}). Nested calls stack.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public function inLanguage(?string $language, callable $render): mixed
    {
        $this->scope[] = DI::getI18n()->resolve($language);
        try {
            return $render();
        } finally {
            array_pop($this->scope);
        }
    }

    /**
     * The language strings are rendered in right now: an {@see inLanguage()} scope, else the
     * request's (URL prefix, or the account's where the module opts in — ADR-056), else — no
     * request, e.g. a job — the default language.
     */
    public function currentLanguage(): string
    {
        if ($this->scope !== []) {
            return $this->scope[array_key_last($this->scope)];
        }
        try {
            return DI::getRequest()->getLanguage();
        } catch (\Throwable) {
            return DI::getI18n()->getDefaultLanguage();
        }
    }

    /**
     * The language a URL built without an explicit language gets (`localizedUrl()`): an
     * {@see inLanguage()} scope if one is open (a mail's links), else — where the account
     * decides the language (ADR-056) — the default language, because after sign-in no URL
     * carries a prefix; else the request's.
     */
    public function urlLanguage(): string
    {
        if ($this->scope !== []) {
            return $this->scope[array_key_last($this->scope)];
        }
        try {
            $request = DI::getRequest();
            return $request->isLanguageFromAccount() ? DI::getI18n()->getDefaultLanguage() : $request->getLanguage();
        } catch (\Throwable) {
            return DI::getI18n()->getDefaultLanguage();
        }
    }

    /**
     * The JS-facing subset of the dictionary for the shared `core.js` and module/project
     * scripts: every key in a `js` namespace — `js.*` (framework) or `<ns>.js.*` (a module or
     * project: `member.js.*`, `rudaz.js.*`) — plus SHARED_CLIENT_KEYS, resolved for $language
     * with the default-language fallback. The set of keys here is exactly what the client
     * needs — it is inlined into the page head as a JSON data island (see {@see HtmlView})
     * and read once at boot.
     *
     * @return array<string, string>
     */
    public function clientDictionary(?string $language = null): array
    {
        $language ??= $this->currentLanguage();
        $default    = DI::getI18n()->getDefaultLanguage();

        $merged = $this->dictionary($default);
        if ($language !== $default) {
            $merged = array_merge($merged, $this->dictionary($language));
        }

        return array_filter(
            $merged,
            fn(string $key) => self::isClientKey($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** `js.x` or `<ns>.js.x` — a string a script renders. */
    public static function isClientKey(string $key): bool
    {
        return str_starts_with($key, 'js.')
            || preg_match('/^[a-z0-9_-]+\.js\./i', $key) === 1
            || in_array($key, self::SHARED_CLIENT_KEYS, true);
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

    /** @return array<string, string> package → project → runtime, later wins */
    private function load(string $language): array
    {
        $merged = [];
        foreach ($this->codeDictionaryFiles($language) as $path) {
            $merged = array_merge($merged, self::readFile($path));
        }

        return array_merge($merged, self::readFile(ABS_BASE_PATH . '/data/framework/i18n/' . $language . '.json'));
    }

    /**
     * The package and project dictionaries of $language, in merge order: per namespace the
     * package first, its override after it. A FileFinder that cannot answer (an installation
     * before `composer install`) leaves only the runtime layer — a missing string shows its
     * key, it never breaks a page.
     *
     * @return list<string> absolute paths
     */
    private function codeDictionaryFiles(string $language): array
    {
        if (preg_match('/^[a-z]{2}$/D', $language) !== 1) {
            return [];
        }
        try {
            $finder     = DI::getFileFinder();
            $namespaces = array_keys($finder->getAllNamespaces());
        } catch (\Throwable) {
            return [];
        }

        $files = [];
        foreach ($namespaces as $namespace) {
            // getBasePaths() lists override first, package last — merge the other way round.
            foreach (array_reverse($finder->getBasePaths((string) $namespace, 'sourcePaths')) as $base) {
                $path = rtrim((string) $base, '/\\') . '/' . self::DICTIONARY_DIR . '/' . $language . '.json';
                if (is_file($path) && !in_array($path, $files, true)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    /** @return array<string, string> a flat key → string map; anything else is skipped */
    private static function readFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            error_log('Translator: ' . $path . ' is not a JSON object — ignored');
            return [];
        }

        return array_filter($data, static fn($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }
}
