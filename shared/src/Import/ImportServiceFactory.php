<?php

namespace Z77\Shared\Import;

use Z77\Core\DI;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Entities\MetaData;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Entities\NavigationAlias;
use Z77\Shared\Import\Source\JsonEntitySource;
use Z77\Shared\Validators\MetaDataValidator;
use Z77\Shared\Validators\NavigationAliasValidator;
use Z77\Shared\Validators\NavigationValidator;

/**
 * The one place the import stack is wired for a running installation
 * (ADR-032) — used by the backend ImportController AND the ImportApplyJob, so
 * screen-apply and job-apply cannot drift apart. Reads its collaborators from
 * DI (the same shortcut `Persistence\File\Bootstrap` takes), which is why it
 * lives here and not in the DI-free core classes.
 */
final class ImportServiceFactory
{
    public static function fromDi(): ImportService
    {
        $uem     = DI::getInstance()->get('UnifiedEntityManager');
        $planner = new ImportPlanner();

        // Validator wiring per entity (§9): signatures differ, so the known v1
        // entities are wired explicitly; an importable entity without a factory
        // entry is applied without validation (documented applier contract) —
        // add its factory here when registering it.
        $knownSlots = array_keys(DI::getModuleManager()->getAllNavSlots());
        $factories  = [
            Navigation::class => fn(object $e) => new NavigationValidator(
                $e, $uem->getRepository(Navigation::class), $knownSlots
            ),
            NavigationAlias::class => fn(object $e) => new NavigationAliasValidator(
                $e, $uem->getRepository(NavigationAlias::class), $uem->getRepository(Navigation::class)
            ),
            MetaData::class => fn(object $e) => new MetaDataValidator(
                $e, $uem->getRepository(MetaData::class), $uem->getRepository(Navigation::class)
            ),
        ];

        $staging = new ImportStaging(ABS_BASE_PATH . '/data/framework/import');

        return new ImportService(
            $uem,
            $planner,
            new ImportApplier($uem, $planner, validatorFactories: $factories),
            $staging,
            new ImportPlanStore($staging->getPlansDir())
        );
    }

    // -------------------------------------------------------------------------
    // Vendor-defaults discovery (runtime twin of the installer's package walk)
    // -------------------------------------------------------------------------

    /**
     * Finds the shipped `*.default.json` for every importable entity: the
     * entity's own storage path (`#[Entity]`) names the file, the FileFinder
     * namespaces name the data roots — override tier first, so a project may
     * override a seed (CE principle). Entities whose default does not exist
     * anywhere are simply absent from the result.
     *
     * @return array<class-string, string> entity class → absolute default path
     */
    public static function discoverVendorDefaults(): array
    {
        // getAllNamespaces(): ns → {sourcePaths, assetPaths} (the fileFinder config map)
        $roots = [];
        foreach (DI::getFileFinder()->getAllNamespaces() as $paths) {
            foreach ($paths['sourcePaths'] ?? [] as $base) {
                $dir = rtrim(str_replace('\\', '/', $base), '/') . '/data';
                if (is_dir($dir)) {
                    $roots[$dir] = true;
                }
            }
        }

        $files = [];
        foreach (DI::getModuleManager()->getImportEntities() as $class) {
            $attr = (new \ReflectionClass($class))->getAttributes(Entity::class)[0] ?? null;
            $path = $attr?->newInstance()->getPath();
            if ($path === null || $path === '' || !str_ends_with($path, '.json')) {
                continue;
            }
            $defaultRel = substr($path, 0, -strlen('.json')) . '.default.json';

            foreach (array_keys($roots) as $root) {
                $candidate = $root . '/' . $defaultRel;
                if (is_file($candidate)) {
                    $files[$class] = $candidate;
                    break;
                }
            }
        }

        return $files;
    }

    // -------------------------------------------------------------------------
    // Source spec ↔ plan-store state
    // -------------------------------------------------------------------------

    /**
     * Builds the stored source spec: type, label, absolute files per entity
     * class, and a content hash per file — apply verifies the hashes so
     * decisions never run against a source that changed underneath
     * (index-keyed decisions would silently shift otherwise).
     *
     * @param array<class-string, string> $files
     */
    public static function sourceSpec(string $type, string $label, array $files): array
    {
        $hashes = [];
        foreach ($files as $class => $path) {
            $hashes[$class] = sha1((string) file_get_contents($path));
        }
        return ['type' => $type, 'label' => $label, 'files' => $files, 'hashes' => $hashes];
    }

    /** Rebuilds the source from a stored spec; a changed/missing file throws (IMP-R011). */
    public static function sourceFromSpec(array $spec): ImportSource
    {
        $files = $spec['files'] ?? [];
        foreach ($files as $class => $path) {
            $expected = $spec['hashes'][$class] ?? null;
            if (!is_file($path) || $expected === null || sha1((string) file_get_contents($path)) !== $expected) {
                throw new ImportStaleException(
                    "Source file for {$class} changed or disappeared since the plan was computed — start over."
                );
            }
        }
        return new JsonEntitySource($files, (string) ($spec['label'] ?? 'import'));
    }
}
