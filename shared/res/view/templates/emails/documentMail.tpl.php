<?php
/**
 * Body of a document mail («Beleg-Mail», step 3 of the mail plan, owner 2026-10-22): the
 * operator's plain text of the kind (Service › E-Mail › Beleg-Mails), the placeholders
 * already filled by `EmailService::documentMail()`. Rendered inside the shared
 * `emails/layout` (the outbox renders at queue time); the document itself is the
 * attachment.
 *
 * The text is DATA, never markup: every paragraph (separated by a blank line) is escaped,
 * single line breaks become `<br>` — which `HtmlToText` turns back into line breaks for the
 * plain-text half (a paragraph end into a blank line).
 *
 * The LOGO (owner 2026-10-23): the empty slot `data-z77-inline="logo"` at the top is filled at
 * SEND time by `InlineImages` (kernel mail) with the image module-mandator answers — «Logo
 * (E-Mail)», embedded as an inline part, its own pixel size, alt = the mandator's name. No logo
 * stored or the file missing → the slot is removed and the mail is the text alone.
 *
 * @var string $body  plain text, placeholders filled
 */
$paragraphs = preg_split('/\R\s*\R/u', trim($body)) ?: [];
?>
<p data-z77-inline="logo" style="margin:0 0 24px;"></p>
<?php foreach ($paragraphs as $paragraph): ?>
<p style="margin:0 0 14px;"><?= nl2br(e(trim($paragraph)), false) ?></p>
<?php endforeach; ?>
