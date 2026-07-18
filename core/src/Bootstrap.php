<?php
namespace Z77\Core;

use Z77\Core\DI,
    Z77\Core\Exception\ExceptionHandler,
    Z77\Core\Exception\FileNotFoundException,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Exception\InvalidRouteException,
    Z77\Core\Exception\LocalizedRedirectException,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\Security\CsrfService,
    Z77\Core\Routing\Dispatcher,
    Z77\Core\Services\AccessGuard,
    Z77\Core\Services\MessageService,
    Z77\Core\Services\NavigationService,
    Z77\Core\Services\NavigationUrlResolver,
    Z77\Shared\Entities\Navigation,
    Z77\Shared\Entities\NavigationAlias,
    Z77\Shared\Entities\MetaData,
    Z77\Core\Routing\PageCachePolicy,
    Z77\Core\Routing\Router,
    Z77\Core\Session\SessionManager,
    Z77\Core\Services\ModuleManager,
    Z77\Core\Services\I18n,
    Z77\Core\Services\Translator,
    Z77\Core\Services\SlugTranslator,
    Z77\Core\Services\TranslationCatalog,
    Z77\Persistence\File\Storage\FileStorage,
    Z77\Core\Controller\ControllerHandler,
    Z77\Core\Libraries\ConfigManager,
    Z77\Core\Libraries\FileFinder,
    Z77\Core\Libraries\CacheManager,
    Z77\Persistence\Resolver\DataSourceResolver,
    Z77\Persistence\Resolver\UnifiedEntityManager,
    Z77\Shared\Libraries\Convention\Naming,
    Z77\Shared\Services\AuthService,
    Z77\Shared\Services\CurrentUserService,
    Z77\Shared\Mail\EmailService
;

/**
 * Bootstrap
 *
 * Wires the DI container, loads configuration, and configures error handling.
 * Called once per request by index.php.
 *
 * Two-phase startup:
 *   __construct() — infrastructure: config, DI, error handling
 *   pullUp()      — routing: request parsing, session, router
 */
class Bootstrap
{
    /**
     * Wires infrastructure services and configures the runtime environment.
     *
     * Sequence:
     *   1. Register CacheManager, FileFinder, ConfigManager in DI
     *   2. Load bootstrap config
     *   3. Define DEBUG constant and configure error reporting
     *   4. Load global helper functions
     *   5. Set timezone
     *   6. Locate log directory
     */
    public function __construct()
    {
        // 1. Register infrastructure services
        DI::getInstance(true)
            ->set('CacheManager', CacheManager::class, true)
            ->set('FileFinder', function($c) {
                return new FileFinder($c->get('CacheManager'));
            }, true)
            ->set('ConfigManager', function($c) {
                return new ConfigManager($c->get('FileFinder'), $c->get('CacheManager'));
            }, true)
        ;
        $fileFinder = DI::getFileFinder();

        // 2. Load bootstrap config
        $bootstrapConfig = DI::getConfigManager()
            ->getBaseConfig(
                configName: 'config/bootstrap',
                cachePersist: false // never APCu-cache: bootstrap config must always be read fresh per request
            )
        ;

        // 3. Configure error handling
        define('DEBUG', file_exists(ABS_BASE_PATH . '/data/framework/debug.flag'));
        // Site-wide search-engine crawl block (staging / pre-launch) — flag-file
        // driven exactly like DEBUG (see metadata.md SEO-NOINDEX-001). When true the
        // frontend head emits `<meta name="robots" content="noindex, nofollow">` and
        // the backend shows a persistent Störer. Distinct from per-page MetaData.
        define('SEO_NOINDEX', file_exists(ABS_BASE_PATH . '/data/framework/seo/noindex.flag'));
        DI::getCacheManager()->setCacheDir($bootstrapConfig->getCacheDir());

        if (DEBUG) {
            DI::getCacheManager()->clearAllApcu();
        }

        define('ABS_PUBLIC_PATH', ABS_BASE_PATH.'/'.ltrim($bootstrapConfig->getHtmlRoot(), '/'));

        $logDir = $fileFinder->getAbsPath('/logs', true);

        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/php-error.log');
        ini_set('display_errors', DEBUG ? '1' : '0');
        error_reporting(E_ALL);

        // 4. Load global helper functions
        require_once $fileFinder->getFirstSourceMatch(
            'autoload/preBoot/php/Functions.php',
            Naming::toNamespaceString(['Z77', 'Core'])
        );

        // 5. Set application timezone
        date_default_timezone_set($bootstrapConfig->getTimeZone());
    }

