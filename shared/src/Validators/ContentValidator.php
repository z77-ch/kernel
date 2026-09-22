<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Content\BlockSchemaValidator;
use Z77\Shared\Content\Blueprint;
use Z77\Shared\Entities\Content;
use Z77\Shared\Repositories\ContentRepository;

class ContentValidator extends EntityValidator
{
    /**
     * @param list<string> $knownTypes registered block type names (from BlockRegistry::types())
     * @param string $rawBlocksJson the raw blocks string from the editor textarea
     *        (used to report JSON parse errors — the entity has already swallowed
     *        invalid JSON into an empty array)
     * @param array<string, list<array<string, mixed>>> $schemas type ⇒ field descriptors;
     *        when given, every known-type block's values are checked against its schema
     *        (ADR-044: required, maxLength, list min/max, URL scheme)
     */
    public function __construct(
        Content $content,
        private array $knownTypes = [],
        private ?ContentRepository $repo = null,
        private bool $isNew = false,
        private string $rawBlocksJson = '',
        private array $schemas = []
    ) {
        parent::__construct($content);
    }

    /** The document's blueprint, if any — only used to name errors by slot label. */
    private ?Blueprint $blueprint = null;

    public function useBlueprint(?Blueprint $blueprint): void
    {
        $this->blueprint = $blueprint;
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
        // The raw editor string only tells whether the post was valid JSON (the
        // entity turns invalid JSON into []). The blocks checked are the entity's —
        // for a blueprint document that is the slot-enforced list, not the post.
        if ($this->rawBlocksJson !== '' && !is_array(json_decode($this->rawBlocksJson, true))) {
            $this->addFieldError('blocks', 'Blöcke müssen gültiges JSON sein (ein Array von Block-Objekten).');
            return;
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

        $this->validateBlockFields(array_values($blocks));
    }

    /** Field values against each type's schema; all messages in one `blocks` error. */
    private function validateBlockFields(array $blocks): void
    {
        if ($this->schemas === []) {
            return;
        }

        $checker  = new BlockSchemaValidator();
        $messages = [];
        foreach ($blocks as $i => $block) {
            $type = (string)$block['type'];
            if (!isset($this->schemas[$type])) {
                continue;
            }
            $slot    = $this->blueprint?->slot((string)($block['key'] ?? ''));
            $context = $slot !== null ? $slot['label'] : 'Block #' . ($i + 1) . ' (' . $type . ')';
            array_push($messages, ...$checker->errors($block, $this->schemas[$type], $context));
        }

        if ($messages !== []) {
            $this->addFieldError('blocks', implode(' · ', $messages));
        }
    }
}
