<?php

namespace Z77\Shared\Mail;

use Z77\Core\DI;
use Z77\Shared\Entities\EmailFormSetting;

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
 * Form mails (v2, 2026-07-18 — email-settings-v2-bauplan.md): `sendForm()`
 * resolves its settings ENTITY-FIRST — a backend-edited {@see EmailFormSetting}
 * record wins completely for to/cc/subject/routes; without a record the
 * override-first `emailConfig` `forms` entry applies (config = seed/fallback).
 * The body template and the form key's existence always come from config
 * (templates are developer artifacts). {@see resolveFormSettings()} stays the
 * single resolution point.
 *
 * Routing: the optional `$routeKey` (a server-validated form option value —
 * never free user input) picks an entry from the settings' `routes` map, which
 * overrides the recipients and optionally the subject. Unknown key → defaults
 * (error-logged). Recipient entries are literal addresses in v2; the reserved
 * `ref:{source}:{id}` Kundenstamm format is dropped with an error_log until
 * the v3 resolver exists ({@see resolveRecipients()} is the seam).
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
     * Sends a form mail: recipients, subject and routes resolved entity-first
     * (backend-edited EmailFormSetting record, else emailConfig `forms`); the
     * body template always comes from config. `$replyTo` (typically the form
     * sender) wins over the config default. `$routeKey` — a server-validated
     * form option value — picks a `routes` entry that overrides recipients
     * (and optionally the subject); unknown key → defaults, error-logged.
     *
     * @param array<string, mixed> $context template context (user input — templates escape via e())
     */
    public function sendForm(string $formKey, array $context, ?string $replyTo = null, ?string $routeKey = null): bool
    {
        $cfg      = DI::getConfigManager()->getArrayConfig(self::CONFIG_NAME, self::CONFIG_NAMESPACE);
        $settings = $this->resolveFormSettings($formKey, $cfg);

        if ($routeKey !== null && $routeKey !== '') {
            if (isset($settings['routes'][$routeKey])) {
                $route          = $settings['routes'][$routeKey];
                $settings['to'] = $route['to'];
                if (!empty($route['subject'])) {
                    $settings['subject'] = (string) $route['subject'];
                }
            } else {
                error_log("EmailService: unknown routeKey '{$routeKey}' for form '{$formKey}' — using default recipients");
            }
        }

        $to = $this->resolveRecipients($settings['to']);
        $cc = $this->resolveRecipients($settings['cc']);

        if ($to === []) {
            $this->errors = ["No (resolvable) recipient configured for form '{$formKey}'."];
            error_log('EmailService: ' . $this->errors[0]);
            return false;
        }

        $email = (new EmailMessage())
            ->to(implode(',', $to))
            ->subject((string) $cfg->get('subjectPrefix', '') . $settings['subject'])
            ->template($settings['template'][0], $settings['template'][1], $context)
            ->replyTo($replyTo ?? $cfg->get('replyTo', null));

        if ($cc !== []) {
            $email->cc(implode(',', $cc));
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
     * Resolves the effective settings for a form key — the single resolution
     * point (v2): the form key + template MUST exist in emailConfig (programmer
     * error otherwise, throws); to/cc/subject/routes come from the backend-
     * edited {@see EmailFormSetting} record when one exists, else from config.
     * A missing recipient is NOT a structure error here — it surfaces as the
     * normal `false` failure path in sendForm() (recipients may live solely in
     * the entity).
     *
     * @return array{to: list<string>, cc: list<string>, subject: string,
     *               template: array{0: string, 1: string},
     *               routes: array<string, array{to: list<string>, subject?: string}>}
     */
    private function resolveFormSettings(string $formKey, object $cfg): array
    {
        $form = $cfg->get(['forms', $formKey], null);

        if (!is_array($form) || empty($form['template'][0]) || empty($form['template'][1])) {
            throw new \RuntimeException(
                "Form key '{$formKey}' is missing or incomplete in emailConfig 'forms' "
                . "(requires 'template' => [tpl, nameSpace])."
            );
        }

        $settings = [
            'to'       => $this->normalizeAddressList($form['to'] ?? ''),
            'cc'       => $this->normalizeAddressList($form['cc'] ?? ''),
            'subject'  => (string) ($form['subject'] ?? ''),
            'template' => [(string) $form['template'][0], (string) $form['template'][1]],
            'routes'   => $this->normalizeRoutes($form['routes'] ?? []),
        ];

        $entity = DI::getUnifiedEntityManager()
            ->getRepository(EmailFormSetting::class)
            ->findByFormKey($formKey);

        // An override applies only when active — a dormant record (active=false)
        // is treated as absent, so the config seed applies (the operator can
        // disable an override without deleting it).
        if ($entity !== null && $entity->isActive()) {
            // Backend record wins completely for the operator-owned fields.
            $settings['to']      = $this->normalizeAddressList($entity->getTo());
            $settings['cc']      = $this->normalizeAddressList($entity->getCc());
            $settings['subject'] = $entity->getSubject();
            $settings['routes']  = $this->normalizeRoutes($entity->getRoutes());
        }

        return $settings;
    }

    /**
     * Kundenstamm seam (v3): maps recipient entries to sendable addresses.
     * v2 passes literal addresses through and DROPS reserved `ref:{source}:{id}`
     * entries with an error_log — the resolver plugs in here later.
     *
     * @param list<string> $entries
     * @return list<string>
     */
    private function resolveRecipients(array $entries): array
    {
        $resolved = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 'ref:')) {
                error_log("EmailService: recipient reference '{$entry}' not resolvable yet (Kundenstamm is v3) — dropped");
                continue;
            }
            $resolved[] = $entry;
        }
        return $resolved;
    }

    /**
     * Accepts a ','/';'-separated string (config v1 form) or a list (entity /
     * config) and returns a trimmed, non-empty list.
     *
     * @return list<string>
     */
    private function normalizeAddressList(mixed $value): array
    {
        if (is_string($value)) {
            return $this->splitAddresses(str_replace(';', ',', $value));
        }
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn ($v) => trim((string) $v),
                $value
            ), static fn (string $v) => $v !== ''));
        }
        return [];
    }

    /**
     * Normalizes a routes map: route `to` accepts string or list, empty routes
     * are dropped, `subject` is kept only when non-empty.
     *
     * @return array<string, array{to: list<string>, subject?: string}>
     */
    private function normalizeRoutes(mixed $routes): array
    {
        if (!is_array($routes)) {
            return [];
        }

        $normalized = [];
        foreach ($routes as $key => $route) {
            if (!is_array($route)) {
                continue;
            }
            $to = $this->normalizeAddressList($route['to'] ?? []);
            if ($to === []) {
                continue;
            }
            $entry = ['to' => $to];
            if (!empty($route['subject'])) {
                $entry['subject'] = (string) $route['subject'];
            }
            $normalized[(string) $key] = $entry;
        }
        return $normalized;
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
