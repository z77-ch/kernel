<?php

namespace Z77\Shared\Import;

/**
 * What the plan proposes for one source record (ADR-032 §6). `Invalid` is the
 * technical sixth state: the record failed hydration/structure checks and can
 * never be accepted — reported, not silently dropped (§10).
 */
enum ImportOutcome: string
{
    /** Identity matched, content identical — already present, nothing to do. */
    case Skipped = 'skipped';

    /** Identity matched, content differs — adoption is per-record opt-in, default No (§8). */
    case Changed = 'changed';

    /** No identity match, no near-match — will be created on accept. */
    case NewRecord = 'new';

    /** No/ambiguous identity — needs a human: assign a match or accept as new (§6). */
    case Unclear = 'unclear';

    /** A referenced record is unclear/declined/invalid — undecidable until that resolves (IMP-R006). */
    case Blocked = 'blocked';

    /** Structurally broken source record — never applicable. */
    case Invalid = 'invalid';
}
