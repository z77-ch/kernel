<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Repositories\NavigationRepository;

class NavigationValidator extends EntityValidator
{
    /**
     * @param list<string> $knownSlots registered render-slot slugs (ModuleManager
     *        `getAllNavSlots()` keys, ADR-022). Empty DISABLES the slot/anchor check —
     *        the live single-field check has no config context, so shared stays
     *        decoupled from core (which owns the slot registry).
     */
    public function __construct(
        Navigation $nav,
        private ?NavigationRepository $repo = null,
        private array $knownSlots = []
    ) {
        parent::__construct($nav);
    }

    public function validateName(string $name): void
    {
        $this->validate('name', 'Name', $name)->notEmpty();
    }

    /**
     * Routing fields must be either all set (routable entry) or all empty
     * (opener / section container with children only). Mixed state is invalid.
     *
     * Since Phase 4 (ADR-015) the URL identity lives in NavigationAlias, so the
     * uniqueness check moved there (unique alias `path`); multiple entries MAY
     * share a 4-tuple. This validator only enforces the all-or-nothing structure.
     *
     * Validated on the synthetic field 'module' so the error surfaces in the
     * Modul input — the natural place to flag a missing routing setup.
     */
    public function validateModule(string $module): void
    {
        /** @var Navigation $entity */
        $entity = $this->entity;

        $fields = [
            'module'     => $entity->getModule(),
            'group'      => $entity->getGroup(),
            'controller' => $entity->getController(),
            'action'     => $entity->getAction(),
        ];

        $set   = array_filter($fields, fn($v) => $v !== '');
        $count = count($set);

        if ($count !== 0 && $count !== 4) {
            $missing = array_diff(array_keys($fields), array_keys($set));
            $this->addFieldError(
                'module',
                'Routing-Felder müssen entweder alle gesetzt oder alle leer sein. Fehlend: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Ref entries are UI-only pointers to another navigation entry.
     * Constraints:
     *  - routing fields must all be empty (no own URL — the target provides it)
     *  - must not be a parent (refs are leaf nodes — no entry may point to it)
     *  - slot must be empty (refs are not tree-roots)
     *  - target must exist and must not itself be a ref (no ref-of-ref)
     */
    public function validateRef(?int $ref): void
    {
        if ($ref === null) return;

        /** @var Navigation $entity */
        $entity = $this->entity;

        $hasRouting = $entity->getModule() !== '' || $entity->getGroup() !== ''
                   || $entity->getController() !== '' || $entity->getAction() !== '';
        if ($hasRouting) {
            $this->addFieldError('ref', 'Verweis-Eintrag darf keine eigenen Routing-Felder haben.');
        }
        if ($this->repo !== null && $entity->getId() !== null) {
            foreach ($this->repo->findAll() as $other) {
                if ($other->getParentId() === $entity->getId()) {
                    $this->addFieldError('ref', 'Verweis-Eintrag darf keine Kinder haben.');
                    break;
                }
            }
        }
        if ($entity->getSlot() !== '') {
            $this->addFieldError('ref', 'Verweis-Eintrag darf keinen Slot haben.');
        }
        if ($ref === $entity->getId()) {
            $this->addFieldError('ref', 'Verweis kann nicht auf sich selbst zeigen.');
            return;
        }
        if ($this->repo === null) return;

        $target = null;
        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() === $ref) {
                $target = $other;
                break;
            }
        }
        if ($target === null) {
            $this->addFieldError('ref', 'Ziel-Eintrag #' . $ref . ' existiert nicht.');
            return;
        }
        if ($target->getRef() !== null) {
            $this->addFieldError('ref', 'Ziel-Eintrag «' . $target->getName() . '» ist selbst ein Verweis — Ketten sind nicht erlaubt.');
        }
    }

    /**
     * A navigation entry is anchored EITHER to a render-slot (its tree-root carries a
     * `slot` slug) XOR to an element-parent (`parentId`) — never both, never neither.
     * The slot MUST be a REGISTERED render-slot (ModuleManager config, ADR-022): a slug
     * no view-area module declares is rejected. Slots are config, not an entity, so the
     * former leaf-group rule is now plain registry membership (XOR + orphan inlined —
     * ADR-022 dropped the shared ElementAnchorRules, Navigation was its only consumer).
     *
     * Skipped for ref entries (validated by validateRef) and when no slot registry was
     * supplied (check disabled — e.g. the live single-field check; shared stays
     * decoupled from core, which owns the registry).
     */
    public function validateSlot(string $slot): void
    {
        if ($this->entity->getRef() !== null) return;
        if ($this->knownSlots === []) return;

        $hasSlot   = $slot !== '';
        $hasParent = $this->entity->getParentId() !== null;

        if ($hasSlot && $hasParent) {
            $this->addFieldError('slot', 'Eintrag hat sowohl einen Slot als auch einen Parent. Genau eines ist erlaubt.');
            return;
        }
        if (!$hasSlot && !$hasParent) {
            $this->addFieldError('slot', 'Eintrag braucht entweder einen Slot (Tree-Root) oder einen Parent (Kind eines anderen Eintrags).');
            return;
        }
        if ($hasSlot && !in_array($slot, $this->knownSlots, true)) {
            $this->addFieldError('slot', 'Slot «' . $slot . '» ist nicht registriert (erlaubt: ' . implode(', ', $this->knownSlots) . ').');
        }
    }
}
