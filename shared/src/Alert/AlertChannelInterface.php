<?php

namespace Z77\Shared\Alert;

/**
 * One delivery path for alerts — email, SMS, a webhook, anything that can
 * carry a short operator message. The framework ships {@see EmailAlertChannel};
 * an SMS or chat channel is an implementation of this interface in project
 * code or an add-on package (provider credentials never live in the kernel).
 *
 * Contract: send() MAY throw on delivery failure — the AlertService catches
 * per channel and falls back to error_log, so one dead channel neither kills
 * the others nor the caller's run. Channels MUST NOT do HTTP-request-bound
 * work (session, Request): alerts fire from cron runs (ADR-030 shape).
 */
interface AlertChannelInterface
{
    public function send(AlertMessage $message): void;
}
