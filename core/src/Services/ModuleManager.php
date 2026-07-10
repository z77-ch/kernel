<?php

namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Libraries\ConfigManager,
    Z77\Core\Config\Config,
    Z77\Shared\Libraries\Convention\Naming
;

class ModuleManager
{
    private ConfigManager $configManager;
    private Config $config;

    private string $frameworkPrefix;
    private string $modulePrefix;
    private string $defaultModuleKey;

    /** Memoized reserved-route map (path prefix → 4-tuple); built once per request. */
    private ?array $reservedRoutes = null;

    public function __construct(ConfigManager $configManager)
    {
        $this->configManager = $configManager;
        $this->config = $this->configManager
            ->getBaseConfig(
                configName: 'config/moduleManager',
                mutable: true,
                cachePersist: true
            )
        ;
        $this->frameworkPrefix = $this->config->getFrameworkPrefix();
        $this->modulePrefix = $this->config->getModulePrefix();
        $this->defaultModuleKey = $this->config->getDefaultModule();
    }

    public function getAllPsr4Entries(): array
    {
        return $this->config->getAll();
    }

    public function getModuleNamespace(string $key): ?string
    {
        $module = $this->modules[$key] ?? null;

        return ($module) ? $module['namespace'] : null;
    }

    public function getDefaultModuleKey(): string
    {
        return $this->defaultModuleKey;
    }

    public function getDefaultController(string $moduleKey): string
    {
        // Single source of truth is `groupDefaults[defaultGroup]` — modules no longer
        // declare a flat `defaultController`. Existing callers (e.g. post-login redirect)
        // keep working through this convenience accessor.
        return $this->getGroupDefaultController(
            $moduleKey,
            $this->getDefaultGroup($moduleKey)
        );
    }

    public function getDefaultGroup(string $moduleKey): string
    {
        $module = $this->getModuleConfig($moduleKey);

        if (!$defaultGroup = $module?->getDefaultGroup()) {
            throw new \RuntimeException("❌ defaultGroup für Module : '{$moduleKey}' ist nicht in der Konfiguration definiert.");
        }
        return $defaultGroup;
    }

    public function getGroupDefaultController(string $moduleKey, string $group): string
    {
        $module = $this->getModuleConfig($moduleKey);
        $groupDefaults = $module?->get('groupDefaults', []);

        $controller = is_array($groupDefaults) ? ($groupDefaults[$group] ?? null) : null;
        if (!$controller) {
            throw new \RuntimeException("❌ groupDefaults['{$group}'] für Module : '{$moduleKey}' ist nicht in der Konfiguration definiert.");
        }
        return $controller;
    }

    public function hasGroup(string $moduleKey, string $group): bool
    {
        $module = $this->getModuleConfig($moduleKey);
        if ($module === null) {
            return false;
        }
        $groupDefaults = $module->get('groupDefaults', []);
        return is_array($groupDefaults) && array_key_exists($group, $groupDefaults);
    }

    public function getDefaultAction(string $moduleKey): ?string
    {
        $module = $this->getModuleConfig($moduleKey);
        return $module?->getDefaultAction() ?: null;
    }

    public function getDefaultActionForController(string $moduleKey, string $group, string $controller): ?string
    {
        $module = $this->getModuleConfig($moduleKey);
        if (!$module) {
            return null;
        }

        // controllers are nested by group: controllers[$group][$controllerKey]
        $groupControllers = ($module->getControllers() ?? [])[$group] ?? [];
        $controllerKey    = Naming::toCamelCase($controller) . 'Controller';
        $controllerDef    = $groupControllers[$controllerKey] ?? $groupControllers['*'] ?? null;

        return (is_array($controllerDef)) ? ($controllerDef['defaultAction'] ?? null) : null;
    }

    public function hasModule(string $moduleKey): bool
    {
        return ($this->getModuleConfig($moduleKey) instanceof Config);
    }

    /**
     * Module keys that declare themselves a view area — a top-level UI
     * environment with its own layout (config carries `'viewArea' => true`).
     * These are the only valid top-level navigation tags (= environments).
     *
     * @return list<string>
     */
    /** All configured module keys (e.g. ['frontend', 'backend']). */
    public function getModuleKeys(): array
    {
        return array_keys($this->config->getAll()['modules'] ?? []);
    }

