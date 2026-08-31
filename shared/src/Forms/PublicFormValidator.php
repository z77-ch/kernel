<?php

namespace Z77\Shared\Forms;

use Z77\Shared\Mail\MailAddress;

use Z77\Core\DI,
    Z77\Persistence\Validation\EntityValidator
;

/**
 * Validates a {@see PublicForm} against the rules declared in its
 * {@see FormDefinition} — the same rules for the full submit and for the
 * per-field blur check (`isValid(['email'])`), so both paths can never drift
 * apart.
 *
 * Inherits the error infrastructure from {@see EntityValidator} (isValid()
 * with the `$only` filter, addFieldError(), getFieldErrors()) but runs the
 * checks itself: a public form is multilingual, so its messages come from the
 * translator (`form.error.{rule}`, `{label}`/`{min}`/`{max}` placeholders) and
 * can be overridden per field via the spec's `messages` map. The inherited
 * fluent checks (notEmpty/minLength/…) carry hard-wired German texts and stay
 * reserved for the single-language backend entities.
 */
final class PublicFormValidator extends EntityValidator
{
    public function __construct(PublicForm $form)
    {
        parent::__construct($form);
    }

    protected function executeValidation(?array $only = null): void
    {
        /** @var PublicForm $form */
        $form = $this->entity;

        foreach ($form->fields() as $name => $spec) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            $this->validateField($form, $name, $spec);
        }
    }

    /**
     * One field, rules in a fixed order: presence first, then format. The first
     * failing rule wins (one message per field, like the blur hint shows one).
     */
    private function validateField(PublicForm $form, string $name, array $spec): void
    {
        $rules = $spec['rules'];
        $value = $form->get($name);

        // Consent checkbox: must be ticked; nothing else applies.
        if (($rules['accepted'] ?? false) === true) {
            if (!$form->isChecked($name)) {
                $this->fail($name, $spec, 'accepted');
            }
            return;
        }

        if ($value === '') {
            if (($rules['required'] ?? false) === true) {
                $this->fail($name, $spec, 'required');
            }
            return; // empty + optional → no format rules
        }

        // A declared options map is the whitelist for that field's value.
        if ($spec['options'] !== [] && !isset($spec['options'][$value])) {
            $this->fail($name, $spec, 'option');
            return;
        }

        if (isset($rules['min']) && mb_strlen($value) < (int) $rules['min']) {
            $this->fail($name, $spec, 'min', ['min' => (int) $rules['min']]);
            return;
        }

        if (isset($rules['max']) && mb_strlen($value) > (int) $rules['max']) {
            $this->fail($name, $spec, 'max', ['max' => (int) $rules['max']]);
            return;
        }

        if (($rules['email'] ?? false) === true && !self::isEmailAddress($value)) {
            $this->fail($name, $spec, 'email');
        }
    }

    /**
     * A public form exists to SEND something to this address, so the bar is
     * deliverability, not just shape: an address in a reserved TLD is refused
     * here (see MailAddress). It can never receive the mail it just asked
     * for, and letting it through buys a bounce and a dead account.
     */
    private static function isEmailAddress(string $value): bool
    {
        return MailAddress::isDeliverable($value);
    }

    /**
     * Records the field error, translated: the spec's `messages[$rule]` key wins
     * over the framework default `form.error.{$rule}`.
     */
    private function fail(string $name, array $spec, string $rule, array $params = []): void
    {
        $key = $spec['messages'][$rule] ?? 'form.error.' . $rule;

        $this->addFieldError($name, DI::getTranslator()->t($key, $params + ['label' => $spec['label']]));
    }
}
