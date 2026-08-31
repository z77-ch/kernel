<?php

namespace Z77\Shared\Import;

/**
 * Computes the import plan (ADR-032): classifies every source record against
 * the target data along two dimensions — declared identity (WHICH record is
 * this?) and content equality (is it still IDENTICAL?) — into
 * skipped | changed | new | unclear | blocked (+ invalid for broken records).
 *
 * Guarantees the review findings demanded:
 *  - ids are never identity; refs compare/resolve via the per-run match map
 *  - a rule only matches bijectively — ambiguity → unclear, never a guess (IMP-R001)
 *  - rules run in order over the WHOLE set, so a ref-bearing rule sees the
 *    matches of the earlier rules (IMP-R002)
 *  - a `new` verdict survives only if the near-match pass finds no unclaimed
 *    candidate (IMP-R003)
 *  - a record whose reference cannot be resolved is unclear/blocked, and
 *    blocking is transitive (IMP-R006)
 *
 * Stateless between calls: decisions are INPUT, and the plan is recomputed
 * from scratch after every decision (an assignment can unblock dependents).
 */
final class ImportPlanner
{
    private const UNRESOLVABLE = "\x00unresolvable";

    /** @var array<class-string, ImportDescriptor> */
    private array $descriptors = [];

    // Per-plan working state (reset by plan()).
    /** @var array<class-string, array<int, int>> matches: source id → target id */
    private array $matches = [];
    /** @var array<class-string, array<int, true>> source ids planned as new */
    private array $plannedNew = [];
    /** @var array<class-string, array<int, true>> all source ids per class */
    private array $sourceIds = [];
    /** @var array<class-string, array<int, ImportPlanEntry>> source id → entry */
    private array $entryById = [];

    /**
     * @param array<class-string, list<array<string, mixed>>> $sourceSets raw records per class
     * @param array<class-string, list<object|array>> $targetSets existing records per class
     *        (entities from a repository, or raw arrays in tests/CLI)
     * @param array<string, array{decision?: string, target_id?: ?int}> $decisions keyed
     *        `{class}#{sourceIndex}` — the developer's choices from a previous round
     */
    public function plan(array $sourceSets, array $targetSets, array $decisions = [], string $sourceLabel = ''): ImportPlan
    {
        $this->matches = $this->plannedNew = $this->sourceIds = $this->entryById = [];

        $classOrder = $this->orderByDependencies(array_keys($sourceSets));

        // Pre-scan all source ids so "ref points outside the source" is
        // detectable regardless of processing order.
        foreach ($classOrder as $class) {
            foreach ($sourceSets[$class] as $raw) {
                if (isset($raw['id']) && is_int($raw['id'])) {
                    $this->sourceIds[$class][$raw['id']] = true;
                }
            }
        }

        $entries = [];
        foreach ($classOrder as $class) {
            foreach ($this->planClass($class, $sourceSets[$class], $targetSets[$class] ?? [], $decisions) as $entry) {
                $entries[] = $entry;
            }
        }

        $this->propagateBlocked($entries);

        return new ImportPlan($entries, $classOrder, $sourceLabel);
    }

    // -------------------------------------------------------------------------
    // Per-class classification
    // -------------------------------------------------------------------------

