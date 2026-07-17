<?php

namespace Z77\Core\Http;

use Z77\Core\DI,
    Z77\Core\Services\ModuleManager,
    Z77\Core\Session\SessionManager,
    Z77\Core\Controller\ControllerHandler,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Exception\InvalidRouteException,
    Z77\Core\Exception\LocalizedRedirectException,
    Z77\Core\Exception\FileNotFoundException,
    Z77\Shared\Libraries\Cleaner\StringCleaner,
    Z77\Shared\ValueObjects\UploadedFile
;

class Request {
    private string $language;
    private bool $languageFromUrl = false;
    private string $module;
    private string $group;
    private string $controller;
    private string $action;

    private string $method;
    private string $rawRequestUri;
    private array $pathSegments;
    /** Content slugs captured after a NavigationAlias match (ADR-015); empty otherwise. */
    private array $slugs = [];
    private RequestMode $mode;

    /** Session key under which the chosen language is remembered (ADR-013). */
    private const SESSION_LANGUAGE_KEY = 'language';

    private ModuleManager $moduleManager;
    private ControllerHandler $controllerHandler;

    public function __construct()
    {
        $this->setRawRequestUri();
        $this->method    = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');
        $this->mode      = $this->resolveRequestMode();
        $this->setPathSegments();

        $this->moduleManager = DI::getModuleManager();
        $this->controllerHandler = DI::getControllerHandler();
    }

    public function runParsing(): void
    {
        $this->extractLanguage();

        // Reserved routes (ADR-017 R3): highest precedence. Structural and
        // mode-independent — resolved before NavigationAlias / static nav / convention
        // AND before the Fetch short-circuit, because a reserved URL like /media is
        // reached via <img src> (Sec-Fetch-Mode: no-cors → Fetch mode), not only by
        // browser navigation. Matched on raw post-language segments (the trailing
        // folder/file path is structural and never language-translated).
        $reserved = $this->matchReserved($this->pathSegments);
        if ($reserved !== null) {
            $this->slugs = $reserved['slugs'];
            $this->assignModule($reserved['tuple']['module']);
            $this->assignGroup($reserved['tuple']['group']);
            $this->assignController($reserved['tuple']['controller']);
            $this->setAction($reserved['tuple']['action']);
            return;
        }

        $requestedSegments = $this->pathSegments;   // localized form, as requested
        $this->translateSlugsToCanonical();

        if ($this->mode === RequestMode::Fetch) {
            $this->parsePathSegments();
            return;
        }

        $hasNavigationSegment = !empty($this->pathSegments);

        if ($hasNavigationSegment) {
            // Precedence: NavigationAlias → static navigation → convention (ADR-015).
            // The alias match captures trailing content slugs; the navigation entry
            // it resolves to supplies the routing target (4-tuple).
            $aliasMatch = DI::getRouter()->matchAlias($this->pathSegments);

            if ($aliasMatch !== null) {
                $entry = $aliasMatch['navigation'];
                $this->slugs = $aliasMatch['slugs'];
                // Still required: enforces the single localized form for alias paths too
                // (e.g. /fr/schweiz/stadt 301 → /fr/suisse/ville). Localizes every matched
                // canonical segment; content slugs without a translation pass through
                // unchanged (entity-slug localization is Phase 5 / ADR-015 D3).
                $this->enforceLocalizedSlug($requestedSegments);
                $this->assignModule($entry->getModule());
                $this->assignGroup($entry->getGroup());
                $this->assignController($entry->getController());
                $this->setAction($entry->getAction());
                return;
            }

            $path  = '/' . implode('/', $this->pathSegments);
            $entry = DI::getRouter()->match($path, $_GET);

            if ($entry !== null) {
                $this->enforceLocalizedSlug($requestedSegments);
                $this->assignModule($entry->getModule());
                $this->assignGroup($entry->getGroup());
                $this->assignController($entry->getController());
                $this->setAction($entry->getAction());
                return;
            }
        }

        $this->parsePathSegments();
    }

