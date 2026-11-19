<?php

namespace Z77\Shared\ProjectHealth;

use Z77\Core\Services\ModuleManager;

/**
 * The registry of the derived project checks (project-health bauplan D1) — the copy of
 * `DashboardContributors`: a module DECLARES under its config key `projectChecks` a list of
 * {@see ProjectCheck} classes, the bridge contributor of module-backend collects the union
 * across all modules. Explicit, one line per module, and a bad entry fails at the first
 * dashboard load, naming the module and the file.
 *
 * ```php
 * // module-financial: financialConfig.inc.php
 * 'projectChecks' => [ChartOfAccountsCheck::class, FiscalYearCheck::class],
 * ```
 *
 * Two additive sources per module: the `projectChecks` key of the module config, and every
 * extension file `App/Config/projectChecksConfig.inc.php` of the module
 * (`ModuleManager::getConfigExtensions()`), returning a plain list of classes — how a project
 * adds a check of its own without copying the module config (Rule 2, BOOT-CONFIG-001):
 *
 * ```php
 * // override/z77/module/backend/src/App/Config/projectChecksConfig.inc.php
 * return [\App\Health\DnsSpfCheck::class];
 * ```
 *
 * A project cannot REMOVE a shipped check by config (a later «Nicht zutreffend», bauplan D4).
 * Ids are unique across all modules — a second check with a taken id is refused naming both.
 */
final class ProjectChecks
{
    public const CONFIG_KEY = 'projectChecks';

    /** Pattern of {@see ProjectCheck::id()}: `<module>.<what>`, lower-case kebab segments. */
    private const ID_PATTERN = '/^[a-z][a-z0-9-]*(\.[a-z0-9][a-z0-9-]*)+$/';

    /** @var array<class-string<ProjectCheck>, string> class => module key */
    private array $classes = [];

    /** @var list<ProjectCheck>|null built on first {@see checks()} */
    private ?array $instances = null;

    /** @param array<string, list<class-string>> $classesByModule module key => check classes */
    public function __construct(array $classesByModule = [])
    {
        foreach ($classesByModule as $moduleKey => $classes) {
            $this->add((string) $moduleKey, $classes, self::CONFIG_KEY . " of module '{$moduleKey}'");
        }
    }

    /** The union of every module's `projectChecks` declaration and extension files, deduplicated. */
    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self();
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
            foreach ($sources as $origin => $classes) {
                $registry->add($moduleKey, $classes, $origin);
            }
        }

        return $registry;
    }

    /** @return array<class-string<ProjectCheck>, string> class => module key, in registration order */
    public function classes(): array
    {
        return $this->classes;
    }

    /**
     * One instance per registered class, in registration order — built once per registry (one
     * dashboard load). Refuses an id that breaks the pattern or is taken by another check.
     *
     * @return list<ProjectCheck>
     */
    public function checks(): array
    {
        if ($this->instances !== null) {
            return $this->instances;
        }
        $instances = [];
        $byId      = [];
        foreach ($this->classes as $class => $moduleKey) {
            $check = new $class();
            $id    = $check->id();
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new \RuntimeException("❌ Project check {$class} (module '{$moduleKey}') has the id " . var_export($id, true) . ' — expected `<module>.<what>` in lower-case kebab-case.');
            }
            if (isset($byId[$id])) {
                throw new \RuntimeException("❌ Project check id '{$id}' is taken twice: {$byId[$id]} and {$class} (module '{$moduleKey}').");
            }
            $byId[$id]   = $class;
            $instances[] = $check;
        }

        return $this->instances = $instances;
    }

    /**
     * Everything that would fail at the first question fails here instead: a list of existing
     * classes implementing the interface, instantiable without arguments.
     */
    private function add(string $moduleKey, mixed $classes, string $origin): void
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . ProjectCheck::class . ' classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, ProjectCheck::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . ProjectCheck::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which cannot be instantiated without arguments — a check reaches its services through DI.");
            }
            $this->classes[$class] ??= $moduleKey;
        }
        $this->instances = null;
    }
}
