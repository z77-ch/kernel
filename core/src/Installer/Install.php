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
    private const BACKUP_CONFIG         = 'backup';
    private const MAIL_CONFIG           = 'mail';
    private const FILE_FINDER_CONFIG    = 'fileFinder.inc.php';

    private const AUTH_DIR              = 'data/framework/auth';
    private const LOGIN_USERS_FILE      = 'loginUsers.json';
    private const SETUP_TOKEN_FILE      = 'SETUP_TOKEN';
    private const ADMIN_USERNAME        = 'admin';
    private const BCRYPT_COST           = 12;

    private const DOCS_PACKAGE          = 'z77/docs';
    private const CLAUDE_TEMPLATE       = __DIR__ . '/../../res/CLAUDE.project.md';
    private const CLAUDE_FILE           = 'CLAUDE.md';

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
    private array  $backupConfig        = [];
    private array  $mailConfig          = [];
    private string $frameworkPrefix     = '';
    private string $modulePrefix        = '';
    private array  $additionalPsr4Paths = [];
    private array  $configPaths         = [];
    private array  $publicAssetPaths    = [];
    private array  $z77Modules          = [];

    // Collected asset-drift entries (update only). Each entry is
    // ['display' => …, 'src' => absolute vendor path, 'dst' => absolute public path].
    // Rendered as one coloured notice at the end of execute(), then offered for an
    // opt-in per-file deploy (interactive only) — see renderAssetDriftNotice() /
    // promptAssetDeploy(). Never printed line-by-line mid-run.
    private array  $assetDriftChanged   = [];
    private array  $assetDriftAdded     = [];

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
            $targetDir = $this->trailingSlash($this->baseDir) . $publicDir;

            // public/ belongs to the developer (ADR-024). Seed the framework baseline on the
            // FIRST install only — once public/ exists the installer never touches it again.
            // New / updated framework assets then stay in vendor and it is the developer's job
            // (with Claude's help) to deploy them into public. No overwrite, no force command.
            $firstInstall = !is_dir($targetDir);

            if ($firstInstall) {
                $sourceDir = __DIR__ . '/../../' . $publicDir;
                $this->copyFiles($sourceDir, $targetDir);
            } else {
                $this->io->write(
                    'public/ exists — left untouched (developer-owned, ADR-024). '
                    . 'New framework assets stay in vendor; deploy them into public yourself.'
                );
                $this->reportAssetDrift();
            }

            $this->createDirectories($config['directories'] ?? [], $firstInstall);
        }

        $this->writeBootstrapConfig();
        $this->writeModuleManagerConfig();
        $this->writeAuthConfig();
        $this->writeI18nConfig();
        $this->writeBackupConfig();
        $this->writeMailConfig();
        $this->writeFileFinderConfig();
        $this->writeDataFiles();
        $this->provisionAdmin();
        $this->writeDebugFlag();
        $this->seedProjectClaudeMd();

        if (!empty($config)) {
            $this->io->write('✓ Z77 Core installation complete');
        } else {
            $this->io->write('Z77 composer.json extra was empty — only default config written.');
        }

        // Last thing shown, so the developer can't miss it: a single coloured notice
        // listing the framework assets that differ from public/ (ADR-025), followed by
        // an opt-in per-file deploy prompt (interactive only, default No — ADR-024 amend).
        $this->renderAssetDriftNotice();
        $this->promptAssetDeploy();

        // After everything else so the answer can trigger a nested `composer require`
        // without interleaving the install log.
        $this->offerDocsInstall();
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

                if (isset($configPaths[$namespace])) {
                    $configPaths[$namespace]['sourcePaths'] = array_merge(
                        $configPaths[$namespace]['sourcePaths'],
                        $sourcePaths
                    );
                    // assetPaths stays single-tier — the project tier set by the override loop.
                    // No public vendor tier for assets (ADR-024).
                } else {
                    // Framework namespace without a project override entry (rare): derive the
                    // single project-tier asset path from the namespace name.
                    $assetName = $this->deriveAssetDirName($namespace);
                    $configPaths[$namespace]['sourcePaths'] = $sourcePaths;
                    $configPaths[$namespace]['assetPaths']  = $assetName === ''
                        ? []
                        : ["\$baseDir.'{$publicDir}/{$assetDir}/{$assetName}'"];
                }

                // Source locator for createPublicAssets: vendor res/assets → project tier.
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

            // Never overwrite an existing file — public/ is developer-owned (ADR-024).
            // (On the first install the target is empty, so everything is copied.)
            if (file_exists($dst)) {
                $this->io->write('   Skipped: ' . basename($dst));
                continue;
            }

            if (!copy($src, $dst)) {
                throw new \RuntimeException("Failed to copy file: {$src} → {$dst}");
            }

            $this->io->write('   Copied: ' . basename($dst));
        }
    }

    // -------------------------------------------------------------------------
    // Directory creation
    // -------------------------------------------------------------------------

    private function createDirectories(array $config, bool $firstInstall): void
    {
        if (empty($config)) {
            return;
        }

        $this->io->write('Creating Z77 Framework Directories');

        $replacements = $this->buildReplacements();

        $this->createOverrideDirs();
        $this->createModuleTree($config['moduleTree'] ?? [], $replacements);
        // Public assets are seeded on the first install only — public/ is developer-owned
        // afterwards (ADR-024). createOverrideDirs / moduleTree / logs only ever mkdir missing
        // dirs (never overwrite content), so they stay unconditional.
        if ($firstInstall) {
            $this->createPublicAssets($config['publicAssetTree'] ?? [], $replacements);
        }
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
     * On an update (public/ present) the installer never writes into public/ (ADR-024).
     * Instead it reports — read-only — which framework assets in vendor differ from what
     * is deployed in public/, so the developer can decide what to adopt (ADR-025,
     * INST-ASSET-DIFF-001). It hashes each shipped `res/assets` file against its public
     * counterpart and COLLECTS the ones that are new or changed into $assetDriftChanged /
     * $assetDriftAdded. It does NOT print here — {@see renderAssetDriftNotice()} shows the
     * collected entries as one coloured notice at the very end of the run. It CANNOT tell a
     * framework change from a developer edit — it collects every file whose deployed copy
     * differs from the shipped one; the developer knows which they customized. Writes nothing.
     */
    private function reportAssetDrift(): void
    {
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

            $assetName = $this->deriveAssetDirName($namespace);
            if ($assetName === '') {
                continue;
            }

            $target = $this->trailingSlash($this->baseDir)
                    . $this->trailingSlash($publicDir) . "{$assetDir}/{$assetName}";

            foreach ($vendorPaths as $vendorPath) {
                $source = $this->trailingSlash($this->baseDir) . "{$vendorPath}/res/assets";
                if (is_dir($source)) {
                    $this->collectAssetDrift(
                        $source,
                        $target,
                        $assetName,
                        $this->assetDriftChanged,
                        $this->assetDriftAdded
                    );
                }
            }
        }
    }

    /**
     * Renders the collected asset drift (ADR-025) as ONE coloured notice at the end of
     * the run — a solid yellow block so it stands out from the plain install log. The
     * action line depends on the mode: interactive → "you'll be asked per file below";
     * non-interactive (CI / deploy) → "deploy yourself" (nothing is written there).
     * Prints nothing when public/ matches the shipped assets.
     */
    private function renderAssetDriftNotice(): void
    {
        if (empty($this->assetDriftChanged) && empty($this->assetDriftAdded)) {
            return;
        }

        $lines = [
            'Framework assets differ from your public/.',
            $this->io->isInteractive()
                ? 'You will be asked per file below whether to deploy it (default: No).'
                : 'Review and deploy the ones you want into public/ yourself:',
            '',
        ];
        foreach ($this->assetDriftAdded as $entry) {
            $lines[] = '  + new:     ' . $entry['display'];
        }
        foreach ($this->assetDriftChanged as $entry) {
            $lines[] = '  ~ changed: ' . $entry['display'];
        }

        // Pad every line to a uniform width so the background colour forms a solid block.
        $width = max(array_map('strlen', $lines)) + 2;

        $this->io->write('');
        foreach ($lines as $line) {
            $padded = ' ' . str_pad($line, $width);
            // <bg=yellow;fg=black> is a Symfony Console (Composer IO) inline style.
            $this->io->write("<bg=yellow;fg=black>{$padded}</>");
        }
        $this->io->write('');
    }

    /**
     * Opt-in, interactive-only deploy of drifted framework assets into public/ (ADR-024
     * amendment). Runs after the drift notice. NEVER runs non-interactively (CI / deploy):
     * there the notice stays a pure read-only report and public/ is never written. Every
     * prompt defaults to NO, so a blind Enter never overwrites anything.
     *
     *   + new     → copying is risk-free (the file is absent in public/): "Deploy? [y/N]".
     *   ~ changed → the deployed copy may be YOUR edit or a build artefact (compiled CSS/JS
     *               from override/scss). Overwriting it with the framework version can wipe
     *               your work — the exact INST-ASSET-002 footgun. Warn loudly, then ask.
     */
    private function promptAssetDeploy(): void
    {
        if (!$this->io->isInteractive()) {
            return;
        }
        if (empty($this->assetDriftChanged) && empty($this->assetDriftAdded)) {
            return;
        }

        $deployed = 0;

        foreach ($this->assetDriftAdded as $entry) {
            $this->io->write('');
            $this->io->write('+ new: ' . $entry['display']);
            if ($this->io->askConfirmation('   Deploy into public/? [y/N] ', false)) {
                $this->deployAsset($entry['src'], $entry['dst']);
                $this->io->write('   ✓ deployed');
                $deployed++;
            }
        }

        foreach ($this->assetDriftChanged as $entry) {
            $this->io->write('');
            $this->io->write('<bg=yellow;fg=black> ~ changed: ' . $entry['display'] . ' </>');
            $this->io->write('   ⚠ This may be YOUR own edit or a compiled build artefact (from override/scss).');
            $this->io->write('   ⚠ Overwriting replaces it with the framework version — your changes are lost.');
            if ($this->io->askConfirmation('   Overwrite public/ file? [y/N] ', false)) {
                $this->deployAsset($entry['src'], $entry['dst']);
                $this->io->write('   ✓ overwritten');
                $deployed++;
            }
        }

        $this->io->write('');
        $this->io->write($deployed > 0
            ? "Asset deploy: {$deployed} file(s) written to public/."
            : 'Asset deploy: nothing written — public/ unchanged.');
    }

    /**
     * Copies one drifted asset from vendor into public/ (opt-in, see promptAssetDeploy()).
     * Creates missing parent dirs and overwrites an existing target (intended for
     * ~ changed). Throws on failure — no silent errors.
     */
    private function deployAsset(string $src, string $dst): void
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
        if (!copy($src, $dst)) {
            throw new \RuntimeException("Failed to deploy asset: {$src} → {$dst}");
        }
    }

    /**
     * Recursively hashes every file under $source against its counterpart under $target
     * (same relative path). Fills $changed / $added with entries
     * ['display' => prefixed rel path, 'src' => abs vendor path, 'dst' => abs public path] —
     * src/dst let the opt-in deploy (promptAssetDeploy()) copy the file. Read-only — never
     * writes. (ADR-025)
     */
    private function collectAssetDrift(string $source, string $target, string $displayPrefix, array &$changed, array &$added): void
    {
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $this->trailingSlash($source) . $item;
            $dst = $this->trailingSlash($target) . $item;
            $rel = $displayPrefix . '/' . $item;

            if (is_dir($src)) {
                $this->collectAssetDrift($src, $dst, $rel, $changed, $added);
                continue;
            }

            if (!file_exists($dst)) {
                $added[] = ['display' => $rel, 'src' => $src, 'dst' => $dst];
                continue;
            }

            if (hash_file('sha1', $src) !== hash_file('sha1', $dst)) {
                $changed[] = ['display' => $rel, 'src' => $src, 'dst' => $dst];
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

    /**
     * Seed-once (INST-CONFIG-001): backup.inc.php holds the installation-wide
     * backup policy (retention, full-backup excludes, optional database block)
     * that the developer adapts after install — same class as auth/i18n. Once
     * it exists the installer never overwrites it. See docs/topics/backup.md.
     */
    private function writeBackupConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::BACKUP_CONFIG . '.inc.php';

        $target = $this->trailingSlash($dir) . $name;
        if (file_exists($target)) {
            $this->io->write("Skipped: {$name} already exists (seed-once, not overwritten)");
            return;
        }

        $this->io->write("Write Backup config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_SEED_ONCE);
        $content .= "return [\n";
        foreach ($this->backupConfig as $key => $value) {
            $content .= "    '{$key}' => " . $this->exportPhpValue($value) . ",\n";
        }
        $content .= "];\n";

        $this->writeFile($dir, $name, $content);
    }

    /**
     * Seed-once (INST-CONFIG-001): mail.inc.php holds transport + sender identity
     * for the Mailer/EmailService (enabled, transport mail|smtp, fromAddress …) —
     * same class as auth/i18n/backup. Once it exists the installer never
     * overwrites it. See docs/topics/mail.md.
     */
    private function writeMailConfig(): void
    {
        $dir  = $this->configDir();
        $name = self::MAIL_CONFIG . '.inc.php';

        $target = $this->trailingSlash($dir) . $name;
        if (file_exists($target)) {
            $this->io->write("Skipped: {$name} already exists (seed-once, not overwritten)");
            return;
        }

        $this->io->write("Write Mail config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_SEED_ONCE);
        $content .= "return [\n";
        foreach ($this->mailConfig as $key => $value) {
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
                if (!touch($flag)) {
                    throw new \RuntimeException("Failed to create debug flag: {$flag}");
                }
                $this->io->write('   Created: debug.flag (debug=true)');
            }
        } else {
            if (file_exists($flag)) {
                if (!unlink($flag)) {
                    throw new \RuntimeException("Failed to remove debug flag: {$flag}");
                }
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
    // AI docs + project CLAUDE.md (see docs/topics/installer.md)
    // -------------------------------------------------------------------------

    /**
     * Seed-once: writes the project-level CLAUDE.md from the template shipped with the
     * kernel. It points an AI coding assistant at `vendor/z77/docs` (topic trigger map,
     * read-first list) and carries the CE key rules in short form — the piece that makes
     * "open the project, ask the assistant, start working" actually happen. Once the
     * file exists it is never touched again: it belongs to the developer.
     */
    private function seedProjectClaudeMd(): void
    {
        $target = $this->trailingSlash($this->baseDir) . self::CLAUDE_FILE;
        if (file_exists($target)) {
            return;
        }

        if (!is_readable(self::CLAUDE_TEMPLATE)) {
            // Template missing would be a packaging defect — report, don't break installs.
            $this->io->writeError('   Skipped CLAUDE.md seed: template not found in kernel.');
            return;
        }

        $content = file_get_contents(self::CLAUDE_TEMPLATE);
        if ($content === false) {
            throw new \RuntimeException('Failed to read CLAUDE.md template: ' . self::CLAUDE_TEMPLATE);
        }

        $this->writeFile($this->baseDir, self::CLAUDE_FILE, $content);
        $this->io->write('   Created: CLAUDE.md (project context for AI assistants — seed-once, yours to edit)');
    }

    /**
     * Opt-in install of the AI-optimized framework documentation (`z77/docs`) as a
     * require-dev package. Asked once per project — never again as soon as the package
     * is installed or required. Default YES: unlike the overwrite prompts (default No),
     * saying yes only adds a dev dependency; nothing existing is touched.
     *
     *   interactive     → ask, then run a nested `composer require --dev z77/docs:^1.0`.
     *                     A failure is non-fatal (the install itself is already complete);
     *                     the manual command is printed instead.
     *   non-interactive → never ask, never require — print the manual command once.
     */
    private function offerDocsInstall(): void
    {
        if ($this->isDocsPresent()) {
            return;
        }

        if (!$this->io->isInteractive()) {
            $this->io->write('');
            $this->io->write('AI-optimized framework docs are not installed. Add them any time with:');
            $this->io->write('    composer require --dev ' . self::DOCS_PACKAGE);
            return;
        }

        $this->io->write('');
        $wants = $this->io->askConfirmation(
            'Install the AI-optimized framework docs into vendor/ (recommended for Claude Code)? [Y/n] ',
            true
        );
        if (!$wants) {
            $this->io->write('Skipped. Add them any time with: composer require --dev ' . self::DOCS_PACKAGE);
            return;
        }

        $cmd = $this->composerCommand()
             . ' require --dev ' . escapeshellarg(self::DOCS_PACKAGE . ':^1.0')
             . ' --working-dir=' . escapeshellarg($this->baseDir);

        $this->io->write('Running: composer require --dev ' . self::DOCS_PACKAGE);
        passthru($cmd, $exitCode);

        if ($exitCode !== 0) {
            $this->io->writeError('   Docs install failed (the project install itself is complete).');
            $this->io->writeError('   Retry manually: composer require --dev ' . self::DOCS_PACKAGE);
            return;
        }

        $this->io->write('   ✓ Docs installed → vendor/' . self::DOCS_PACKAGE . ' (entry: README.md)');
    }

    /** True when `z77/docs` is already installed or declared by the root package. */
    private function isDocsPresent(): bool
    {
        foreach ($this->getInstalledPackages() as $package) {
            if ($package->getName() === self::DOCS_PACKAGE) {
                return true;
            }
        }

        $root = $this->composer->getPackage();
        return isset($root->getRequires()[self::DOCS_PACKAGE])
            || isset($root->getDevRequires()[self::DOCS_PACKAGE]);
    }

    /**
     * Rebuilds the command line of the currently running Composer so the nested
     * `require` uses the exact same binary (php + composer.phar). Falls back to a
     * plain `composer` lookup on PATH when argv is not usable.
     */
    private function composerCommand(): string
    {
        $argv0 = $_SERVER['argv'][0] ?? '';
        if ($argv0 !== '' && is_file($argv0)) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($argv0);
        }
        return 'composer';
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

        $defaults              = require $dir . self::BACKUP_CONFIG . '.default.inc.php';
        $this->backupConfig    = array_merge($defaults, $config['core-backup'] ?? []);

        $defaults              = require $dir . self::MAIL_CONFIG . '.default.inc.php';
        $this->mailConfig      = array_merge($defaults, $config['core-mail'] ?? []);

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
