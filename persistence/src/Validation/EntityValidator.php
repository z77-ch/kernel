<?php

namespace Z77\Persistence\Validation;

use Z77\Shared\Libraries\Convention\Naming;

abstract class EntityValidator
{
    protected object $entity;
    private array $errors = [];
    private array $fieldErrors = [];

    private string $currentField = '';
    private string $currentLabel = '';
    private mixed $currentValue = null;

    public function __construct(object $entity)
    {
        $this->entity = $entity;
    }

    /**
     * Runs validation and returns whether the entity is valid. Each call resets
     * state and re-runs — validators are pure functions over already-cleaned
     * values, so re-running is cheap.
     *
     * @param array<string>|null $only When provided, only fields listed here are
     *     validated (snake_case keys matching the entity's `mapToArray()`).
     *     Fields without a `validate{FieldName}()` method are silently skipped.
     */
    final public function isValid(?array $only = null): bool
    {
        $this->errors      = [];
        $this->fieldErrors = [];
        $this->executeValidation($only);
        return empty($this->errors) && empty($this->fieldErrors);
    }

    protected function executeValidation(?array $only = null): void
    {
        foreach ($this->entity->mapToArray() as $key => $value) {
            if ($only !== null && !in_array($key, $only, true)) {
                continue;
            }
            $method = 'validate' . Naming::toCamelCase($key);
            if (method_exists($this, $method)) {
                $this->$method($value);
            }
        }
    }

    public function getErrors(): array { return $this->errors; }
    public function getFieldErrors(): array { return $this->fieldErrors; }
    public function hasErrors(): bool { return !empty($this->errors) || !empty($this->fieldErrors); }
    public function hasFieldError(string $field): bool { return isset($this->fieldErrors[$field]); }
    public function getFieldError(string $field): string { return $this->fieldErrors[$field] ?? ''; }

    protected function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    protected function addFieldError(string $field, string $message): void
    {
        $this->fieldErrors[$field] = $message;
    }

    // fluent validation builder — call inside validate{FieldName}() methods
    protected function validate(string $field, string $label, mixed $value): static
    {
        $this->currentField = $field;
        $this->currentLabel = $label;
        $this->currentValue = $value;
        return $this;
    }

    protected function notEmpty(): static
    {
        if (!isset($this->fieldErrors[$this->currentField]) && mb_strlen((string)$this->currentValue) === 0) {
            $this->fieldErrors[$this->currentField] = $this->currentLabel . ' ist ein Pflichtfeld';
        }
        return $this;
    }

    protected function minLength(int $min): static
    {
        if (!isset($this->fieldErrors[$this->currentField]) && mb_strlen((string)$this->currentValue) < $min) {
            $this->fieldErrors[$this->currentField] = $this->currentLabel . ' muss mindestens ' . $min . ' Zeichen lang sein';
        }
        return $this;
    }

    protected function maxLength(int $max): static
    {
        if (!isset($this->fieldErrors[$this->currentField]) && mb_strlen((string)$this->currentValue) > $max) {
            $this->fieldErrors[$this->currentField] = $this->currentLabel . ' darf maximal ' . $max . ' Zeichen lang sein';
        }
        return $this;
    }

    protected function isEmail(): static
    {
        if (!isset($this->fieldErrors[$this->currentField])) {
            $v = (string)$this->currentValue;
            if (!filter_var($v, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $v)) {
                $this->fieldErrors[$this->currentField] = $this->currentLabel . ' hat ein ungültiges Format';
            }
        }
        return $this;
    }

    protected function isUrl(): static
    {
        if (!isset($this->fieldErrors[$this->currentField])) {
            if (!preg_match('/^[a-z0-9\-_\/]+$/', (string)$this->currentValue)) {
                $this->fieldErrors[$this->currentField] = $this->currentLabel . ' darf nur Kleinbuchstaben (a-z), Ziffern (0-9), - _ / enthalten';
            }
        }
        return $this;
    }

    protected function isAlphaAscii(): static
    {
        if (!isset($this->fieldErrors[$this->currentField])) {
            if (!preg_match('/^[a-z_\-]+$/', mb_strtolower((string)$this->currentValue))) {
                $this->fieldErrors[$this->currentField] = $this->currentLabel . ' darf nur ASCII-Buchstaben (a-z) und _ - enthalten';
            }
        }
        return $this;
    }

    protected function isAlphaAsciiNum(): static
    {
        if (!isset($this->fieldErrors[$this->currentField])) {
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', (string)$this->currentValue)) {
                $this->fieldErrors[$this->currentField] = $this->currentLabel . ' darf nur ASCII-Buchstaben (a-z, A-Z), Ziffern (0-9) und _ - enthalten';
            }
        }
        return $this;
    }

    protected function isAlphaNum(): static
    {
        if (!isset($this->fieldErrors[$this->currentField])) {
            if (!preg_match('/^[A-Za-z0-9 \.,àäüèöéçÄÖÜô\-]+$/u', (string)$this->currentValue)) {
                $this->fieldErrors[$this->currentField] = $this->currentLabel . ' enthält unerlaubte Zeichen';
            }
        }
        return $this;
    }
}
