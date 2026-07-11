<?php

namespace Z77\Core\Installer;

use Composer\Script\Event;
use Composer\Composer;
use Composer\IO\IOInterface;
use Z77\Shared\Auth\PasswordPolicy;
use Z77\Shared\Auth\PasswordTier;

/**
 * Composer post-install / post-update script.
 *
 * Entry point: Install::run() — registered in skeleton/composer.json.
 * Reads project configuration from the extra section of composer.json,
 * copies public entry-point files, creates the directory structure,
 * writes the three runtime config files, and seeds missing data files.
 */
class Install
{
    private const SOURCE_DIR            = 'src';
    private const BOOTSTRAP_CONFIG      = 'bootstrap';
    private const MODULE_MANAGER_CONFIG = 'moduleManager';
    private const AUTH_CONFIG           = 'auth';
    private const I18N_CONFIG           = 'i18n';
    private const FILE_FINDER_CONFIG    = 'fileFinder.inc.php';

    private const AUTH_DIR              = 'data/framework/auth';
    private const LOGIN_USERS_FILE      = 'loginUsers.json';
    private const SETUP_TOKEN_FILE      = 'SETUP_TOKEN';
    private const ADMIN_USERNAME        = 'admin';
    private const BCRYPT_COST           = 12;

    // Header policy notes written into generated config files so the developer
    // knows whether a file may be edited by hand (see docs/topics/installer.md).
    private const NOTE_REGENERATE =
          "//\n"
        . "// DO NOT EDIT — regenerated on every `composer install` / `composer update`.\n"
        . "// Manual changes are lost. Configure via composer.json (extra / autoload),\n"
        . "// then re-run the install.\n";

    private const NOTE_SEED_ONCE =
          "//\n"
        . "// Seed-once — written only when absent; the installer NEVER overwrites it.\n"
        . "// Safe to edit by hand: this is where you adapt the project's settings.\n";

    private IOInterface $io;
    private Composer    $composer;
    private string      $baseDir;
    private string      $vendorBaseName;
    private string      $dateString     = '';

    private array  $bootstrapConfig     = [];
    private array  $moduleManagerConfig = [];
    private array  $authConfig          = [];
    private array  $i18nConfig          = [];
    private string $frameworkPrefix     = '';
    private string $modulePrefix        = '';
    private array  $additionalPsr4Paths = [];
    private array  $configPaths         = [];
    private array  $publicAssetPaths    = [];
    private array  $z77Modules          = [];

    // -------------------------------------------------------------------------
    // Composer entry point
    // -------------------------------------------------------------------------

    public static function run(Event $event): void
    {
        (new self($event))->execute();
    }

    private function __construct(Event $event)
    {
        $this->io             = $event->getIO();
        $this->composer       = $event->getComposer();
        $vendorDir            = $this->composer->getConfig()->get('vendor-dir');
        $this->baseDir        = dirname($vendorDir);
        $this->vendorBaseName = basename($vendorDir);
    }

    private function execute(): void
    {
        $config = $this->loadConfig();

        $this->frameworkPrefix = $this->moduleManagerConfig['frameworkPrefix']
            ?? throw new \RuntimeException(
                'Missing required config: core-module-manager.frameworkPrefix'
            );

        $this->modulePrefix = $this->moduleManagerConfig['modulePrefix']
            ?? throw new \RuntimeException(
                'Missing required config: core-module-manager.modulePrefix'
            );

        $tz               = $this->bootstrapConfig['timeZone'] ?? 'Europe/Zurich';
        $this->dateString = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d H:i:s');

        if (!empty($config)) {
            $this->additionalPsr4Paths = $this->composer->getPackage()->getAutoload()['psr-4'] ?? [];
            $this->buildPaths();

            $publicDir = $this->bootstrapConfig['htmlRoot'];
            $sourceDir = __DIR__ . '/../../' . $publicDir;
            $targetDir = $this->trailingSlash($this->baseDir) . $publicDir;

            $this->copyFiles($sourceDir, $targetDir);
            $this->createDirectories($config['directories'] ?? []);
        }

        $this->writeBootstrapConfig();
        $this->writeModuleManagerConfig();
        $this->writeAuthConfig();
        $this->writeI18nConfig();
        $this->writeFileFinderConfig();
        $this->writeDataFiles();
        $this->provisionAdmin();
        $this->writeDebugFlag();

        if (!empty($config)) {
            $this->io->write('✓ Z77 Core installation complete');
        } else {
            $this->io->write('Z77 composer.json extra was empty — only default config written.');
        }
    }

