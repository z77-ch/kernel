<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Entities\Content;
use Z77\Shared\Repositories\ContentRepository;

class ContentValidator extends EntityValidator
{
    /**
     * @param list<string> $knownTypes registered block type names (from BlockRegistry::types())
     * @param string $rawBlocksJson the raw blocks string from the editor textarea
     *        (used to report JSON parse errors — the entity has already swallowed
     *        invalid JSON into an empty array)
     */
    public function __construct(
        Content $content,
        private array $knownTypes = [],
        private ?ContentRepository $repo = null,
        private bool $isNew = false,
        private string $rawBlocksJson = ''
    ) {
        parent::__construct($content);
    }

    public function validateSlug(string $slug): void
    {
        $this->validate('slug', 'Slug', $slug)->notEmpty()->isUrl();

        if (!$this->isNew || $this->repo === null || $this->hasFieldError('slug')) {
            return;
        }
        if ($this->repo->findBySlug($slug, $this->entity->getLanguage()) !== null) {
            $this->addFieldError('slug', 'Slug + Sprache sind bereits vergeben');
        }
    }

    public function validateLanguage(string $language): void
    {
        $this->validate('language', 'Sprache', $language)->notEmpty()->isAlphaAscii();
    }

    public function validateTitle(string $title): void
    {
        $this->validate('title', 'Titel', $title)->notEmpty()->maxLength(120);
    }

    public function validateBlocks(mixed $blocks): void
    {
        // Prefer the raw editor string so a JSON parse error is reported (the entity
        // turns invalid JSON into []). Fall back to the entity value otherwise.
        if ($this->rawBlocksJson !== '') {
            $decoded = json_decode($this->rawBlocksJson, true);
            if (!is_array($decoded)) {
                $this->addFieldError('blocks', 'Blöcke müssen gültiges JSON sein (ein Array von Block-Objekten).');
                return;
            }
            $blocks = $decoded;
        }

        if (!is_array($blocks)) {
            $this->addFieldError('blocks', 'Blöcke müssen ein Array sein.');
            return;
        }

        foreach (array_values($blocks) as $i => $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                $this->addFieldError('blocks', 'Block #' . ($i + 1) . ' hat keinen gültigen «type».');
                return;
            }
            if ($this->knownTypes !== [] && !in_array($block['type'], $this->knownTypes, true)) {
                $this->addFieldError(
                    'blocks',
                    'Unbekannter Block-Typ «' . $block['type'] . '» (erlaubt: ' . implode(', ', $this->knownTypes) . ').'
                );
                return;
            }
        }
    }
}
