<?php

namespace Z77\Core\Services;

use Z77\Core\DI,
    Z77\Core\Config\Config,
    Z77\Core\Exception\LayoutManagerException,
    Z77\Core\Controller\ControllerHandler,
    Z77\Core\Http\RequestMode,
    Z77\Shared\Libraries\Convention\Naming,
    Z77\Shared\Libraries\Convention\LayoutDefaults
;

/**
 * LayoutManager
 *
 * Builder for HTML layout configuration. Responsibilities:
 * - Loads layout configuration (skeleton, partials, assets) for the current module/controller
 * - Manages CSS and JS assets (add, remove, versioning)
 * - Manages partial template paths (add, remove)
 * - Hands off a fully configured snapshot to HtmlView for rendering
 *
 * Not responsible for: rendering itself (HtmlView does that), JSON, file
 * downloads, redirects, PDF, email — those are handled by their respective
 * Response types.
 *
 * Lifecycle:
 *   1. construct (in AbstractBaseController::run)
 *   2. initialize (lazy, via AbstractBaseController::html)
 *   3. addCss / addJs / addPartials may run from action and postExecute
 *   4. buildView (in HtmlResponse::getHtml — at send-time)
 */
class LayoutManager
{
    private string $module;
    private string $group;
    private string $controller;
    private string $action;
    private string $nameSpace;

    private array $partialFilePaths = [];
    private string $documentTplPath = '';
    private StylesheetManager $stylesheetManager;
    private JavascriptManager $javascriptManager;

    private array $assets = ['css' => [], 'js' => []];

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(
        private ControllerHandler $controllerHandler,
        private bool $debug = false
    ) {
        $controllerFqcn   = $this->controllerHandler->getCurrentControllerClassName();
        $this->module     = $this->controllerHandler->getCurrentModule();
        $this->group      = Naming::toControllerGroupSegment($controllerFqcn);
        $this->controller = Naming::toClassBaseName($controllerFqcn);
        $this->action     = $this->controllerHandler->getCurrentActionMethod();
        $this->nameSpace  = Naming::toNamespaceString(['Z77', 'Module', $this->module]);

        $assetVersion             = new AssetVersionService($this->debug);
        $assetCleaner             = new AssetCleaner();
        $this->stylesheetManager  = new StylesheetManager($assetVersion, $assetCleaner);
        $this->javascriptManager  = new JavascriptManager($assetVersion, $assetCleaner, $this->debug);
    }

    // -------------------------------------------------------------------------
    // Initialisation — called once by AbstractBaseController before the action
    // -------------------------------------------------------------------------

    /**
     * Reads layout configuration for the current module and controller.
     * Sets skeleton template, partials, and assets.
     *
     * Fetch mode: only sets the fetch skeleton, skips config loading.
     * Page mode:
     *   1. Module config Ui/Config/layoutConfig.inc.php — required, defines defaults
     *   2. Controller config Ui/Config/{controller}Config.inc.php — optional, extends/overrides
     *   3. Default skeleton fallback if no config set one
     *   4. Action template fallback if body.main is empty
     *
     * After initialize(), the action may still extend the layout, e.g.:
     *   $this->layoutManager->addPartials('sidebar', 'partials', $ns,
     *       section: 'sidebar', level: 'body');
     */
    public function initialize(): self
    {
        if (DI::getRequest()->getMode() === RequestMode::Fetch) {
            $this->setSkeletonTemplate(LayoutDefaults::FETCH_SKELETON);
            return $this;
        }

        $moduleLayoutConfig = DI::getConfigManager()->getArrayConfig(
            configName: 'Ui/Config/layoutConfig',
            nameSpace: $this->nameSpace,
            throwError: true
        );
        $this->applyLayoutConfig($moduleLayoutConfig);

        $controllerLayoutConfig = DI::getConfigManager()->getArrayConfig(
            configName: 'Ui/Config/' . $this->controllerResourcePath() . 'Config',
            nameSpace: $this->nameSpace,
            throwError: false
        );
        if ($controllerLayoutConfig) {
            $this->applyLayoutConfig($controllerLayoutConfig);
        }

        if ($this->documentTplPath === '') {
            $this->setSkeletonTemplate();
        }

        if (empty($this->partialFilePaths['body']['main'] ?? [])) {
            $this->addPartials(
                $this->action,
                $this->controllerTplDir(),
                $this->nameSpace
            );
        }

        // Dev tool: partial-label overlay script (gate: DEBUG + admin + user preference).
        // Missing deployment must not take a page down — tool goes silently off.
        if (PartialLabels::active()) {
            try {
                $this->addJs('partial-labels', 'Z77\\Shared');
            } catch (LayoutManagerException $e) {
                error_log(
                    'PartialLabels: overlay script not deployed to public/assets/shared/js — '
                    . $e->getMessage()
                );
            }
        }

        return $this;
    }

