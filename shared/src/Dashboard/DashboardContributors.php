<?php

namespace Z77\Shared\Dashboard;

use Z77\Core\Services\ModuleManager;
use Z77\Shared\Auth\AuthUser;

/**
 * The backend dashboard's contributor registry (ADR-052) — the pattern of the
 * open-work registry (`OpenWorkChecks`) and of `doctrineEntities`: a module
 * DECLARES under its config key `dashboard` a list of
 * {@see DashboardContributor} classes, the dashboard collects the union
 * across all modules. Explicit, one line per module, and a bad entry fails
 * at the first page load, naming the module and the file.
 *
 * ```php
 * // module-order: orderConfig.inc.php
 * 'dashboard' => [OrderDashboard::class],
 * ```
 *
 * Two additive sources per module: the `dashboard` key of the module config,
 * and every extension file `App/Config/dashboardConfig.inc.php` of the module
 * (`ModuleManager::getConfigExtensions()`), returning a plain list of classes.
 * That is how a project adds a contributor of its own without copying the
 * module config (Rule 2):
 *
 * ```php
 * // override/z77/module/order/src/App/Config/dashboardConfig.inc.php
 * return [ProjectDeliveriesDashboard::class];
 * ```
 *
 * Asking ({@see entriesFor()}) isolates every contributor: one that throws —
 * or yields something else than a {@see DashboardEntry} — is logged and
 * replaced by ONE line «Pendenzen aus Modul «…» nicht verfügbar»; the
 * others' entries stand. No cache: the numbers are live.
 */
final class DashboardContributors
{
    public const CONFIG_KEY = 'dashboard';

    /** @var array<class-string<DashboardContributor>, string> class => module key */
    private array $contributors = [];

    /** @param array<string, list<class-string>> $classesByModule module key => contributor classes */
    public function __construct(array $classesByModule = [])
    {
        foreach ($classesByModule as $moduleKey => $classes) {
            $this->add((string) $moduleKey, $classes, self::CONFIG_KEY . " of module '{$moduleKey}'");
        }
    }

    /** The union of every module's `dashboard` declaration and extension files, deduplicated. */
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

    /** @return array<class-string<DashboardContributor>, string> class => module key, in registration order */
    public function contributors(): array
    {
        return $this->contributors;
    }

    /**
     * Every contributor's entries for $user, in registration order. Not yet
     * filtered by access — that is the dashboard's step (`canReach()` on each
     * target), so a contributor cannot forget it.
     *
     * @return list<DashboardEntry>
     */
    public function entriesFor(AuthUser $user): array
    {
        $all = [];
        foreach ($this->contributors as $class => $moduleKey) {
            try {
                $mine = [];
                foreach ((new $class())->entries($user) as $entry) {
                    if (!$entry instanceof DashboardEntry) {
                        throw new \UnexpectedValueException("{$class} must yield " . DashboardEntry::class . ' objects, got ' . get_debug_type($entry) . '.');
                    }
                    $mine[] = $entry;
                }
                array_push($all, ...$mine);
            } catch (\Throwable $e) {
                error_log("dashboard: contributor {$class} (module '{$moduleKey}') failed: " . $e);
                $all[] = DashboardEntry::unavailable("Pendenzen aus Modul «{$moduleKey}» nicht verfügbar");
            }
        }

        return $all;
    }

    /**
     * Everything that would fail at the first question fails here instead:
     * a list of existing classes implementing the interface, instantiable
     * without arguments.
     */
    private function add(string $moduleKey, mixed $classes, string $origin): void
    {
        if (!is_array($classes) || !array_is_list($classes)) {
            throw new \RuntimeException("❌ {$origin} must be a list of " . DashboardContributor::class . ' classes.');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new \RuntimeException("❌ {$origin} names a non-existent class: " . var_export($class, true));
            }
            if (!is_subclass_of($class, DashboardContributor::class)) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which does not implement " . DashboardContributor::class . '.');
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new \RuntimeException("❌ {$origin} names {$class}, which cannot be instantiated without arguments — a contributor reaches its services through DI.");
            }
            $this->contributors[$class] ??= $moduleKey;
        }
    }
}
