<?php

namespace Z77\Shared\Controller;

use Z77\Core\DI;

/**
 * Provides the routing context of the current request (module / controller short
 * name / action method / resolved main template path) for service panels — the
 * backend avatar "Info" section and the frontend admin overlay.
 *
 * Opt-in by design: each module's abstract controller `use`s this where it needs
 * it. Deliberately NOT in the core base controller, so it is never forced on
 * modules that do not want it.
 */
trait RouteInfoTrait
{
    /**
     * @return array{module: string, controller: string, action: string, template: string}
     */
    protected function routeInfo(): array
    {
        $handler    = DI::getControllerHandler();
        $class      = $handler->getCurrentControllerClassName();
        $pos        = strrpos($class, '\\');
        $controller = $pos !== false ? substr($class, $pos + 1) : $class;
        $action     = $handler->getCurrentActionMethod();

        return [
            'module'     => $handler->getCurrentModule(),
            'controller' => $controller,
            'action'     => $action,
            'template'   => $controller . '/' . $action . '.tpl.php',
        ];
    }
}
