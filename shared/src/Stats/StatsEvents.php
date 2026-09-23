<?php

namespace Z77\Shared\Stats;

use Z77\Core\DI;

/**
 * The events a PROJECT declares — the framework knows none (owner decision
 * 2026-09-23). A project without a declaration counts page views only.
 *
 * The declaration is a map `event name → German label`, read from two
 * additive sources per module (the `doctrineEntities` pattern, bootstrap.md
 * BOOT-CONFIG-001): the `statsEvents` key of the module config, and every
 * extension file `App/Config/statsEventsConfig.inc.php` under any of the
 * module's source paths (`ModuleManager::getConfigExtensions()`). A project
 * therefore writes ONE small file and copies no module config:
 *
 * ```php
 * // override/z77/module/frontend/src/App/Config/statsEventsConfig.inc.php
 * return [
 *     'form-submitted'   => 'Kontaktformular abgeschickt',
 *     'floorplan-pdf'    => 'Grundriss-PDF geöffnet',
 *     'application-link' => 'Bewerbungslink geklickt',
 * ];
 * ```
 *
 * The declared NAME is what the recorder stores and the rollup counts; the
 * label is for the report page only (step 1.3 reads it from declared() — no
 * lookup helper until then). ⚠️ The list IS the security boundary of
 * the event endpoint: a name that is not declared is dropped, whatever else
 * the request says. A name is `[a-z0-9-]`, 1–40 characters — a key, never a
 * sentence — and `page` is reserved for the page view itself.
 *
 * A malformed declaration is a configuration error and THROWS (fail-fast,
 * like a job or a reserved route declared twice): the endpoint answers 500
 * and the error log names the file, instead of every event being dropped
 * silently.
 */
final class StatsEvents
{
    public const CONFIG_KEY = 'statsEvents';

    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9-]{0,39}$~';

    /** @var array<string, string>|null name => label */
    private static ?array $declared = null;

    /** @return array<string, string> name => label, in declaration order */
    public static function declared(): array
    {
        if (self::$declared !== null) {
            return self::$declared;
        }

        $modules = DI::getModuleManager();
        $sources = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $inline = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            if (is_array($inline) && $inline !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $inline;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
        }

        return self::$declared = self::fromSources($sources);
    }

    public static function isDeclared(string $name): bool
    {
        return isset(self::declared()[$name]);
    }

    /**
     * Merges and validates the declarations. Pure — the harness feeds it
     * directly. The same name may appear in two sources with the SAME label
     * (a package and its override agreeing); a different label is a conflict.
     *
     * @param array<string, mixed> $sources origin label => the declared map
     * @return array<string, string>
     */
    public static function fromSources(array $sources): array
    {
        $events = [];
        foreach ($sources as $origin => $map) {
            if (!is_array($map) || ($map !== [] && array_is_list($map))) {
                throw new \RuntimeException("❌ {$origin} must return a map of event name => label.");
            }
            foreach ($map as $name => $label) {
                $name = (string)$name;
                if (preg_match(self::NAME_PATTERN, $name) !== 1 || $name === StatsRecorder::PAGE_EVENT) {
                    throw new \RuntimeException(
                        "❌ {$origin}: '{$name}' is not a valid event name ([a-z0-9-], 1–40 characters, not 'page')."
                    );
                }
                if (!is_string($label) || trim($label) === '') {
                    throw new \RuntimeException("❌ {$origin}: event '{$name}' needs a non-empty label.");
                }
                $label = trim($label);
                if (isset($events[$name]) && $events[$name] !== $label) {
                    throw new \RuntimeException(
                        "❌ {$origin}: event '{$name}' is already declared with another label ('{$events[$name]}')."
                    );
                }
                $events[$name] = $label;
            }
        }

        return $events;
    }

    /** Replace the declaration — tests only. Null re-arms the config read. */
    public static function use(?array $events): void
    {
        self::$declared = $events === null ? null : self::fromSources(['test' => $events]);
    }
}
