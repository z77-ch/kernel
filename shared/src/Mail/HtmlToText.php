<?php

namespace Z77\Shared\Mail;

/**
 * Derives the plain-text alternative of a template-rendered HTML mail body
 * (port of the proven wdv-6.2.2 `Email::preparePlainText()`).
 *
 * Conversion contract with the templates: a `<tr data-str="new-line">` row, a
 * closing block element (`</p>`, `</h1>`–`</h6>`, `</li>`, `</tr>`) or a `<br>`
 * becomes a line break; `</td>` cells are separated by a space; a link whose
 * text is not its address keeps the address in parentheses (`label (href)`),
 * so a button link is still a link in the text part — and the text and HTML
 * halves carry the SAME number of URLs, which spam filters compare
 * (`URI_COUNT_ODD`); a link to a bare `#fragment` is the one exception, since
 * no receiver counts it; everything else is stripped. Entities are decoded and
 * non-breaking spaces normalised so the text part reads naturally in clients
 * that prefer it.
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
        // between tags. ⚠️ A pass over `(> )+` belongs here as little as it
        // looks like it does: the line above already closes the tag-to-tag
        // case, so what is left after a `>` is the space a sentence needs —
        // `</strong> Auf` read as «zuerst:Auf» (MAIL-TEXT-001, fix from
        // wdv-6.3.0 2026-09-20). Indentation at the START of a line is taken
        // out at the end, once the line breaks exist.
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = preg_replace('/(> <)+/', '><', $text) ?? '';
        $text = preg_replace('/( <\/)+/', '</', $text) ?? '';

        $text = str_replace(
            ['<tr data-str="new-line">', '</tr>', '<br />', '<br>'],
            "\r\n",
            $text
        );

        // Keep table cells apart once the tags are gone.
        $text = str_replace('</td>', ' </td>', $text);

        // A link's address survives only when its label does not already spell
        // it out (the "open this address" fallback); attribute values are still
        // entity-encoded here and are decoded together with the rest below.
        $text = preg_replace_callback(
            '/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            static function (array $m): string {
                $url = trim($m[2]);
                // The label is compared WITHOUT its markup: `<strong>` around
                // an address does not make it a different address.
                $label = trim(strip_tags($m[3]));

                // A bare fragment points into a document the text part does
                // not have, and no receiver counts it as a URI — so it costs
                // nothing to leave out and would only read as noise.
                if ($url === '' || $url[0] === '#') {
                    return $m[3] . ' ';
                }
                // Label and address are the same thing (a trailing slash is
                // not a second address).
                if (rtrim($label, '/') === rtrim($url, '/')) {
                    return $m[3] . ' ';
                }

                // Everything else keeps the address — an EMPTY label included
                // (a linked image): the HTML half carries that URL, so the
                // text half must carry it too (`URI_COUNT_ODD`).
                return $m[3] . ' (' . $url . ') ';
            },
            $text
        ) ?? $text;

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

        // What the markup's indentation left at the start of a line — now that
        // the line breaks exist, this is the safe place to take it out, and the
        // only one: a space INSIDE a sentence looks the same to a pass that
        // runs earlier (MAIL-TEXT-001).
        $text = preg_replace('/^[ \t]+/m', '', $text) ?? $text;

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
