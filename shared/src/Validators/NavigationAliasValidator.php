<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Entities\NavigationAlias;
use Z77\Shared\Repositories\NavigationAliasRepository;
use Z77\Shared\Repositories\NavigationRepository;

/**
 * Validates a {@see NavigationAlias} (ADR-015). The alias `path` is the URL
 * identity, so it must be unique across all aliases (this replaces the old
 * NAV-DUP-001 4-tuple uniqueness). At most one canonical alias per navigation.
 */
class NavigationAliasValidator extends EntityValidator
{
    public function __construct(
        NavigationAlias $alias,
        private ?NavigationAliasRepository $repo = null,
        private ?NavigationRepository $navRepo = null
    ) {
        parent::__construct($alias);
    }

    public function validateNavigationId(mixed $navigationId): void
    {
        /** @var NavigationAlias $entity */
        $entity = $this->entity;
        $id     = $entity->getNavigationId();

        if ($id === null) {
            $this->addFieldError('navigation_id', 'Ein Alias braucht ein Navigations-Ziel.');
            return;
        }
        if ($this->navRepo !== null && $this->navRepo->find($id) === null) {
            $this->addFieldError('navigation_id', 'Navigationseintrag #' . $id . ' existiert nicht.');
        }
    }

    public function validatePath(string $path): void
    {
        $this->validate('path', 'Pfad', $path)->notEmpty()->isUrl();
        if ($this->hasFieldError('path') || $this->repo === null) {
            return;
        }

        /** @var NavigationAlias $entity */
        $entity = $this->entity;
        $ownId  = $entity->getId();
        $norm   = $entity->getPath();   // normalized (leading slash, no trailing)

        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() === $ownId) continue;
            if ($other->getPath() === $norm) {
                $this->addFieldError('path', 'Der Pfad ' . $norm . ' ist bereits bei einem anderen Alias vergeben.');
                return;
            }
        }
    }

    public function validateIsCanonical(mixed $isCanonical): void
    {
        /** @var NavigationAlias $entity */
        $entity = $this->entity;
        if (!$entity->isCanonical() || $this->repo === null) {
            return;
        }

        $navId = $entity->getNavigationId();
        $ownId = $entity->getId();
        if ($navId === null) {
            return;
        }

        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() === $ownId) continue;
            if ($other->getNavigationId() === $navId && $other->isCanonical()) {
                $this->addFieldError(
                    'is_canonical',
                    'Es gibt bereits einen canonical Alias für diesen Navigationseintrag.'
                );
                return;
            }
        }
    }
}