    /**
     * Registers routing services, parses the request, and returns the Dispatcher.
     *
     * Also defines REL_INDEX_PATH, loads autoloads, and starts the session.
     * The session is started after request parsing deliberately — routing first
     * validates that the request is valid (correct module, controller, action).
     * If routing throws, the request is rejected and no session is needed.
     * Starting the session only after a successful route match avoids unnecessary
     * session overhead (cookie, session file read/write) for invalid requests.
     *
     * @return Dispatcher
     */
    public function pullUp(): Dispatcher
    {
        define('REL_INDEX_PATH', getRelativePath(ABS_INDEX_PATH));

        // Register routing services
        $di = DI::getInstance();
        $di->set('ModuleManager', function($c) {
                return new ModuleManager($c->getConfigManager());
            }, true)
            ->set('I18n', function($c) {
                return new I18n($c->getConfigManager());
            }, true)
            ->set('Translator', function() {
                return new Translator();
            }, true)
            ->set('SlugTranslator', function() {
                return new SlugTranslator();
            }, true)
            ->set('TranslationCatalog', function($c) {
                return new TranslationCatalog(
                    new FileStorage(),
                    $c->get('I18n'),
                    $c->getCacheManager()
                );
            }, true)
            ->set('ControllerHandler', function($c) {
                return new ControllerHandler($c->getModuleManager());
            }, true)
            ->set('Request', 'Z77\\Core\\Http\\Request', true)
            ->set('DataSourceResolver', function() {
                return new DataSourceResolver(['file' => 'File']);
            }, true)
            ->set('UnifiedEntityManager', function($c) {
                return new UnifiedEntityManager($c->get('DataSourceResolver'));
            }, true)
            ->set('NavigationUrlResolver', function($c) {
                $uem = $c->get('UnifiedEntityManager');
                return new NavigationUrlResolver(
                    $uem->getRepository(NavigationAlias::class),
                    $c->getCacheManager()
                );
            }, true)
            ->set('NavigationService', function($c) {
                $uem = $c->get('UnifiedEntityManager');
                return new NavigationService(
                    $uem->getRepository(Navigation::class),
                    $uem->getRepository(MetaData::class),
                    $c->getModuleManager(),
                    $c->get('NavigationUrlResolver'),
                    $c->getCacheManager()
                );
            }, true)
            // Content is consumption, not framework infrastructure: BlockRegistry
            // and ContentService are built on demand by their consumers via
            // factories (BlockRegistry::assemble() / ContentService::create()) —
            // deliberately NOT registered here. See adr-012.
            ->set('Router', function($c) {
                return new Router(
                    $c->get('NavigationService'),
                    $c->get('NavigationUrlResolver')
                );
            }, true)
            ->set('PageCachePolicy', function($c) {
                // Lazy factory: first resolved via the Dispatcher factory, at
                // which point AuthService (registered after routing) exists.
                return new PageCachePolicy(
                    $c->getModuleManager(),
                    $c->getCacheManager()->page(),
                    $c->get('AuthService'),
                    DEBUG
                );
            }, true)
        ;

        $request = $di->getRequest();

        try {
            $request->runParsing();
            /*
             * After Request parsing succeeded (no NotFoundException thrown),
             * lock ControllerHandler to guarantee that the current module,
             * action method, and controller class are resolved and cannot
             * be overwritten.
             */
            DI::getControllerHandler()->lock();
        } catch (LocalizedRedirectException $e) {
            // SEO single-form (ADR-014): canonical/non-localized slug reached a real
            // page → permanent redirect to its localized form. Routing-control flow,
            // not an error — emit the 301 and stop before dispatch (mirrors the
            // ExceptionHandler::handle() exit on the rejection paths below).
            (new RedirectResponse($e->getTargetUrl(), 301))->send();
            exit;
        } catch (
            NotFoundException |
            FileNotFoundException |
            InvalidRouteException $e
        ) {
            ExceptionHandler::handle($e);
        }

        // Session is started after routing — see method docblock for rationale
        $di->set('SessionManager', function() {
                return new SessionManager();
            }, true)
            ->set('MessageService', function($c) {
                return new MessageService($c->get('SessionManager'));
            }, true)
            ->set('EmailService', function() {
                return new EmailService();
            }, true)
            ->set('CsrfService', function($c) {
                return new CsrfService($c->get('SessionManager'));
            }, true)
            ->set('AuthService', function($c) {
                return new AuthService(
                    $c->get('SessionManager'),
                    $c->get('ControllerHandler')
                );
            }, true)
            ->set('CurrentUserService', function($c) {
                return new CurrentUserService(
                    $c->get('AuthService'),
                    $c->get('UnifiedEntityManager')
                );
            }, true)
            ->set('AccessGuard', function($c) {
                return new AccessGuard(
                    $c->get('AuthService'),
                    $c->get('SessionManager'),
                    $c->get('ControllerHandler'),
                    $c->get('ModuleManager'),
                    $c->get('CsrfService'),
                    $c->get('MessageService')
                );
            }, true)
            ->set('Dispatcher', function($c) {
                return new Dispatcher($c->getCacheManager(), $c->get('PageCachePolicy'), $c->get('AccessGuard'));
            }, true)
        ;

        // Load global helpers — only after successful routing, before Dispatcher::execute()
        $fileFinder = DI::getFileFinder();
        require_once $fileFinder->getFirstSourceMatch(
            'autoload/prod/php/Functions.php',
            Naming::toNamespaceString(['Z77', 'Core'])
        );
        require_once $fileFinder->getFirstSourceMatch(
            'autoload/prod/php/Helper.php',
            Naming::toNamespaceString(['Z77', 'Core'])
        );
        if (DEBUG) {
            require_once $fileFinder->getFirstSourceMatch(
                'autoload/debug/php/Functions.php',
                Naming::toNamespaceString(['Z77', 'Core'])
            );
            setOwnExceptionHandler();
        }

        return $di->getDispatcher();
    }
}