    private function parsePathSegments(): void
    {
        $lastSegment = end($this->pathSegments);
        if ($lastSegment !== false && strpos($lastSegment, '.') !== false) {
            throw new FileNotFoundException('404');
        }

        $count = count($this->pathSegments);

        // Full 4-segment URL: assign positionally without default cascade.
        // Cascading set* methods would resolve default action in segment 2 before
        // segment 3 can set the explicit action — breaks POST-only controllers
        // like SystemController that intentionally have no defaultAction.
        if ($count >= 4) {
            $this->assignModule($this->pathSegments[0]);
            $this->assignGroup($this->pathSegments[1]);
            $this->assignController($this->pathSegments[2]);
            $this->setAction($this->pathSegments[3]);
            return;
        }

        // Partial URL (0-3 segments): cascade through defaults.
        $this->setDefaults();

        foreach ($this->pathSegments as $index => $path) {
            match ((int)$index) {
                0 => $this->setModule($path),
                1 => $this->setGroup($path),
                2 => $this->setController($path),
            };
        }
    }

    /**
     * Longest-prefix reserved-route match (ADR-017 R3). Returns the routing target
     * (4-tuple) plus the trailing segments as content slugs, or null when no reserved
     * prefix matches. Reserved routes are declared per module (`reservedRoutes` config)
     * and aggregated by {@see ModuleManager::getReservedRoutes()}.
     *
     * @param list<string> $segments canonical path segments (language already stripped)
     * @return array{tuple: array{module:string,group:string,controller:string,action:string}, slugs: list<string>}|null
     */
    private function matchReserved(array $segments): ?array
    {
        if ($segments === []) {
            return null;
        }

        $best    = null;
        $bestLen = 0;
        foreach ($this->moduleManager->getReservedRoutes() as $path => $tuple) {
            $prefix = array_values(array_filter(explode('/', $path)));
            $len    = count($prefix);
            if ($len === 0 || $len > count($segments) || $len <= $bestLen) {
                continue;
            }
            if (array_slice($segments, 0, $len) === $prefix) {
                $best    = ['tuple' => $tuple, 'slugs' => array_values(array_slice($segments, $len))];
                $bestLen = $len;
            }
        }

        return $best;
    }

    private function extractLanguage(): void
    {
        $i18n = DI::getI18n();
        $this->language = $i18n->getDefaultLanguage();

        if (empty($this->pathSegments)) {
            return;
        }

        $lang = $this->getCleanLanguage($this->pathSegments[0]);

        if ($lang) {
            $lang = strtolower($lang);
            // A two-letter alpha segment is reserved for the language. It MUST be a
            // configured language (i18n whitelist) — an unknown code is no longer
            // silently accepted (ADR-013).
            if (!$i18n->isValidLanguage($lang)) {
                throw new InvalidRouteException("Unknown language segment: {$this->pathSegments[0]}");
            }

            $this->language       = $lang;
            $this->languageFromUrl = true;
            array_shift($this->pathSegments);
            return;
        }

        if (strlen($this->pathSegments[0]) === 2) {
            throw new InvalidRouteException("Invalid language segment: {$this->pathSegments[0]}");
        }
    }

    /**
     * Translates localized URL path segments back to their canonical (default-
     * language) form (ADR-014), so the router and the whole resolution chain only
     * ever see canonical segments. Default language = canonical → no translation.
     * A non-translatable segment stays unchanged: already-canonical resolves,
     * genuine garbage 404s downstream (no matching controller/action).
     */
    private function translateSlugsToCanonical(): void
    {
        if (empty($this->pathSegments)
            || $this->language === DI::getI18n()->getDefaultLanguage()) {
            return;
        }

        $slugTranslator = DI::getSlugTranslator();
        $this->pathSegments = array_map(
            fn(string $segment): string => $slugTranslator->toCanonical($segment, $this->language),
            $this->pathSegments
        );
    }

