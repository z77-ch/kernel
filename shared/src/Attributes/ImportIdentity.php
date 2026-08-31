<?php

namespace Z77\Shared\Attributes;

/**
 * Declares how a data import recognizes "the same record" for this entity
 * (ADR-032). Takes ORDERED fallback rules — each rule is a list of property
 * names (camelCase, as declared on the class). The matcher tries the rules in
 * order; a rule only produces an identity when every named field carries a
 * non-empty value, and it only MATCHES when that identity is unique on both
 * sides (bijective, IMP-R001).
 *
 * A rule MAY name properties carrying {@see ImportRef} — they contribute the
 * resolved identity of the referenced record, never the local numeric id
 * (IMP-R002; e.g. MetaData's `['navigationId', 'language']`).
 *
 *   #[ImportIdentity(['key'], ['module', 'group', 'controller', 'action'], ['parentId', 'ref'])]
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ImportIdentity
{
    /** @var list<list<string>> */
    public readonly array $rules;

    public function __construct(array ...$rules)
    {
        $this->rules = array_values($rules);
    }
}
