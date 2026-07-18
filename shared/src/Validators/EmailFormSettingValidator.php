<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates an {@see \Z77\Shared\Entities\EmailFormSetting} before it is
 * persisted from the backend editor. Field keys are snake_case (mapToArray).
 *
 * - Every recipient entry must be a literal, valid address. The reserved
 *   `ref:{source}:{id}` Kundenstamm format is REJECTED in v2 (resolver does
 *   not exist yet — bauplan v3 seam).
 * - Subjects must be CR/LF-free (the Message VO would throw at send time;
 *   validating here turns that into a field error instead of a failed send).
 * - Routes: key + at least one recipient required per row.
 */
class EmailFormSettingValidator extends EntityValidator
{
    public function validateFormKey(mixed $formKey): void
    {
        $this->validate('form_key', 'Formular-Key', $formKey)->notEmpty();
    }

    public function validateTo(mixed $to): void
    {
        if (!is_array($to) || $to === []) {
            $this->addFieldError('to', 'Mindestens ein Empfänger ist erforderlich');
            return;
        }
        $this->validateAddressList('to', 'Empfänger', $to);
    }

    public function validateCc(mixed $cc): void
    {
        if (is_array($cc) && $cc !== []) {
            $this->validateAddressList('cc', 'CC', $cc);
        }
    }

    public function validateSubject(mixed $subject): void
    {
        $this->rejectLineBreaks('subject', 'Betreff', (string) $subject);
    }

    public function validateRoutes(mixed $routes): void
    {
        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $key => $route) {
            if (trim((string) $key) === '') {
                $this->addFieldError('routes', 'Jede Route braucht einen Auswahl-Wert');
                return;
            }
            if (empty($route['to']) || !is_array($route['to'])) {
                $this->addFieldError('routes', "Route «{$key}»: mindestens ein Empfänger ist erforderlich");
                return;
            }
            if (!$this->validateAddressList('routes', "Route «{$key}»", $route['to'])) {
                return;
            }
            if (isset($route['subject']) && !$this->rejectLineBreaks('routes', "Route «{$key}»: Betreff", (string) $route['subject'])) {
                return;
            }
        }
    }

    /** @param array<mixed> $addresses */
    private function validateAddressList(string $field, string $label, array $addresses): bool
    {
        foreach ($addresses as $address) {
            $address = trim((string) $address);

            if (str_starts_with($address, 'ref:')) {
                $this->addFieldError($field, "{$label}: Kundenstamm-Verweise ({$address}) werden noch nicht unterstützt");
                return false;
            }
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $this->addFieldError($field, "{$label}: «{$address}» ist keine gültige E-Mail-Adresse");
                return false;
            }
        }
        return true;
    }

    private function rejectLineBreaks(string $field, string $label, string $value): bool
    {
        if (preg_match('/[\r\n]/', $value)) {
            $this->addFieldError($field, "{$label} darf keine Zeilenumbrüche enthalten");
            return false;
        }
        return true;
    }
}
