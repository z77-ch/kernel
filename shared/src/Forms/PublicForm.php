<?php

namespace Z77\Shared\Forms;

/**
 * One submission of a {@see FormDefinition} — the generic form DTO that replaces
 * a hand-written per-form DTO (see docs/03-development/public-form-bauplan.md).
 * Values are addressed by field name, which is what makes generic templates (the
 * form partial and the e-mail body) possible in the first place.
 *
 * Only declared fields and declared honeypots are taken from the POST; every
 * value is normalized by its type (trim, e-mail lowercased, checkbox to bool),
 * mirroring the setters of the former per-project DTOs.
 */
final class PublicForm
{
    /** @var array<string,string|bool> field name => normalized value */
    private array $values = [];

    /** @var array<string,string> honeypot name => raw value */
    private array $honeypots = [];

    private function __construct(private FormDefinition $definition)
    {
        foreach ($this->definition->normalizedFields() as $name => $spec) {
            $this->values[$name] = $spec['type'] === FormDefinition::TYPE_CHECKBOX ? false : '';
        }
        foreach ($this->definition->honeypots() as $name) {
            $this->honeypots[$name] = '';
        }
    }

    /** An empty form — the initial GET render. */
    public static function blank(FormDefinition $definition): self
    {
        return new self($definition);
    }

    /** A form filled from POST data; unknown keys are ignored. */
    public static function fromPost(FormDefinition $definition, array $post): self
    {
        $form = new self($definition);

        foreach ($definition->normalizedFields() as $name => $spec) {
            if (array_key_exists($name, $post)) {
                $form->values[$name] = self::normalize($spec['type'], $post[$name]);
            }
        }
        foreach ($form->honeypots as $name => $unused) {
            $form->honeypots[$name] = trim((string) ($post[$name] ?? ''));
        }

        return $form;
    }

    private static function normalize(string $type, mixed $value): string|bool
    {
        return match ($type) {
            FormDefinition::TYPE_CHECKBOX => ((int) $value) > 0,
            FormDefinition::TYPE_EMAIL    => mb_strtolower(trim((string) $value)),
            default                       => trim((string) $value),
        };
    }

    /** The raw value as a string ('1'/'' for a checked/unchecked checkbox). */
    public function get(string $field): string
    {
        $value = $this->values[$field] ?? '';

        return is_bool($value) ? ($value ? '1' : '') : $value;
    }

    /**
     * Checkbox state, or — with $value — whether that radio option is selected
     * (template helper for `checked` attributes).
     */
    public function isChecked(string $field, ?string $value = null): bool
    {
        if ($value !== null) {
            return $this->get($field) === $value;
        }

        return ($this->values[$field] ?? false) === true;
    }

    /** The field's display label. */
    public function label(string $field): string
    {
        return $this->definition->normalizedFields()[$field]['label'] ?? $field;
    }

    /**
     * The value as shown to a human: for a field with `options` the option's
     * label, otherwise the raw value (checkbox → 'ja'/'' via the caller).
     */
    public function display(string $field): string
    {
        $options = $this->options($field);
        $value   = $this->get($field);

        return $options === [] ? $value : (string) ($options[$value] ?? '');
    }

    /** @return array<string,string> option value => label */
    public function options(string $field): array
    {
        return $this->definition->normalizedFields()[$field]['options'] ?? [];
    }

    /** @return array<string,array> the normalized field specs (render order) */
    public function fields(): array
    {
        return $this->definition->normalizedFields();
    }

    /** @return array<string,string|bool> field name => value */
    public function all(): array
    {
        return $this->values;
    }

    /** EntityValidator contract (the validator iterates this map). */
    public function mapToArray(): array
    {
        return $this->values;
    }

    /** True when a bot filled a hidden field — caller convention: fake success. */
    public function isHoneypotTripped(): bool
    {
        foreach ($this->honeypots as $value) {
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    public function definition(): FormDefinition
    {
        return $this->definition;
    }
}
