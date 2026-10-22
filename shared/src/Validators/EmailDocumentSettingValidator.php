<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Mail\DocumentMailKinds;

/**
 * Validates an {@see \Z77\Shared\Entities\EmailDocumentSetting} before the
 * backend editor persists it. Field keys are snake_case (mapToArray); the
 * per-language errors land on `subjects` / `bodies` naming the language.
 *
 * - German (`de`) subject and text are required — the fallback of every other
 *   language (an empty other language is allowed and falls back).
 * - A subject is CR/LF-free and at most {@see SUBJECT_LENGTH} characters (the
 *   outbox row holds 255 after the placeholders are filled; the kernel
 *   `Message` would refuse a line break at send time).
 * - A text is at most {@see BODY_LENGTH} characters.
 */
class EmailDocumentSettingValidator extends EntityValidator
{
    public const SUBJECT_LENGTH = 150;
    public const BODY_LENGTH    = 5000;

    /** Language code → German name for the messages; anything else prints its code. */
    private const LANGUAGE_NAMES = ['de' => 'Deutsch', 'en' => 'Englisch', 'fr' => 'Französisch', 'it' => 'Italienisch'];

    public function validateKind(mixed $kind): void
    {
        $this->validate('kind', 'Beleg-Art', $kind)->notEmpty();
    }

    public function validateSubjects(mixed $subjects): void
    {
        $subjects = is_array($subjects) ? $subjects : [];
        if (trim((string) ($subjects[DocumentMailKinds::FALLBACK_LANGUAGE] ?? '')) === '') {
            $this->addFieldError('subjects', 'Betreff (Deutsch) ist erforderlich — andere Sprachen fallen darauf zurück');
            return;
        }
        foreach ($subjects as $language => $subject) {
            $subject = (string) $subject;
            if (preg_match('/[\r\n]/', $subject) === 1) {
                $this->addFieldError('subjects', 'Betreff (' . $this->name((string) $language) . ') darf keine Zeilenumbrüche enthalten');
                return;
            }
            if (mb_strlen(trim($subject)) > self::SUBJECT_LENGTH) {
                $this->addFieldError('subjects', 'Betreff (' . $this->name((string) $language) . ') hat höchstens ' . self::SUBJECT_LENGTH . ' Zeichen');
                return;
            }
        }
    }

    public function validateBodies(mixed $bodies): void
    {
        $bodies = is_array($bodies) ? $bodies : [];
        if (trim((string) ($bodies[DocumentMailKinds::FALLBACK_LANGUAGE] ?? '')) === '') {
            $this->addFieldError('bodies', 'Text (Deutsch) ist erforderlich — andere Sprachen fallen darauf zurück');
            return;
        }
        foreach ($bodies as $language => $body) {
            if (mb_strlen((string) $body) > self::BODY_LENGTH) {
                $this->addFieldError('bodies', 'Text (' . $this->name((string) $language) . ') hat höchstens ' . self::BODY_LENGTH . ' Zeichen');
                return;
            }
        }
    }

    private function name(string $language): string
    {
        return self::LANGUAGE_NAMES[$language] ?? $language;
    }
}
