<?php

namespace Z77\Shared\Mail;

use Z77\Core\Services\ModuleManager;

/**
 * The DOCUMENT MAIL KINDS («Beleg-Mails», step 3 of the mail plan, owner
 * 2026-10-22): the mail that carries a business document — an invoice, a
 * credit note, later an offer or an order confirmation — to the customer.
 * Its subject and text are fixed per KIND («eigener Dienst»), like a form
 * mail's settings under Service › E-Mail; the sender is the installation's
 * identity (`config/client/mail.inc.php`), as for every mail.
 *
 * A module DECLARES the kinds it sends under its config key `documentMails`
 * (kind => declaration) — the registry pattern of `mailAttachments` and the
 * dashboard: the kernel knows no document, the module that owns the document
 * owns its default text (Rule 9). A project adds a kind of its own through the
 * extension file `App/Config/documentMailsConfig.inc.php` of a module.
 *
 * ```php
 * // module-debtor: debtorConfig.inc.php
 * 'documentMails' => [
 *     'invoice' => [
 *         'label'        => 'Rechnung',
 *         'subject'      => ['de' => 'Rechnung {nummer} — {absender}', 'en' => 'Invoice {nummer} — {absender}'],
 *         'body'         => ['de' => "Guten Tag\n\n…", 'en' => "Hello\n\n…"],
 *         'placeholders' => ['nummer' => 'Belegnummer', …],
 *     ],
 * ],
 * ```
 *
 * The declaration is the SEED: an operator's record ({@see \Z77\Shared\Entities\EmailDocumentSetting},
 * Service › E-Mail › Beleg-Mails) overrides subject and text completely while
 * it is active. The one resolution is {@see EmailService::documentMail()}.
 * German (`de`) is required — the fallback language of every kind.
 */
final class DocumentMailKinds
{
    public const CONFIG_KEY = 'documentMails';

    /** The language every kind must carry and every other language falls back to. */
    public const FALLBACK_LANGUAGE = 'de';

    private const KIND_PATTERN        = '/^[a-z][a-z0-9-]{0,39}$/';
    private const LANGUAGE_PATTERN    = '/^[a-z]{2}$/';
    private const PLACEHOLDER_PATTERN = '/^[a-z][a-z0-9_]{0,29}$/';

    /**
     * @var array<string, array{label: string, subject: array<string, string>, body: array<string, string>,
     *                          placeholders: array<string, string>, origin: string}>
     */
    private array $kinds = [];

    /** @param array<string, mixed> $declarations kind => declaration (tests, or a caller that already knows them) */
    public function __construct(array $declarations = [])
    {
        $this->add($declarations, self::CONFIG_KEY);
    }

    /** The union of every module's `documentMails` declaration and extension files. */
    public static function fromModules(ModuleManager $modules): self
    {
        $registry = new self();
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            $sources  = [];
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
            foreach ($sources as $origin => $declarations) {
                $registry->add($declarations, $origin);
            }
        }

        return $registry;
    }

    /** @return array<string, array{label: string, subject: array<string, string>, body: array<string, string>, placeholders: array<string, string>, origin: string}> kind => declaration, in declaration order */
    public function all(): array
    {
        return $this->kinds;
    }

    public function has(string $kind): bool
    {
        return isset($this->kinds[$kind]);
    }

    /**
     * @return array{label: string, subject: array<string, string>, body: array<string, string>, placeholders: array<string, string>, origin: string}
     * @throws \InvalidArgumentException an undeclared kind — a programmer error
     */
    public function get(string $kind): array
    {
        return $this->kinds[$kind] ?? throw new \InvalidArgumentException("Document mail kind '{$kind}' is not declared by any module (config key '" . self::CONFIG_KEY . "').");
    }

    /**
     * The languages a kind is edited in: the declared ones, German first.
     *
     * @return list<string>
     */
    public function languages(string $kind): array
    {
        $languages = array_keys($this->get($kind)['subject']);
        usort($languages, static fn(string $a, string $b) => [$a !== self::FALLBACK_LANGUAGE, $a] <=> [$b !== self::FALLBACK_LANGUAGE, $b]);

        return $languages;
    }

    /** Everything that would fail at the first use fails here: the shape, German present, no double kind. */
    private function add(mixed $declarations, string $origin): void
    {
        if (!is_array($declarations) || ($declarations !== [] && array_is_list($declarations))) {
            throw new \RuntimeException("❌ {$origin} must be an array of kind => declaration.");
        }
        foreach ($declarations as $kind => $declaration) {
            if (!is_string($kind) || preg_match(self::KIND_PATTERN, $kind) !== 1) {
                throw new \RuntimeException("❌ {$origin}: document mail kind " . var_export($kind, true) . ' must be lower-case kebab (e.g. invoice, credit-note).');
            }
            if (isset($this->kinds[$kind])) {
                throw new \RuntimeException("❌ {$origin}: document mail kind '{$kind}' is already declared ({$this->kinds[$kind]['origin']}).");
            }
            if (!is_array($declaration) || !is_string($declaration['label'] ?? null) || trim($declaration['label']) === '') {
                throw new \RuntimeException("❌ {$origin}: kind '{$kind}' needs a 'label'.");
            }
            $texts = [];
            foreach (['subject', 'body'] as $part) {
                $map = $declaration[$part] ?? null;
                if (!is_array($map) || trim((string) ($map[self::FALLBACK_LANGUAGE] ?? '')) === '') {
                    throw new \RuntimeException("❌ {$origin}: kind '{$kind}' needs '{$part}' => ['" . self::FALLBACK_LANGUAGE . "' => '…', …].");
                }
                foreach ($map as $language => $text) {
                    if (!is_string($language) || preg_match(self::LANGUAGE_PATTERN, $language) !== 1 || !is_string($text)) {
                        throw new \RuntimeException("❌ {$origin}: kind '{$kind}' {$part}: " . var_export($language, true) . ' must be a two-letter language mapped to a text.');
                    }
                    if ($part === 'subject' && preg_match('/[\r\n]/', $text) === 1) {
                        throw new \RuntimeException("❌ {$origin}: kind '{$kind}' subject ({$language}) must not contain a line break.");
                    }
                }
                $texts[$part] = $map;
            }
            $placeholders = $declaration['placeholders'] ?? [];
            if (!is_array($placeholders)) {
                throw new \RuntimeException("❌ {$origin}: kind '{$kind}' placeholders must be name => description.");
            }
            foreach ($placeholders as $name => $description) {
                if (!is_string($name) || preg_match(self::PLACEHOLDER_PATTERN, $name) !== 1 || !is_string($description)) {
                    throw new \RuntimeException("❌ {$origin}: kind '{$kind}' placeholder " . var_export($name, true) . ' must be lower-case (e.g. nummer) => description.');
                }
            }
            $this->kinds[$kind] = [
                'label'        => trim($declaration['label']),
                'subject'      => $texts['subject'],
                'body'         => $texts['body'],
                'placeholders' => $placeholders,
                'origin'       => $origin,
            ];
        }
    }
}