    /**
     * Template directory for the current controller, group-nested to mirror the
     * controller's physical location (ADR-005): `{Group}/{Controller}`, or just
     * `{Controller}` for flat (group-less) controllers.
     * Used as the FileFinder sub-path for action templates and controller-owned
     * partials, e.g. `res/view/templates/Content/NavigationController/list.tpl.php`.
     */
    private function controllerTplDir(): string
    {
        return $this->group !== ''
            ? $this->group . '/' . $this->controller
            : $this->controller;
    }

    /**
     * Controller config resource path, group-nested to mirror the controller's
     * location: `{Group}/{controller}` (lcfirst), or `{controller}` when flat.
     * Resolves to `src/Ui/Config/{Group}/{controller}Config.inc.php`. Group
     * nesting prevents config collisions when two controllers share a base name
     * across groups.
     */
    private function controllerResourcePath(): string
    {
        return $this->group !== ''
            ? $this->group . '/' . lcfirst($this->controller)
            : lcfirst($this->controller);
    }

    // -------------------------------------------------------------------------
    // Snapshot — called by HtmlResponse::getHtml() at send-time
    // -------------------------------------------------------------------------

    /**
     * Builds an immutable HtmlView from the current layout state.
     * Called lazily by HtmlResponse::getHtml(), after preExecute, the action,
     * and postExecute have all run — so every late asset registration is
     * captured.
     *
     * The view holds a snapshot — the LayoutManager may be discarded afterwards.
     */
    public function buildView(): HtmlView
    {
        return new HtmlView(
            skeletonPath: $this->documentTplPath,
            partials:     $this->partialFilePaths,
            css:          $this->assets['css'],
            js:           $this->assets['js'],
            nameSpace:    $this->nameSpace,
        );
    }

    // -------------------------------------------------------------------------
    // Skeleton template
    // -------------------------------------------------------------------------

