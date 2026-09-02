<?php

namespace Z77\Core\Libraries;

use Z77\Core\Config\Config,
    Z77\Shared\Libraries\ConfigLocator
;

class ConfigManager
{

    private FileFinder $fileFinder;
    private CacheManager $cacheManager;

    public function __construct(
        FileFinder $fileFinder,
        CacheManager $cacheManager
    ) {
        $this->fileFinder = $fileFinder;
        $this->cacheManager = $cacheManager;
    }

    public function getArrayConfig(
        string $configName,
        ?string $nameSpace = 'ROOT',
        bool $throwError = true,
        bool $mutable = false,
        bool $cachePersist = true
    ): Config {
        $key = [$configName, $nameSpace, (int)$mutable];
        $config = $this->cacheManager->data()->get(self::class, $key);
        if ($config) {
            return $config;
        }

        $firstMatchConfig = $this->fileFinder->getFirstSourceMatch(
            fileName: $configName.'.inc.php',
            nameSpace: $nameSpace,
            throwError: $throwError,
            cachePersist: $cachePersist
        );

        return $this->loadAndCache(
            firstMatchConfig: $firstMatchConfig,
            configName: $configName,
            nameSpace: $nameSpace,
            key: $key,
            mutable: $mutable,
            cachePersist: $cachePersist,
            throwError: $throwError
        );
    }

    public function getBaseConfig(
        string $configName,
        bool $throwError = true,
        bool $mutable = false,
        bool $cachePersist = true
    ): Config {
        $key = ['__base__', $configName, (int)$mutable];
        $config = $this->cacheManager->data()->get(self::class, $key);
        if ($config) {
            return $config;
        }

        // Split layout (ADR-036): a `config/X` name resolves through
        // config/vendor/ → config/client/ → config/ (legacy flat fallback).
        if (str_starts_with($configName, 'config/')) {
            $firstMatchConfig = ConfigLocator::path(substr($configName, 7) . '.inc.php');
        } else {
            $firstMatchConfig = ABS_BASE_PATH . '/' . $configName . '.inc.php';
            $firstMatchConfig = file_exists($firstMatchConfig) ? $firstMatchConfig : null;
        }

        if ($firstMatchConfig === null && $throwError) {
            throw new \RuntimeException(sprintf(
                'Config "%s" not found at base path: %s (searched config/vendor, config/client, config)',
                $configName,
                ABS_BASE_PATH
            ));
        }

        return $this->loadAndCache(
            firstMatchConfig: $firstMatchConfig,
            configName: $configName,
            nameSpace: null,
            key: $key,
            mutable: $mutable,
            cachePersist: $cachePersist,
            throwError: $throwError
        );
    }

    private function loadAndCache(
        ?string $firstMatchConfig,
        string $configName,
        ?string $nameSpace,
        array $key,
        bool $mutable,
        bool $cachePersist,
        bool $throwError
    ): Config {
        if (!$firstMatchConfig && $throwError) {
            throw new \RuntimeException(
                "Config {$configName} in {$nameSpace} does not exist."
            );
        }

        $configArray = $firstMatchConfig ? require $firstMatchConfig : [];

        $config = new Config($configArray, $mutable);

        $this->cacheManager->data()->set(
            className: self::class,
            components: $key,
            value: $config,
            cachePersist: $cachePersist
        );

        return $config;
    }
}