    /**
     * SEO single-form enforcement (ADR-014, 301): in a non-default language the
     * localized slug is the one URL that should be indexed. If the page was reached
     * through any other form — its canonical slug, a partially-localized or a
     * wrong-language slug — permanently redirect to the localized form.
     *
     * Runs only after a successful route match, so the redirect target — built from
     * the matched canonical segments via the validated 1:1 slug table — is guaranteed
     * to canonicalize back to this very page (never a redirect into a 404). When the
     * requested form already IS the localized form, nothing happens. Read methods
     * only (GET/HEAD) — a POST is never 301'd (would drop the body).
     *
     * @param list<string> $requestedSegments the path segments (language prefix
     *                                         already stripped) exactly as requested
     * @throws LocalizedRedirectException when a 301 to the localized form is due
     */
    private function enforceLocalizedSlug(array $requestedSegments): void
    {
        // The default language IS canonical: it has no slug table and no localized
        // form, so it is never redirected. Only non-default languages own localized
        // URLs — this is the deliberate SEO choice (canonical = default language).
        if (!$this->isReadMethod()
            || $this->language === DI::getI18n()->getDefaultLanguage()) {
            return;
        }

        $slugTranslator = DI::getSlugTranslator();
        $localized = array_map(
            fn(string $segment): string => $slugTranslator->toLocalized($segment, $this->language),
            $this->pathSegments   // canonical (already translated)
        );

        if ($localized === $requestedSegments) {
            return;   // already the localized form (or nothing to localize)
        }

        $target = '/' . $this->language . '/' . implode('/', $localized);
        // Carry the original query verbatim — $_SERVER['QUERY_STRING'] is already
        // encoded and preserves repeated keys (?tag=a&tag=b) that $_GET would collapse.
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if ($query !== '') {
            $target .= '?' . $query;
        }

        throw new LocalizedRedirectException($target);
    }

    /**
     * Reconciles the request language with the session (ADR-013, revised by ADR-015).
     * Runs after the session starts (Dispatcher), before the page-cache language key.
     *
     * The rendered language of a resolved URL is ALWAYS determined by the URL itself —
     * a prefix sets it, its absence means the (canonical) default language. The session
     * NEVER overrides the rendered language of a resolved page; doing so made a
     * prefix-less canonical URL (`/home`) render in whatever language the session held,
     * so the same URL served different content (broken canonical / page-cache key).
     *
     * The remembered preference is used only to route the bare site root: a non-default
     * remembered language redirects `/` → `/{lang}` (the localized home). An explicit
     * prefix-less page (e.g. `/home`) renders the default language and is never
     * redirected — it is the stable canonical form.
     *
     * @return string|null target URL when the bare root must redirect, else null
     */
    public function applyLanguageSession(SessionManager $session): ?string
    {
        // URL prefix = explicit choice → persist it. Rendered language stays the URL's.
        if ($this->languageFromUrl) {
            $session->set(self::SESSION_LANGUAGE_KEY, $this->language);
            return null;
        }

        // No prefix: render the default language (never the session's). Only the bare
        // root honors the remembered preference, via a redirect to the localized root.
        $remembered = $session->get(self::SESSION_LANGUAGE_KEY);
        if ($this->pathSegments === []
            && is_string($remembered)
            && $remembered !== DI::getI18n()->getDefaultLanguage()
            && DI::getI18n()->isValidLanguage($remembered)
        ) {
            return '/' . $remembered;
        }
        return null;
    }

    /**
     * The current request path with the language prefix removed (e.g. `/about`
     * for both `/about` and `/fr/about`, `/` for the home root). Used to build
     * language-switch links that re-prefix the same page.
     */
    public function getPathWithoutLanguage(): string
    {
        return '/' . implode('/', $this->pathSegments);
    }

    private function setAction(string $action): void
    {
        $cleanActionName = $this->cleanAndTranslate($action, 'cleanAlphaNum');
        if ($this->controllerHandler->hasAction($cleanActionName)) {
            $this->action = $cleanActionName;
            return;
        }

        throw new NotFoundException("Action not Found: $cleanActionName");
    }

