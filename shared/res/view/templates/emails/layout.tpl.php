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
 * @var string $emailBody rendered body template HTML (trusted — templates escape user input via e())
 * @var string $subject
 */
?>
<!DOCTYPE html>
<html lang="de">
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
                        <?= raw($emailBody) ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
