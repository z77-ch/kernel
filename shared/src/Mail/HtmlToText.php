<?php

namespace Z77\Shared\Mail;

/**
 * Derives the plain-text alternative of a template-rendered HTML mail body
 * (port of the proven wdv-6.2.2 `Email::preparePlainText()`).
 *
 * Conversion contract with the templates: a `<tr data-str="new-line">` row, a
 * closing block element (`</p>`, `</h1>`–`</h6>`, `</li>`, `</tr>`) or a `<br>`
 * becomes a line break; `</td>` cells are separated by a space; everything else
 * is stripped. Entities are decoded and non-breaking spaces normalised so the
 * text part reads naturally in clients that prefer it.
 *
 * Pure function object — no framework dependencies, isolated testable.
 */
final class HtmlToText
{
    /**
     * @param array<string, string> $replacements optional preg_replace map
     *                                            (pattern => replacement) applied last
     */
    public function __construct(private array $replacements = [])
    {
    }

    public function convert(string $html): string
    {
        // Remove style/title entirely — strip_tags would keep their text content.
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? '';
        $text = preg_replace('/<title\b[^>]*>.*?<\/title>/is', '', $text) ?? '';

        // Collapse all whitespace, then drop the spaces markup indentation left
        // between and around tags.
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = preg_replace('/(> <)+/', '><', $text) ?? '';
        $text = preg_replace('/(> )+/', '>', $text) ?? '';
        $text = preg_replace('/( <\/)+/', '</', $text) ?? '';

        $text = str_replace(
            ['<tr data-str="new-line">', '</tr>', '<br />', '<br>'],
            "\r\n",
            $text
        );

        // Keep table cells apart once the tags are gone.
        $text = str_replace('</td>', ' </td>', $text);

        // Closing block elements break the line; inline links end with a space.
        $text = str_replace(
            ['</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>', '</a>'],
            ["\r\n", "\r\n", "\r\n", "\r\n", "\r\n", "\r\n", "\r\n", "\r\n", ' '],
            $text
        );

        $text = strip_tags($text);

        // Decode entities (&nbsp; &amp; &rarr; …), then normalise the U+00A0
        // that &nbsp; decodes to into a plain space.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        if ($this->replacements !== []) {
            $text = preg_replace(
                array_keys($this->replacements),
                array_values($this->replacements),
                $text
            ) ?? $text;
        }

        return trim($text);
    }
}