    private function setController(string $controller): void
    {
        $cleanController = $this->cleanAndTranslate($controller, 'cleanAlphaNum');

        if ($this->controllerHandler->hasController($this->module, $this->group, $cleanController)) {
            $this->controller = $cleanController;

            $defaultAction = $this->moduleManager->getDefaultActionForController($this->module, $this->group, $cleanController)
                ?? $this->moduleManager->getDefaultAction($this->module);

            if ($defaultAction === null) {
                throw new NotFoundException("No default action defined for controller: $cleanController");
            }

            $this->setAction($defaultAction);
            return;
        }

        throw new NotFoundException("Controller not Found: $cleanController");
    }

    private function setGroup(string $group): void
    {
        $cleanGroup = $this->cleanAndTranslate($group, 'cleanAlpha');

        if (!$this->moduleManager->hasGroup($this->module, $cleanGroup)) {
            throw new NotFoundException("Group not Found: $group");
        }
        $this->group = $cleanGroup;
        $this->setController($this->moduleManager->getGroupDefaultController($this->module, $cleanGroup));
    }

    private function assignModule(string $moduleUrl): void
    {
        $cleanModuleUrl = $this->cleanAndTranslate($moduleUrl, 'cleanAlpha');
        if (!$this->moduleManager->hasModule($cleanModuleUrl)) {
            throw new NotFoundException("Module not Found: $moduleUrl");
        }
        $this->module = $cleanModuleUrl;
    }

    private function assignGroup(string $group): void
    {
        $cleanGroup = $this->cleanAndTranslate($group, 'cleanAlpha');
        if (!$this->moduleManager->hasGroup($this->module, $cleanGroup)) {
            throw new NotFoundException("Group not Found: $group");
        }
        $this->group = $cleanGroup;
    }

    private function assignController(string $controller): void
    {
        $cleanController = $this->cleanAndTranslate($controller, 'cleanAlphaNum');
        if (!$this->controllerHandler->hasController($this->module, $this->group, $cleanController)) {
            throw new NotFoundException("Controller not Found: $cleanController");
        }
        $this->controller = $cleanController;
    }

    /**
     * @throws NotFoundException
     */
    private function setModule(string $moduleUrl): void
    {
        $cleanModuleUrl = $this->cleanAndTranslate($moduleUrl, 'cleanAlpha');

        if ($this->moduleManager->hasModule($cleanModuleUrl)) {
            $this->module = $cleanModuleUrl;
            $this->setGroup($this->moduleManager->getDefaultGroup($cleanModuleUrl));
            return;
        }

        throw new NotFoundException("Module not Found: $moduleUrl");
    }

    private function getCleanLanguage(string $lang): string
    {
        $lang = StringCleaner::cleanAlpha($lang);
        if (strlen($lang) === 2 && ctype_alpha($lang)) {
            return $lang;
        }
        return '';
    }

    private function cleanAndTranslate(string $dirty, string $cleaner): string
    {
        if (!$dirty) { return ''; }

        return StringCleaner::$cleaner($dirty);
    }

    private function setDefaults(): void
    {
        $this->setModule($this->moduleManager->getDefaultModuleKey());
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    private function setPathSegments(): void
    {
        $parts = $this->parseUrl();
        $pathString = $parts['path'] ?? '';
        $pathString = $this->removeBasePath($pathString, REL_INDEX_PATH);
        $this->pathSegments = array_merge(array_filter(explode('/', $pathString)));
    }

    public function getMode(): RequestMode
    {
        return $this->mode;
    }

    private function resolveRequestMode(): RequestMode
    {
        $fetchMode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';

        if ($fetchMode !== '' && $fetchMode !== 'navigate') {
            return RequestMode::Fetch;
        }

        // NOTE: Accept-header fallback intentionally removed.
        // z77 does not serve as an API backend — API access is a separate concern.
        // Non-browser clients (Postman, mobile apps) are not supported via this routing.

        return RequestMode::Page;
    }

    private function parseUrl(): array
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $rawUrl = $scheme . "://" . $host . $this->rawRequestUri;

        return parse_url($rawUrl);
    }