    // -------------------------------------------------------------------------
    // Path building
    // -------------------------------------------------------------------------

    /**
     * Builds $this->configPaths (used for fileFinder.inc.php) and
     * $this->publicAssetPaths (used for public asset directory creation).
     *
     * Override paths from the project's autoload.psr-4 come first;
     * vendor paths from installed packages come second — this is what
     * implements the CE (Customer Extension) override lookup order.
     */
    private function buildPaths(): void
    {
        $configPaths      = [];
        $publicAssetPaths = [];

        $publicDir   = $this->bootstrapConfig['htmlRoot'];
        $overrideDir = $this->bootstrapConfig['overrideDir'];
        $assetDir    = $this->bootstrapConfig['assetDir'];
        $moduleDir   = $this->bootstrapConfig['moduleDir'];
        $fwDir       = strtolower($this->frameworkPrefix);

        // Override paths from project autoload.psr-4
        foreach ($this->additionalPsr4Paths as $namespace => $paths) {
            $paths = (array) $paths;

            $configPaths[$namespace]['sourcePaths'] = array_map(
                fn($p) => "\$baseDir.'" . $this->stripSrc($p) . "'",
                $paths
            );

            $assetSuffixes = array_map(
                fn($p) => $this->deriveAssetSuffix($p, $overrideDir, $moduleDir, $fwDir),
                $paths
            );

            $configPaths[$namespace]['assetPaths'] = array_map(
                fn($s) => "\$baseDir.'{$publicDir}/{$assetDir}/{$s}'",
                $assetSuffixes
            );

            $publicAssetPaths[$namespace]['public'] = $assetSuffixes;
        }

        // Vendor paths from installed packages
        foreach ($this->getInstalledPackages() as $package) {
            $relPath = $package->getName();
            $psr4    = $package->getAutoload()['psr-4'] ?? [];

            foreach ($psr4 as $namespace => $path) {
                if (!str_starts_with($namespace, $this->frameworkPrefix)) {
                    continue;
                }

                $paths         = (array) $path;
                $sourcePaths   = [];
                $assetSuffixes = [];

                foreach ($paths as $p) {
                    // stripSrc('src/') === '' (package root) vs stripSrc('shared/src/') === 'shared'
                    // (nested psr-4 root, e.g. z77/kernel exposing Core/Shared/Persistence): join with a
                    // slash so the second case yields 'z77/kernel/shared', not 'z77/kernelshared'.
                    $stripped        = $this->stripSrc($p);
                    $rel             = $stripped === '' ? $relPath : $relPath . '/' . ltrim($stripped, '/');
                    $sourcePaths[]   = "\$vendorDir.'{$rel}'";
                    $assetSuffixes[] = "vendor/{$rel}";
                }

                $assetPaths = array_map(
                    fn($s) => "\$baseDir.'{$publicDir}/{$assetDir}/{$s}'",
                    $assetSuffixes
                );

                if (isset($configPaths[$namespace])) {
                    $configPaths[$namespace]['sourcePaths'] = array_merge(
                        $configPaths[$namespace]['sourcePaths'],
                        $sourcePaths
                    );
                    $configPaths[$namespace]['assetPaths'] = array_merge(
                        $configPaths[$namespace]['assetPaths'],
                        $assetPaths
                    );
                } else {
                    $configPaths[$namespace]['sourcePaths'] = $sourcePaths;
                    $configPaths[$namespace]['assetPaths']  = $assetPaths;
                }

                $publicAssetPaths[$namespace]['vendor'] = $assetSuffixes;
            }
        }

        $this->configPaths      = $configPaths;
        $this->publicAssetPaths = $publicAssetPaths;
    }

    /**
     * Strips the framework/module/override directory segments from an override
     * path to derive the asset suffix used in public asset path construction.
     */
    private function deriveAssetSuffix(string $path, string $overrideDir, string $moduleDir, string $fwDir): string
    {
        $path = $this->stripSrc($path);
        $path = str_replace([$overrideDir, $moduleDir, $fwDir], '', $path);
        return trim(preg_replace('#/+#', '/', $path), '/');
    }

    private function getInstalledPackages(): array
    {
        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $unique   = [];
        foreach ($packages as $p) {
            $unique[$p->getName()] = $p;
        }
        return array_values($unique);
    }

