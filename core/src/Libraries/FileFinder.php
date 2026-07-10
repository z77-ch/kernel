<?php
namespace Z77\Core\Libraries;

use Z77\Core\Config\Config,
    Z77\Shared\Libraries\Convention\Naming
;

class FileFinder
{
    private CacheManager $cacheManager;
    private ?Config $config = null;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * Loads fileFinder.inc.php, resolves namespace paths against the filesystem,
     * and caches the result for the request lifetime.
     */
    private function resolveConfig(): Config
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $cached = $this->cacheManager->data()->get(self::class, ['config']);
        if ($cached instanceof Config) {
            $this->config = $cached;
            return $cached;
        }

        $configFile = ABS_BASE_PATH . '/config/fileFinder.inc.php';
        if (!file_exists($configFile)) {
            throw new \RuntimeException('Missing config file. Run composer run-script post-install-cmd.');
        }

        $raw = require $configFile;
        $cfgReader = new Config($raw);
        $namespacePaths = [];

        $resourceDirs = $cfgReader->getResourceDir();

        foreach ($cfgReader->getNamespaces() as $namespace => $resources) {
            foreach ($resources as $resource => $paths) {
                foreach ($paths as $path) {

                    $baseDir = $resource === 'sourcePaths'
                        ? '/' . trim($resourceDirs['sourceDir'], '/')
                        : '';

                    if (!realpath($path . $baseDir)) {
                        continue; // path does not exist — skip silently (e.g. module has no assets)
                    }

                    $namespacePaths[$namespace][$resource][] = $path;
                }
            }
        }

        $this->config = new Config(array_merge($raw, ['namespacePaths' => $namespacePaths]));
        $this->cacheManager->data()->set(self::class, ['config'], $this->config, true);

        return $this->config;
    }

    /**
     * Core lookup: walks registered paths for the given namespace and resource type,
     * returns the absolute path of the first matching file.
     */
    private function findFirstMatch(
        string $fileName,
        string $nameSpace,
        string $resourceType,
        string $subDir = '',
        bool $throwError = true,
        bool $cachePersist = true
    ): ?string {
        $ns = Naming::toNamespaceString($nameSpace);

        $key = [$ns, $resourceType, $subDir, $fileName];
        $cached = $this->cacheManager->data()->get(self::class, $key);

        if ($cached !== null) {
            return $cached;
        }

        $paths = $this->getBasePaths($ns, $resourceType);

        if (empty($paths)) {
            if ($throwError) {
                throw new \RuntimeException(
                    "Namespace '{$ns}' has no registered {$resourceType} paths. " .
                    "Check config/fileFinder.inc.php."
                );
            }
            return null;
        }

        foreach ($paths as $p) {
            $fullPath = rtrim($p, '/') . ($subDir !== '' ? "/{$subDir}" : '') . "/{$fileName}";

            if (is_file($fullPath)) {
                $this->cacheManager->data()->set(self::class, $key, $fullPath, $cachePersist);
                return $fullPath;
            }
        }

        if ($throwError) {
            throw new \RuntimeException(
                "File '{$fileName}' not found in: " . implode(', ', $paths) . " (namespace '{$ns}')."
            );
        }
        return null;
    }

    public function getFirstAssetMatch(
        string $fileName,
        string $nameSpace,
        bool $throwError = true,
        bool $cachePersist = true
    ): ?string {
        return $this->findFirstMatch(
            fileName: $fileName,
            nameSpace: $nameSpace,
            resourceType: 'assetPaths',
            throwError: $throwError,
            cachePersist: $cachePersist
        );
    }

    public function getFirstSourceMatch(
        string $fileName,
        string $nameSpace,
        bool $throwError = true,
        bool $cachePersist = true
    ): ?string {
        $cfg = $this->resolveConfig();

        return $this->findFirstMatch(
            fileName: $fileName,
            nameSpace: $nameSpace,
            resourceType: 'sourcePaths',
            subDir: $cfg->getResourceDir()['sourceDir'],
            throwError: $throwError,
            cachePersist: $cachePersist
        );
    }

    public function getFirstTplMatch(
        string $fileName,
        string $nameSpace,
        bool $throwError = true,
        bool $cachePersist = true
    ): ?string {
        $cfg = $this->resolveConfig();

        return $this->findFirstMatch(
            fileName: $fileName,
            nameSpace: $nameSpace,
            resourceType: 'sourcePaths',
            subDir: $cfg->getResourceDir()['tplDir'],
            throwError: $throwError,
            cachePersist: $cachePersist
        );
    }

    public function getBasePaths(string $ns, string $resourceType): array
    {
        $ns = Naming::toNamespaceString($ns);
        return $this->resolveConfig()->getNamespacePaths()[$ns][$resourceType] ?? [];
    }

    public function getAbsPath(string $subDir, bool $mkdir = false): string
    {
        $absPath = ABS_BASE_PATH . '/' . ltrim($subDir, '/');
        if (!is_dir($absPath) && $mkdir && !mkdir($absPath, 0755, true) && !is_dir($absPath)) {
            throw new \RuntimeException(sprintf('Could not create directory %s', $absPath));
        }
        return $absPath;
    }

    public function getAllNamespaces(): array
    {
        return $this->resolveConfig()->getNamespacePaths();
    }
}
