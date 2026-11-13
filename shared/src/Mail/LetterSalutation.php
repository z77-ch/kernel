<?php

namespace Z77\Shared\Mail;

/**
 * How a machine-sent mail addresses its recipient — `{briefanrede}` of the document mails (owner
 * decision 2026-11-13, board «E-Mail-Standard»). The ONE rule, for every module that mails a
 * document (Rule 9):
 *
 *   1. the address's own «Briefanrede» when it is filled — free text, written as the operator
 *      addresses that person («Sehr geehrter Herr Meier», «Liebe Lulu»);
 *   2. else, in German, «Guten Tag» + salutation + title + name when the address names a person
 *      («Guten Tag Herr Meier», «Guten Tag Frau Dr. Muster») — a salutation «Firma» or none, or no
 *      name, is no person;
 *   3. else the greeting of the mail's language alone («Guten Tag», «Hello», «Bonjour»,
 *      «Buongiorno»). Outside German the salutation field (free text, German by convention —
 *      «Herr», «Frau») is not put into a foreign sentence.
 *
 * The mail texts are written NEUTRALLY (no «Sie», no «du») so that one text fits every
 * Briefanrede — the informal one comes from the address, not from a second text.
 */
final class LetterSalutation
{
    /** The greeting word per language; a language not listed greets in German. */
    public const GREETINGS = ['de' => 'Guten Tag', 'en' => 'Hello', 'fr' => 'Bonjour', 'it' => 'Buongiorno'];

    /** A salutation that names no person (case-insensitive). */
    private const NO_PERSON = ['firma'];

    public static function resolve(string $letterSalutation, string $salutation, string $title, string $name, string $language): string
    {
        $letter = self::oneLine($letterSalutation);
        if ($letter !== '') {
            return $letter;
        }
        $language   = strtolower(trim($language));
        $greeting   = self::GREETINGS[$language] ?? self::GREETINGS['de'];
        $salutation = self::oneLine($salutation);
        $name       = self::oneLine($name);
        if (($language === 'de' || !isset(self::GREETINGS[$language]))
            && $salutation !== '' && $name !== '' && !in_array(mb_strtolower($salutation), self::NO_PERSON, true)) {
            return self::oneLine($greeting . ' ' . $salutation . ' ' . $title . ' ' . $name);
        }

        return $greeting;
    }

    /** Line breaks and runs of white space become one space — the Briefanrede is one line of the text. */
    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
