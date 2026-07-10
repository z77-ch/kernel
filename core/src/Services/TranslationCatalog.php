<?php

namespace Z77\Core\Services;

use Z77\Core\Libraries\CacheManager;
use Z77\Persistence\File\Storage\FileStorage;

/**
 * Read/write access to the editable i18n catalog for the backend translation tool.
 *
 * Two families live under `data/framework/i18n/`, both edited here (the runtime
 * files the app actually reads — the `*.default.json` seeds are installer-owned and
 * not touched):
 *
 *  - **UI strings** — `{lang}.json`, a flat `key → value` map per language. The
 *    default language is the master key set + fallback (see {@see Translator}).
 *  - **Route slugs** — `route-slugs.{lang}.json`, `canonical → localized` per
 *    NON-default language (the default is canonical, has no table — see
 *    {@see SlugTranslator}). Writes are validated against the slug invariants
 *    (1:1, no shadowing) before they hit disk.
 *
 * Neither translation service uses a persistent cache (both lazy-load per request),
 * so a file write is immediately effective; only the rendered-HTML page cache is
 * cleared so already-cached pages pick up changed strings / localized URLs.
 *
 * NOT the language authority — that is {@see I18n}. This only reads/writes the data.
 */
class TranslationCatalog
{
    private const DIR = 'framework/i18n';

    /** Acceptable UI translation key: namespaced dotted identifier (e.g. `nav.home`, `js.scriptLoadError`). */
    private const UI_KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/';

    /** Acceptable URL slug segment (canonical or localized): lowercase, digits, dashes. */
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(
        private FileStorage  $storage,
        private I18n         $i18n,
        private CacheManager $cacheManager
    ) {}

    // ── UI strings ───────────────────────────────────────────────────────────

    /** All languages, default first — the columns of the UI-string grid. @return list<string> */
    public function uiLanguages(): array
    {
        $default = $this->i18n->getDefaultLanguage();
        $rest    = array_values(array_filter($this->i18n->getLanguages(), fn($l) => $l !== $default));
        return array_merge([$default], $rest);
    }

