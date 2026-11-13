<?php

namespace Z77\Shared\Mail;

/**
 * The closing words of a machine-sent mail (owner decision 2026-11-13, board «E-Mail-Standard»):
 * every template mail says it was made by a machine; a mail a staff member sends under «Senden
 * als» (the document mails) adds who answers questions — name, e-mail, phone of the identity.
 *
 * Set by the TEMPLATES, never part of an editable text: `emails/documentMail` writes the full
 * block, `emails/layout` writes the note alone for every other mail (it looks for
 * {@see MARKER} in the body to know the body closed itself). Per language; a language not
 * listed reads German.
 */
final class MailClosing
{
    /** The attribute a body template puts on its own closing block — the layout then writes none. */
    public const MARKER = 'data-z77-closing';

    public const NOTE = [
        'de' => 'Diese Nachricht wurde maschinell erstellt.',
        'en' => 'This message was generated automatically.',
        'fr' => 'Ce message a été généré automatiquement.',
        'it' => 'Questo messaggio è stato generato automaticamente.',
    ];

    public const QUESTIONS = [
        'de' => 'Fragen beantwortet gerne:',
        'en' => 'Questions are gladly answered by:',
        'fr' => 'Pour toute question :',
        'it' => 'Per domande:',
    ];

    public static function note(?string $language): string
    {
        return self::NOTE[self::language($language)];
    }

    public static function questions(?string $language): string
    {
        return self::QUESTIONS[self::language($language)];
    }

    /** Whether a rendered body already carries its own closing block. */
    public static function isClosed(string $html): bool
    {
        return str_contains($html, self::MARKER);
    }

    private static function language(?string $language): string
    {
        $language = strtolower(trim((string) $language));

        return isset(self::NOTE[$language]) ? $language : 'de';
    }
}
