<?php

namespace Z77\Shared\Mail;

/**
 * Answers ONE named inline image of the mails (2026-10-23) — registered by a module under
 * the config key `mailInlineImages` (name => class), read by {@see InlineImages} at SEND
 * time. First source: module-mandator's `Mail\EmailLogo` («Logo (E-Mail)»), name `logo`.
 *
 * Contract: no-argument constructor (services through DI); {@see image()} answers null when
 * there is nothing to show — the slot then disappears and the mail goes out without it. A
 * throw is treated the same (logged): an image never stops a mail.
 */
interface InlineImageSource
{
    public function image(): ?InlineImage;
}
