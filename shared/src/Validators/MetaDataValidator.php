<?php

namespace Z77\Shared\Validators;

use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Entities\MetaData;
use Z77\Shared\Entities\Navigation;
use Z77\Shared\Repositories\MetaDataRepository;
use Z77\Shared\Repositories\NavigationRepository;

class MetaDataValidator extends EntityValidator
{
    /**
     * @param string $rawLdJson the raw application_ld string from the editor textarea
     *        (used to report JSON parse errors — the entity has already swallowed
     *        invalid JSON into an empty array; mirrors ContentValidator::$rawBlocksJson)
     */
    public function __construct(
        MetaData $metaData,
        private ?MetaDataRepository $repo = null,
        private ?NavigationRepository $navRepo = null,
        private bool $isNew = false,
        private string $rawLdJson = ''
    ) {
        parent::__construct($metaData);
    }

    public function validateNavigationId(?int $navigationId): void
    {
        if ($navigationId === null || $navigationId <= 0) {
            $this->addFieldError('navigation_id', 'Navigationseintrag ist ein Pflichtfeld');
            return;
        }
        if ($this->navRepo === null) {
            return;
        }
        /** @var Navigation|null $nav */
        $nav = $this->navRepo->find($navigationId);
        if ($nav === null) {
            $this->addFieldError('navigation_id', 'Navigationseintrag #' . $navigationId . ' existiert nicht');
            return;
        }
        // Metadata describes a routable page — a container/opener entry has no URL.
        if ($nav->getCanonicalUrl() === '') {
            $this->addFieldError('navigation_id', 'Navigationseintrag «' . $nav->getName() . '» ist keine routbare Seite');
            return;
        }

        // (navigation_id, language) is the identity — reject a second record for
        // the same page+language on add.
        if ($this->isNew && $this->repo !== null) {
            $lang = $this->entity->getLanguage();
            if ($lang !== '' && $this->repo->findByNavigationAndLanguage($navigationId, $lang) !== null) {
                $this->addFieldError('navigation_id', 'Für diese Seite und Sprache existieren bereits Metadaten');
            }
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

    public function validateDescription(string $description): void
    {
        // Optional — only length-bound (SEO descriptions stay short).
        $this->validate('description', 'Beschreibung', $description)->maxLength(320);
    }

    public function validateThemeColor(string $themeColor): void
    {
        if ($themeColor === '') {
            return; // optional — falls back to the template default
        }
        if (!preg_match('/^#?[0-9a-fA-F]{3,8}$/', $themeColor)) {
            $this->addFieldError('theme_color', 'Theme-Color muss ein Hex-Farbwert sein (z.B. #ffffff)');
        }
    }

    public function validateApplicationLd(mixed $applicationLd): void
    {
        // Optional. Prefer the raw editor string so a JSON parse error is reported
        // (the entity turns invalid JSON into []). Empty = no structured data.
        if ($this->rawLdJson !== '') {
            $decoded = json_decode($this->rawLdJson, true);
            if (!is_array($decoded)) {
                $this->addFieldError('application_ld', 'JSON-LD muss gültiges JSON sein (ein Objekt).');
                return;
            }
            $applicationLd = $decoded;
        }

        if (!is_array($applicationLd)) {
            $this->addFieldError('application_ld', 'JSON-LD muss ein Objekt sein.');
        }
    }
}
