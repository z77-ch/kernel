<?php

namespace Z77\Persistence\Validation;

use Z77\Shared\Libraries\Convention\Naming,
    Z77\Persistence\Concurrency\EntityStateHash;

abstract class EntityValidator
{
    protected object $entity;
    private array $errors = [];
    private array $fieldErrors = [];
    private ?string $stateConflict = null;

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
        if ($this->stateConflict !== null) {
            $this->errors[] = $this->stateConflict;
        }
        $this->executeValidation($only);
        return empty($this->errors) && empty($this->fieldErrors);
    }

    /**
     * Optimistic locking: compares the hash the edit form was rendered from
     * (hidden entity_hash field) against the entity's CURRENT stored state.
     * A mismatch means someone else saved in between — the save is rejected
     * as a general validation error through the normal re-render path.
     *
     * MUST be called BEFORE mapFromArray() hydrates the POST body, while the
     * entity still carries the freshly loaded stored state. New entities have
     * no stored state — skip the call entirely. The conflict survives the
     * per-call reset in isValid() (state, not a rule result).
     */
    final public function guardStoredState(string $submittedHash): void
    {
        if ($submittedHash === '' || EntityStateHash::of($this->entity) !== $submittedHash) {
            $this->stateConflict = 'Der Eintrag wurde inzwischen geändert — neu laden und Änderung erneut anbringen.';
        }
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

    /**
     * Whether guardStoredState() detected a concurrent modification. Lets the
     * form template offer a reload control next to the conflict message.
     */
    final public function hasStateConflict(): bool { return $this->stateConflict !== null; }

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
            // One notion of "valid e-mail" for the whole framework; the rule
            // used to be copied here from PublicFormValidator, character for
            // character. Deliverability (reserved TLDs) is checked too: a
            // stored address that can never receive mail is not an address.
            if (!\Z77\Shared\Mail\MailAddress::isDeliverable((string)$this->currentValue)) {
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
