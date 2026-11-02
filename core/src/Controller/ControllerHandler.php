<?php

namespace Z77\Core\Controller;

use Z77\Core\Services\ModuleManager,
    Z77\Shared\Libraries\Convention\Naming
;

class ControllerHandler
{
    private ModuleManager $moduleManager;
    private ?string $currentModule = null;
    private ?string $currentGroup = null;
    private ?string $currentControllerClassName = null;
    private ?string $currentActionMethod = null;
    private ?object $currentControllerInstance = null;
    private bool $locked = false;

    public function __construct(ModuleManager $moduleManager) {
        $this->moduleManager = $moduleManager;
    }

    public function hasAction(string $action): bool
    {
        return ($this->resolveAction($action) !== null);
    }

    public function getCurrentActionMethod(): string
    {
        return $this->currentActionMethod
            ?? throw new \LogicException('ControllerHandler has not resolved an action yet.');
    }

    public function getCurrentModule(): string
    {
        return $this->currentModule
            ?? throw new \LogicException('ControllerHandler has not resolved a module yet.');
    }

    public function getCurrentGroup(): string
    {
        return $this->currentGroup
            ?? throw new \LogicException('ControllerHandler has not resolved a group yet.');
    }

    public function getCurrentControllerClassName(): string
    {
        return $this->currentControllerClassName
            ?? throw new \LogicException('ControllerHandler has not resolved a controller yet.');
    }

    public function getCurrentControllerInstance(): object
    {
        if ($this->currentControllerInstance !== null) {
            return $this->currentControllerInstance;
        }
        $this->currentControllerInstance = new $this->currentControllerClassName($this->currentActionMethod);

        return $this->currentControllerInstance;
    }

    private function resolveAction(string $action): ?string
    {
        if ($this->locked) {
            return $this->currentActionMethod;
        }
        $method = Naming::toActionMethod($action);
        if (
            !$this->currentControllerClassName ||
            !method_exists($this->currentControllerClassName, $method)
        ) {
            return null;
        }

        // ⚠️ PHP finds a method in any casing, the access config does not: `twofactorresetAction`
        // would run `twoFactorResetAction` while its role entry stays unread, and the gate would
        // fall back to the controller's role. Everything downstream gets the declared name.
        $method = (new \ReflectionMethod($this->currentControllerClassName, $method))->getName();

        $this->currentActionMethod = $method;

        return $method;
    }

    public function hasController(string $module, string $group, string $controller): bool
    {
        return ($this->resolveController($module, $group, $controller) !== null);
    }

    private function resolveController(string $module, string $group, string $controller): ?string
    {
        if ($this->locked) {
            return $this->currentControllerClassName;
        }
        $moduleNamespacePrefix = $this->moduleManager->getNamespacePrefix($module);
        $controllerClassName = Naming::toControllerClassName($moduleNamespacePrefix, $group, $controller);

        if (!class_exists($controllerClassName)) {
            return null;
        }
        // The same as for the action: on a case-insensitive filesystem `back-up` loads
        // BackupController, and the config is keyed by the declared name.
        $controllerClassName = (new \ReflectionClass($controllerClassName))->getName();
        $this->currentModule = $module;
        $this->currentGroup = $group;
        $this->currentControllerClassName = $controllerClassName;

        return $controllerClassName;
    }

    public function lock(): void
    {
        $this->locked = true;
    }
}
