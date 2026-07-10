<?php

namespace Z77\Core\Config;

class Config
{
    private array $data;
    private bool $mutable;
    private bool $isDirty = false;

    public function __construct(array $data, bool $mutable = false)
    {
        $this->data = $this->sanitize($data);
        $this->mutable = $mutable;
    }

    public function get(string|array $key, mixed $default = null): mixed
    {
        $result = $this->traverse((array) $key);
        return $result['found'] ? $result['value'] : $default;
    }

    public function has(string|array $key): bool
    {
        return $this->traverse((array) $key)['found'];
    }

    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) $data[$k] = $this->sanitize($v);
            elseif (!is_scalar($v) && $v !== null) {
                throw new \InvalidArgumentException("Invalid config value for {$k}");
            }
        }
        return $data;
    }
    /**
     * Hilfsfunktion zum Durchlaufen eines verschachtelten Arrays.
     * Gibt ['found' => bool, 'value' => mixed] zurück.
     */
    private function traverse(array $keys): array
    {
        $current = $this->data;

        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return ['found' => false, 'value' => null];
            }
            $current = $current[$k];
        }

        return ['found' => true, 'value' => $current];
    }

    public function set(string|array $key, mixed $value): void
    {
        if (!$this->mutable) {
            throw new \RuntimeException("Config is immutable – cannot modify '{$key}'.");
        }
        $this->isDirty = true;

        $keys = (array) $key;
        $current =& $this->data;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current =& $current[$k];
        }

        $current = $value;
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function isMutable(): bool
    {
        return $this->mutable;
    }

    public function toObject(): object
    {
        return json_decode(json_encode($this->data));
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function __call(string $name, array $args): mixed
    {
        $method = substr($name, 0, 3);
        $key = lcfirst(substr($name, 3)); // z. B. getDatabaseHost → "databaseHost"

        switch ($method) {
            case 'get':
                return $this->get($key, $args[0] ?? null);

            case 'set':
                if (!isset($args[0])) {
                    throw new \InvalidArgumentException("Missing value for {$name}()");
                }
                $this->set($key, $args[0]);
                return $this; // fluent chaining (z. B. $config->setFoo('x')->setBar('y'));

            default:
                throw new \BadMethodCallException("Undefined dynamic method {$name}()");
        }
    }

}