    private function setRawRequestUri(): void
    {
        // rawurldecode() is correct for URI paths — unlike urldecode(), it does not convert + to space.
        $this->rawRequestUri = rawurldecode($_SERVER['REQUEST_URI'] ?? '/');
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getController(): string
    {
        return $this->controller;
    }

    public function isPost(): bool
    {
        return ($this->method === 'post');
    }

    public function isGet(): bool
    {
        return ($this->method === 'get');
    }

    public function isHead(): bool
    {
        return $this->method === 'head';
    }

    /**
     * GET and HEAD are equivalent for caching/routing — HEAD is "GET without body".
     * Use this anywhere you would otherwise reject non-GET methods for safe reads.
     */
    public function isReadMethod(): bool
    {
        return $this->method === 'get' || $this->method === 'head';
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPostParameter(string $parameter): mixed
    {
        return $_POST[$parameter] ?? null;
    }

    /**
     * All POST body parameters. THE way to hand a whole form to a DTO/mapper
     * (e.g. Form::fromPost($request->getPostParameters())) — controllers must
     * never touch $_POST directly (Key Rule 4).
     */
    public function getPostParameters(): array
    {
        return $_POST;
    }

    /**
     * Uploaded files for a form field as transport-agnostic {@see UploadedFile} VOs.
     * Handles both a single `<input name="doc">` and a multiple `<input name="doc[]">`
     * (PHP's column-wise `$_FILES` layout). Entries with no file are skipped; an absent
     * field yields an empty array. The bytes/MIME are resolved server-side by the VO —
     * the downstream `UploadService` never sees `$_FILES` (placement decision C).
     *
     * @return list<UploadedFile>
     */
    public function getUploadedFiles(string $field): array
    {
        if (!isset($_FILES[$field])) {
            return [];
        }
        $entry = $_FILES[$field];

        // Multiple: every key holds an array, indexed by upload slot.
        if (is_array($entry['name'])) {
            $files = [];
            foreach (array_keys($entry['name']) as $i) {
                if ((int) ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $files[] = UploadedFile::fromPhpFile([
                    'name'     => $entry['name'][$i] ?? '',
                    'tmp_name' => $entry['tmp_name'][$i] ?? '',
                    'size'     => $entry['size'][$i] ?? 0,
                    'type'     => $entry['type'][$i] ?? '',
                    'error'    => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                ]);
            }
            return $files;
        }

        // Single upload.
        if ((int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        return [UploadedFile::fromPhpFile($entry)];
    }

    /**
     * First uploaded file for a field, or null if none.
     */
    public function getUploadedFile(string $field): ?UploadedFile
    {
        return $this->getUploadedFiles($field)[0] ?? null;
    }

    public function getJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getGetParameter(string $parameter): mixed
    {
        return $_GET[$parameter] ?? null;
    }

    public function getQueryParameters(): array
    {
        return $_GET;
    }

    /**
     * Positional content slugs captured after a NavigationAlias match (ADR-015) —
     * the path remainder beyond the matched alias, in canonical form. Empty for
     * routes without a dynamic remainder, in Fetch mode, and on convention routes.
     * The action resolves its entity from these (e.g. `getSlugs()[0]` = `basel`).
     *
     * @return list<string>
     */
    public function getSlugs(): array
    {
        return $this->slugs;
    }

    public function hasQueryString(): bool
    {
        return !empty($_GET);
    }

    public function getCsrfToken(): ?string
    {
        $value = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        return ($value === null || $value === '') ? null : $value;
    }

    public function getIfNoneMatch(): ?string
    {
        $value = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        return ($value === null || $value === '') ? null : $value;
    }

    private function removeBasePath(string $fullPath, string $basePath): string
    {
        $fullPath = ltrim($fullPath, '/');
        $basePath = trim($basePath, '/');

        if (str_starts_with($fullPath, $basePath)) {
            $rest = substr($fullPath, strlen($basePath));
            return ltrim($rest, '/');
        }

        return $fullPath;
    }
}
