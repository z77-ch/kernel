<?php

namespace Z77\Shared\Navigation;

use Z77\Core\Services\ModuleManager;

/**
 * The menu-condition registry — the pattern of the dashboard registry
 * (`DashboardContributors`) and of `openWorkChecks`: a module DECLARES under its
 * config key `menuConditions` which {@see MenuCondition} decides whether the menu
 * entry of one of its routes is shown; the backend menu collects the union across
 * all modules and asks it while it builds (`BackendMenu`).
 *
 * The key is the ROUTE `module/group/controller/action` exactly as a navigation
 * entry stores it (the URL form, kebab-case) — never a seed key or a record id:
 * every installation holds its own navigation records, moved, renamed or copied
 * by hand; the route is what they all still lead to. A ref entry is asked through
 * its target's route, so a reference to a hidden page is hidden too.
 *
 * ```php
 * // module-financial: financialConfig.inc.php
 * 'menuConditions' => [
 *     'backend/finance/vat-return/list' => VatReturnMenuCondition::class,
 * ],
 * ```
 *
 * Two additive sources per module: the `menuConditions` key of the module config,
 * and every extension file `App/Config/menuConditionsConfig.inc.php` of the module
 * (`ModuleManager::getConfigExtensions()`), returning the same shape. Two
 * conditions for one route must BOTH say visible. A bad entry (not a route, a
 * missing class, not a MenuCondition, a constructor with arguments) fails at the
 * first backend page, naming the module and the file.
 *
 * Asking ({@see visible()}): no condition for the route → visible (the menu as
 * without this registry). A condition is instantiated only when its route is
 * asked and answers at most once per instance — the menu builds one instance per
 * request. A condition that throws is logged and counts as visible: a menu must
 * never die on a data question.
 */
final class MenuConditions
{
    public const CONFIG_KEY = 'menuConditions';

    /** @var array<string, list<class-string<MenuCondition>>> route => condition classes */
    private array $conditions = [];

    /** @var array<string, bool> route => answer, for this instance (= this request) */
    private array $answers = [];

    /** @param array<string, class-string> $classesByRoute route => condition class */
    public function __construct(array $classesByRoute = [])
    {
        $this->addAll($classesByRoute, self::CONFIG_KEY);
    }

    /** The union of every module's `menuConditions` declaration and extension files. */
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
            foreach ($sources as $origin => $classesByRoute) {
                $registry->addAll($classesByRoute, $origin);
            }
        }

        return $registry;
    }

    /**
     * Is the menu entry leading to this route shown, as far as the data goes?
     * The four parts as a navigation entry stores them.
     */
    public function visible(string $module, string $group, string $controller, string $action): bool
    {
        $route = "{$module}/{$group}/{$controller}/{$action}";
        if (!isset($this->conditions[$route])) {
            return true;
        }

        return $this->answers[$route] ??= $this->ask($route);
    }

    private function ask(string $route): bool
    {
        foreach ($this->conditions[$route] as $class) {
            try {
                if (!(new $class())->visible()) {
                    return false;
                }
            } catch (\Throwable $e) {
                error_log("menu: condition {$class} for route '{$route}' failed, entry shown: " . $e);
            }
        }

        return true;
    }

    /**
     * Everything that would fail at the first question fails here instead: a
     * map route => class, the route of four non-empty parts, the class existing,
     * implementing the interface, instantiable without arguments.
     */
    private function addAll(mixed $classesByRoute, string $origin): void
    {
        if (!is_array($classesByRoute) || ($classesByRoute !== [] && array_is_list($classesByRoute))) {
            throw new \RuntimeException("❌ {$origin} must be an array of 'module/group/controller/action' => " . MenuCondition::class . ' class.');
        }
        foreach ($classesByRoute as $route => $class) {
            $parts = explode('/', (string) $route);
            if (count($parts) !== 4 || in_array('', $parts, true)) {
                throw new \RuntimeException("❌ {$origin}: '{$route}' is not a route — write it as the navigation entry stores it: 'module/group/controller/action'.");
            }
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin}: route '{$route}' names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, MenuCondition::class)) {
                throw new \RuntimeException("❌ {$origin}: route '{$route}' names {$class}, which does not implement " . MenuCondition::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin}: route '{$route}' names {$class}, which cannot be instantiated without arguments — a condition reaches its services through DI.");
            }
            if (!in_array($class, $this->conditions[$route] ?? [], true)) {
                $this->conditions[$route][] = $class;
            }
        }
    }
}