    public function getViewAreaKeys(): array
    {
        $modules = $this->config->getAll()['modules'] ?? [];
        $keys    = [];
        foreach (array_keys($modules) as $moduleKey) {
            $cfg = $this->getModuleConfig($moduleKey);
            if ($cfg instanceof Config && $cfg->get('viewArea', false)) {
                $keys[] = $moduleKey;
            }
        }
        return $keys;
    }

    /**
     * View-area module keys flagged public (config carries `'public' => true`) —
     * publicly reachable, indexable environments that warrant SEO metadata. The
     * admin backend is a view area but NOT public. A subset of getViewAreaKeys().
     *
     * @return list<string>
     */
    public function getPublicViewAreaKeys(): array
    {
        $keys = [];
        foreach ($this->getViewAreaKeys() as $moduleKey) {
            $cfg = $this->getModuleConfig($moduleKey);
            if ($cfg instanceof Config && $cfg->get('public', false)) {
                $keys[] = $moduleKey;
            }
        }
        return $keys;
    }

    /**
     * Display label of a view-area environment (config `viewAreaLabel`),
     * falling back to the ucfirst module key. See ADR-022.
     */
    public function getViewAreaLabel(string $moduleKey): string
    {
        $cfg   = $this->getModuleConfig($moduleKey);
        $label = ($cfg instanceof Config) ? (string) $cfg->get('viewAreaLabel', '') : '';
        return $label !== '' ? $label : ucfirst($moduleKey);
    }

    /**
     * Navigation slots of a view-area environment as an ordered map
     * fullSlug => label, where fullSlug = `{moduleKey}-{slotKey}` (config
     * `navSlots`). A slot is a render area; WHERE it renders is a layout
     * decision (a `getBySlot('{slug}')` call). See ADR-022.
     *
     * @return array<string, string>
     */
    public function getNavSlots(string $moduleKey): array
    {
        $cfg   = $this->getModuleConfig($moduleKey);
        $slots = ($cfg instanceof Config) ? $cfg->get('navSlots', []) : [];
        if (!is_array($slots)) {
            return [];
        }
        $out = [];
        foreach ($slots as $slotKey => $label) {
            $out[$moduleKey . '-' . $slotKey] = (string) $label;
        }
        return $out;
    }

    /**
     * The slot registry: every valid slot slug across all view-area modules as an
     * ordered map fullSlug => label. The single source of "which slots exist";
     * {@see \Z77\Core\Services\NavigationService::getBySlot()} validates against it.
     * See ADR-022.
     *
     * @return array<string, string>
     */
    public function getAllNavSlots(): array
    {
        $all = [];
        foreach ($this->getViewAreaKeys() as $moduleKey) {
            $all += $this->getNavSlots($moduleKey);
        }
        return $all;
    }

    /** True if $slug is a registered navigation slot. See ADR-022. */
    public function isKnownSlot(string $slug): bool
    {
        return array_key_exists($slug, $this->getAllNavSlots());
    }

    /**
     * Languages a module declares for its switch UI (config key `languages`,
     * ADR-013). Opt-in: a module without the key returns `[]` (no switch). The
     * raw declared codes are returned in declared order; validation against the
     * global i18n whitelist happens at the consumption boundary (the render path).
     *
     * A declared (non-empty) set MUST contain the system default language — it is
     * the guaranteed fallback every module serves. Omitting it is a config error
     * (fail-fast) rather than a silent reliance on the implicit fallback.
     *
     * @return list<string>
     */
    public function getModuleLanguages(string $moduleKey): array
    {
        $cfg       = $this->getModuleConfig($moduleKey);
        $languages = $cfg?->get('languages', null);

        if (!is_array($languages)) {
            return [];
        }

        $languages = array_values(array_filter($languages, 'is_string'));
        if ($languages === []) {
            return [];
        }

        $default = DI::getI18n()->getDefaultLanguage();
        if (!in_array($default, $languages, true)) {
            throw new \RuntimeException(
                "❌ Module '{$moduleKey}' declares languages [" . implode(', ', $languages)
                . "] but omits the default language '{$default}'. A module's declared "
                . "language set MUST include the default (it is the fallback). "
                . "Fix the 'languages' key in the module config."
            );
        }

        return $languages;
    }

