<?php
/**
 * Body of a document mail («Beleg-Mail», step 3 of the mail plan, owner 2026-10-22; the standard of
 * the board «E-Mail-Standard», owner 2026-11-13). Rendered inside the shared `emails/layout` (the
 * outbox renders at queue time); the document itself is the attachment. Top to bottom:
 *
 *   1. the FACT LINE, bold — «Rechnung 26100012 · CHF 640.00 · zahlbar bis 13.12.2026» (the kind's
 *      `facts`, placeholders filled by `EmailService::documentMail()`); none → no line;
 *   2. the operator's plain TEXT of the kind (Service › E-Mail › Beleg-Mails) — it opens with
 *      `{briefanrede}` and carries no greeting formula and no signature;
 *   3. the CLOSING, set here and not editable: «Diese Nachricht wurde maschinell erstellt. Fragen
 *      beantwortet gerne:» + name, e-mail and phone of the identity chosen under «Senden als»
 *      (`$sender`; no phone → no line; no identity → the note alone). The block carries
 *      `data-z77-closing`, so the layout adds no second note; the layout adds the company foot.
 *
 * The text is DATA, never markup: every paragraph (separated by a blank line) is escaped,
 * single line breaks become `<br>` — which `HtmlToText` turns back into line breaks for the
 * plain-text half (a paragraph end into a blank line). The e-mail address in the closing is plain
 * text (no `mailto:` link: the text half would repeat it in parentheses).
 *
 * The LOGO comes from the shared `emails/layout` — not written here.
 *
 * @var string $body  plain text, placeholders filled
 * @var string $facts the fact line, placeholders filled ('' = none)
 * @var array{name?: string, email?: string, phone?: string} $sender  the «Senden als» identity ([] = none)
 * @var string $language      the document mail's language (the text's — `EmailService::documentMail()`); the
 *                            closing speaks it even where the installation's translator does not know it
 * @var string $mailLanguage  the render language (fallback)
 */
use Z77\Shared\Mail\MailClosing;

$paragraphs = preg_split('/\R\s*\R/u', trim($body)) ?: [];
$facts      = trim((string) ($facts ?? ''));
$sender     = is_array($sender ?? null) ? $sender : [];
$who        = array_values(array_filter([
    trim((string) ($sender['email'] ?? '')),
    trim((string) ($sender['phone'] ?? '')),
], static fn(string $v): bool => $v !== ''));
$senderName = trim((string) ($sender['name'] ?? ''));
$language   = (string) ($language ?? $mailLanguage ?? 'de');
?>
<?php if ($facts !== ''): ?>
<p style="margin:0 0 18px;font-size:15px;font-weight:bold;"><?= e($facts) ?></p>
<?php endif; ?>
<?php foreach ($paragraphs as $paragraph): ?>
<p style="margin:0 0 14px;"><?= nl2br(e(trim($paragraph)), false) ?></p>
<?php endforeach; ?>
<p data-z77-closing style="margin:22px 0 0;font-size:13px;line-height:1.55;color:#444444;"><?= e(MailClosing::note($language)) ?><?php if ($senderName !== ''): ?> <?= e(MailClosing::questions($language)) ?><br><strong style="color:#222222;"><?= e($senderName) ?></strong><?php foreach ($who as $line): ?><br><?= e($line) ?><?php endforeach; ?><?php endif; ?></p>
