<?php

namespace Z77\Shared\Mail;

/**
 * Answers the company a mail closes with (owner decision 2026-11-13, board «E-Mail-Standard»:
 * the divider and the full company address at the foot of EVERY template mail) — registered by
 * ONE module under the config key `mailCompany` (the class), read by {@see MailCompany::current()}
 * when a mail body is rendered. Shipped: module-mandator's `Mail\MandatorMailCompany`.
 *
 * Contract: no-argument constructor (services through DI); {@see company()} answers null when
 * there is nothing to show — the mail then has no company foot. A throw is treated the same
 * (logged): a footer never stops a mail.
 */
interface MailCompanySource
{
    public function company(): ?MailCompany;
}