    public function getModuleConfig(string $moduleKey): ?Config
    {
        $lowerKey = Naming::toLcFirstCamelCase($moduleKey);
        $upperKey = Naming::toCamelCase($moduleKey);

        if (!$this->config->has(['modules', $lowerKey])) {
            if ($moduleKey === $this->defaultModuleKey) {
                throw new \RuntimeException(
                    "❌ Default Module '{$moduleKey}' not found. Run composer post-install-cmd."
                );
            }
            return null;
        }

        $moduleConfig = $this->config->get(['modules', $lowerKey]);

        // Wenn noch leer, laden und cachen
        if (empty($moduleConfig)) {
            $nameSpace = Naming::toNamespaceString(
                [
                    $this->frameworkPrefix,
                    $this->modulePrefix,
                    $upperKey
                ]
            );
            $moduleConfig = $this->configManager
                ->getArrayConfig(
                    configName: "App/Config/{$lowerKey}Config",
                    nameSpace: $nameSpace,
                    cachePersist: false // ModuleConfig will hold this config in cache
            );

            $this->config->set(['modules', $lowerKey], $moduleConfig);
        }

        return $moduleConfig;
    }


    /**
     * Reserved routes aggregated across all modules (ADR-017 R3): a structural path
     * prefix → its routing target (`module/group/controller/action`). The highest
     * routing precedence — resolved before NavigationAlias / static nav / convention.
     * A module declares them under the `reservedRoutes` config key. A prefix declared
     * by two modules is a configuration error (fail-fast — reserved routes are global).
     *
     * @return array<string, array{module:string,group:string,controller:string,action:string}>
     */
    public function getReservedRoutes(): array
    {
        if ($this->reservedRoutes !== null) {
            return $this->reservedRoutes;
        }

        $routes = [];
        foreach ($this->getModuleKeys() as $moduleKey) {
            $declared = $this->getModuleConfig($moduleKey)?->get('reservedRoutes', []);
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $path => $target) {
                $normalized = '/' . trim((string) $path, '/');
                if (isset($routes[$normalized])) {
                    throw new \RuntimeException(
                        "❌ Reserved route '{$normalized}' is declared by more than one module."
                    );
                }
                $routes[$normalized] = [
                    'module'     => $target['module']     ?? $moduleKey,
                    'group'      => $target['group']      ?? '',
                    'controller' => $target['controller'] ?? '',
                    'action'     => $target['action']     ?? '',
                ];
            }
        }

        return $this->reservedRoutes = $routes;
    }

    public function getModuleParameter(string $moduleKey, string $parameter): string | array
    {
        $module = $this->getModuleConfig($moduleKey);

        return $module[$parameter] ?? null;
    }

    public function getNamespacePrefix(string $moduleKey): string
    {
        return Naming::toNamespaceString(
            [
                $this->frameworkPrefix,
                $this->modulePrefix,
                $moduleKey
            ]
        );
    }

    /**
     * Resolves the page-cache policy for a (module, controller, action) tuple.
     *
     * Three-level cascade in the module config under `cache`:
     *   module-default → controllers[$controller] → controllers[$controller].actions[$action]
     *
     * Each level overrides only the keys it specifies. A missing or absent
     * `cache` block defaults to disabled with a TTL of 0.
     *
     * @return array{enabled: bool, ttl: int}
     */
    public function getCachePolicy(
        string $moduleKey,
        string $controller,
        string $action
    ): array {
        $module = $this->getModuleConfig($moduleKey);
        $cfg    = $module ? $module->get('cache', []) : [];

        $enabled = (bool)($cfg['enabled'] ?? false);
        $ttl     = (int) ($cfg['ttl']     ?? 0);

        $cCfg = $cfg['controllers'][$controller] ?? null;
        if (is_array($cCfg)) {
            if (array_key_exists('enabled', $cCfg)) { $enabled = (bool)$cCfg['enabled']; }
            if (array_key_exists('ttl',     $cCfg)) { $ttl     = (int) $cCfg['ttl']; }

            $aCfg = $cCfg['actions'][$action] ?? null;
            if (is_array($aCfg)) {
                if (array_key_exists('enabled', $aCfg)) { $enabled = (bool)$aCfg['enabled']; }
                if (array_key_exists('ttl',     $aCfg)) { $ttl     = (int) $aCfg['ttl']; }
            }
        }

        return ['enabled' => $enabled, 'ttl' => $ttl];
    }
}