    // -------------------------------------------------------------------------
    // File copying
    // -------------------------------------------------------------------------

    private function copyFiles(string $source, string $target): void
    {
        $this->io->write("Copying files from {$source}");

        if (!is_dir($source)) {
            throw new \RuntimeException("Source directory not found: {$source}");
        }

        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException("Failed to create target directory: {$target}");
        }

        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $this->trailingSlash($source) . $item;
            $dst = $this->trailingSlash($target) . $item;

            if (is_dir($src)) {
                $this->copyFiles($src, $dst);
                continue;
            }

            if (file_exists($dst) && !$this->shouldOverwrite($dst)) {
                $this->io->write('   Skipped: ' . basename($dst));
                continue;
            }

            if (!copy($src, $dst)) {
                throw new \RuntimeException("Failed to copy file: {$src} → {$dst}");
            }

            $this->io->write('   Copied: ' . basename($dst));
        }
    }

    /**
     * debug=true  → always overwrite (development: keep public/ files in sync)
     * debug=false → ask if interactive, skip if non-interactive (CI/production)
     */
    private function shouldOverwrite(string $path): bool
    {
        if ($this->bootstrapConfig['debug'] ?? false) {
            return true;
        }
        if ($this->io->isInteractive()) {
            $answer = $this->io->ask("File '" . basename($path) . "' exists. Overwrite? [y/N]: ");
            return strtolower((string) $answer) === 'y';
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Directory creation
    // -------------------------------------------------------------------------

    private function createDirectories(array $config): void
    {
        if (empty($config)) {
            return;
        }

        $this->io->write('Creating Z77 Framework Directories');

        $replacements = $this->buildReplacements();

        $this->createOverrideDirs();
        $this->createModuleTree($config['moduleTree'] ?? [], $replacements);
        $this->createPublicAssets($config['publicAssetTree'] ?? [], $replacements);
        $this->createLogDirs($config['logs'] ?? [], $replacements);
    }

    private function createOverrideDirs(): void
    {
        foreach ($this->additionalPsr4Paths as $paths) {
            $this->mkDirs((array) $paths, []);
        }
    }

    private function createModuleTree(array $tree, array $replacements): void
    {
        if (empty($tree)) {
            $this->io->write('No moduleTree config found — skipping.');
            return;
        }

        foreach ($this->resolveModules() as $module => $paths) {
            $this->io->write("Creating module tree for {$module}");
            $r               = $replacements;
            $r['<*module*>'] = $module;
            $this->mkDirs($tree, $r);
        }
    }

    /**
     * Installs public assets for every framework package that ships a `res/assets/`
     * directory in vendor — modules (e.g. `Z77\Module\Frontend`) AND shared/utility
     * packages (e.g. `Z77\Shared`). The asset directory name under `public/{assetDir}/`
     * is derived from the namespace via `deriveAssetDirName()`.
     *
     * For each qualifying namespace:
     *   1. Create the `publicAssetTree` subdirectories with `<*module*>` replaced by
     *      the derived asset dir name.
     *   2. Copy `vendor/{package}/res/assets/` recursively into
     *      `public/{assetDir}/{name}/`.
     *
     * Packages without a `res/assets/` directory are silently skipped (so adding
     * assets to any future framework package needs no installer changes).
     */
    private function createPublicAssets(array $tree, array $replacements): void
    {
        if (empty($tree)) {
            $this->io->write('No publicAssetTree config found — skipping.');
            return;
        }

        $publicDir = $this->bootstrapConfig['htmlRoot'];
        $assetDir  = $this->bootstrapConfig['assetDir'];

        foreach ($this->publicAssetPaths as $namespace => $types) {
            if (!str_starts_with($namespace, $this->frameworkPrefix)) {
                continue;
            }

            $vendorPaths = $types['vendor'] ?? [];
            if (empty($vendorPaths)) {
                continue;
            }

            $existingSources = [];
            foreach ($vendorPaths as $vendorPath) {
                $source = $this->trailingSlash($this->baseDir) . "{$vendorPath}/res/assets";
                if (is_dir($source)) {
                    $existingSources[] = $source;
                }
            }
            if (empty($existingSources)) {
                continue;
            }

            $assetName = $this->deriveAssetDirName($namespace);
            if ($assetName === '') {
                continue;
            }

            $this->io->write("Installing public assets for {$namespace} → {$publicDir}/{$assetDir}/{$assetName}");

            $r               = $replacements;
            $r['<*module*>'] = $assetName;
            $this->mkDirs($tree, $r);

            $target = $this->trailingSlash($this->baseDir)
                    . $this->trailingSlash($publicDir) . "{$assetDir}/{$assetName}";

            foreach ($existingSources as $source) {
                $this->copyFiles($source, $target);
            }
        }
    }

    /**
     * Derives the public asset directory name from a namespace.
     *
     *   Z77\Module\Frontend  → 'frontend'  (third segment, for module namespaces)
     *   Z77\Module\Backend   → 'backend'
     *   Z77\Shared           → 'shared'    (second segment, for non-module namespaces)
     *   Z77\Core             → 'core'
     *
     * Returns '' if the namespace has fewer than two segments (cannot derive a name).
     */
    private function deriveAssetDirName(string $namespace): string
    {
        $parts = array_values(array_filter(explode('\\', $namespace)));

        if (count($parts) >= 3 && ($parts[1] ?? '') === $this->modulePrefix) {
            return strtolower($parts[2]);
        }
        if (count($parts) >= 2) {
            return strtolower($parts[1]);
        }
        return '';
    }

    private function createLogDirs(array|string $logs, array $replacements): void
    {
        $logs = (array) $logs;
        if (empty($logs)) {
            $this->io->write('No logs config found — skipping.');
            return;
        }

        $this->io->write('Creating log directories');
        $this->mkDirs($logs, $replacements);
    }

    private function buildReplacements(): array
    {
        return [
            '<htmlRoot>'      => $this->bootstrapConfig['htmlRoot'],
            '<*overrideDir*>' => $this->bootstrapConfig['overrideDir'] . '/' . strtolower($this->frameworkPrefix),
            '<moduleDir>'     => $this->bootstrapConfig['moduleDir'],
            '<assetDir>'      => $this->bootstrapConfig['assetDir'],
            '<tplDir>'        => $this->bootstrapConfig['tplDir'],
        ];
    }

    private function resolveModules(): array
    {
        if (!empty($this->z77Modules)) {
            return $this->z77Modules;
        }

        $moduleNsPrefix = rtrim($this->frameworkPrefix, '\\') . '\\'
                        . rtrim($this->modulePrefix, '\\') . '\\';
        $modules        = [];

        foreach ($this->additionalPsr4Paths as $namespace => $paths) {
            if (!str_starts_with($namespace, $moduleNsPrefix)) {
                continue;
            }

            $parts = array_values(array_filter(explode('\\', $namespace)));
            if (isset($parts[2])) {
                $modules[strtolower($parts[2])] = (array) $paths;
            }
        }

        return $this->z77Modules = $modules;
    }

    private function mkDirs(array $dirs, array $replacements): void
    {
        foreach ($dirs as $path) {
            if (is_array($path)) {
                $this->mkDirs($path, $replacements);
                continue;
            }

            $realPath = $this->trailingSlash($this->baseDir)
                      . str_replace(array_keys($replacements), array_values($replacements), $path);

            if (is_dir($realPath)) {
                continue;
            }

            if (!mkdir($realPath, 0775, true) && !is_dir($realPath)) {
                throw new \RuntimeException("Failed to create directory: {$realPath}");
            }

            $this->io->write("   Created: {$realPath}");
        }
    }

    // -------------------------------------------------------------------------
    // Config writing
    // -------------------------------------------------------------------------

    private function writeBootstrapConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::BOOTSTRAP_CONFIG . '.inc.php';

        $this->io->write("Write Bootstrap config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_REGENERATE);
        $content .= "return [\n";
        foreach ($this->bootstrapConfig as $key => $value) {
            $content .= "    '{$key}' => " . $this->exportPhpValue($value) . ",\n";
        }
        $content .= "];\n";

        $this->writeFile($dir, $name, $content);
    }

    private function writeModuleManagerConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::MODULE_MANAGER_CONFIG . '.inc.php';

        $this->io->write("Write ModuleManager config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_REGENERATE);
        $content .= "return [\n";

        foreach ($this->moduleManagerConfig as $key => $value) {
            if ($key === 'modules') {
                continue;
            }
            $content .= "    '{$key}' => " . $this->exportPhpValue($value) . ",\n";
        }

        $content .= "    'modules' => [\n";
        foreach (array_keys($this->resolveModules()) as $module) {
            $content .= "        '{$module}' => [],\n";
        }
        $content .= "    ],\n];\n";

        $this->writeFile($dir, $name, $content);
    }

    /**
     * Seed-once (INST-CONFIG-001): auth.inc.php holds installation-wide auth policy
     * (e.g. passwordTier) that the developer adapts after install — same class of
     * user-adjustable config as i18n.inc.php. Once it exists the installer never
     * overwrites it, so an update cannot clobber the project's auth settings.
     * Decoupled from the `debug` flag (a caching/dev switch, not an overwrite policy).
     */
    private function writeAuthConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::AUTH_CONFIG . '.inc.php';

        $target = $this->trailingSlash($dir) . $name;
        if (file_exists($target)) {
            $this->io->write("Skipped: {$name} already exists (seed-once, not overwritten)");
            return;
        }

        $this->io->write("Write Auth config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_SEED_ONCE);
        $content .= "return [\n";
        foreach ($this->authConfig as $key => $value) {
            $content .= "    '{$key}' => " . $this->exportPhpValue($value) . ",\n";
        }
        $content .= "];\n";

        $this->writeFile($dir, $name, $content);
    }

    /**
     * Seed-once (INST-CONFIG-001): i18n.inc.php defines the project's languages,
     * which the developer adapts after install. Unlike the other config files it is
     * NEVER regenerated — once it exists the installer leaves it untouched, so an
     * update cannot clobber the project's language configuration. Deliberately
     * decoupled from the `debug` flag (which is a caching/dev switch, not an
     * overwrite policy).
     */
    private function writeI18nConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::I18N_CONFIG . '.inc.php';

        $target = $this->trailingSlash($dir) . $name;
        if (file_exists($target)) {
            $this->io->write("Skipped: {$name} already exists (seed-once, not overwritten)");
            return;
        }

        $this->io->write("Write i18n config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_SEED_ONCE);
        $content .= "return [\n";
        foreach ($this->i18nConfig as $key => $value) {
            $content .= "    '{$key}' => " . $this->exportPhpValue($value) . ",\n";
        }
        $content .= "];\n";

        $this->writeFile($dir, $name, $content);
    }

    private function writeFileFinderConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::FILE_FINDER_CONFIG;

        $this->io->write("Write FileFinder config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_REGENERATE);
        $content .= "\$vendorDir = dirname(__DIR__).'/{$this->vendorBaseName}/';\n";
        $content .= "\$baseDir = dirname(__DIR__).'/';\n";
        $content .= "return [\n";
        $content .= "    'resourceDir' => [\n";
        $content .= "        'sourceDir' => '" . self::SOURCE_DIR . "',\n";
        $content .= "        'tplDir'    => '{$this->bootstrapConfig['tplDir']}',\n";
        $content .= "    ],\n";
        $content .= "    'namespaces' => [\n";

        foreach ($this->configPaths as $namespace => $targets) {
            $content .= "        '" . addslashes($namespace) . "' => [\n";
            foreach ($targets as $key => $paths) {
                $dirs     = '[' . implode(', ', $paths) . ']';
                $content .= "            '" . addslashes($key) . "' => {$dirs},\n";
            }
            $content .= "        ],\n";
        }

        $content .= "    ],\n];\n";

        $this->writeFile($dir, $name, $content);
    }
    /**
     * Exports a value as PHP source code using `[]` short-array syntax instead of array().
     */
    private function exportPhpValue(mixed $value, int $indent = 1): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }

        $spaces = str_repeat('    ', $indent);
        $next   = str_repeat('    ', $indent + 1);

        $lines = ["["];

        foreach ($value as $key => $item) {
            $lines[] = sprintf(
                "%s%s => %s,",
                $next,
                var_export($key, true),
                $this->exportPhpValue($item, $indent + 1)
            );
        }

        $lines[] = $spaces . ']';

        return implode("\n", $lines);
    }
    // -------------------------------------------------------------------------
    // Data files
    // -------------------------------------------------------------------------

    /**
     * Seeds runtime data files from their defaults. Generic by convention: every
     * `*.default.json` anywhere under the package `data/` directory is deployed to
     * the same relative path with the `.default` marker stripped, e.g.
     *   `framework/routing/navigation.default.json` → `data/framework/routing/navigation.json`
     *   `content/home.de.default.json`              → `data/content/home.de.json`
     * `writeDataFile` skips targets that already exist, so existing runtime data
     * is preserved. Adding a new seeded entity needs no installer change — just drop
     * its `*.default.json` under `data/` (framework scaffolding or starter content).
     */
    private function writeDataFiles(): void
    {
        $base = realpath(__DIR__ . '/../../data');
        if ($base === false) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.default.json')) {
                continue;
            }

            $relPath  = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $subDir   = trim(dirname($relPath), '.\\/');
            $relDir   = 'data' . ($subDir !== '' ? '/' . $subDir : '');
            $fileName = substr($file->getFilename(), 0, -strlen('.default.json')) . '.json';

            $this->writeDataFile($relDir, $fileName, $file->getPathname());
        }
    }

    private function writeDebugFlag(): void
    {
        $flag  = $this->trailingSlash($this->baseDir) . 'data/framework/debug.flag';
        $debug = $this->bootstrapConfig['debug'] ?? false;

        if ($debug) {
            if (!file_exists($flag)) {
                touch($flag);
                $this->io->write('   Created: debug.flag (debug=true)');
            }
        } else {
            if (file_exists($flag)) {
                unlink($flag);
                $this->io->write('   Removed: debug.flag (debug=false)');
            }
        }
    }

    private function writeDataFile(string $relDir, string $fileName, string $sourcePath): void
    {
        $dir    = $this->trailingSlash($this->baseDir) . $relDir;
        $target = $this->trailingSlash($dir) . $fileName;

        if (file_exists($target)) {
            $this->io->write("Skipped: {$fileName} already exists");
            return;
        }

        if (!is_readable($sourcePath)) {
            throw new \RuntimeException("Data source file not found: {$sourcePath}");
        }

        $content = file_get_contents($sourcePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read data source: {$sourcePath}");
        }

        $this->io->write("Write data file → {$target}");
        $this->writeFile($dir, $fileName, $content);
    }

    // -------------------------------------------------------------------------
    // Admin provisioning (secure-by-default — see docs/topics/security.md)
    // -------------------------------------------------------------------------

    /**
     * Provisions the first account — the SUPER_USER (ADR-021) — WITHOUT ever shipping a
     * default credential (the framework is open source — anything seeded would be
     * public). Runs once: if `loginUsers.json` already exists it is never touched
     * (re-install / update). The username stays `admin` (cosmetic); the ROLE is
     * `superUser` — `admin` (level 80) is a normal, grant-managed role.
     *
     *   interactive     → create the account now, prompting for a password (hidden).
     *   non-interactive → defer: write a one-time `SETUP_TOKEN` under `data/` so a
     *                     token-gated `/setup` can create the account on first run.
     *
     * The environment (`debug` flag, host) is deliberately NOT a factor here —
     * security is by default, not by environment detection.
     */
    private function provisionAdmin(): void
    {
        $authDir   = $this->trailingSlash($this->baseDir) . self::AUTH_DIR;
        $usersFile = $this->trailingSlash($authDir) . self::LOGIN_USERS_FILE;

        if (file_exists($usersFile)) {
            return;
        }

        if ($this->io->isInteractive()) {
            $this->provisionAdminInteractive($authDir, $usersFile);
        } else {
            $this->provisionSetupToken($authDir);
        }
    }

    /**
     * Creates the admin from a hidden password prompt. The password is evaluated
     * against {@see PasswordPolicy} (length + blocklist, never composition): a weak
     * one is accepted but the resulting `password_weak` flag drives the every-login
     * nag. The user store is written as plain JSON matching the {@see LoginUser}
     * shape (snake_case) — no DI / EntityManager boot needed at install time.
     */
    private function provisionAdminInteractive(string $authDir, string $usersFile): void
    {
        $username = self::ADMIN_USERNAME;
        $tier     = PasswordTier::fromName($this->authConfig['passwordTier'] ?? null);

        $this->io->write('');
        $this->io->write('Z77 — create the admin account');
        $this->io->write("   Username: {$username}");

        // veryStrong is the only tier that rejects a weak password — re-prompt
        // until it passes. All other tiers accept it (the every-login nag handles it).
        do {
            $password = $this->promptNewPassword();
            $eval     = PasswordPolicy::evaluate($password, [$username], $tier);

            if ($eval['weak'] && $tier->blocksWeak()) {
                $this->io->writeError('   Password does not meet the required strength (' . $tier->value . '):');
                foreach ($eval['reasons'] as $reason) {
                    $this->io->writeError('       – ' . $reason);
                }
                continue;
            }
            break;
        } while (true);

        if ($eval['weak']) {
            $this->io->write('   ⚠ Weak password accepted — you will be reminded at every login:');
            foreach ($eval['reasons'] as $reason) {
                $this->io->write('       – ' . $reason);
            }
        }

        // The first account is the SUPER_USER (ADR-021): the DMS/installation governor.
        // `admin` (level 80) is a normal, grant-managed role — never provisioned here.
        $admin = [[
            'id'            => 1,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
            'roles'         => ['superUser'],
            'sort_key'      => 0,
            'password_weak' => $eval['weak'],
        ]];

        $json = json_encode($admin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $this->writeFile($authDir, self::LOGIN_USERS_FILE, $json);
        $this->io->write('   ✓ Admin account created → ' . self::AUTH_DIR . '/' . self::LOGIN_USERS_FILE);
    }

    /** Asks for a password twice (hidden) until non-empty and both entries match. */
    private function promptNewPassword(): string
    {
        while (true) {
            $password = (string) $this->io->askAndHideAnswer('   Choose a password: ');
            if ($password === '') {
                $this->io->writeError('   Password must not be empty.');
                continue;
            }
            $confirm = (string) $this->io->askAndHideAnswer('   Repeat password:   ');
            if ($password !== $confirm) {
                $this->io->writeError('   Passwords do not match — try again.');
                continue;
            }
            return $password;
        }
    }

    /**
     * Non-interactive install: defer admin creation. Writes a one-time, random
     * setup token under `data/` (filesystem-only — NEVER `public/`, which would be
     * web-reachable and re-open the public first-in-first-win race). A token-gated
     * `/setup` then creates the admin and deletes the token (Phase 5).
     */
    private function provisionSetupToken(string $authDir): void
    {
        $tokenFile = $this->trailingSlash($authDir) . self::SETUP_TOKEN_FILE;
        if (file_exists($tokenFile)) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $this->writeFile($authDir, self::SETUP_TOKEN_FILE, $token . "\n");

        $this->io->write('');
        $this->io->write('Z77 — non-interactive install: no admin account was created.');
        $this->io->write('A one-time setup token was written to:');
        $this->io->write('    ' . self::AUTH_DIR . '/' . self::SETUP_TOKEN_FILE);
        $this->io->write('Read it from the server filesystem, then open /backend/system/setup/setup to create the admin.');
    }

    // -------------------------------------------------------------------------
    // Low-level helpers
    // -------------------------------------------------------------------------

    private function loadConfig(): array
    {
        $config = $this->composer->getPackage()->getExtra() ?? [];
        $dir    = __DIR__ . '/../Config/';

        $defaults              = require $dir . self::BOOTSTRAP_CONFIG . '.default.inc.php';
        $this->bootstrapConfig = array_merge($defaults, $config['core-bootstrap'] ?? []);

        $defaults                    = require $dir . self::MODULE_MANAGER_CONFIG . '.default.inc.php';
        $this->moduleManagerConfig   = array_merge($defaults, $config['core-module-manager'] ?? []);

        $defaults              = require $dir . self::AUTH_CONFIG . '.default.inc.php';
        $this->authConfig      = array_merge($defaults, $config['core-auth'] ?? []);

        $defaults              = require $dir . self::I18N_CONFIG . '.default.inc.php';
        $this->i18nConfig      = array_merge($defaults, $config['core-i18n'] ?? []);

        return $config;
    }

    private function writeFile(string $dir, string $fileName, string $content): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        $path = $this->trailingSlash($dir) . $fileName;
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }
    }

    private function trailingSlash(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/') . '/';
    }

    private function stripSrc(string $path): string
    {
        $path = rtrim($path, '/');
        if (str_ends_with($path, '/' . self::SOURCE_DIR)) {
            return substr($path, 0, -(strlen(self::SOURCE_DIR) + 1));
        }
        return ($path === self::SOURCE_DIR) ? '' : $path;
    }

    private function configDir(): string
    {
        return $this->trailingSlash($this->baseDir) . 'config';
    }

    private function header(string $name, string $policyNote = ''): string
    {
        $header = "<?php\n// Auto-generated by Z77 Core Installer\n// {$name} at: {$this->dateString}\n";
        return $header . $policyNote;
    }
}
