<?php

namespace Z77\Shared\Mail;

use Z77\Core\DI;
use Z77\Shared\Entities\EmailDocumentSetting;
use Z77\Shared\Entities\EmailFormSetting;

/**
 * Template-mail façade on top of the Mail stack (the final mail-service step —
 * see docs/topics/mail.md and docs/03-development/email-service-bauplan.md).
 * Renders an {@see EmailMessage} (body template inside the shared
 * `emails/layout`, both override-first via FileFinder), derives the plain-text
 * alternative ({@see HtmlToText}), maps onto the hardened {@see Message} VO and
 * sends via {@see Mailer} (transport per `config/client/mail.inc.php`: mail() or SMTP).
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
    private ?MailCompany $company = null;

    private bool $companyResolved = false;

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
            } elseif ($email->getFromName() !== '') {
                $message->fromName($email->getFromName());
            }
            $message->to(...$this->splitAddresses($email->getTo()));
            if ($email->getCc() !== '') {
                $message->cc(...$this->splitAddresses($email->getCc()));
            }

            // Only user-controlled header: validate, drop silently when invalid.
            $replyTo = trim($email->getReplyTo());
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false) {
                $message->replyTo($replyTo, $email->getReplyToName());
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

    /**
     * The HTML a template mail renders to — the body template inside the shared
     * `emails/layout`, both override-first — WITHOUT sending it. For a caller that
     * stores a mail and sends it later: module-mailing's outbox renders at queue
     * time, so the stored row is exactly what goes out and what its screen shows
     * (2026-10-22). Null for a text-only message. A missing template throws, as
     * in {@see send()} (programmer error).
     */
    public function renderBody(EmailMessage $email): ?string
    {
        return $this->renderHtml($email);
    }

    /**
     * Where a form mail would go right now — resolved exactly as {@see sendForm()}
     * resolves it (active backend record first, else emailConfig; default route),
     * for a screen that says «goes to …» next to its send button. Reading the
     * config directly would miss a backend override (mail.md rule). Throws like
     * sendForm() on an unknown form key; an entry without a template is fine here (a
     * mail that writes its own text, `recipientsOnly`).
     *
     * @return array{to: list<string>, cc: list<string>}
     */
    public function formRecipients(string $formKey): array
    {
        $cfg      = DI::getConfigManager()->getArrayConfig(self::CONFIG_NAME, self::CONFIG_NAMESPACE);
        $settings = $this->resolveFormSettings($formKey, $cfg, needsTemplate: false);

        return [
            'to' => $this->resolveRecipients($settings['to']),
            'cc' => $this->resolveRecipients($settings['cc']),
        ];
    }

    /**
     * The subject, fact line and text of a DOCUMENT MAIL («Beleg-Mail», step 3 of the mail plan,
     * owner 2026-10-22; the fact line owner 2026-11-13) — the single resolution point, like
     * {@see resolveFormSettings()} for a form:
     *
     *   1. the operator's {@see EmailDocumentSetting} of the kind, when one exists AND is active
     *      (it wins completely — the fact line too, once the record was saved with one; a record
     *      from before the fact line keeps the declaration's), else the module's declaration
     *      ({@see DocumentMailKinds});
     *   2. within that source the document's `$language` when BOTH its subject and its text are
     *      filled, else German (`de`, always present) — {@see documentMailLanguage()};
     *   3. every `{name}` replaced by `$values[name]` (a placeholder without a value stays as
     *      written — visible, not silently dropped); line breaks in a value are flattened in the
     *      subject and the fact line (one line each). A body LINE that holds nothing but
     *      placeholders which all resolved to '' is dropped (2026-10-23, `{anzeigename}` on its
     *      own line under the greeting: a mail without a display name must not show an empty
     *      line there). A fact-line SEGMENT (between « · ») whose placeholders all resolved to ''
     *      is dropped the same way («· Termin {termin}» without a date).
     *
     * Sending is the caller's (module-mailing's outbox): this answers text, not a mail. The
     * body is PLAIN TEXT — render it through `emails/documentMail` (escaped). An undeclared
     * kind throws (programmer error).
     *
     * @param array<string, string> $values placeholder name => value (data — never markup)
     * @return array{subject: string, facts: string, body: string, language: string}
     */
    public function documentMail(string $kind, string $language, array $values, ?DocumentMailKinds $kinds = null): array
    {
        [$subjects, $bodies, $facts] = $this->documentTexts($kind, $kinds);
        $language = self::chooseLanguage($subjects, $bodies, $language);

        $inBody = [];
        $inLine = [];
        foreach ($values as $name => $value) {
            $inBody['{' . $name . '}'] = (string) $value;
            $inLine['{' . $name . '}'] = trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $value));
        }

        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($bodies[$language] ?? '')) ?: [] as $line) {
            $filled = strtr($line, $inBody);
            $onlyPlaceholders = preg_match('/^\s*(\{[a-z0-9_-]+\}\s*)+$/i', $line) === 1;
            if ($onlyPlaceholders && trim($filled) === '' && $filled !== $line) {
                continue;
            }
            $lines[] = $filled;
        }

        $factLine = trim((string) ($facts[$language] ?? ''));
        if ($factLine === '') {
            $factLine = trim((string) ($facts[DocumentMailKinds::FALLBACK_LANGUAGE] ?? ''));
        }
        $segments = [];
        foreach (explode(' · ', $factLine) as $segment) {
            $filled = trim(strtr($segment, $inLine));
            $placeholders = preg_match_all('/\{[a-z0-9_-]+\}/i', $segment, $found);
            // A segment of placeholders that all came back empty («Termin {termin}» without a date) goes.
            $resolvedEmpty = $placeholders > 0 && trim(strtr(implode('', $found[0]), $inLine)) === '' && strtr(implode('', $found[0]), $inLine) !== implode('', $found[0]);
            if ($filled === '' || $resolvedEmpty) {
                continue;
            }
            $segments[] = $filled;
        }

        return [
            'subject'  => trim(strtr((string) ($subjects[$language] ?? ''), $inLine)),
            'facts'    => implode(' · ', $segments),
            'body'     => trim(implode("\n", $lines)),
            'language' => $language,
        ];
    }

    /**
     * The language a document mail of $kind renders in for a document in $language — the language
     * itself when BOTH its subject and its text are filled in the effective source (record or
     * declaration), else German. A caller that prepares language-bound values (the Briefanrede)
     * asks this first.
     */
    public function documentMailLanguage(string $kind, string $language, ?DocumentMailKinds $kinds = null): string
    {
        [$subjects, $bodies] = $this->documentTexts($kind, $kinds);

        return self::chooseLanguage($subjects, $bodies, $language);
    }

    /**
     * Subjects, bodies and fact lines of $kind — the active record's, else the declaration's.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>}
     */
    private function documentTexts(string $kind, ?DocumentMailKinds $kinds): array
    {
        $declared = ($kinds ?? DocumentMailKinds::fromModules(DI::getModuleManager()))->get($kind);
        $subjects = $declared['subject'];
        $bodies   = $declared['body'];
        $facts    = $declared['facts'];

        $record = DI::getUnifiedEntityManager()->getRepository(EmailDocumentSetting::class)->findByKind($kind);
        if ($record !== null && $record->isActive()) {
            $subjects = $record->getSubjects();
            $bodies   = $record->getBodies();
            if ($record->getFacts() !== []) {
                $facts = $record->getFacts();
            }
        }

        return [$subjects, $bodies, $facts];
    }

    /**
     * @param array<string, string> $subjects
     * @param array<string, string> $bodies
     */
    private static function chooseLanguage(array $subjects, array $bodies, string $language): string
    {
        $language = strtolower(trim($language));
        if (trim((string) ($subjects[$language] ?? '')) === '' || trim((string) ($bodies[$language] ?? '')) === '') {
            return DocumentMailKinds::FALLBACK_LANGUAGE;
        }

        return $language;
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
    private function resolveFormSettings(string $formKey, object $cfg, bool $needsTemplate = true): array
    {
        $form = $cfg->get(['forms', $formKey], null);

        if (!is_array($form) || ($needsTemplate && (empty($form['template'][0]) || empty($form['template'][1])))) {
            throw new \RuntimeException(
                "Form key '{$formKey}' is missing or incomplete in emailConfig 'forms' "
                . "(requires 'template' => [tpl, nameSpace])."
            );
        }

        $settings = [
            'to'       => $this->normalizeAddressList($form['to'] ?? ''),
            'cc'       => $this->normalizeAddressList($form['cc'] ?? ''),
            'subject'  => (string) ($form['subject'] ?? ''),
            'template' => [(string) ($form['template'][0] ?? ''), (string) ($form['template'][1] ?? '')],
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

        $draw = function (string $language) use ($bodyTpl, $layoutTpl, $email): string {
            $body = $this->render($bodyTpl, $email->getContext() + ['mailLanguage' => $language]);

            return $this->render($layoutTpl, [
                'emailBody'    => $body,
                'subject'      => $email->getSubject(),
                'mailLanguage' => $language,
                // The company at the foot of every template mail (owner 2026-11-13) — null: no foot.
                'mailCompany'  => $this->mailCompany(),
            ]);
        };

        // The recipient's language (EmailMessage::language()): body AND layout render in it,
        // every t() inside answers in it. Without one, the current language as before.
        $language = $email->getLanguage();
        if ($language === null) {
            return $draw(self::currentLanguage());
        }
        $translator = DI::getTranslator();

        return $translator->inLanguage($language, fn(): string => $draw($translator->currentLanguage()));
    }

    /**
     * The company the layout closes with ({@see MailCompany}) — asked once per service; null where
     * no module registers one or no container answers (a harness without modules).
     */
    private function mailCompany(): ?MailCompany
    {
        if ($this->companyResolved) {
            return $this->company;
        }
        $this->companyResolved = true;
        try {
            $this->company = MailCompany::current(DI::getModuleManager());
        } catch (\LogicException $e) {
            throw $e;   // two sources, a bad declaration — a programmer error, loud
        } catch (\Throwable) {
            $this->company = null;
        }

        return $this->company;
    }

    /** The language a mail without its own renders in; 'de' where no container answers (a harness). */
    private static function currentLanguage(): string
    {
        try {
            return DI::getTranslator()->currentLanguage();
        } catch (\Throwable) {
            return 'de';
        }
    }

    /**
     * Closure-scope template render (pattern: StylesheetManager::createCss) —
     * the template sees only the context variables, not $this.
     *
     * The closure's locals carry a prefix no mail variable would use, and
     * EXTR_SKIP guards them on top: without either, a context key named
     * `tplPath` would not merely render wrong, it would decide which file gets
     * included.
     *
     * @param array<string, mixed> $context
     */
    private function render(string $tplPath, array $context): string
    {
        return (static function (string $z77TplPath, array $z77TplContext): string {
            extract($z77TplContext, EXTR_SKIP);
            ob_start();
            include $z77TplPath;
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
