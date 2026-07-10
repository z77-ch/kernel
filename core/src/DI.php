<?php

namespace Z77\Core;

/**
 * Service container (Singleton).
 *
 * Services are registered once at bootstrap via set() and retrieved anywhere via
 * DI::getServiceName() or $di->get('ServiceName').
 */
class DI
{
    private static ?self $instance = null;
    private array $container = [];

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(bool $reset = false): self
    {
        if (self::$instance === null || $reset) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a service.
     *
     * @param string                 $name            Service name used to retrieve it.
     * @param string|object|callable $classDefinition Class name, existing object, or factory callable.
     * @param bool                   $shared          true = single shared instance; false = new instance on every get().
     */
    public function set(string $name, string|object|callable $classDefinition, bool $shared = false): self
    {
        if (!isset($this->container[$name])) {
            $this->container[$name] = (object)['def' => $classDefinition, 'shared' => $shared, 'instance' => null];
        }

        return $this;
    }

    /**
     * Retrieve a service. Throws if not registered.
     *
     * @param string $name
     *
     * @return object
     */
    public function get(string $name): object
    {
        if (!isset($this->container[$name])) {
            throw new \RuntimeException('Requested service "' . $name . '" not defined.');
        }

        $service = $this->container[$name];

        if (!$service->shared) {
            // Not shared — create a fresh instance on every call.
            return $this->createInstance($service->def, false);
        }

        if ($service->instance === null) {
            // First request — create and cache the shared instance.
            $service->instance = $this->createInstance($service->def, true);
        }

        return $service->instance;
    }

    /**
     * Instantiate a service from its definition.
     *
     * @param string|object|callable $definition
     * @param bool                   $shared
     *
     * @return object
     */
    private function createInstance(string|object|callable $definition, bool $shared): object
    {
        if (is_callable($definition)) {
            return $definition($this);
        }

        if (is_string($definition)) {
            return new $definition;
        }

        if (is_object($definition)) {
            if ($shared) {
                return $definition;
            } else {
                return new ($definition::class)();
            }
        }

        throw new \RuntimeException('Malformed service definition!');
    }

    public function __call($name, $args)
    {
        $objectName = substr($name, 3);
        return $this->get($objectName);
    }

    public static function __callStatic($name, $args)
    {
        $objectName = substr($name, 3); // e.g. getFileFinder → get('FileFinder')
        return self::getInstance()->get($objectName);
    }
}
