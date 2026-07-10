<?php

namespace Z77\Core\Services;

use Z77\Core\Libraries\ConfigManager,
    Z77\Core\Config\Config
;

/**
 * Single source of truth for the system's language policy (ADR-013).
 *
 * Reads the dedicated `config/i18n` base config:
 *   - `defaultLanguage` — the one system-wide fallback (replaces the former
 *     scattered `Request::DEFAULT_LANGUAGE` / `MetaDataController::DEFAULT_LANGUAGE`).
 *   - `languages`       — the globally available set, used as the validation
 *     whitelist for the URL language segment.
 *
 * Per-module narrowing (`languages` key in a module config) is read from the
 * module config via `ModuleManager`, NOT here — this service holds the global
 * policy only.
 */
class I18n
{
    private Config $config;

    public function __construct(ConfigManager $configManager)
    {
        $this->config = $configManager->getBaseConfig(
            configName: 'config/i18n',
            mutable: false,
            cachePersist: true
        );
    }

    public function getDefaultLanguage(): string
    {
        return (string) $this->config->get('defaultLanguage', 'de');
    }

    /**
     * Globally available languages — the routing validation whitelist. Falls back
     * to the default language alone if none are configured.
     *
     * @return list<string>
     */
    public function getLanguages(): array
    {
        $languages = $this->config->get('languages', []);
        $languages = is_array($languages)
            ? array_values(array_filter($languages, 'is_string'))
            : [];

        return $languages !== [] ? $languages : [$this->getDefaultLanguage()];
    }

    public function isValidLanguage(string $language): bool
    {
        return in_array($language, $this->getLanguages(), true);
    }
}
