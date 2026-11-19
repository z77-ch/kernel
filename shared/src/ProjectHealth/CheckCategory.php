<?php

namespace Z77\Shared\ProjectHealth;

/**
 * What kind of finding a {@see ProjectCheck} reports (project-health bauplan, «Ersteinrichtung»):
 *
 *   - `Setup`  — a step of the FIRST SETUP of the installation («Kontenplan übernehmen»,
 *     «Mandant erfassen»). The dashboard shows only the next few OPEN setup steps, in the order
 *     of {@see ProjectCheck::order()} — the rest appear as the earlier ones are done (owner
 *     2026-11-19: «nicht alles auf einmal»);
 *   - `Health` — a state of the installation that is wrong whenever it occurs (the kernel checks
 *     of the bauplan §1: debug on the live address, mail not delivered …). Shown whenever open,
 *     no limit, no order.
 */
enum CheckCategory: string
{
    case Setup  = 'setup';
    case Health = 'health';
}
