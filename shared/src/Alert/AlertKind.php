<?php

namespace Z77\Shared\Alert;

/**
 * The three reportable transitions of a watched source. Edge-triggered by
 * design: a persisting outage is NOT a kind — it is silence between Outage
 * and (at most one) Escalation.
 */
enum AlertKind: string
{
    case Outage     = 'outage';     // ok → failing
    case Escalation = 'escalation'; // failing longer than the escalation window (once)
    case Recovery   = 'recovery';   // failing → ok
}
