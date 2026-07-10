<?php

namespace Z77\Core\Libraries;

use Z77\Core\Config\Config;

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

        $firstMatchConfig = ABS_BASE_PATH.'/'.$configName.'.inc.php';
        if (!file_exists($firstMatchConfig)) {
            if ($throwError) {
                throw new \RuntimeException(sprintf(
                    'Config "%s" not found at base path: %s',
                    $configName,
                    ABS_BASE_PATH
                ));
            }
            $firstMatchConfig = null;
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
