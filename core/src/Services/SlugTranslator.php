<?php

namespace Z77\Core\Services;

/**
 * Translates URL path segments between their canonical (default-language) form and
 * a language's localized form (ADR-014). Canonical = what is stored in
 * navigation/code; only non-default languages carry a table
 * (`data/framework/i18n/route-slugs.{lang}.json`, canonical → localized).
 *
 * Inbound (Request, before routing): localized → canonical, so the router and the
 * whole resolution chain only ever see canonical segments. Outbound (link building):
 * canonical → localized, to render localized URLs.
 *
 * A segment with no table entry is returned unchanged: an already-canonical segment
 * still resolves; genuine garbage stays garbage and 404s downstream (no controller).
 */
class SlugTranslator
{
    /** @var array<string, array<string, string>> language → (canonical → localized) */
    private array $forward = [];
    /** @var array<string, array<string, string>> language → (localized → canonical) */
    private array $reverse = [];

    /** canonical → localized, e.g. ('privacy', 'fr') → 'confidentialite'. */
    public function toLocalized(string $canonical, string $language): string
    {
        return $this->forwardMap($language)[$canonical] ?? $canonical;
    }

    /** localized → canonical, e.g. ('confidentialite', 'fr') → 'privacy'. */
    public function toCanonical(string $localized, string $language): string
    {
        return $this->reverseMap($language)[$localized] ?? $localized;
    }

    /** @return array<string, string> */
    private function forwardMap(string $language): array
    {
        return $this->forward[$language] ??= $this->load($language);
    }

    /** @return array<string, string> */
    private function reverseMap(string $language): array
    {
        return $this->reverse[$language] ??= array_flip($this->forwardMap($language));
    }

    /** @return array<string, string> */
    private function load(string $language): array
    {
        $path = ABS_BASE_PATH . '/data/framework/i18n/route-slugs.' . $language . '.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }

        $table = array_filter(
            $data,
            static fn($value, $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH
        );

        if (defined('DEBUG') && DEBUG) {
            $this->validate($language, $table);
        }

        return $table;
    }

    /**
     * Fail-fast (debug only) on a malformed slug table (ADR-014). Two invariants
     * guarantee a deterministic, collision-free translation:
     *   1. 1:1 — localized values are unique (else reverse mapping is ambiguous).
     *   2. No localized value shadows a DIFFERENT canonical key (else
     *      `/lang/<localized>` would never reach the canonical page of that name).
     *      An identity mapping (canonical === localized, same word in both
     *      languages, e.g. `contact`) is fine — it reaches its own page.
     *
     * @param array<string, string> $table canonical → localized
     */
    private function validate(string $language, array $table): void
    {
        $localized = array_values($table);

        $duplicates = array_keys(array_filter(array_count_values($localized), fn($n) => $n > 1));
        if ($duplicates !== []) {
            throw new \RuntimeException(
                "❌ route-slugs.{$language}: localized slug not 1:1 — duplicate target(s): " . implode(', ', $duplicates)
            );
        }

        $collisions = [];
        foreach ($table as $canonical => $loc) {
            if ($canonical !== $loc && array_key_exists($loc, $table)) {
                $collisions[] = $loc;
            }
        }
        if ($collisions !== []) {
            throw new \RuntimeException(
                "❌ route-slugs.{$language}: localized slug shadows a different canonical slug: " . implode(', ', $collisions)
            );
        }
    }
}
