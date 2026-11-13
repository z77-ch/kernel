<?php
/**
 * Shared e-mail layout — wraps every template-based mail (EmailService).
 * Override per project: override/z77/shared/res/view/templates/emails/layout.tpl.php
 *
 * Inline styles only (mail clients ignore <style> in many cases; HtmlToText
 * strips it anyway). Plain-text derivation contract for BODY templates:
 * `<tr data-str="new-line">` rows and closing block tags become line breaks —
 * see HtmlToText.
 *
 * The LOGO (owner 2026-11-12): the empty slot `data-z77-inline="logo"` above the body is filled
 * at SEND time by `InlineImages` (kernel mail) — module-mandator answers «Logo (E-Mail)». Here, in
 * the layout, so EVERY template mail carries it (document mails, the app's sign-in code, DMS ZIP
 * mails, …); a body template never writes the slot itself. No logo stored → the slot is removed.
 *
 * The FOOT (owner 2026-11-13, board «E-Mail-Standard» — every mail we send by machine says so):
 *   - the note «Diese Nachricht wurde maschinell erstellt.» (`MailClosing`, in the mail's
 *     language) — unless the body closed itself (`data-z77-closing`, the document mails: note +
 *     «Fragen beantwortet gerne:» + the sender identity);
 *   - a divider and the COMPANY, smaller and grey: the full name (Art. 954a OR), the address on
 *     one line, phone · e-mail · web on the next — `MailCompany` (module-mandator registers it;
 *     none → no divider, no company). Plain text, no links: the text half reads «Muster AG /
 *     Bahnhofstrasse 12 · 8001 Zürich / …» and the URL parity of HtmlToText is untouched.
 *
 * @var string $emailBody rendered body template HTML (trusted — templates escape user input via e())
 * @var string $subject
 * @var string $mailLanguage the language the mail renders in (EmailMessage::language(), ADR-056)
 * @var \Z77\Shared\Mail\MailCompany|null $mailCompany the company of the foot, null = none
 */
use Z77\Shared\Mail\MailClosing;

$mailCompany = $mailCompany ?? null;
?>
<!DOCTYPE html>
<html lang="<?= e($mailLanguage ?? 'de') ?>">
<head>
<meta charset="utf-8">
<title><?= e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e0e0e0;">
                <tr>
                    <td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222222;">
                        <p data-z77-inline="logo" style="margin:0 0 24px;"></p>
                        <?= raw($emailBody) ?>
                        <?php if (!MailClosing::isClosed((string) $emailBody)): ?>
                        <p style="margin:22px 0 0;font-size:13px;line-height:1.55;color:#444444;"><?= e(MailClosing::note($mailLanguage ?? 'de')) ?></p>
                        <?php endif; ?>
                        <?php if ($mailCompany !== null): ?>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:26px 0 16px;">
                            <tr>
                                <td width="48" style="width:48px;border-top:3px solid #9a9aa8;font-size:0;line-height:0;">&nbsp;</td>
                                <td style="border-top:1px solid #e0e0e0;font-size:0;line-height:0;">&nbsp;</td>
                            </tr>
                        </table>
                        <p style="margin:0;font-size:11.5px;line-height:1.55;color:#6b6b6b;"><?php
                            $footLines = array_values(array_filter([$mailCompany->addressLine, $mailCompany->contactLine], static fn(string $l): bool => trim($l) !== ''));
                            ?><?php if (trim($mailCompany->name) !== ''): ?><strong style="color:#444444;"><?= e($mailCompany->name) ?></strong><?= $footLines !== [] ? '<br>' : '' ?><?php endif; ?><?= implode('<br>', array_map(static fn(string $l): string => e($l), $footLines)) ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