    /**
     * The UI-string matrix: one row per key (union across all languages, sorted),
     * each with its value per language ('' when absent in that language).
     *
     * @return list<array{key: string, values: array<string, string>}>
     */
    public function uiMatrix(): array
    {
        $languages = $this->uiLanguages();
        $tables    = [];
        $keys      = [];
        foreach ($languages as $lang) {
            $tables[$lang] = $this->readMap($this->uiPath($lang));
            $keys          = array_merge($keys, array_keys($tables[$lang]));
        }
        $keys = array_values(array_unique($keys));
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $values = [];
            foreach ($languages as $lang) {
                $values[$lang] = (string)($tables[$lang][$key] ?? '');
            }
            $rows[] = ['key' => $key, 'values' => $values];
        }
        return $rows;
    }

    /**
     * One UI-string key's value per language ('' when absent) — for the edit form.
     *
     * @return array<string, string> language → value
     */
    public function uiEntry(string $key): array
    {
        $values = [];
        foreach ($this->uiLanguages() as $lang) {
            $values[$lang] = (string)($this->readMap($this->uiPath($lang))[$key] ?? '');
        }
        return $values;
    }

    /**
     * Upserts one UI-string key across all languages. The default language always
     * keeps the key (master + fallback, stored even if empty); a non-default
     * language with an empty value drops the key so `t()` falls back to the default.
     * Pass $originalKey to rename (removes the old key from every language first).
     *
     * @param array<string, string> $values language → value
     * @return list<string> validation errors; empty = persisted
     */
    public function saveUiEntry(string $key, array $values, ?string $originalKey = null): array
    {
        $key = trim($key);
        if (!preg_match(self::UI_KEY_PATTERN, $key)) {
            return ['Ungültiger Schlüssel «' . $key . '» — erwartet wird ein Namespace wie «nav.home».'];
        }

        $default   = $this->i18n->getDefaultLanguage();
        $languages = $this->uiLanguages();
        $rename    = $originalKey !== null && $originalKey !== '' && $originalKey !== $key;

        // Reject a rename / add onto an already existing key (would silently merge).
        if (($rename || $originalKey === null) && $this->uiKeyExists($key)) {
            return ['Der Schlüssel «' . $key . '» existiert bereits.'];
        }

        foreach ($languages as $lang) {
            $table = $this->readMap($this->uiPath($lang));
            if ($rename) {
                unset($table[$originalKey]);
            }
            $value = trim((string)($values[$lang] ?? ''));
            if ($lang === $default || $value !== '') {
                $table[$key] = $value;
            } else {
                unset($table[$key]);
            }
            $this->writeMap($this->uiPath($lang), $table);
        }
        $this->flushPageCache();
        return [];
    }

    /** Removes a UI-string key from every language file. */
    public function deleteUiEntry(string $key): void
    {
        foreach ($this->uiLanguages() as $lang) {
            $table = $this->readMap($this->uiPath($lang));
            if (array_key_exists($key, $table)) {
                unset($table[$key]);
                $this->writeMap($this->uiPath($lang), $table);
            }
        }
        $this->flushPageCache();
    }

    private function uiKeyExists(string $key): bool
    {
        foreach ($this->uiLanguages() as $lang) {
            if (array_key_exists($key, $this->readMap($this->uiPath($lang)))) {
                return true;
            }
        }
        return false;
    }

    // ── Route slugs ──────────────────────────────────────────────────────────

    /** Non-default languages — the columns of the slug grid (the default is canonical, no table). @return list<string> */
    public function slugLanguages(): array
    {
        $default = $this->i18n->getDefaultLanguage();
        return array_values(array_filter($this->i18n->getLanguages(), fn($l) => $l !== $default));
    }

    /**
     * The route-slug matrix: one row per canonical segment (union across the
     * non-default language tables, sorted), each with its localized value per
     * language ('' when not localized there).
     *
     * @return list<array{canonical: string, values: array<string, string>}>
     */
    public function slugMatrix(): array
    {
        $languages = $this->slugLanguages();
        $tables    = [];
        $canonical = [];
        foreach ($languages as $lang) {
            $tables[$lang] = $this->readMap($this->slugPath($lang));
            $canonical     = array_merge($canonical, array_keys($tables[$lang]));
        }
        $canonical = array_values(array_unique($canonical));
        sort($canonical);

        $rows = [];
        foreach ($canonical as $key) {
            $values = [];
            foreach ($languages as $lang) {
                $values[$lang] = (string)($tables[$lang][$key] ?? '');
            }
            $rows[] = ['canonical' => $key, 'values' => $values];
        }
        return $rows;
    }

    /**
     * One canonical segment's localized value per non-default language ('' when not
     * localized there) — for the edit form.
     *
     * @return array<string, string> language → localized slug
     */
    public function slugEntry(string $canonical): array
    {
        $values = [];
        foreach ($this->slugLanguages() as $lang) {
            $values[$lang] = (string)($this->readMap($this->slugPath($lang))[$canonical] ?? '');
        }
        return $values;
    }

    /**
     * Upserts one canonical segment's localized forms across the non-default
     * languages. An empty value drops the localization for that language (the
     * segment stays canonical there). Validated per language against the slug
     * invariants (1:1, no shadowing — see {@see SlugTranslator}) BEFORE any write;
     * on any error nothing is persisted. Pass $originalCanonical to rename.
     *
     * @param array<string, string> $values language → localized slug
     * @return list<string> validation errors; empty = persisted
     */
    public function saveSlugEntry(string $canonical, array $values, ?string $originalCanonical = null): array
    {
        $canonical = trim($canonical);
        if (!preg_match(self::SLUG_PATTERN, $canonical)) {
            return ['Ungültiger kanonischer Slug «' . $canonical . '» — nur Kleinbuchstaben, Ziffern und Bindestriche.'];
        }

        $rename = $originalCanonical !== null && $originalCanonical !== '' && $originalCanonical !== $canonical;
        if (($rename || $originalCanonical === null) && $this->slugCanonicalExists($canonical)) {
            return ['Der kanonische Slug «' . $canonical . '» existiert bereits.'];
        }

        $errors  = [];
        $pending = []; // lang → [table, path] to write once all languages validate

        foreach ($this->slugLanguages() as $lang) {
            $table = $this->readMap($this->slugPath($lang));
            if ($rename) {
                unset($table[$originalCanonical]);
            }
            $value = trim((string)($values[$lang] ?? ''));

            if ($value === '') {
                unset($table[$canonical]);
            } else {
                if (!preg_match(self::SLUG_PATTERN, $value)) {
                    $errors[] = $lang . ': ungültiger Slug «' . $value . '» — nur Kleinbuchstaben, Ziffern und Bindestriche.';
                    continue;
                }
                $table[$canonical] = $value;
                $rowError = $this->slugTableError($lang, $table);
                if ($rowError !== null) {
                    $errors[] = $rowError;
                    continue;
                }
            }
            $pending[$lang] = $table;
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach ($pending as $lang => $table) {
            $this->writeMap($this->slugPath($lang), $table);
        }
        $this->flushPageCache();
        return [];
    }

    /** Removes a canonical segment from every non-default slug table. */
    public function deleteSlugEntry(string $canonical): void
    {
        foreach ($this->slugLanguages() as $lang) {
            $table = $this->readMap($this->slugPath($lang));
            if (array_key_exists($canonical, $table)) {
                unset($table[$canonical]);
                $this->writeMap($this->slugPath($lang), $table);
            }
        }
        $this->flushPageCache();
    }

    private function slugCanonicalExists(string $canonical): bool
    {
        foreach ($this->slugLanguages() as $lang) {
            if (array_key_exists($canonical, $this->readMap($this->slugPath($lang)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The slug-table invariants (mirrors {@see SlugTranslator::validate}): localized
     * targets are 1:1 unique, and no localized value shadows a DIFFERENT canonical
     * key. Returns a message on the first violation, or null when clean.
     *
     * @param array<string, string> $table canonical → localized
     */
    private function slugTableError(string $language, array $table): ?string
    {
        $localized  = array_values($table);
        $duplicates = array_keys(array_filter(array_count_values($localized), fn($n) => $n > 1));
        if ($duplicates !== []) {
            return $language . ': lokalisierter Slug nicht eindeutig (1:1 verletzt): ' . implode(', ', $duplicates);
        }

        foreach ($table as $canonical => $loc) {
            if ($canonical !== $loc && array_key_exists($loc, $table)) {
                return $language . ': lokalisierter Slug «' . $loc . '» verdeckt einen anderen kanonischen Slug.';
            }
        }
        return null;
    }

    // ── File IO ──────────────────────────────────────────────────────────────

    private function uiPath(string $language): string
    {
        return self::DIR . '/' . $language . '.json';
    }

    private function slugPath(string $language): string
    {
        return self::DIR . '/route-slugs.' . $language . '.json';
    }

    /** @return array<string, string> */
    private function readMap(string $path): array
    {
        $data = $this->storage->load($path);
        return array_filter(
            $data,
            static fn($v, $k): bool => is_string($k) && is_string($v),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @param array<string, string> $map */
    private function writeMap(string $path, array $map): void
    {
        ksort($map);
        $this->storage->save($path, $map);
    }

    /** Translation services lazy-load per request; only the rendered-page cache must drop. */
    private function flushPageCache(): void
    {
        $this->cacheManager->page()->clearAll();
    }
}
