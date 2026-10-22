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
 * plain-text half.
 *
 * @var string $body  plain text, placeholders filled
 */
$paragraphs = preg_split('/\R\s*\R/u', trim($body)) ?: [];
?>
<?php foreach ($paragraphs as $paragraph): ?>
<p style="margin:0 0 14px;"><?= nl2br(e(trim($paragraph)), false) ?></p>
<?php endforeach; ?>
