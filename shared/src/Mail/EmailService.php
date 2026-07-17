<?php

namespace Z77\Shared\Mail;

use Z77\Core\DI;

/**
 * Template-mail façade on top of the Mail stack (the final mail-service step —
 * see docs/topics/mail.md and docs/03-development/email-service-bauplan.md).
 * Renders an {@see EmailMessage} (body template inside the shared
 * `emails/layout`, both override-first via FileFinder), derives the plain-text
 * alternative ({@see HtmlToText}), maps onto the hardened {@see Message} VO and
 * sends via {@see Mailer} (transport per `config/mail.inc.php`: mail() or SMTP).
 *
 * Error contract (agreed in the requirements handoff):
 * - Programmer/config-structure errors THROW: unknown form key, missing template.
 * - Runtime failures return FALSE + {@see getLastErrors()} and are error-logged:
 *   transport refusal, mail unconfigured, invalid configured address, unreadable
 *   attachment. A config typo must never 500 a public form submit.
 * - Reply-To is the only user-controlled header: validated here, silently
 *   dropped when invalid (handoff security requirement).
 *
 * Form mails: `sendForm()` resolves recipient/subject/template from the
 * override-first `emailConfig` (`Config/emailConfig.inc.php`, namespace
 * Z77\Shared). {@see resolveFormSettings()} is the single attachment point for
 * the planned v2 backend-editable settings entity (entity overrides config).
 */
final class EmailService
{
    private const CONFIG_NAME      = 'Config/emailConfig';
    private const CONFIG_NAMESPACE = 'Z77\\Shared';
    private const LAYOUT_TEMPLATE  = 'emails/layout';

    /** @var list<string> */
    private array $errors = [];

    /** Mailer injectable for isolated testing; default resolves config-driven. */
    public function __construct(private ?Mailer $mailer = null)
    {
    }

    /**
     * Sends a config-defined form mail: recipient, subject and body template
     * come from emailConfig `forms[$formKey]`; `$replyTo` (typically the form
     * sender) wins over the config default.
     *
     * @param array<string, mixed> $context template context (user input — templates escape via e())
     */
    public function sendForm(string $formKey, array $context, ?string $replyTo = null): bool
    {
        $cfg  = DI::getConfigManager()->getArrayConfig(self::CONFIG_NAME, self::CONFIG_NAMESPACE);
        $form = $this->resolveFormSettings($formKey);

        $email = (new EmailMessage())
            ->to((string) $form['to'])
            ->subject((string) $cfg->get('subjectPrefix', '') . (string) ($form['subject'] ?? ''))
            ->template((string) $form['template'][0], (string) $form['template'][1], $context)
            ->replyTo($replyTo ?? $cfg->get('replyTo', null));

        if (!empty($form['cc'])) {
            $email->cc((string) $form['cc']);
        }

        return $this->send($email);
    }

    public function send(EmailMessage $email): bool
    {
        $this->errors = [];

        // Template resolution/rendering throws (programmer error) — deliberately
        // outside the failure-path try below.
        $html = $this->renderHtml($email);
        $text = $html !== null
            ? (new HtmlToText())->convert($html)
            : $email->getText();

        try {
            $message = new Message();

            if ($email->getFrom() !== '') {
                $message->from($email->getFrom(), $email->getFromName());
            }
            $message->to(...$this->splitAddresses($email->getTo()));
            if ($email->getCc() !== '') {
                $message->cc(...$this->splitAddresses($email->getCc()));
            }

            // Only user-controlled header: validate, drop silently when invalid.
            $replyTo = trim($email->getReplyTo());
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
                $message->replyTo($replyTo);
            }

            $message->subject($email->getSubject());
            $message->text($text);
            if ($html !== null) {
                $message->html($html);
            }

            foreach ($email->getAttachments() as $attachment) {
                $message->attach($this->loadAttachment($attachment['path'], $attachment['name']));
            }

            ($this->mailer ?? Mailer::create())->send($message);
            return true;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->errors[] = $e->getMessage();
            error_log('EmailService: sending failed — ' . $e->getMessage());
            return false;
        }
    }

    /** @return list<string> empty on success */
    public function getLastErrors(): array
    {
        return $this->errors;
    }

    /**
     * Resolves the settings block for a form key from emailConfig. Single
     * resolution point by design: the v2 backend-editable settings entity will
     * override the config here — config stays the seed/fallback (see bauplan).
     *
     * @return array{to: string, subject?: string, template: array{0: string, 1: string}, cc?: string}
     */
    private function resolveFormSettings(string $formKey): array
    {
        $cfg  = DI::getConfigManager()->getArrayConfig(self::CONFIG_NAME, self::CONFIG_NAMESPACE);
        $form = $cfg->get(['forms', $formKey], null);

        if (!is_array($form) || empty($form['to']) || empty($form['template'][0]) || empty($form['template'][1])) {
            throw new \RuntimeException(
                "Form key '{$formKey}' is missing or incomplete in emailConfig 'forms' "
                . "(requires 'to' and 'template' => [tpl, nameSpace])."
            );
        }

        return $form;
    }

    /**
     * Renders body template + shared layout to the final HTML, or null for
     * text-only mails. Missing templates throw (FileFinder, programmer error).
     */
    private function renderHtml(EmailMessage $email): ?string
    {
        if ($email->getTemplate() === '') {
            return null;
        }

        $fileFinder = DI::getFileFinder();

        $bodyTpl = $fileFinder->getFirstTplMatch(
            $email->getTemplate() . '.tpl.php',
            $email->getTemplateNameSpace()
        );
        $layoutTpl = $fileFinder->getFirstTplMatch(
            self::LAYOUT_TEMPLATE . '.tpl.php',
            self::CONFIG_NAMESPACE
        );

        $body = $this->render($bodyTpl, $email->getContext());

        return $this->render($layoutTpl, [
            'emailBody' => $body,
            'subject'   => $email->getSubject(),
        ]);
    }

    /**
     * Closure-scope template render (pattern: StylesheetManager::createCss) —
     * the template sees only the context variables, not $this.
     *
     * @param array<string, mixed> $context
     */
    private function render(string $tplPath, array $context): string
    {
        return (static function (string $tplPath, array $context): string {
            extract($context);
            ob_start();
            include $tplPath;
            return (string) ob_get_clean();
        })($tplPath, $context);
    }

    /** @return list<string> */
    private function splitAddresses(string $addresses): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $addresses))));
    }

    private function loadAttachment(string $absPath, string $fileName): Attachment
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            throw new \RuntimeException("Attachment not readable: {$absPath}");
        }

        return new Attachment(
            $fileName,
            (string) (mime_content_type($absPath) ?: 'application/octet-stream'),
            (string) file_get_contents($absPath)
        );
    }
}
