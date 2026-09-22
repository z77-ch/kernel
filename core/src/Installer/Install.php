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
    private const SYSTEM_CONFIG         = 'systemConfig';
    private const DATABASE_CONFIG       = 'database';
    private const FILE_FINDER_CONFIG    = 'fileFinder.inc.php';

    // Release-local runtime state (ADR-035). Mirrors ABS_STATE_PATH in
    // Bootstrap — fixed structure, deliberately not read from config.
    private const STATE_DIR             = 'var/state';

    // Publication record for public assets (INST-ASSET-DIFF-001): project-relative
    // path → sha1 the file had WHEN THE INSTALLER WROTE IT. It is what lets an update
    // tell "untouched since we published it" (refresh silently) from "edited here"
    // (never overwrite without consent). Release-local runtime state like the flags
    // above (ADR-035): a fixed path, not configurable, gitignored, never deployed.
    private const PUBLISHED_ASSETS_FILE = self::STATE_DIR . '/published-assets.json';

    // The public ENTRY files the record covers: framework-owned code that must not go
    // stale silently. The branding files that share that directory (favicons,
    // site.webmanifest) are deliberately NOT listed — nearly every project replaces them,
    // so they would stand in every install log forever (INST-ASSET-ENTRY-001).
    private const RECORDED_ENTRY_FILES  = ['index.php', '.htaccess'];

    // Stores that must never be web-reachable — each gets a seed-once deny
    // .htaccess. Never a store served THROUGH public/ (a deny file inside
    // public/media would 403 every image — same rule as .releases/deploy.php).
    private const DENY_TEMPLATE         = __DIR__ . '/../../res/htaccess-deny';
    private const DENY_DIRS             = ['data', 'config', 'logs'];

    private const AUTH_DIR              = 'data/framework/auth';
    private const BACKEND_USERS_FILE      = 'backendUsers.json';
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
    private array  $systemConfig        = [];
    private array  $databaseConfig      = [];
    private string $frameworkPrefix     = '';
    private string $modulePrefix        = '';
    private array  $additionalPsr4Paths = [];
    private array  $configPaths         = [];
    private array  $publicAssetPaths    = [];
    private array  $z77Modules          = [];

    // Collected drift entries (update only). Each entry is ['display' => …,
    // 'src' => absolute vendor path, 'dst' => absolute public path]; entries in
    // $assetDriftChanged also carry 'reason' => 'edited' | 'unrecorded'. The four lists
    // are what classifyPublishedFile() sorts every shipped file into:
    //
    //   $assetRefreshed    — present, differs from vendor, but IDENTICAL to the publication
    //                        record: we wrote it, nobody touched it → rewritten, no prompt.
    //   $assetPublishedNew — absent AND unrecorded: never published here, so nobody can have
    //                        edited it → written, no prompt.
    //   $assetRemovedHere  — absent but RECORDED: we published it, the project deleted it.
    //                        Republishing would undo a deliberate act → reported only.
    //   $assetDriftChanged — present and differs from the record ('edited') or has no record
    //                        ('unrecorded') → never written without an explicit yes.
    //
    // Nothing is printed line-by-line mid-run: renderAssetWriteNotice() /
    // renderAssetDriftNotice() report at the end, then promptAssetDeploy() asks.
    private array  $assetDriftChanged   = [];
    private array  $assetRefreshed      = [];
    private array  $assetPublishedNew   = [];
    private array  $assetRemovedHere    = [];

    // The publication record itself: project-relative path → sha1 at publication time.
    // It only ever GROWS — an entry for a file the framework no longer ships is dead
    // weight, not a defect, and is deliberately not pruned (a sha1 per path costs bytes,
    // while deciding "no longer shipped" would mean trusting one run's view of vendor/).
    private array  $publishedAssets     = [];
    private bool   $publishedAssetsDirty = false;

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
            // FIRST install only. Afterwards the installer writes there only where it can PROVE
            // nothing of the project's is at stake: a file byte-identical to the copy it
            // published itself, and a file public/ never had (ADR-046, INST-ASSET-DIFF-001).
            // Everything else needs an explicit yes (ADR-026). No blanket overwrite, no force.
            $firstInstall = !is_dir($targetDir);

            $this->loadPublishedAssets();

            $entrySourceDir = __DIR__ . '/../../' . $publicDir;

            if ($firstInstall) {
                $this->copyFiles($entrySourceDir, $targetDir);
                $this->recordEntryFiles($targetDir);
            } else {
                $this->io->write(
                    'public/ exists — developer-owned (ADR-024). Only files still identical to '
                    . 'the copy the installer published are refreshed; everything else stays.'
                );
                $this->reportAssetDrift();
                $this->reportEntryFileDrift($entrySourceDir, $targetDir);
                // The automatic writes into an existing public/ (INST-ASSET-DIFF-001): files
                // the project never touched since we published them, and files it never had.
                // A stale copy of an unedited framework file is a bug, not developer ownership.
                $this->deployUndisputedAssets();
            }

            $this->createDirectories($config['directories'] ?? [], $firstInstall);
            $this->seedCronEntry();
        }

        $this->migrateConfigSplit();
        $this->writeBootstrapConfig();
        $this->writeModuleManagerConfig();
        $this->writeAuthConfig();
        $this->writeI18nConfig();
        $this->writeBackupConfig();
        $this->writeMailConfig();
        $this->writeSystemConfig();
        $this->writeDatabaseConfig();
        $this->writeFileFinderConfig();
        $this->writeDataFiles();
        $this->provisionAdmin();
        $this->writeDebugFlag();
        $this->seedDenyFiles();
        $this->seedProjectClaudeMd();

        if (!empty($config)) {
            $this->io->write('✓ Z77 Core installation complete');
        } else {
            $this->io->write('Z77 composer.json extra was empty — only default config written.');
        }

        $this->reportMissingCanonicalBaseUrl();

        // Last thing shown, so the developer can't miss it: what was refreshed silently,
        // then a single coloured notice listing the framework assets that differ from
        // public/ and need a decision (ADR-025), followed by an opt-in per-file deploy
        // prompt (interactive) or a named stale list (non-interactive, ADR-024 amend).
        $this->renderAssetWriteNotice();
        $this->renderAssetDriftNotice();
        $this->promptAssetDeploy();
        $this->savePublishedAssets();

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

    /**
     * @param bool $record  true for the public ASSET trees: every file actually written is
     *                      entered into the publication record (INST-ASSET-DIFF-001), so a
     *                      later install can tell an untouched copy from a project edit.
     *                      false for the entry files (index.php, .htaccess, favicons) —
     *                      they are outside the drift/refresh mechanism (see installer.md).
     */
    private function copyFiles(string $source, string $target, bool $record = false): void
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
                $this->copyFiles($src, $dst, $record);
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

            if ($record) {
                $this->recordPublishedAsset($dst);
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
     *      `public/{assetDir}/{name}/` and enter every written file into the publication
     *      record (`var/state/published-assets.json`, INST-ASSET-DIFF-001) — that record
     *      is what lets the NEXT install refresh an untouched copy without asking.
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
                // record = true: the first install is what creates the publication record
                // every later install compares against (INST-ASSET-DIFF-001).
                $this->copyFiles($source, $target, true);
            }
        }
    }

    /**
     * On an update (public/ present) the installer walks every shipped `res/assets` file and
     * hands it to {@see classifyPublishedFile()}, which sorts it into the four lists declared
     * at the top of this class (ADR-025 + INST-ASSET-DIFF-001). Collects only — the notices
     * print at the very end of the run, after {@see deployUndisputedAssets()} has written
     * what needs no decision.
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
                    $this->collectAssetDrift($source, $target, $assetName);
                }
            }
        }
    }

    /**
     * Writes the files no one can dispute (INST-ASSET-DIFF-001): the deployed copy is still
     * byte-identical to what the installer published while the shipped file changed, or the
     * file was never published here at all. No prompt, in every run mode — the developer's
     * own work cannot be at stake in either case. This is the fix for the silent staleness
     * ADR-024/025/026 left behind: a non-interactive `composer install` answered "No" to
     * every prompt, so a stale copy of an untouched framework asset survived every install
     * and the browser kept serving it. Reported afterwards by
     * {@see renderAssetWriteNotice()} — silent means no question, not invisible.
     */
    private function deployUndisputedAssets(): void
    {
        try {
            foreach ($this->assetRefreshed as $entry) {
                $this->deployAsset($entry['src'], $entry['dst']);
            }
            foreach ($this->assetPublishedNew as $entry) {
                $this->deployAsset($entry['src'], $entry['dst']);
            }
        } finally {
            // finally, not "after the loop": a copy failure mid-way must still leave the
            // record describing the files already written. A record entry that lags behind
            // the file on disk is the one way this mechanism turns against itself — the
            // next install would read a file WE wrote as "your edit" and stop refreshing it.
            $this->savePublishedAssets();
        }
    }

    /**
     * One plain block naming every file written without asking — so an operator reading a
     * deploy log sees what changed under public/ and why it needed no decision.
     */
    private function renderAssetWriteNotice(): void
    {
        if (empty($this->assetRefreshed) && empty($this->assetPublishedNew)) {
            return;
        }

        $count = count($this->assetRefreshed) + count($this->assetPublishedNew);
        $this->io->write('');
        $this->io->write(
            "Asset write: {$count} file(s) in public/ needed no decision — either still "
            . 'identical to the copy the installer published, or never published here before:'
        );
        foreach ($this->assetRefreshed as $entry) {
            $this->io->write('  ↻ refreshed: ' . $entry['display']);
        }
        foreach ($this->assetPublishedNew as $entry) {
            $this->io->write('  + published: ' . $entry['display']);
        }
    }

    /**
     * Renders the files that need a DECISION (ADR-025) as ONE coloured notice at the end of
     * the run — a solid yellow block so it stands out from the plain install log. This is the
     * ONLY list of them: the interactive run asks about them right after, the non-interactive
     * run adds one guidance line and ends. Files written without a question are not here.
     * Prints nothing when public/ needs no decision.
     */
    private function renderAssetDriftNotice(): void
    {
        if (empty($this->assetDriftChanged) && empty($this->assetRemovedHere)) {
            return;
        }

        $lines = [
            'Framework files in public/ that the installer did NOT write by itself:',
            $this->io->isInteractive()
                ? 'You will be asked per file below (default: No).'
                : 'Nothing was written — they keep their current state.',
            '',
        ];
        foreach ($this->assetDriftChanged as $entry) {
            $lines[] = '  ~ kept (project-owned or unrecorded): ' . $entry['display'];
            $lines[] = '      ' . $this->driftReasonLabel($entry);
        }
        foreach ($this->assetRemovedHere as $entry) {
            $lines[] = '  − removed here: ' . $entry['display'];
            $lines[] = '      (the installer published it once and it is gone — deleted in this project)';
        }
        if (!$this->io->isInteractive()) {
            $lines[] = '';
            $lines[] = '  → run `composer install` interactively to decide per file,';
            $lines[] = '    or copy the file from vendor into public/ by hand.';
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
     * Opt-in, interactive-only deploy of the files that need a DECISION (ADR-024 amendment).
     * Runs after the drift notice, which already NAMED them — in a non-interactive run
     * (CI / deploy) that notice is the whole report and this method does nothing, so no file
     * is listed twice. Every prompt defaults to NO, so a blind Enter never writes anything.
     * Undisputed files never reach this method: they were written without a question
     * ({@see deployUndisputedAssets()}).
     *
     *   ~ kept        → the deployed copy may be YOUR edit or a build artefact (compiled
     *                   CSS/JS from override/scss). Overwriting it with the framework version
     *                   can wipe your work — the exact INST-ASSET-002 footgun. Warn, then ask.
     *   − removed here → NOT a risk-free copy: the file was published here and deleted since.
     *                   Restoring it undoes that deletion, so it is asked, never assumed.
     */
    private function promptAssetDeploy(): void
    {
        if (!$this->io->isInteractive()) {
            return;
        }
        if (empty($this->assetDriftChanged) && empty($this->assetRemovedHere)) {
            return;
        }

        $deployed = 0;

        try {
            foreach ($this->assetDriftChanged as $entry) {
                $this->io->write('');
                $this->io->write('<bg=yellow;fg=black> ~ ' . $entry['display'] . ' </>');
                $this->io->write('   ' . $this->driftReasonLabel($entry));
                $this->io->write('   ⚠ This may be YOUR own edit or a compiled build artefact (from override/scss).');
                $this->io->write('   ⚠ Overwriting replaces it with the framework version — your changes are lost.');
                if ($this->io->askConfirmation('   Overwrite public/ file? [y/N] ', false)) {
                    $this->deployAsset($entry['src'], $entry['dst']);
                    $this->io->write('   ✓ overwritten');
                    $deployed++;
                }
            }

            foreach ($this->assetRemovedHere as $entry) {
                $this->io->write('');
                $this->io->write('− removed here: ' . $entry['display']);
                $this->io->write('   ⚠ The installer published this file once; it is gone now — someone deleted it here.');
                $this->io->write('   ⚠ Restoring it undoes that deletion.');
                if ($this->io->askConfirmation('   Restore into public/? [y/N] ', false)) {
                    $this->deployAsset($entry['src'], $entry['dst']);
                    $this->io->write('   ✓ restored');
                    $deployed++;
                }
            }
        } finally {
            // Same reason as in deployUndisputedAssets(): every file we wrote must be in the
            // record before this run can end, however it ends.
            $this->savePublishedAssets();
        }

        $this->io->write('');
        $this->io->write($deployed > 0
            ? "Asset deploy: {$deployed} file(s) written to public/."
            : 'Asset deploy: nothing written on request — public/ keeps its own files.');
    }

    /**
     * Why a drifted file was not refreshed on its own — the publication record's verdict.
     */
    private function driftReasonLabel(array $entry): string
    {
        return ($entry['reason'] ?? 'unrecorded') === 'edited'
            ? '(differs from the copy we published — your edit or a build artefact)'
            : '(no publication record — provenance unknown)';
    }

    /**
     * Copies one drifted asset from vendor into public/ (opt-in, see promptAssetDeploy();
     * also the automatic writes, see deployUndisputedAssets()). Creates missing parent
     * dirs, overwrites an existing target and enters the written file into the publication
     * record, so the NEXT install knows this copy came from us. Throws on failure — no
     * silent errors.
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

        $this->recordPublishedAsset($dst);
    }

    // -------------------------------------------------------------------------
    // Publication record (INST-ASSET-DIFF-001)
    // -------------------------------------------------------------------------

    /**
     * Reads `var/state/published-assets.json` — project-relative path → sha1 at publication.
     * A missing, unreadable or malformed record is NOT an error: it simply means "we know
     * nothing about these files", and every drifted file is then treated as 'unrecorded'
     * (kept, reported) — exactly the behaviour before this record existed.
     */
    private function loadPublishedAssets(): void
    {
        $file = $this->trailingSlash($this->baseDir) . self::PUBLISHED_ASSETS_FILE;
        if (!is_file($file)) {
            return;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $this->publishedAssets[$path] = $hash;
            }
        }
    }

    /**
     * Notes the hash a just-written public file has, so a later install can recognise it
     * as "ours, untouched". Called from every path that writes into public/assets.
     */
    private function recordPublishedAsset(string $dst): void
    {
        $hash = hash_file('sha1', $dst);
        if ($hash === false) {
            return;
        }

        $this->publishedAssets[$this->publishedKey($dst)] = $hash;
        $this->publishedAssetsDirty = true;
    }

    /**
     * Writes the record back, once, at the end of the run — only when something was
     * published. Throws on failure like every other installer write (no silent errors);
     * the record is state the next install depends on.
     */
    private function savePublishedAssets(): void
    {
        if (!$this->publishedAssetsDirty) {
            return;
        }

        $file = $this->trailingSlash($this->baseDir) . self::PUBLISHED_ASSETS_FILE;
        $dir  = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }

        ksort($this->publishedAssets);
        $json = json_encode($this->publishedAssets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode asset publication record: {$file}");
        }

        // Write-then-rename, never in place: an interrupted write would otherwise leave a
        // truncated record. That reads back as "no entry" for every file below the cut —
        // and a missing entry means "provenance unknown", i.e. those files would stop being
        // refreshed until someone answered a prompt. rename() is atomic on both platforms
        // (Windows replaces an existing target since PHP 5.3).
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException("Failed to write asset publication record: {$tmp}");
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to replace asset publication record: {$file}");
        }

        $this->publishedAssetsDirty = false;
    }

    /**
     * The record's key: the path relative to the project root, forward slashes — readable
     * in the file and independent of where the project lives on disk.
     */
    private function publishedKey(string $absPath): string
    {
        $base = $this->trailingSlash(str_replace('\\', '/', $this->baseDir));
        $path = str_replace('\\', '/', $absPath);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Recursively walks $source and hands every file to {@see classifyPublishedFile()}.
     * Read-only — the writing happens later, from the collected lists. (ADR-025)
     */
    private function collectAssetDrift(string $source, string $target, string $displayPrefix): void
    {
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $src = $this->trailingSlash($source) . $item;
            $dst = $this->trailingSlash($target) . $item;
            $rel = $displayPrefix . '/' . $item;

            if (is_dir($src)) {
                $this->collectAssetDrift($src, $dst, $rel);
                continue;
            }

            $this->classifyPublishedFile($src, $dst, $rel);
        }
    }

    /**
     * Decides what may happen to ONE published file, by comparing three values: the shipped
     * file, the deployed copy, and what the publication record says WE last wrote there
     * (INST-ASSET-DIFF-001). Collects into the four lists declared at the top of this class;
     * the only thing it writes is the record itself (the adoption below), never public/.
     */
    private function classifyPublishedFile(string $src, string $dst, string $display): void
    {
        $entry     = ['display' => $display, 'src' => $src, 'dst' => $dst];
        $published = $this->publishedAssets[$this->publishedKey($dst)] ?? null;

        if (!file_exists($dst)) {
            if ($published === null) {
                // Never published here, so nobody can have edited it and nothing can be
                // lost by writing it: genuinely new (a new module, a new asset file).
                $this->assetPublishedNew[] = $entry;
            } else {
                // We published it and it is gone: someone deleted it in THIS project.
                // Re-creating it would silently undo that — report it, never write it.
                $this->assetRemovedHere[] = $entry;
            }
            return;
        }

        $deployedHash = hash_file('sha1', $dst);

        if (hash_file('sha1', $src) === $deployedHash) {
            // In sync. Record it whenever the record does not already say so — a MISSING
            // entry (an installation older than the record) and a STALE one (an aborted run,
            // a file hand-copied from vendor as the installer itself advises) are the same
            // case: both sides agree RIGHT NOW, and that is a statement about the present,
            // not a guess about the past. Without this a wrong hash would never heal: the
            // file would count as "edited" at the next framework change and never be
            // refreshed again. Writes nothing into public/.
            if ($published !== $deployedHash) {
                $this->recordPublishedAsset($dst);
            }
            return;
        }

        if ($published !== null && $published === $deployedHash) {
            // Byte-identical to what we wrote → nobody edited it here, the shipped file
            // moved. Refreshing destroys nothing; asking would only teach the developer to
            // answer prompts blindly.
            $this->assetRefreshed[] = $entry;
            return;
        }

        // 'edited'     → differs from what we published: a project edit or a build artefact
        //                — the INST-ASSET-002 footgun, never written without an explicit yes.
        // 'unrecorded' → no record: an installation from before the record existed, or a
        //                file that arrived some other way. Provenance unknown → same care.
        $entry['reason'] = $published === null ? 'unrecorded' : 'edited';
        $this->assetDriftChanged[] = $entry;
    }

    /**
     * The public ENTRY files under the record (INST-ASSET-ENTRY-001): `index.php` and
     * `.htaccess` are framework-owned code in a developer-owned directory, and before this
     * they were copied on the first install and never looked at again — a changed
     * `index.php` reached no existing project and nobody was told. They now run through the
     * same classifier as the assets. The favicons and `site.webmanifest` beside them stay
     * out: nearly every project replaces those, so they would appear in every install log
     * forever.
     */
    private function reportEntryFileDrift(string $sourceDir, string $targetDir): void
    {
        foreach (self::RECORDED_ENTRY_FILES as $name) {
            $src = $this->trailingSlash($sourceDir) . $name;
            if (!is_file($src)) {
                continue;
            }
            $this->classifyPublishedFile($src, $this->trailingSlash($targetDir) . $name, $name);
        }
    }

    /**
     * First install: note the entry files we just wrote, so the next run can recognise them.
     * {@see reportEntryFileDrift()} for why only these two.
     */
    private function recordEntryFiles(string $targetDir): void
    {
        foreach (self::RECORDED_ENTRY_FILES as $name) {
            $dst = $this->trailingSlash($targetDir) . $name;
            if (is_file($dst)) {
                $this->recordPublishedAsset($dst);
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
        $dir  = $this->vendorConfigDir();
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
        $dir  = $this->vendorConfigDir();
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
        $dir  = $this->clientConfigDir();
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
        $dir  = $this->clientConfigDir();
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
     * backup policy (retention, full-backup excludes, dump settings) that the
     * developer adapts after install — same class as auth/i18n. Once it exists
     * the installer never overwrites it. See docs/topics/backup.md. The
     * database connection itself is NOT here — database.inc.php (ADR-039).
     */
    private function writeBackupConfig(): void
    {
        $dir  = $this->clientConfigDir();
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
            if ($key === 'retention') {
                // The one key whose SEMANTICS the operator needs where they
                // edit: the generated file carries no comments otherwise, and
                // the tier form is not guessable from the values.
                $content .= "    // Per type: integer = keep the newest N (0 = unlimited), or a tiered\n";
                $content .= "    // map for LATE discovery (a mistake noticed days later still has a\n";
                $content .= "    // clean state): tiers last/daily/weekly/monthly/yearly, count per\n";
                $content .= "    // tier, 0 = that tier unlimited; `last` protects a manual pre-change\n";
                $content .= "    // backup from the same-day scheduled run. A misspelled tier name\n";
                $content .= "    // throws. Full story: docs/topics/backup.md.\n";
            }
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
    /**
     * Installation identity (ADR-030) — seed-once like the mail config: written
     * when absent, never overwritten, meant to be edited on the server. It must
     * survive `composer install`, because it is the one place where an
     * installation differs from every other one built from the same repository.
     */
    private function writeSystemConfig(): void
    {
        $this->writeSeedOnceConfig(self::SYSTEM_CONFIG, $this->systemConfig, 'System');
    }

    /**
     * The one hand edit a fresh installation still needs: without
     * `canonicalBaseUrl` the setup and the backend run, but public pages and
     * mail links answer 500 (SEC-005, INST-FRESH-001). Printed after the
     * install log so it is not lost in it; the seed-once file is only read.
     */
    private function reportMissingCanonicalBaseUrl(): void
    {
        $notice = self::canonicalBaseUrlNotice(
            $this->trailingSlash($this->clientConfigDir()) . self::SYSTEM_CONFIG . '.inc.php'
        );
        if ($notice !== null) {
            $this->io->write('<bg=yellow;fg=black> ' . $notice . ' </>');
        }
    }

    /**
     * The notice line for an installed systemConfig file, or null when the
     * value is set (or the file is absent — nothing to point at). Static and
     * Composer-free so `tests/fresh-install-setup.php` can call it directly.
     */
    public static function canonicalBaseUrlNotice(string $systemConfigFile): ?string
    {
        if (!is_file($systemConfigFile)) {
            return null;
        }
        $values = require $systemConfigFile;
        $url    = is_array($values) ? trim((string)($values['canonicalBaseUrl'] ?? '')) : '';
        if ($url !== '') {
            return null;
        }

        return "Action needed: set 'canonicalBaseUrl' in config/client/systemConfig.inc.php "
            . "(e.g. 'https://kunde.ch'). Setup and backend work without it; public pages "
            . "and mail links answer 500 until it is set.";
    }

    /**
     * The relational connection (ADR-039 decision 4) — seed-once like the
     * system config, and like it NOT fed from composer.json: credentials are a
     * property of the single installation. Read by the Doctrine driver and by
     * the `db` backup; an installation without a database leaves 'name' empty.
     */
    private function writeDatabaseConfig(): void
    {
        $this->writeSeedOnceConfig(self::DATABASE_CONFIG, $this->databaseConfig, 'Database');
    }

    /**
     * Seed-once writer for a plain key → value config in config/client/:
     * written when absent, never overwritten, no per-key comments. (The
     * backup config keeps its own writer for the retention comment.)
     */
    private function writeSeedOnceConfig(string $configName, array $values, string $label): void
    {
        $dir  = $this->clientConfigDir();
        $name = $configName . '.inc.php';

        $target = $this->trailingSlash($dir) . $name;
        if (file_exists($target)) {
            $this->io->write("Skipped: {$name} already exists (seed-once, not overwritten)");
            return;
        }

        $this->io->write("Write {$label} config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_SEED_ONCE);
        $content .= "return [\n";
        foreach ($values as $key => $value) {
            $content .= "    '{$key}' => " . $this->exportPhpValue($value) . ",\n";
        }
        $content .= "];\n";

        $this->writeFile($dir, $name, $content);
    }

    private function writeMailConfig(): void
    {
        $dir  = $this->clientConfigDir();
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
        $dir  = $this->vendorConfigDir();
        $name = self::FILE_FINDER_CONFIG;

        $this->io->write("Write FileFinder config → {$dir}/{$name}");

        $content  = $this->header($name, self::NOTE_REGENERATE);
        // Anchored on ABS_BASE_PATH, NOT dirname(__DIR__): __DIR__ is the
        // symlink-RESOLVED real path, so a symlinked config/ would anchor
        // these paths in the link target's tree instead of the project's.
        // ABS_BASE_PATH comes from the entry point and is the same anchor
        // FileFinder already uses to locate this very file — one anchor,
        // and the file works regardless of where it physically lives.
        $content .= "\$vendorDir = ABS_BASE_PATH.'/{$this->vendorBaseName}/';\n";
        $content .= "\$baseDir = ABS_BASE_PATH.'/';\n";
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
     * `*.default.json` anywhere under a framework package's data directory is
     * deployed to the same relative path with the `.default` marker stripped, e.g.
     *   `framework/routing/navigation.default.json` → `data/framework/routing/navigation.json`
     *   `content/home.de.default.json`              → `data/content/home.de.json`
     * `writeDataFile` skips targets that already exist, so existing runtime data
     * is preserved. Adding a new seeded entity needs no installer change — just drop
     * its `*.default.json` under the package's data dir (framework scaffolding or
     * starter content).
     *
     * Walks EVERY installed framework package (INST-SEED-001) — previously only the
     * kernel's own `core/data` was scanned, so module seeds (e.g. module-dms
     * `data/documents/folders.default.json`) never reached a project. The kernel is
     * not special-cased anymore. On a rel-path collision across packages the first
     * package wins (and seed-once protects existing runtime data either way).
     */
    private function writeDataFiles(): void
    {
        foreach ($this->frameworkDataRoots() as $base) {
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
    }

    /**
     * Absolute, existing data roots of all installed framework packages. Derived
     * from each package's framework psr-4 paths with the same `stripSrc` logic
     * `buildPaths()` uses: `core/src` → `{install}/core/data`, `src` → `{install}/data`.
     * Deduplicated (a package exposing several namespaces from one root, e.g. the
     * kernel, contributes each data dir once).
     *
     * @return string[]
     */
    private function frameworkDataRoots(): array
    {
        $roots   = [];
        $manager = $this->composer->getInstallationManager();

        foreach ($this->getInstalledPackages() as $package) {
            $installPath = null;

            foreach ($package->getAutoload()['psr-4'] ?? [] as $namespace => $path) {
                if (!str_starts_with($namespace, $this->frameworkPrefix)) {
                    continue;
                }

                $installPath ??= $manager->getInstallPath($package);
                if ($installPath === null || $installPath === '') {
                    break;  // metapackage — nothing on disk
                }

                foreach ((array) $path as $p) {
                    $sub  = trim($this->stripSrc($p), '/');
                    $dir  = $this->trailingSlash($installPath)
                          . ($sub !== '' ? $sub . '/' : '') . 'data';
                    $real = realpath($dir);
                    if ($real !== false && is_dir($real)) {
                        $roots[$real] = true;
                    }
                }
            }
        }

        return array_keys($roots);
    }

    /**
     * The flag lives in the release-local state directory (ADR-035), not in the
     * shared `data/`: on a release layout `data/` sits behind two doors, so a flag
     * written there would switch production along with staging.
     */
    private function writeDebugFlag(): void
    {
        $dir   = $this->trailingSlash($this->baseDir) . self::STATE_DIR;
        $flag  = $this->trailingSlash($dir) . 'debug.flag';
        $debug = $this->bootstrapConfig['debug'] ?? false;

        if ($debug) {
            if (!file_exists($flag)) {
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Failed to create state directory: {$dir}");
                }
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

    /**
     * Seeds a deny .htaccess (`Require all denied`) into every store that must
     * never be web-reachable (DENY_DIRS). With the document root on `public/`
     * Apache never reads these files; the day a panel misconfiguration or a
     * project unpacked straight into htdocs puts the project root into the web,
     * they turn `data/framework/auth/SETUP_TOKEN`, the password hashes and the
     * mail credentials from URLs into 403s — the alarm, not the fault (same
     * philosophy as `.releases/htaccess-deny` for the release layout, and only
     * effective where .htaccess is honoured; the correct document root stays
     * the primary defence). Seed-once: an existing .htaccess is never touched.
     * A directory that does not exist is skipped — `logs/` is config-driven.
     */
    private function seedDenyFiles(): void
    {
        if (!is_readable(self::DENY_TEMPLATE)) {
            // Template missing would be a packaging defect — report, don't break installs.
            $this->io->writeError('   Skipped deny .htaccess seed: template not found in kernel.');
            return;
        }

        $content = file_get_contents(self::DENY_TEMPLATE);
        if ($content === false) {
            throw new \RuntimeException('Failed to read deny template: ' . self::DENY_TEMPLATE);
        }

        foreach (self::DENY_DIRS as $dir) {
            $absDir = $this->trailingSlash($this->baseDir) . $dir;
            if (!is_dir($absDir) || file_exists($this->trailingSlash($absDir) . '.htaccess')) {
                continue;
            }
            $this->writeFile($absDir, '.htaccess', $content);
            $this->io->write("   Created: {$dir}/.htaccess (deny — must never be web-reachable)");
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
     * public). Runs once: if `backendUsers.json` already exists it is never touched
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
        $usersFile = $this->trailingSlash($authDir) . self::BACKEND_USERS_FILE;

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
     * nag. The user store is written as plain JSON matching the {@see BackendUser}
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
        $this->writeFile($authDir, self::BACKEND_USERS_FILE, $json);
        $this->io->write('   ✓ Admin account created → ' . self::AUTH_DIR . '/' . self::BACKEND_USERS_FILE);
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

        // ADR-030: installation identity — deliberately NOT merged from composer
        // `extra`. composer.json is committed, so staging and production would
        // share one value; these belong to the single installation.
        $this->systemConfig    = require $dir . self::SYSTEM_CONFIG . '.default.inc.php';
        // Same reasoning for the database credentials (ADR-039 decision 4).
        $this->databaseConfig  = require $dir . self::DATABASE_CONFIG . '.default.inc.php';

        return $config;
    }

    /**
     * Seeds `cron/run.php` — the cron entry for hosts whose panel takes one
     * command and no `cd` (the panel cron starts in the home directory, where
     * z77-run's upward walk finds no project). Seed-ONCE like public/: the
     * file is three lines of hand-over with no generated content, and an
     * installation may have adapted it (a hoster-specific guard, a different
     * PHP ini) — regenerating would overwrite that silently. Unlike public/
     * it seeds even on an existing installation, as long as the file itself
     * is absent — existing projects get the entry on their next install.
     */
    private function seedCronEntry(): void
    {
        $dir    = $this->trailingSlash($this->baseDir) . 'cron';
        $target = $dir . '/run.php';
        if (is_file($target)) {
            return;
        }

        $this->io->write("Seed cron entry → {$target}");
        $source = __DIR__ . '/../../cron/run.php';
        if (!is_file($source)) {
            throw new \RuntimeException("Cron entry template not found: {$source}");
        }
        $this->writeFile($dir, 'run.php', (string) file_get_contents($source));
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

    /**
     * Installer-GENERATED configs (bootstrap, moduleManager, fileFinder) — a
     * function of composer.json + vendor/, owned by the RELEASE (ADR-036).
     */
    private function vendorConfigDir(): string
    {
        return $this->configDir() . '/vendor';
    }

    /**
     * Hand-maintained machine/project configs (seed-once tier) — owned by the
     * INSTALLATION; in the release layout `config/client` is a symlink into
     * shared/ (ADR-036).
     */
    private function clientConfigDir(): string
    {
        return $this->configDir() . '/client';
    }

    /**
     * One-time move from the flat pre-ADR-036 layout: generated leftovers are
     * deleted (rewritten into config/vendor/ right after), seed-once files are
     * RENAMED into config/client/ so hand edits survive. Runs on every
     * install; on an already-split installation it does nothing.
     */
    private function migrateConfigSplit(): void
    {
        $flat = $this->trailingSlash($this->configDir());

        foreach (['bootstrap', 'moduleManager', 'fileFinder'] as $generated) {
            $file = $flat . $generated . '.inc.php';
            if (is_file($file)) {
                @unlink($file);
                $this->io->write("Migrated: removed flat config/{$generated}.inc.php (regenerated in config/vendor/, ADR-036)");
            }
        }

        foreach (['auth', 'i18n', 'backup', 'mail', 'systemConfig', 'database', 'geoip'] as $seedOnce) {
            $from = $flat . $seedOnce . '.inc.php';
            $to   = $this->trailingSlash($this->clientConfigDir()) . $seedOnce . '.inc.php';
            if (is_file($from) && !is_file($to)) {
                if (!is_dir($this->clientConfigDir()) && !mkdir($this->clientConfigDir(), 0755, true)) {
                    throw new \RuntimeException('Failed to create ' . $this->clientConfigDir());
                }
                if (!rename($from, $to)) {
                    throw new \RuntimeException("Failed to move {$from} to config/client/");
                }
                $this->io->write("Migrated: config/{$seedOnce}.inc.php → config/client/ (ADR-036)");
            }
        }
    }

    private function header(string $name, string $policyNote = ''): string
    {
        $header = "<?php\n// Auto-generated by Z77 Core Installer\n// {$name} at: {$this->dateString}\n";
        return $header . $policyNote;
    }
}