    /**
     * Sets the skeleton template by name.
     * Resolves the file path via FileFinder.
     */
    public function setSkeletonTemplate(
        string $name = LayoutDefaults::SKELETON,
        ?string $nameSpace = null
    ): void {
        try {
            $this->documentTplPath = DI::getFileFinder()->getFirstTplMatch(
                $name . '.tpl.php',
                $nameSpace ?? $this->nameSpace
            );
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                "Skeleton template '{$name}' not found: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    // -------------------------------------------------------------------------
    // Partial templates
    // -------------------------------------------------------------------------

    /**
     * Adds one or more partial templates to a level/section slot.
     * Resolves paths via FileFinder.
     *
     * @param array|string $partials  Partial name(s) without extension
     * @param string|null  $path      Sub-path within templates directory
     * @param string|null  $nameSpace Module namespace to search in
     * @param string       $section   Slot within level: 'main', 'header', 'footer', ...
     * @param string       $level     Template level: 'body' or 'head'
     */
    public function addPartials(
        array|string $partials,
        ?string $path = null,
        ?string $nameSpace = null,
        string $section = 'main',
        string $level = 'body',
    ): void {
        foreach ((array) $partials as $partialFile) {
            try {
                $filePath = DI::getFileFinder()->getFirstTplMatch(
                    $path . '/' . $partialFile . '.tpl.php',
                    $nameSpace
                );
            } catch (\RuntimeException $e) {
                throw new LayoutManagerException(
                    "Partial '{$partialFile}' not found in namespace {$nameSpace}, path: {$path}.",
                    0,
                    $e
                );
            }
            $this->partialFilePaths[$level][$section][] = $filePath;
        }
    }

    /**
     * Removes a complete section (and all its partials) from a level.
     * Use case: action wants to drop a default section provided by module config,
     * e.g. removing the sidebar for a fullwidth landing page.
     */
    public function removeSection(string $section, string $level = 'body'): void
    {
        unset($this->partialFilePaths[$level][$section]);
    }

    // -------------------------------------------------------------------------
    // CSS assets
    // -------------------------------------------------------------------------

    /**
     * Adds a versioned CSS file to the asset list.
     * Resolves via StylesheetManager. Prevents duplicates by resolved path.
     */
    public function addCss(string $name, string $nameSpace, string $mediaQueryOption = ''): void
    {
        try {
            $filePath = $this->stylesheetManager->getVersionedCss(
                baseName: $name,
                nameSpace: $nameSpace
            );
            $webPath = $this->toWebPath($filePath);
            if (!in_array($webPath, array_column($this->assets['css'], 'path'), true)) {
                $this->assets['css'][] = [
                    'name'             => $name,
                    'path'             => $webPath,
                    'mediaQueryOption' => $mediaQueryOption,
                ];
            }
        } catch (\RuntimeException $e) {
            throw new LayoutManagerException(
                "CSS file 'css/{$name}.css' not found in assets directory.",
                0,
                $e
            );
        }
    }

    /**
     * Generates a CSS file from a PHP template and runtime data, then registers it as a CSS asset.
     * Version must come from the caller — typically max(updatedAt) of the driving entities, OR a
     * pure function of the driving data (e.g. an entity count) when the CSS depends on nothing
     * else: a count version never regenerates needlessly and cannot go backwards on a delete the
     * way max(updatedAt) can (see docs/01-handbook/patterns/slider.md).
     * If the versioned file already exists it is reused without re-rendering.
     *
     * Template path convention: res/view/templates/{group}/{controller}/css/{template}.css.tpl.php
     * Use case: CSS-only sliders where selectors and values depend on DB entities
     * (image count, indices, paths). Not suitable for static CSS — use addCss() for that.
     */
    public function createCss(
        string $name,
        string $nameSpace,
        string $template,
        array  $data,
        int    $version,
        string $mediaQueryOption = ''
    ): void {
        try {
            $filePath = $this->stylesheetManager->createCss($name, $nameSpace, $template, $data, $version);
            $webPath  = $this->toWebPath($filePath);
            if (!in_array($webPath, array_column($this->assets['css'], 'path'), true)) {
                $this->assets['css'][] = [
                    'name'             => $name,
                    'path'             => $webPath,
                    'mediaQueryOption' => $mediaQueryOption,
                ];
            }
        } catch (\RuntimeException $e) {
            throw new LayoutManagerException(
                "Generated CSS '{$name}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Removes one or more CSS assets by their original name (as passed to addCss).
     */
    public function removeCss(string|array $names): void
    {
        $toRemove = (array) $names;
        $this->assets['css'] = array_values(array_filter(
            $this->assets['css'],
            fn(array $entry) => !in_array($entry['name'] ?? null, $toRemove, true)
        ));
    }

    // -------------------------------------------------------------------------
    // JS assets
    // -------------------------------------------------------------------------

    /**
     * Adds a versioned JS file to the asset list.
     * Uses minified suffix automatically (production: .min.js, debug: .js).
     * Prevents duplicates by path.
     *
     * @param string $position  'head' | 'footer' — where the <script> tag is rendered
     * @param bool   $defer     Add defer attribute (default true — non-blocking load)
     * @param bool   $async     Add async attribute (default false)
     */
    public function addJs(
        string $name,
        string $nameSpace,
        string $position = 'footer',
        bool $defer = true,
        bool $async = false
    ): void {
        try {
            $filePath = $this->javascriptManager->getVersionedJs($name, $nameSpace);
            $webPath  = $this->toWebPath($filePath);
            if (!in_array($webPath, array_column($this->assets['js'], 'path'), true)) {
                $this->assets['js'][] = [
                    'name'     => $name,
                    'path'     => $webPath,
                    'position' => $position,
                    'defer'    => $defer,
                    'async'    => $async,
                ];
            }
        } catch (\RuntimeException $e) {
            $min = $this->javascriptManager->minSuffix();
            $tried = $min !== ''
                ? "'js/{$name}{$min}.js' (unminified fallback 'js/{$name}.js' also missing)"
                : "'js/{$name}.js'";
            throw new LayoutManagerException(
                "JS file {$tried} not found in assets directory.",
                0,
                $e
            );
        }
    }

    /**
     * Resolves a JS asset to its versioned web path WITHOUT registering it as
     * a `<script>` in the page skeleton. Use this when the script is loaded
     * lazily from a fetch-mode popup via the `load-script` command — the
     * skeleton would not render it anyway, and we want to avoid polluting
     * $jsFooter with assets only relevant to modal partials.
     */
    public function resolveJsPath(string $name, string $nameSpace): string
    {
        try {
            $filePath = $this->javascriptManager->getVersionedJs($name, $nameSpace);
            return $this->toWebPath($filePath);
        } catch (\RuntimeException $e) {
            $min = $this->javascriptManager->minSuffix();
            $tried = $min !== ''
                ? "'js/{$name}{$min}.js' (unminified fallback 'js/{$name}.js' also missing)"
                : "'js/{$name}.js'";
            throw new LayoutManagerException(
                "JS file {$tried} not found in assets directory.",
                0,
                $e
            );
        }
    }

    /**
     * Removes one or more JS assets by their original name (as passed to addJs).
     */
    public function removeJs(string|array $names): void
    {
        $toRemove = (array) $names;
        $this->assets['js'] = array_values(array_filter(
            $this->assets['js'],
            fn(array $entry) => !in_array($entry['name'] ?? null, $toRemove, true)
        ));
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Applies a layout config (module or controller level) to skeleton, partials, and assets.
     *
     * Merge behavior on repeated apply:
     * - Skeleton: overwritten — last apply wins
     * - Partials: appended per (level, section) — multiple partials accumulate
     * - CSS / JS: appended with deduplication by resolved file path
     *
     * Body section keys are free-form (header, main, footer, sidebar, ...) and become
     * template variables in the skeleton. Reserved names (must not be used as section
     * keys): head, css, jsHead, jsFooter — these are layout system variables.
     */
    private function applyLayoutConfig(Config $layoutConfig): void
    {
        $documentName = $layoutConfig->get(['documentTpl', 'name'], null);
        if ($documentName !== null) {
            $nameSpace = $layoutConfig->get(['documentTpl', 'nameSpace'], $this->nameSpace);
            $this->setSkeletonTemplate($documentName, $nameSpace);
        } elseif (!$this->documentTplPath) {
            $this->setSkeletonTemplate();
        }

        foreach ($layoutConfig->get('levelElements', []) as $level => $sections) {
            foreach ($sections as $section => $entries) {
                // String shortcut: 'meta' => 'partials/head/meta'
                // Array shortcut: 'main' => ['partials/intro', 'partials/cta']
                // Full form:      'sidebar' => [['nameSpace' => '…', 'path' => '…', 'name' => '…']]
                // Mix:            'main' => ['partials/intro', ['nameSpace' => '…', …]]
                $entries = is_string($entries) ? [$entries] : $entries;

                foreach ($entries as $entry) {
                    $normalized = $this->normalizePartialEntry($entry, $level, $section);
                    $this->addPartials(
                        $normalized['name'],
                        $normalized['path'],
                        $normalized['nameSpace'],
                        $section,
                        $level
                    );
                }
            }
        }

        $this->loadCssFromConfig($layoutConfig->get('styleSheets', []));
        $this->loadJsFromConfig($layoutConfig->get('javascripts', []));
    }

    /**
     * Normalizes a single partial entry from layoutConfig to {nameSpace, path, name}.
     * Accepts string shortcut ('partials/head/meta') or full array form.
     */
    private function normalizePartialEntry(mixed $entry, string $level, string $section): array
    {
        if (is_string($entry)) {
            $entry = trim($entry);
            if ($entry === '') {
                throw new LayoutManagerException(
                    "Empty partial entry at level:{$level}, section:{$section}"
                );
            }
            $lastSlash = strrpos($entry, '/');
            return [
                'nameSpace' => $this->nameSpace,
                'path'      => $lastSlash !== false ? substr($entry, 0, $lastSlash) : '',
                'name'      => $lastSlash !== false ? substr($entry, $lastSlash + 1) : $entry,
            ];
        }

        if (is_array($entry)) {
            $name = $entry['name'] ?? null;
            $path = $entry['path'] ?? null;
            if (!$name || $path === null) {
                throw new LayoutManagerException(
                    "Missing 'name' or 'path' in layoutConfig level:{$level}, section:{$section}"
                );
            }
            return [
                'nameSpace' => $entry['nameSpace'] ?? $this->nameSpace,
                'path'      => $path,
                'name'      => $name,
            ];
        }

        throw new LayoutManagerException(
            "Invalid layoutConfig entry at level:{$level}, section:{$section} — "
            . "expected string or array, got " . gettype($entry)
        );
    }

    private function loadCssFromConfig(array $styleSheets): void
    {
        foreach ($styleSheets as $styleSheet) {
            $this->addCss(
                $styleSheet['name'] ?? LayoutDefaults::STYLESHEET_NAME,
                $styleSheet['nameSpace'] ?? $this->nameSpace,
                $styleSheet['media'] ?? ''
            );
        }
    }

    private function loadJsFromConfig(array $javascripts): void
    {
        foreach ($javascripts as $javascript) {
            $this->addJs(
                name:      $javascript['name'],
                nameSpace: $javascript['nameSpace'] ?? $this->nameSpace,
                position:  $javascript['position'] ?? 'footer',
                defer:     (bool) ($javascript['defer'] ?? true),
                async:     (bool) ($javascript['async'] ?? false),
            );
        }
    }

    private function toWebPath(string $absFilePath): string
    {
        $normalized = str_replace('\\', '/', $absFilePath);
        $base       = rtrim(str_replace('\\', '/', ABS_PUBLIC_PATH), '/');
        return '/' . ltrim(substr($normalized, strlen($base)), '/');
    }
}
