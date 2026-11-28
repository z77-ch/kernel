<?php
namespace Z77\Core\Services;

use Z77\Core\Controller\ControllerHandler,
    Z77\Core\Http\Request,
    Z77\Shared\Attributes\CrossOriginPost,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Attributes\Page,
    Z77\Shared\Security\CrossOriginPolicyInterface
;

/**
 * The two keys of a cross-origin POST door (CROSS-ORIGIN-POST-001), read by Bootstrap after the
 * route is locked and before any session service exists:
 *
 *   - the action method carries #[CrossOriginPost], AND
 *   - the module config names the same action under `crossOriginPosts`:
 *     `'group/controller/action' => <CrossOriginPolicyInterface FQCN>`.
 *
 * Either key without the other is a config error and THROWS — the door never opens by one of them
 * alone, and an entry for an action that lost its attribute is not silently ignored. The action
 * must also carry exactly #[HttpMethod('POST')] (so OPTIONS stays a 404 and no preflight is ever
 * answered) and neither #[Csrf] nor #[Page] (both contradict the door).
 */
final class CrossOriginPostDoor
{
    public const CONFIG_KEY = 'crossOriginPosts';

    /**
     * The policy FQCN when the current action is a door, null when it is none.
     *
     * @throws \LogicException when the two keys disagree or the action is declared wrongly
     */
    public static function policyFor(Request $request, ControllerHandler $handler, ModuleManager $modules): ?string
    {
        $class  = $handler->getCurrentControllerClassName();
        $method = $handler->getCurrentActionMethod();
        if (!method_exists($class, $method)) {
            return null;   // the Dispatcher answers «action not found»
        }

        $route    = self::route($request);
        $declared = $modules->getModuleConfig($handler->getCurrentModule())?->get(self::CONFIG_KEY, []) ?? [];
        $policy   = is_array($declared) ? ($declared[$route] ?? null) : null;

        $ref      = new \ReflectionMethod($class, $method);
        $hasDoor  = $ref->getAttributes(CrossOriginPost::class) !== [];

        if (!$hasDoor && $policy === null) {
            return null;
        }
        if (!$hasDoor) {
            throw new \LogicException(
                "'{$route}' is named in " . self::CONFIG_KEY . " but {$class}::{$method}() has no #[CrossOriginPost]."
            );
        }
        if ($policy === null) {
            throw new \LogicException(
                "{$class}::{$method}() carries #[CrossOriginPost] but '{$route}' is not named in " . self::CONFIG_KEY . '.'
            );
        }
        if (!is_string($policy) || !is_subclass_of($policy, CrossOriginPolicyInterface::class)) {
            throw new \LogicException(
                self::CONFIG_KEY . "['{$route}'] must name a class implementing CrossOriginPolicyInterface."
            );
        }

        $methods = $ref->getAttributes(HttpMethod::class);
        if ($methods === [] || $methods[0]->newInstance()->methods !== ['POST']) {
            throw new \LogicException("{$class}::{$method}(): a #[CrossOriginPost] action must carry exactly #[HttpMethod('POST')].");
        }
        if ($ref->getAttributes(Csrf::class) !== [] || $ref->getAttributes(Page::class) !== []) {
            throw new \LogicException("{$class}::{$method}(): #[CrossOriginPost] excludes #[Csrf] and #[Page].");
        }

        return $policy;
    }

    /** The door's name as `crossOriginPosts` writes it: `group/controller/action` of the URL. */
    public static function route(Request $request): string
    {
        return $request->getGroup() . '/' . $request->getController() . '/' . $request->getAction();
    }
}
