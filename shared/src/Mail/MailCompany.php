<?php

namespace Z77\Shared\Mail;

use Z77\Core\Services\ModuleManager;

/**
 * The company at the foot of every template mail (owner decision 2026-11-13, board
 * «E-Mail-Standard»): under a divider, smaller and grey — the full company name (Art. 954a OR:
 * the registered firm, complete and unchanged, in the correspondence), its address on one line,
 * phone · e-mail · web on the next. Only the parts that are filled appear.
 *
 * The kernel knows no mandator: ONE module registers a {@see MailCompanySource} under the config
 * key `mailCompany` (module-mandator's `Mail\MandatorMailCompany`); {@see current()} asks it
 * when `EmailService` renders a body into `emails/layout` — for the outbox at QUEUE time, like
 * the body itself. No module registers one, nothing filled, or the source throws → no foot.
 */
final class MailCompany
{
    public const CONFIG_KEY = 'mailCompany';

    /**
     * @param string $name         the full company name
     * @param string $addressLine  «Bahnhofstrasse 12 · 8001 Zürich» — '' for none
     * @param string $contactLine  «+41 44 123 45 00 · info@muster.example · muster.example» — '' for none
     */
    public function __construct(
        public readonly string $name,
        public readonly string $addressLine = '',
        public readonly string $contactLine = '',
    ) {
    }

    /** «a · b · c» of the filled parts (white space collapsed). */
    public static function joinLine(string ...$parts): string
    {
        $parts = array_map(static fn(string $p): string => trim((string) preg_replace('/\s+/u', ' ', $p)), $parts);

        return implode(' · ', array_values(array_filter($parts, static fn(string $p): bool => $p !== '')));
    }

    public function isEmpty(): bool
    {
        return trim($this->name) === '' && trim($this->addressLine) === '' && trim($this->contactLine) === '';
    }

    /**
     * The registered source's answer — null without a source, with nothing to show, or when the
     * source fails (logged; a footer never stops a mail). Two modules registering one is refused
     * loud (a programmer error, like two sources for one inline image).
     */
    public static function current(ModuleManager $modules): ?self
    {
        $class = self::sourceClass($modules);

        return $class !== null ? self::fromSource($class) : null;
    }

    /**
     * What the source $class answers — null with nothing to show or when it fails (logged).
     *
     * @param class-string<MailCompanySource> $class
     */
    public static function fromSource(string $class): ?self
    {
        try {
            $company = (new $class())->company();
        } catch (\Throwable $e) {
            error_log('mail company: ' . $class . ' failed — ' . $e->getMessage());

            return null;
        }

        return $company !== null && !$company->isEmpty() ? $company : null;
    }

    /** @return class-string<MailCompanySource>|null the source the modules declare under `mailCompany` */
    public static function sourceClass(ModuleManager $modules): ?string
    {
        $declared = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared[$moduleKey] = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, null);
        }

        return self::sourceClassFrom($declared);
    }

    /**
     * The ONE source among the modules' declarations (module key => class or null) — a class that is
     * no {@see MailCompanySource}, or two of them, is refused loud.
     *
     * @param array<string, mixed> $declaredByModule
     * @return class-string<MailCompanySource>|null
     */
    public static function sourceClassFrom(array $declaredByModule): ?string
    {
        $found  = null;
        $origin = '';
        foreach ($declaredByModule as $moduleKey => $declared) {
            if ($declared === null || $declared === '') {
                continue;
            }
            if (!is_string($declared) || !class_exists($declared) || !is_subclass_of($declared, MailCompanySource::class)) {
                throw new \LogicException("❌ " . self::CONFIG_KEY . " of module '{$moduleKey}' must name a class implementing " . MailCompanySource::class . '.');
            }
            if ($found !== null) {
                throw new \LogicException("❌ " . self::CONFIG_KEY . " is declared twice (module '{$origin}' and module '{$moduleKey}') — one module answers the mail's company.");
            }
            $found  = $declared;
            $origin = (string) $moduleKey;
        }

        return $found;
    }
}