    /** @return list<ImportPlanEntry> */
    private function planClass(string $class, array $rawRecords, array $targetRecords, array $decisions): array
    {
        $desc = $this->descriptor($class);

        // Normalize both sides into the canonical snake_case shape.
        $sources = [];   // index => normalized record
        $invalid = [];   // index => reason
        foreach ($rawRecords as $i => $raw) {
            try {
                $sources[$i] = $desc->normalize($raw);
            } catch (\Throwable $e) {
                $invalid[$i] = 'Record could not be hydrated: ' . $e->getMessage();
                $sources[$i] = ['id' => (isset($raw['id']) && is_int($raw['id'])) ? $raw['id'] : null] + (is_array($raw) ? $raw : []);
            }
        }

        $targets = [];   // target id => normalized record
        foreach ($targetRecords as $record) {
            $normalized = is_object($record) ? $record->mapToArray() : $desc->normalize($record);
            if (isset($normalized['id']) && is_int($normalized['id'])) {
                $targets[$normalized['id']] = $normalized;
            }
        }

        $matched   = [];  // source index => target id
        $ambiguous = [];  // source index => true
        $claimed   = [];  // target id => true

        // Manual assignments from a previous round act like identity matches
        // and claim their target before any rule runs.
        foreach ($sources as $i => $src) {
            $decision = $decisions[$class . '#' . $i] ?? null;
            $assigned = $decision['target_id'] ?? null;
            if ($assigned !== null && isset($targets[$assigned]) && !isset($claimed[$assigned]) && !isset($invalid[$i])) {
                $matched[$i]        = $assigned;
                $claimed[$assigned] = true;
                // Register immediately — same-class ref rules in the passes
                // below (and dependent classes) resolve through this match.
                if ($src['id'] !== null) $this->matches[$class][$src['id']] = $assigned;
            }
        }

        // Identity rules, in declared order, bijective per rule (IMP-R001).
        $unresolvable = [];  // source index => true (ref points outside the source)
        foreach ($desc->getIdentityRules() as $rule) {
            $srcTokens = [];
            foreach ($sources as $i => $src) {
                if (isset($matched[$i]) || isset($invalid[$i])) continue;
                $token = $this->identityToken($desc, $rule, $src, isTarget: false);
                if ($token === self::UNRESOLVABLE) {
                    $unresolvable[$i] = true;
                    continue;
                }
                if ($token !== null) $srcTokens[$token][] = $i;
            }

            $tgtTokens = [];
            foreach ($targets as $id => $tgt) {
                if (isset($claimed[$id])) continue;
                $token = $this->identityToken($desc, $rule, $tgt, isTarget: true);
                if ($token !== null && $token !== self::UNRESOLVABLE) $tgtTokens[$token][] = $id;
            }

            foreach ($srcTokens as $token => $srcIndexes) {
                $tgtIds = $tgtTokens[$token] ?? [];
                if (count($srcIndexes) === 1 && count($tgtIds) === 1) {
                    $i = $srcIndexes[0];
                    $matched[$i]          = $tgtIds[0];
                    $claimed[$tgtIds[0]]  = true;
                    unset($ambiguous[$i]);
                    // Same-class refs in later rules resolve through this match.
                    $srcId = $sources[$i]['id'];
                    if ($srcId !== null) $this->matches[$class][$srcId] = $tgtIds[0];
                    continue;
                }
                // Duplicate on either side: no guessing (IMP-R001). The records
                // stay unmatched and may still match by a later rule.
                if (count($tgtIds) > 0) {
                    foreach ($srcIndexes as $i) $ambiguous[$i] = true;
                }
                if (count($srcIndexes) > 1 && count($tgtIds) > 0) {
                    foreach ($srcIndexes as $i) $ambiguous[$i] = true;
                }
            }
        }

        // Register matches (again, for classes whose ids only appear here).
        foreach ($matched as $i => $targetId) {
            $srcId = $sources[$i]['id'];
            if ($srcId !== null) $this->matches[$class][$srcId] = $targetId;
        }

        // Outcomes.
        $entries = [];
        foreach ($sources as $i => $src) {
            $decision     = $decisions[$class . '#' . $i] ?? null;
            $decisionCode = $decision['decision'] ?? ImportPlanEntry::DECISION_PENDING;

            if (isset($invalid[$i])) {
                $entry = new ImportPlanEntry($class, $i, $src, ImportOutcome::Invalid, reason: $invalid[$i]);
            } elseif (isset($matched[$i])) {
                $diff  = $this->contentDiff($desc, $src, $targets[$matched[$i]]);
                $entry = new ImportPlanEntry(
                    $class, $i, $src,
                    $diff === [] ? ImportOutcome::Skipped : ImportOutcome::Changed,
                    targetId: $matched[$i],
                    diff: $diff,
                    reason: $diff === [] ? 'Identical record exists.' : 'Existing record differs in: ' . implode(', ', array_keys($diff)),
                );
            } elseif (isset($ambiguous[$i])) {
                $entry = new ImportPlanEntry($class, $i, $src, ImportOutcome::Unclear,
                    reason: 'Identity is ambiguous — the identity value is not unique on both sides.');
            } elseif (isset($unresolvable[$i])) {
                $entry = new ImportPlanEntry($class, $i, $src, ImportOutcome::Unclear,
                    reason: 'References a record that is neither part of the source nor matched.');
            } else {
                $entry = $this->classifyUnmatched(
                    $desc, $i, $src, $targets, $claimed,
                    forceNew: (bool) ($decision['force_new'] ?? false)
                );
            }

            $entry->decision         = $decisionCode;
            $entry->assignedTargetId = $decision['target_id'] ?? null;

            if ($entry->outcome === ImportOutcome::NewRecord && $src['id'] !== null) {
                $this->plannedNew[$class][$src['id']] = true;
            }
            if ($src['id'] !== null) {
                $this->entryById[$class][$src['id']] = $entry;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * A record no rule could match: near-match pass (IMP-R003) or genuinely new.
     * A new record must also be APPLICABLE — every ref has to resolve to a match
     * or to a fellow source record; otherwise it is unclear, not new. $forceNew
     * is the developer's "this really is new" answer to an unclear record: it
     * skips the near-match suggestion, never the applicability checks.
     */
    private function classifyUnmatched(ImportDescriptor $desc, int $index, array $src, array $targets, array $claimed, bool $forceNew = false): ImportPlanEntry
    {
        foreach ($desc->getRefs() as $field => $ref) {
            $value = $src[$field] ?? null;
            if ($value === null) continue;
            $refClass = $ref->targetClass;
            if (!isset($this->matches[$refClass][$value]) && !isset($this->sourceIds[$refClass][$value])) {
                return new ImportPlanEntry($desc->entityClass, $index, $src, ImportOutcome::Unclear,
                    reason: "Field {$field} references #{$value}, which is neither part of the source nor matched.");
            }
        }

        $nearFields = $desc->getNearMatchFields();
        if ($nearFields !== [] && !$forceNew) {
            $candidates = [];
            foreach ($targets as $id => $tgt) {
                if (isset($claimed[$id])) continue;
                if ($this->fieldsCoincide($desc, $nearFields, $src, $tgt)) {
                    $candidates[] = $id;
                }
            }
            if (count($candidates) === 1) {
                return new ImportPlanEntry($desc->entityClass, $index, $src, ImportOutcome::Unclear,
                    suggestionId: $candidates[0],
                    reason: 'No identity match, but an existing record coincides on ' . implode(', ', $nearFields) . ' — assign or accept as new.');
            }
            if (count($candidates) > 1) {
                return new ImportPlanEntry($desc->entityClass, $index, $src, ImportOutcome::Unclear,
                    reason: 'No identity match; several existing records coincide on ' . implode(', ', $nearFields) . '.');
            }
        }

        return new ImportPlanEntry($desc->entityClass, $index, $src, ImportOutcome::NewRecord,
            reason: 'No matching record exists.');
    }

    // -------------------------------------------------------------------------
    // Identity + content comparison
    // -------------------------------------------------------------------------

    /**
     * Builds the identity token of one rule for one record, or null when a
     * named field is empty (rule not applicable), or UNRESOLVABLE when a
     * source-side ref points at a record that is neither matched nor part of
     * the source. Ref fields contribute the resolved TARGET id (`t:`) or the
     * planned-new marker (`n:` — never matches a target token, but keeps
     * source-side uniqueness meaningful). Target-side refs contribute their
     * own raw id — both sides therefore speak target-id language (IMP-R002).
     */
    private function identityToken(ImportDescriptor $desc, array $rule, array $record, bool $isTarget): ?string
    {
        $parts = [];
        foreach ($rule as $field) {
            $value = $record[$field] ?? null;

            if ($desc->isRefField($field)) {
                if ($value === null) return null;
                if ($isTarget) {
                    $parts[] = 't:' . $value;
                    continue;
                }
                $ref = $desc->getRefs()[$field];
                $this->guardResolveMode($ref);
                if (isset($this->matches[$ref->targetClass][$value])) {
                    $parts[] = 't:' . $this->matches[$ref->targetClass][$value];
                } elseif (isset($this->plannedNew[$ref->targetClass][$value])) {
                    $parts[] = 'n:' . $value;
                } elseif (isset($this->sourceIds[$ref->targetClass][$value])) {
                    return null;   // referenced source record not classified (yet) — rule not usable
                } else {
                    return self::UNRESOLVABLE;
                }
                continue;
            }

            if ($value === null || $value === '') return null;
            $parts[] = 'v:' . (is_scalar($value) ? (string) $value : json_encode(self::canonical($value)));
        }
        return implode("\x1f", $parts);
    }

    /**
     * Field-level diff of a matched pair. Content fields compare canonically
     * (arrays key-sorted recursively — IMP-R005); ref fields compare "does the
     * target point at the matched counterpart of the source's ref target".
     * Ref differences are REPORTED but never auto-applied — reparenting is
     * moveAction's turf, with its guards.
     *
     * @return array<string, array{source: mixed, target: mixed}>
     */
    private function contentDiff(ImportDescriptor $desc, array $src, array $tgt): array
    {
        $diff = [];
        foreach ($desc->getContentFields() as $field) {
            $sv = self::canonical($src[$field] ?? null);
            $tv = self::canonical($tgt[$field] ?? null);
            if ($sv !== $tv) {
                $diff[$field] = ['source' => $src[$field] ?? null, 'target' => $tgt[$field] ?? null];
            }
        }
        foreach ($desc->getRefs() as $field => $ref) {
            $sv = $src[$field] ?? null;
            $tv = $tgt[$field] ?? null;
            if ($sv === null && $tv === null) continue;
            if ($sv !== null && $tv !== null) {
                $counterpart = $this->matches[$ref->targetClass][$sv] ?? null;
                if ($counterpart === $tv) continue;                      // same referenced record
                if ($counterpart === null && !isset($this->plannedNew[$ref->targetClass][$sv])) {
                    // Referenced source record is still pending (unclear container,
                    // later round): sameness is UNKNOWN, not different — omitting it
                    // keeps round-1 plans free of bogus ref diffs; the replan after
                    // the assignment settles it either way.
                    continue;
                }
            }
            $diff[$field] = ['source' => $sv, 'target' => $tv];
        }
        return $diff;
    }

    /** Near-match equality over $fields — refs via counterpart, values canonically. */
    private function fieldsCoincide(ImportDescriptor $desc, array $fields, array $src, array $tgt): bool
    {
        foreach ($fields as $field) {
            $sv = $src[$field] ?? null;
            $tv = $tgt[$field] ?? null;

            if ($desc->isRefField($field)) {
                $ref = $desc->getRefs()[$field];
                $this->guardResolveMode($ref);
                if ($sv === null && $tv === null) continue;
                $counterpart = $sv !== null ? ($this->matches[$ref->targetClass][$sv] ?? null) : null;
                if ($counterpart === null || $counterpart !== $tv) return false;
                continue;
            }
            if (self::canonical($sv) !== self::canonical($tv)) return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Blocking (IMP-R006) — transitive, after all classes are classified
    // -------------------------------------------------------------------------

    /** @param list<ImportPlanEntry> $entries */
    private function propagateBlocked(array $entries): void
    {
        $entryIndex = [];
        foreach ($entries as $idx => $entry) {
            $srcId = $entry->record['id'] ?? null;
            if ($srcId !== null) $entryIndex[$entry->entityClass][$srcId] = $idx;
        }

        do {
            $changed = false;
            foreach ($entries as $idx => $entry) {
                // Only records that would be INSERTED can be blocked: a matched
                // record already exists in the target with its own refs (apply of
                // `changed` never rewrites refs), and unclear/invalid are terminal.
                if ($entry->outcome !== ImportOutcome::NewRecord) {
                    continue;
                }
                $desc = $this->descriptor($entry->entityClass);
                foreach ($desc->getRefs() as $field => $ref) {
                    $value = $entry->record[$field] ?? null;
                    if ($value === null) continue;
                    if (isset($this->matches[$ref->targetClass][$value])) continue;   // resolves to an existing target

                    $refIdx = $entryIndex[$ref->targetClass][$value] ?? null;
                    if ($refIdx === null) continue;   // already handled as unresolvable at classification
                    $referenced = $entries[$refIdx];

                    $blocking = in_array($referenced->outcome, [ImportOutcome::Unclear, ImportOutcome::Invalid, ImportOutcome::Blocked], true)
                        || ($referenced->outcome === ImportOutcome::NewRecord && $referenced->decision === ImportPlanEntry::DECISION_REJECT);

                    if ($blocking) {
                        $entry->outcome        = ImportOutcome::Blocked;
                        $entry->blockedByIndex = $refIdx;
                        $entry->reason         = "Blocked: {$field} depends on " . $referenced->describe()
                            . ' (' . $referenced->outcome->value . ($referenced->decision === ImportPlanEntry::DECISION_REJECT ? ', declined' : '') . ').';
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function descriptor(string $class): ImportDescriptor
    {
        return $this->descriptors[$class] ??= new ImportDescriptor($class);
    }

    /**
     * Processing order: referenced entity classes before their dependents
     * (self-refs ignored) — a dependent's ref-bearing identity rules need the
     * referenced class's matches (IMP-R002). Classes outside the source set
     * are ignored; cross-cycles would fall back to input order (none exist —
     * refs across classes form a DAG in this framework).
     */
    private function orderByDependencies(array $classes): array
    {
        $ordered = [];
        $visiting = [];
        $visit = function (string $class) use (&$visit, &$ordered, &$visiting, $classes): void {
            if (in_array($class, $ordered, true) || isset($visiting[$class])) return;
            $visiting[$class] = true;
            foreach ($this->descriptor($class)->getRefs() as $ref) {
                if ($ref->targetClass !== $class && in_array($ref->targetClass, $classes, true)) {
                    $visit($ref->targetClass);
                }
            }
            unset($visiting[$class]);
            $ordered[] = $class;
        };
        foreach ($classes as $class) $visit($class);
        return $ordered;
    }

    private function guardResolveMode(\Z77\Shared\Attributes\ImportRef $ref): void
    {
        if ($ref->resolveBy !== 'map') {
            throw new \LogicException(
                "ImportRef resolveBy '{$ref->resolveBy}' is declared but not implemented (v2, ADR-032 §13)."
            );
        }
    }

    /** Canonical comparison form: arrays recursively key-sorted (IMP-R005). */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $k => $v) $result[$k] = self::canonical($v);
        ksort($result);
        return $result;
    }
}
